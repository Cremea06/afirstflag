<?php
/**
 * Art drawer Publish: the one latest published drawing (afirstflag.com shop, CHG-098).
 * GET ?meta - { exists, updatedAt, bytes, w, h, v }   (v = short content hash, used as the ?img version)
 * GET ?img  - the PNG, streamed through PHP (image/png, nosniff, CSP default-src 'none', ETag, short cache).
 *             404 when nothing is published.
 * POST      - raw PNG body, Content-Type: image/png (raw, not JSON/base64: no 33% base64 overhead, the body
 *             cap applies to the real bytes, and there is no JSON parser in front of the image check).
 *             Anyone may publish (approved by Andy); it replaces the drawing for everyone.
 *             Same-origin only (Origin, or Referer when Origin is missing; Sec-Fetch-Site must be same-origin).
 *             Body max 1,000,000 bytes (413). The PNG must be real: signature, IHDR first, every chunk CRC valid,
 *             IEND last with nothing after it, width 1..800 and height 1..800 (422 otherwise), not interlaced,
 *             and the image data must inflate to exactly the size the header promises (when zlib is present).
 *             The stored file is re-encoded through GD when GD is available; otherwise it is rebuilt from only
 *             the IHDR, PLTE, tRNS, IDAT and IEND chunks, so text/metadata/extra chunks never get stored.
 * Rate limit per IP (salted HMAC of the IP, never the raw IP): 1 POST per 30 s and 20 per 24 h (429 + Retry-After).
 * Store (data/.htaccess denies HTTP access; gitignored). No history: each publish overwrites both files.
 *   data/art-latest.png          the drawing                     temp file + rename under the lock
 *   data/art-latest.json         { updatedAt, bytes, w, h, v }   temp file + rename under the lock
 *   data/art-publish.lock        flock target (exclusive for publish, shared for reads)
 *   data/art-publish-hits.json   recent POST times per hashed IP (pruned after 24 h)
 *   data/click-record-salt.php   salt shared with api/click-record.php and api/click-total.php
 * Emergency clear: delete data/art-latest.png and data/art-latest.json.
 * Local testing only: AFF_CLICK_RECORD_ALLOW_LOCAL=1 also allows http://localhost / http://127.0.0.1 origins.
 * PHP 7.0+.
 */

const AF_AP_MAX_BODY = 1000000;     // bytes (413 above)
const AF_AP_MAX_W = 800;            // the page sends at most 800x500
const AF_AP_MAX_H = 800;
const AF_AP_MAX_RAW = 4000000;      // max inflated image data (800*800*4 + rows, with room)
const AF_AP_RL_GAP_SEC = 30;        // 1 publish per 30 s per IP
const AF_AP_RL_WINDOW_SEC = 86400;  // and 20 per 24 h per IP
const AF_AP_RL_MAX = 20;
const AF_AP_RL_MAX_KEYS = 5000;

$dataDir = dirname(__DIR__) . '/data';
$pngPath = $dataDir . '/art-latest.png';
$metaPath = $dataDir . '/art-latest.json';
$lockPath = $dataDir . '/art-publish.lock';
$hitsPath = $dataDir . '/art-publish-hits.json';
$saltPath = $dataDir . '/click-record-salt.php';
$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

function af_ap_fail($code, $msg, $extraHeaders = []) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function af_ap_local_allowed() {
    $v = getenv('AFF_CLICK_RECORD_ALLOW_LOCAL');
    if ($v === false && isset($_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'])) {
        $v = $_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'];
    }
    return (string) $v === '1';
}

function af_ap_origin_ok($origin) {
    $origin = strtolower(rtrim(trim((string) $origin), '/'));
    if ($origin === 'https://afirstflag.com' || $origin === 'https://www.afirstflag.com') {
        return true;
    }
    return af_ap_local_allowed() && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin);
}

function af_ap_referer_origin($ref) {
    $p = parse_url((string) $ref);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function af_ap_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to the data store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

function af_ap_salt($path) {
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

function af_ap_read_meta($path) {
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $m = (is_string($raw) && trim($raw) !== '') ? json_decode($raw, true) : null;
    if (!is_array($m) || !isset($m['updatedAt'], $m['bytes'], $m['w'], $m['h'], $m['v'])) {
        return null;
    }
    if (!is_string($m['updatedAt']) || !is_int($m['bytes']) || !is_int($m['w']) || !is_int($m['h'])
        || !is_string($m['v']) || !preg_match('/^[0-9a-f]{16}$/', $m['v'])) {
        return null;
    }
    return $m;
}

/**
 * Strict PNG check. Returns [w, h, cleanPng] (clean = only IHDR/PLTE/tRNS/IDAT/IEND) or a string error.
 */
function af_ap_check_png($bin) {
    $len = strlen($bin);
    if ($len < 57 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return 'not a PNG';
    }
    $pos = 8;
    $keep = ['IHDR' => 1, 'PLTE' => 1, 'tRNS' => 1, 'IDAT' => 1, 'IEND' => 1];
    $allowedAncillary = ['sRGB' => 1, 'gAMA' => 1, 'cHRM' => 1, 'pHYs' => 1, 'iCCP' => 1, 'bKGD' => 1, 'sBIT' => 1];
    $out = "\x89PNG\r\n\x1a\n";
    $idat = '';
    $w = 0; $h = 0; $depth = 0; $ctype = 0; $first = true; $ended = false; $sawIdat = false; $idatDone = false; $plte = false;
    while ($pos < $len) {
        if ($pos + 12 > $len) {
            return 'truncated chunk';
        }
        $u = unpack('N', substr($bin, $pos, 4));
        $clen = $u[1];
        $type = substr($bin, $pos + 4, 4);
        if ($clen > $len - $pos - 12 || !preg_match('/^[A-Za-z]{4}$/', $type)) {
            return 'bad chunk';
        }
        $data = substr($bin, $pos + 8, $clen);
        $crc = unpack('N', substr($bin, $pos + 8 + $clen, 4));
        if ((crc32($type . $data) & 0xFFFFFFFF) !== ($crc[1] & 0xFFFFFFFF)) {
            return 'bad chunk CRC';
        }
        if ($first) {
            if ($type !== 'IHDR' || $clen !== 13) {
                return 'IHDR must come first';
            }
            $hd = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilter/Cinterlace', $data);
            $w = $hd['w']; $h = $hd['h']; $depth = $hd['depth']; $ctype = $hd['ctype'];
            $validDepth = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];
            if (!isset($validDepth[$ctype]) || !in_array($depth, $validDepth[$ctype], true)
                || $hd['comp'] !== 0 || $hd['filter'] !== 0) {
                return 'bad IHDR';
            }
            if ($hd['interlace'] !== 0) {
                return 'interlaced PNG not accepted';
            }
            if ($w < 1 || $h < 1 || $w > AF_AP_MAX_W || $h > AF_AP_MAX_H) {
                return 'dimensions';
            }
            $first = false;
        } elseif ($type === 'IHDR') {
            return 'duplicate IHDR';
        }
        if ($type === 'IDAT') {
            if ($idatDone) {
                return 'IDAT chunks must be consecutive';
            }
            $sawIdat = true;
            $idat .= $data;
        } elseif ($sawIdat) {
            $idatDone = true;
        }
        if ($type === 'PLTE') {
            $plte = true;
        }
        if (isset($keep[$type])) {
            $out .= substr($bin, $pos, 12 + $clen);
        } elseif (!isset($allowedAncillary[$type])) {
            return 'chunk not allowed: ' . preg_replace('/[^A-Za-z]/', '', $type);
        }
        $pos += 12 + $clen;
        if ($type === 'IEND') {
            $ended = true;
            break;
        }
    }
    if (!$ended || $pos !== $len) {
        return 'data after IEND or missing IEND';
    }
    if (!$sawIdat || ($ctype === 3 && !$plte)) {
        return 'missing image data';
    }
    $chan = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];
    $rowBytes = (int) ceil($w * $chan[$ctype] * $depth / 8);
    $expect = $h * (1 + $rowBytes);
    if ($expect > AF_AP_MAX_RAW) {
        return 'dimensions';
    }
    if (function_exists('gzuncompress')) {
        $raw = @gzuncompress($idat, $expect + 1);
        if (!is_string($raw) || strlen($raw) !== $expect) {
            return 'image data does not match header';
        }
    }
    return [$w, $h, $out];
}

// ---------- GET / HEAD
if ($method === 'GET' || $method === 'HEAD') {
    $wantImg = isset($_GET['img']);
    $lf = @fopen($lockPath, 'r');
    if ($lf !== false) {
        flock($lf, LOCK_SH);
    }
    $meta = af_ap_read_meta($metaPath);
    $png = null;
    if ($meta !== null && $wantImg) {
        $png = is_file($pngPath) ? @file_get_contents($pngPath) : false;
        if (!is_string($png) || strlen($png) !== $meta['bytes']) {
            $png = null;
        }
    }
    if ($meta !== null && !$wantImg && !is_file($pngPath)) {
        $meta = null; // the image was deleted by hand (emergency clear of only one file)
    }
    if ($lf !== false) {
        flock($lf, LOCK_UN);
        fclose($lf);
    }
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    if ($wantImg) {
        if ($png === null) {
            af_ap_fail(404, 'Nothing published');
        }
        $etag = '"ap-' . $meta['v'] . '"';
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: public, max-age=30');
        header('ETag: ' . $etag);
        header('Content-Disposition: inline; filename="art.png"');
        if ($inm !== '' && strpos($inm, $etag) !== false) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        if ($method !== 'HEAD') {
            echo $png;
        }
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    if ($meta === null) {
        echo json_encode(['exists' => false, 'updatedAt' => null, 'bytes' => 0, 'w' => 0, 'h' => 0, 'v' => null]);
    } else {
        echo json_encode(['exists' => true, 'updatedAt' => $meta['updatedAt'], 'bytes' => $meta['bytes'],
            'w' => $meta['w'], 'h' => $meta['h'], 'v' => $meta['v']]);
    }
    exit;
}

if ($method !== 'POST') {
    af_ap_fail(405, 'Method not allowed', ['Allow: GET, HEAD, POST']);
}

// ---------- POST: same-origin check
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
    $origin = af_ap_referer_origin($_SERVER['HTTP_REFERER']);
}
if ($origin === '' || !af_ap_origin_ok($origin)) {
    af_ap_fail(403, 'Forbidden');
}
$sfs = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($sfs !== '' && $sfs !== 'same-origin') {
    af_ap_fail(403, 'Forbidden');
}

// ---------- raw image/png only, capped body
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim((string) $_SERVER['CONTENT_TYPE'])) : '';
if ($ctype !== 'image/png') {
    af_ap_fail(415, 'image/png only');
}
$len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($len > AF_AP_MAX_BODY) {
    af_ap_fail(413, 'Too big');
}
$bin = file_get_contents('php://input', false, null, 0, AF_AP_MAX_BODY + 1);
if (!is_string($bin) || $bin === '') {
    af_ap_fail(400, 'Empty body');
}
if (strlen($bin) > AF_AP_MAX_BODY) {
    af_ap_fail(413, 'Too big');
}

if (!af_ap_ensure_dir($dataDir)) {
    af_ap_fail(503, 'Store unavailable');
}

// ---------- rate limit per IP (salted hash only), counted before validation
$salt = af_ap_salt($saltPath);
if ($salt === null) {
    af_ap_fail(503, 'Store unavailable');
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$key = substr(hash_hmac('sha256', 'art-publish|' . $ip, $salt), 0, 32);
$now = microtime(true);
$hf = @fopen($hitsPath, 'c+');
if ($hf === false || !flock($hf, LOCK_EX)) {
    if ($hf !== false) {
        fclose($hf);
    }
    af_ap_fail(503, 'Store unavailable');
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
        if (is_numeric($ts) && $now - (float) $ts < AF_AP_RL_WINDOW_SEC && (float) $ts <= $now + 5) {
            $recent[] = (float) $ts;
        }
    }
    if ($recent) {
        $kept[$k] = $recent;
    }
}
$mine = isset($kept[$key]) ? $kept[$key] : [];
$retry = 0;
if ($mine && $now - max($mine) < AF_AP_RL_GAP_SEC) {
    $retry = (int) ceil(AF_AP_RL_GAP_SEC - ($now - max($mine)));
} elseif (count($mine) >= AF_AP_RL_MAX) {
    $retry = (int) ceil(AF_AP_RL_WINDOW_SEC - ($now - min($mine)));
}
if ($retry > 0) {
    flock($hf, LOCK_UN);
    fclose($hf);
    af_ap_fail(429, 'Too many publishes', ['Retry-After: ' . max(1, $retry)]);
}
$mine[] = round($now, 3);
$kept[$key] = $mine;
if (count($kept) > AF_AP_RL_MAX_KEYS) {
    uasort($kept, function ($a, $b) {
        return max($b) <=> max($a);
    });
    $kept = array_slice($kept, 0, AF_AP_RL_MAX_KEYS, true);
}
ftruncate($hf, 0);
rewind($hf);
fwrite($hf, json_encode($kept, JSON_UNESCAPED_SLASHES) . "\n");
fflush($hf);
flock($hf, LOCK_UN);
fclose($hf);

// ---------- validate the PNG
$chk = af_ap_check_png($bin);
if (!is_array($chk)) {
    af_ap_fail(422, 'Not an accepted PNG: ' . $chk);
}
if (function_exists('getimagesizefromstring')) {
    $info = @getimagesizefromstring($bin);
    if (!is_array($info) || $info[2] !== IMAGETYPE_PNG || $info[0] !== $chk[0] || $info[1] !== $chk[1]) {
        af_ap_fail(422, 'Not an accepted PNG');
    }
}
$store = $chk[2];
$gdUsed = false;
if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
    $im = @imagecreatefromstring($bin);
    if ($im === false) {
        af_ap_fail(422, 'Not an accepted PNG');
    }
    if (imagesx($im) !== $chk[0] || imagesy($im) !== $chk[1]) {
        imagedestroy($im);
        af_ap_fail(422, 'Not an accepted PNG');
    }
    imagesavealpha($im, true);
    ob_start();
    $okPng = imagepng($im, null, 9);
    $re = ob_get_clean();
    imagedestroy($im);
    $reChk = ($okPng && is_string($re)) ? af_ap_check_png($re) : null;
    if (is_array($reChk) && $reChk[0] === $chk[0] && $reChk[1] === $chk[1]) {
        $store = $reChk[2]; // GD output, also reduced to the critical chunks
        $gdUsed = true;
    }
}
if (strlen($store) > AF_AP_MAX_BODY) {
    af_ap_fail(413, 'Too big');
}

// ---------- overwrite both files under the exclusive lock (temp + rename each)
$lf = @fopen($lockPath, 'c');
if ($lf === false || !flock($lf, LOCK_EX)) {
    if ($lf !== false) {
        fclose($lf);
    }
    af_ap_fail(503, 'Store unavailable');
}
$meta = ['updatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'bytes' => strlen($store), 'w' => $chk[0], 'h' => $chk[1],
    'v' => substr(hash('sha256', $store), 0, 16)];
$json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$sfx = '.' . bin2hex(random_bytes(4)) . '.tmp';
$tmpPng = $pngPath . $sfx;
$tmpMeta = $metaPath . $sfx;
$ok = @file_put_contents($tmpPng, $store) === strlen($store)
    && @file_put_contents($tmpMeta, $json) === strlen($json)
    && @rename($tmpPng, $pngPath)
    && @rename($tmpMeta, $metaPath);
@unlink($tmpPng);
@unlink($tmpMeta);
flock($lf, LOCK_UN);
fclose($lf);
if (!$ok) {
    af_ap_fail(503, 'Store unavailable');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'exists' => true, 'updatedAt' => $meta['updatedAt'], 'bytes' => $meta['bytes'],
    'w' => $meta['w'], 'h' => $meta['h'], 'v' => $meta['v'], 'gd' => $gdUsed]);
