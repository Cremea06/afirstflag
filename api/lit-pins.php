<?php
/**
 * Literature drawer site-wide pins (afirstflag.com shop, CHG-157). Anyone may pin/unpin (approved by Andy).
 * GET  - { pins: [ {key, title, authors, year, cover_id}, ... ] }  max 3, newest first. Missing store => [].
 * POST - JSON body (max 4096 bytes, 413), Content-Type application/json:
 *          {"action":"pin","key":"/works/OL123W","title":"..","authors":[".."],"year":1965,"cover_id":123}
 *          {"action":"unpin","key":"/works/OL123W"}
 *        Pinning a key already pinned moves it to the front; a 4th pin drops the oldest.
 *        Returns the new { pins: [...] }.
 *        Same-origin only (Origin, or Referer when Origin is missing): https://afirstflag.com or www (soft guard).
 *        Text is stored as plain text only (control chars stripped, length capped); the page renders with textContent.
 * Rate limit per IP (salted HMAC of the IP, never the raw IP): 10 changes per 10 min per IP (429 + Retry-After),
 * and a global cap of 120 changes per 10 min across all visitors (429).
 * Store (data/.htaccess denies HTTP access; gitignored):
 *   data/lit-pins.json         { pins: [...], updatedAt }   temp file + rename under the lock
 *   data/lit-pins.lock         flock target
 *   data/lit-pins-hits.json    recent POST times per hashed IP + global list (pruned after 10 min)
 *   data/click-record-salt.php salt shared with api/click-record.php etc.
 * Emergency clear: delete data/lit-pins.json.
 * Local testing only: AFF_CLICK_RECORD_ALLOW_LOCAL=1 also allows http://localhost / http://127.0.0.1 origins.
 * PHP 7.0+.
 */

const AF_LP_MAX_PINS = 3;
const AF_LP_MAX_BODY = 4096;
const AF_LP_RL_WINDOW = 600;
const AF_LP_RL_MAX_IP = 10;
const AF_LP_RL_MAX_ALL = 120;
const AF_LP_RL_MAX_KEYS = 5000;

$dataDir = dirname(__DIR__) . '/data';
$storePath = $dataDir . '/lit-pins.json';
$lockPath = $dataDir . '/lit-pins.lock';
$hitsPath = $dataDir . '/lit-pins-hits.json';
$saltPath = $dataDir . '/click-record-salt.php';
$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

function af_lp_out($code, $body, $extra = []) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    foreach ($extra as $h) {
        header($h);
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function af_lp_local_allowed() {
    $v = getenv('AFF_CLICK_RECORD_ALLOW_LOCAL');
    if ($v === false && isset($_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'])) {
        $v = $_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'];
    }
    return (string) $v === '1';
}

function af_lp_origin_ok($origin) {
    $origin = strtolower(rtrim(trim((string) $origin), '/'));
    if ($origin === 'https://afirstflag.com' || $origin === 'https://www.afirstflag.com') {
        return true;
    }
    return af_lp_local_allowed() && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin);
}

function af_lp_referer_origin($ref) {
    $p = parse_url((string) $ref);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function af_lp_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to the data store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

function af_lp_salt($path) {
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

function af_lp_text($s, $max) {
    if (!is_string($s)) {
        return '';
    }
    if (!preg_match('//u', $s)) {
        return '';
    }
    $s = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    return substr($s, 0, $max);
}

function af_lp_key_ok($k) {
    return is_string($k) && (bool) preg_match('#^/works/OL[1-9][0-9]{0,11}W$#', $k);
}

/** Returns a clean pin array or null. */
function af_lp_clean($d) {
    if (!is_array($d) || !isset($d['key']) || !af_lp_key_ok($d['key'])) {
        return null;
    }
    $title = af_lp_text(isset($d['title']) ? $d['title'] : '', 200);
    if ($title === '') {
        $title = 'Untitled';
    }
    $authors = [];
    if (isset($d['authors']) && is_array($d['authors'])) {
        foreach (array_slice(array_values($d['authors']), 0, 5) as $a) {
            $a = af_lp_text($a, 100);
            if ($a !== '') {
                $authors[] = $a;
            }
        }
    }
    $year = isset($d['year']) && is_int($d['year']) && $d['year'] >= -3000 && $d['year'] <= 2100 ? $d['year'] : null;
    $cover = isset($d['cover_id']) && is_int($d['cover_id']) && $d['cover_id'] > 0 && $d['cover_id'] < 1000000000 ? $d['cover_id'] : null;
    return ['key' => $d['key'], 'title' => $title, 'authors' => $authors, 'year' => $year, 'cover_id' => $cover];
}

function af_lp_read($path) {
    $raw = is_file($path) ? @file_get_contents($path, false, null, 0, 65536) : false;
    $j = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    $out = [];
    $seen = [];
    if (is_array($j) && isset($j['pins']) && is_array($j['pins'])) {
        foreach ($j['pins'] as $p) {
            $c = af_lp_clean($p);
            if ($c !== null && !isset($seen[$c['key']])) {
                $seen[$c['key']] = 1;
                $out[] = $c;
            }
            if (count($out) >= AF_LP_MAX_PINS) {
                break;
            }
        }
    }
    return $out;
}

// ---------- GET / HEAD
if ($method === 'GET' || $method === 'HEAD') {
    $lf = @fopen($lockPath, 'r');
    if ($lf !== false) {
        flock($lf, LOCK_SH);
    }
    $pins = af_lp_read($storePath);
    if ($lf !== false) {
        flock($lf, LOCK_UN);
        fclose($lf);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    if ($method !== 'HEAD') {
        echo json_encode(['pins' => $pins], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($method !== 'POST') {
    af_lp_out(405, ['error' => 'Method not allowed'], ['Allow: GET, HEAD, POST']);
}

// ---------- POST
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
    $origin = af_lp_referer_origin($_SERVER['HTTP_REFERER']);
}
if (!af_lp_origin_ok($origin)) {
    af_lp_out(403, ['error' => 'Forbidden']);
}
$sfs = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower((string) $_SERVER['HTTP_SEC_FETCH_SITE']) : '';
if ($sfs !== '' && $sfs !== 'same-origin') {
    af_lp_out(403, ['error' => 'Forbidden']);
}
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
if (strpos($ctype, 'application/json') !== 0) {
    af_lp_out(415, ['error' => 'JSON only']);
}
$clen = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($clen > AF_LP_MAX_BODY) {
    af_lp_out(413, ['error' => 'Too large']);
}
$body = file_get_contents('php://input', false, null, 0, AF_LP_MAX_BODY + 1);
if (!is_string($body) || strlen($body) > AF_LP_MAX_BODY) {
    af_lp_out(413, ['error' => 'Too large']);
}
$req = json_decode($body, true);
if (!is_array($req) || !isset($req['action']) || !is_string($req['action'])) {
    af_lp_out(400, ['error' => 'Bad request']);
}
$action = $req['action'];
if ($action === 'pin') {
    $pin = af_lp_clean($req);
    if ($pin === null) {
        af_lp_out(422, ['error' => 'Invalid book']);
    }
} elseif ($action === 'unpin') {
    if (!isset($req['key']) || !af_lp_key_ok($req['key'])) {
        af_lp_out(422, ['error' => 'Invalid key']);
    }
} else {
    af_lp_out(400, ['error' => 'Unknown action']);
}

if (!af_lp_ensure_dir($dataDir)) {
    af_lp_out(500, ['error' => 'Store unavailable']);
}
$salt = af_lp_salt($saltPath);
if ($salt === null) {
    af_lp_out(500, ['error' => 'Store unavailable']);
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$ipKey = substr(hash_hmac('sha256', 'lit-pins|' . $ip, $salt), 0, 32);

$lf = @fopen($lockPath, 'c');
if ($lf === false || !flock($lf, LOCK_EX)) {
    af_lp_out(500, ['error' => 'Store unavailable']);
}
$now = time();
// Rate limit (under the same lock).
$hraw = is_file($hitsPath) ? @file_get_contents($hitsPath) : false;
$hits = is_string($hraw) ? json_decode($hraw, true) : null;
if (!is_array($hits) || !isset($hits['ip'], $hits['all']) || !is_array($hits['ip']) || !is_array($hits['all'])) {
    $hits = ['ip' => [], 'all' => []];
}
$cut = $now - AF_LP_RL_WINDOW;
$keep = function ($t) use ($cut) { return is_int($t) && $t > $cut; };
$hits['all'] = array_values(array_filter($hits['all'], $keep));
foreach ($hits['ip'] as $k => $list) {
    $list = is_array($list) ? array_values(array_filter($list, $keep)) : [];
    if ($list) {
        $hits['ip'][$k] = $list;
    } else {
        unset($hits['ip'][$k]);
    }
}
if (count($hits['ip']) > AF_LP_RL_MAX_KEYS) {
    $hits['ip'] = array_slice($hits['ip'], -AF_LP_RL_MAX_KEYS, null, true);
}
$mine = isset($hits['ip'][$ipKey]) ? $hits['ip'][$ipKey] : [];
if (count($mine) >= AF_LP_RL_MAX_IP || count($hits['all']) >= AF_LP_RL_MAX_ALL) {
    $oldest = count($mine) >= AF_LP_RL_MAX_IP ? min($mine) : min($hits['all']);
    $retry = max(1, $oldest + AF_LP_RL_WINDOW - $now);
    flock($lf, LOCK_UN);
    fclose($lf);
    af_lp_out(429, ['error' => 'Too many changes, try again later'], ['Retry-After: ' . $retry]);
}
$mine[] = $now;
$hits['ip'][$ipKey] = $mine;
$hits['all'][] = $now;
$htmp = $hitsPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (@file_put_contents($htmp, json_encode($hits)) === false || !@rename($htmp, $hitsPath)) {
    @unlink($htmp);
}

$pins = af_lp_read($storePath);
if ($action === 'pin') {
    $pins = array_values(array_filter($pins, function ($p) use ($pin) { return $p['key'] !== $pin['key']; }));
    array_unshift($pins, $pin);
    $pins = array_slice($pins, 0, AF_LP_MAX_PINS);
} else {
    $k = $req['key'];
    $pins = array_values(array_filter($pins, function ($p) use ($k) { return $p['key'] !== $k; }));
}
$tmp = $storePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
$json = json_encode(['pins' => $pins, 'updatedAt' => gmdate('c', $now)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ok = $json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $storePath);
if (!$ok) {
    @unlink($tmp);
}
flock($lf, LOCK_UN);
fclose($lf);
if (!$ok) {
    af_lp_out(500, ['error' => 'Store unavailable']);
}
af_lp_out(200, ['pins' => $pins]);
