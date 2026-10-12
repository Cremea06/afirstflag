<?php
/**
 * Music drawer Publish: the one latest published recording (afirstflag.com shop, CHG-124, mirrors CHG-098 art-publish).
 * GET        - the .mid, streamed through PHP (audio/midi, nosniff, CSP default-src 'none', ETag, short cache).
 *              404 when nothing is published. (?mid is accepted as an alias.)
 * GET ?meta  - { exists, updatedAt, bytes, ms, notes, v }   (v = short content hash, used as the ?mid version)
 * POST       - raw Standard MIDI File body, Content-Type: audio/midi (the file the Save .mid exporter builds).
 *              Anyone may publish; it replaces the recording for everyone. No name is attached.
 *              Same-origin only (Origin, or Referer when Origin is missing; Sec-Fetch-Site must be same-origin).
 *              Body max 65,536 bytes (413). The file must be real: MThd length 6, format 0, exactly 1 track,
 *              ticks-per-quarter 24..960, one MTrk that fills the rest of the file exactly, valid events ending
 *              with End of Track, at most 4000 notes, and at most 5 minutes long by its tempo map (422 otherwise).
 *              The stored file is rebuilt from only tempo, time signature, end of track and channel
 *              note/controller/program events, so names, text, lyrics and SysEx never get stored.
 * Rate limit per IP (salted HMAC of the IP, never the raw IP): 1 POST per 30 s and 20 per 24 h (429 + Retry-After).
 * Store (data/.htaccess denies HTTP access; gitignored). No history: each publish overwrites both files.
 *   data/music-latest.mid          the recording                         temp file + rename under the lock
 *   data/music-latest.json         { updatedAt, bytes, ms, notes, v }    temp file + rename under the lock
 *   data/music-publish.lock        flock target (exclusive for publish, shared for reads)
 *   data/music-publish-hits.json   recent POST times per hashed IP (pruned after 24 h)
 *   data/click-record-salt.php     salt shared with api/click-record.php, click-total.php and art-publish.php
 * Emergency clear: delete data/music-latest.mid and data/music-latest.json.
 * Local testing only: AFF_CLICK_RECORD_ALLOW_LOCAL=1 also allows http://localhost / http://127.0.0.1 origins.
 * PHP 7.0+.
 */

const AF_MP_MAX_BODY = 65536;       // bytes (413 above)
const AF_MP_MAX_MS = 300000;        // 5 minutes
const AF_MP_MAX_NOTES = 4000;
const AF_MP_RL_GAP_SEC = 30;        // 1 publish per 30 s per IP
const AF_MP_RL_WINDOW_SEC = 86400;  // and 20 per 24 h per IP
const AF_MP_RL_MAX = 20;
const AF_MP_RL_MAX_KEYS = 5000;

$dataDir = dirname(__DIR__) . '/data';
$midPath = $dataDir . '/music-latest.mid';
$metaPath = $dataDir . '/music-latest.json';
$lockPath = $dataDir . '/music-publish.lock';
$hitsPath = $dataDir . '/music-publish-hits.json';
$saltPath = $dataDir . '/click-record-salt.php';
$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

function af_mp_fail($code, $msg, $extraHeaders = []) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function af_mp_local_allowed() {
    $v = getenv('AFF_CLICK_RECORD_ALLOW_LOCAL');
    if ($v === false && isset($_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'])) {
        $v = $_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'];
    }
    return (string) $v === '1';
}

function af_mp_origin_ok($origin) {
    $origin = strtolower(rtrim(trim((string) $origin), '/'));
    if ($origin === 'https://afirstflag.com' || $origin === 'https://www.afirstflag.com') {
        return true;
    }
    return af_mp_local_allowed() && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin);
}

function af_mp_referer_origin($ref) {
    $p = parse_url((string) $ref);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function af_mp_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to the data store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

function af_mp_salt($path) {
    // Same file and format as api/click-record.php, so all endpoints hash an IP the same way.
    $salt = is_file($path) ? @include $path : null;
    if (is_string($salt) && preg_match('/^[0-9a-f]{64}$/', $salt)) {
        return $salt;
    }
    $salt = bin2hex(random_bytes(32));
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $php = "<?php\n// CHG-072: random salt for hashing visitor IPs (click record rate limit). Created by api/click-record.php. Never commit.\nreturn '" . $salt . "';\n";
    if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return null;
    }
    return $salt;
}

function af_mp_read_meta($path) {
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $m = (is_string($raw) && trim($raw) !== '') ? json_decode($raw, true) : null;
    if (!is_array($m) || !isset($m['updatedAt'], $m['bytes'], $m['ms'], $m['notes'], $m['v'])) {
        return null;
    }
    if (!is_string($m['updatedAt']) || !is_int($m['bytes']) || !is_int($m['ms']) || !is_int($m['notes'])
        || !is_string($m['v']) || !preg_match('/^[0-9a-f]{16}$/', $m['v'])) {
        return null;
    }
    return $m;
}

function af_mp_vlq_enc($n) {
    $out = chr($n & 0x7f);
    while (($n >>= 7) > 0) {
        $out = chr(($n & 0x7f) | 0x80) . $out;
    }
    return $out;
}

/**
 * Strict Standard MIDI File check (format 0, one track).
 * Returns [cleanMid, durationMs, noteCount] or a string error.
 */
function af_mp_check_mid($bin) {
    $len = strlen($bin);
    if ($len < 26 || substr($bin, 0, 8) !== "MThd\x00\x00\x00\x06") {
        return 'not a MIDI file';
    }
    $hd = unpack('nfmt/ntracks/ndiv', substr($bin, 8, 6));
    if ($hd['fmt'] !== 0) {
        return 'type 0 only';
    }
    if ($hd['tracks'] !== 1) {
        return 'one track only';
    }
    $ppq = $hd['div'];
    if ($ppq & 0x8000 || $ppq < 24 || $ppq > 960) {
        return 'bad timing division';
    }
    if (substr($bin, 14, 4) !== 'MTrk') {
        return 'missing track';
    }
    $u = unpack('N', substr($bin, 18, 4));
    $tlen = $u[1];
    if ($tlen !== $len - 22) {
        return 'track length does not match file';
    }
    $pos = 22;
    $end = $len;
    $status = 0;
    $tick = 0;
    $lastTick = 0;
    $tempo = 500000;    // us per quarter until the first Set Tempo
    $us = 0.0;          // elapsed microseconds
    $notes = 0;
    $ended = false;
    $out = '';
    $outTick = 0;
    $emit = function ($t, $data) use (&$out, &$outTick) {
        $out .= af_mp_vlq_enc($t - $outTick) . $data;
        $outTick = $t;
    };
    while ($pos < $end) {
        // delta time (max 4 bytes)
        $d = 0;
        for ($i = 0; ; $i++) {
            if ($pos >= $end || $i >= 4) {
                return 'bad delta time';
            }
            $c = ord($bin[$pos++]);
            $d = ($d << 7) | ($c & 0x7f);
            if (!($c & 0x80)) {
                break;
            }
        }
        $tick += $d;
        $us += $d * $tempo / $ppq;
        if ($us > AF_MP_MAX_MS * 1000) {
            return 'longer than 5 minutes';
        }
        if ($pos >= $end) {
            return 'truncated event';
        }
        $b = ord($bin[$pos]);
        if ($b === 0xff) {
            if ($pos + 2 > $end) {
                return 'truncated event';
            }
            $type = ord($bin[$pos + 1]);
            $pos += 2;
            $ml = 0;
            for ($i = 0; ; $i++) {
                if ($pos >= $end || $i >= 4) {
                    return 'bad meta length';
                }
                $c = ord($bin[$pos++]);
                $ml = ($ml << 7) | ($c & 0x7f);
                if (!($c & 0x80)) {
                    break;
                }
            }
            if ($ml > $end - $pos) {
                return 'truncated event';
            }
            $data = substr($bin, $pos, $ml);
            $pos += $ml;
            $status = 0; // meta events cancel running status
            if ($type === 0x2f) {
                if ($ml !== 0) {
                    return 'bad end of track';
                }
                $ended = true;
                $emit($tick, "\xff\x2f\x00");
                break;
            } elseif ($type === 0x51) {
                if ($ml !== 3) {
                    return 'bad tempo';
                }
                $t = unpack('N', "\x00" . $data);
                if ($t[1] < 100000 || $t[1] > 3000000) { // 20..600 BPM
                    return 'bad tempo';
                }
                $tempo = $t[1];
                $emit($tick, "\xff\x51\x03" . $data);
            } elseif ($type === 0x58) {
                if ($ml !== 4) {
                    return 'bad time signature';
                }
                $emit($tick, "\xff\x58\x04" . $data);
            } elseif ($type > 0x7f) {
                return 'bad meta event';
            }
            // other meta (track name, text, lyrics, markers, ...) are dropped: no names are stored
            continue;
        }
        if ($b === 0xf0 || $b === 0xf7) {
            return 'SysEx not accepted';
        }
        if ($b & 0x80) {
            if ($b >= 0xf0) {
                return 'bad event';
            }
            $status = $b;
            $pos++;
        } elseif ($status === 0) {
            return 'bad running status';
        }
        $hi = $status & 0xf0;
        $n = ($hi === 0xc0 || $hi === 0xd0) ? 1 : 2;
        if ($pos + $n > $end) {
            return 'truncated event';
        }
        $d1 = ord($bin[$pos]);
        $d2 = $n === 2 ? ord($bin[$pos + 1]) : 0;
        $pos += $n;
        if ($d1 > 127 || $d2 > 127) {
            return 'bad event data';
        }
        if ($hi === 0x90 || $hi === 0x80) {
            if ($hi === 0x90 && $d2 > 0 && ++$notes > AF_MP_MAX_NOTES) {
                return 'too many notes';
            }
            $emit($tick, chr($status) . chr($d1) . chr($d2));
        } elseif ($hi === 0xb0 || $hi === 0xc0) {
            $emit($tick, chr($status) . chr($d1) . ($n === 2 ? chr($d2) : ''));
        }
        // aftertouch and pitch bend are dropped (the site synth ignores them)
    }
    if (!$ended || $pos !== $end) {
        return 'data after end of track or missing end of track';
    }
    if ($notes < 1) {
        return 'no notes';
    }
    $clean = "MThd\x00\x00\x00\x06\x00\x00\x00\x01" . pack('n', $ppq) . 'MTrk' . pack('N', strlen($out)) . $out;
    return [$clean, (int) round($us / 1000), $notes];
}

// ---------- GET / HEAD
if ($method === 'GET' || $method === 'HEAD') {
    $wantMeta = isset($_GET['meta']);
    $lf = @fopen($lockPath, 'r');
    if ($lf !== false) {
        flock($lf, LOCK_SH);
    }
    $meta = af_mp_read_meta($metaPath);
    $mid = null;
    if ($meta !== null && !$wantMeta) {
        $mid = is_file($midPath) ? @file_get_contents($midPath) : false;
        if (!is_string($mid) || strlen($mid) !== $meta['bytes']) {
            $mid = null;
        }
    }
    if ($meta !== null && $wantMeta && !is_file($midPath)) {
        $meta = null; // the recording was deleted by hand (emergency clear of only one file)
    }
    if ($lf !== false) {
        flock($lf, LOCK_UN);
        fclose($lf);
    }
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    if (!$wantMeta) {
        if ($mid === null) {
            af_mp_fail(404, 'Nothing published');
        }
        $etag = '"mp-' . $meta['v'] . '"';
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: public, max-age=30');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) strtotime($meta['updatedAt'])) . ' GMT');
        header('Content-Disposition: inline; filename="music.mid"');
        if ($inm !== '' && strpos($inm, $etag) !== false) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: audio/midi');
        header('Content-Length: ' . strlen($mid));
        if ($method !== 'HEAD') {
            echo $mid;
        }
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    if ($meta === null) {
        echo json_encode(['exists' => false, 'updatedAt' => null, 'bytes' => 0, 'ms' => 0, 'notes' => 0, 'v' => null]);
    } else {
        echo json_encode(['exists' => true, 'updatedAt' => $meta['updatedAt'], 'bytes' => $meta['bytes'],
            'ms' => $meta['ms'], 'notes' => $meta['notes'], 'v' => $meta['v']]);
    }
    exit;
}

if ($method !== 'POST') {
    af_mp_fail(405, 'Method not allowed', ['Allow: GET, HEAD, POST']);
}

// ---------- POST: same-origin check
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
    $origin = af_mp_referer_origin($_SERVER['HTTP_REFERER']);
}
if ($origin === '' || !af_mp_origin_ok($origin)) {
    af_mp_fail(403, 'Forbidden');
}
$sfs = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($sfs !== '' && $sfs !== 'same-origin') {
    af_mp_fail(403, 'Forbidden');
}

// ---------- raw audio/midi only, capped body
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim((string) $_SERVER['CONTENT_TYPE'])) : '';
if ($ctype !== 'audio/midi') {
    af_mp_fail(415, 'audio/midi only');
}
$len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($len > AF_MP_MAX_BODY) {
    af_mp_fail(413, 'Too big');
}
$bin = file_get_contents('php://input', false, null, 0, AF_MP_MAX_BODY + 1);
if (!is_string($bin) || $bin === '') {
    af_mp_fail(400, 'Empty body');
}
if (strlen($bin) > AF_MP_MAX_BODY) {
    af_mp_fail(413, 'Too big');
}

if (!af_mp_ensure_dir($dataDir)) {
    af_mp_fail(503, 'Store unavailable');
}

// ---------- rate limit per IP (salted hash only), counted before validation
$salt = af_mp_salt($saltPath);
if ($salt === null) {
    af_mp_fail(503, 'Store unavailable');
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$key = substr(hash_hmac('sha256', 'music-publish|' . $ip, $salt), 0, 32);
$now = microtime(true);
$hf = @fopen($hitsPath, 'c+');
if ($hf === false || !flock($hf, LOCK_EX)) {
    if ($hf !== false) {
        fclose($hf);
    }
    af_mp_fail(503, 'Store unavailable');
}
$rawHits = stream_get_contents($hf);
$hits = (is_string($rawHits) && trim($rawHits) !== '') ? json_decode($rawHits, true) : null;
if (!is_array($hits)) {
    $hits = [];
}
$kept = [];
foreach ($hits as $k => $list) {
    if (!is_string($k) || !is_array($list)) {
        continue;
    }
    $recent = [];
    foreach ($list as $ts) {
        if (is_numeric($ts) && $now - (float) $ts < AF_MP_RL_WINDOW_SEC && (float) $ts <= $now + 5) {
            $recent[] = (float) $ts;
        }
    }
    if ($recent) {
        $kept[$k] = $recent;
    }
}
$mine = isset($kept[$key]) ? $kept[$key] : [];
$retry = 0;
if ($mine && $now - max($mine) < AF_MP_RL_GAP_SEC) {
    $retry = (int) ceil(AF_MP_RL_GAP_SEC - ($now - max($mine)));
} elseif (count($mine) >= AF_MP_RL_MAX) {
    $retry = (int) ceil(AF_MP_RL_WINDOW_SEC - ($now - min($mine)));
}
if ($retry > 0) {
    flock($hf, LOCK_UN);
    fclose($hf);
    af_mp_fail(429, 'Too many publishes', ['Retry-After: ' . max(1, $retry)]);
}
$mine[] = round($now, 3);
$kept[$key] = $mine;
if (count($kept) > AF_MP_RL_MAX_KEYS) {
    uasort($kept, function ($a, $b) {
        return max($b) <=> max($a);
    });
    $kept = array_slice($kept, 0, AF_MP_RL_MAX_KEYS, true);
}
ftruncate($hf, 0);
rewind($hf);
fwrite($hf, json_encode($kept, JSON_UNESCAPED_SLASHES) . "\n");
fflush($hf);
flock($hf, LOCK_UN);
fclose($hf);

// ---------- validate and rebuild the MIDI file
$chk = af_mp_check_mid($bin);
if (!is_array($chk)) {
    af_mp_fail(422, 'Not an accepted MIDI file: ' . $chk);
}
$store = $chk[0];
if (strlen($store) > AF_MP_MAX_BODY) {
    af_mp_fail(413, 'Too big');
}

// ---------- overwrite both files under the exclusive lock (temp + rename each)
$lf = @fopen($lockPath, 'c');
if ($lf === false || !flock($lf, LOCK_EX)) {
    if ($lf !== false) {
        fclose($lf);
    }
    af_mp_fail(503, 'Store unavailable');
}
$meta = ['updatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'bytes' => strlen($store), 'ms' => $chk[1], 'notes' => $chk[2],
    'v' => substr(hash('sha256', $store), 0, 16)];
$json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$sfx = '.' . bin2hex(random_bytes(4)) . '.tmp';
$tmpMid = $midPath . $sfx;
$tmpMeta = $metaPath . $sfx;
$ok = @file_put_contents($tmpMid, $store) === strlen($store)
    && @file_put_contents($tmpMeta, $json) === strlen($json)
    && @rename($tmpMid, $midPath)
    && @rename($tmpMeta, $metaPath);
@unlink($tmpMid);
@unlink($tmpMeta);
flock($lf, LOCK_UN);
fclose($lf);
if (!$ok) {
    af_mp_fail(503, 'Store unavailable');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'exists' => true, 'updatedAt' => $meta['updatedAt'], 'bytes' => $meta['bytes'],
    'ms' => $meta['ms'], 'notes' => $meta['notes'], 'v' => $meta['v']]);
