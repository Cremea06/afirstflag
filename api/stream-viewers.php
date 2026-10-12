<?php
/**
 * Live Stream drawer viewer counts: how many sessions ON THIS SITE are watching each embed (afirstflag.com shop,
 * CHG-125, conventions of CHG-098 art-publish / CHG-124 music-publish). Only our own visitors are counted; nothing
 * is asked of or sent to YouTube, Twitch or Rumble.
 * GET   - { youtube:n, twitch:n, rumble:n }   (Cache-Control: public, max-age=5)
 * POST  - JSON { sid, stream } heartbeat (Content-Type application/json or text/plain, for sendBeacon).
 *         sid: 32 lowercase hex chars (a random 128-bit value the browser makes in memory per page load; no cookie,
 *         no storage). stream: youtube | twitch | rumble, or none to leave (removes the sid).
 *         Same-origin only (Origin, or Referer when Origin is missing; Sec-Fetch-Site must be same-origin).
 *         Body max 256 bytes (413). Bad JSON / sid / stream: 400.
 *         Entries older than 75 s are pruned. Caps: 2000 sids in total and 10 sids per hashed IP (429 when full).
 * Rate limit per IP (salted HMAC of the IP, never the raw IP): 40 POSTs per 60 s (429 + Retry-After).
 * Store (data/.htaccess denies HTTP access; gitignored):
 *   data/stream-viewers.json        { sid: { s: stream, t: last_seen, k: hashed IP } }   pruned at 75 s
 *   data/stream-viewers-hits.json   recent POST times per hashed IP (pruned after 60 s)
 *   data/stream-viewers.lock        flock target (exclusive for POST, shared for GET)
 *   data/click-record-salt.php      salt shared with api/click-record.php, click-total.php, art/music-publish.php
 * Emergency clear: delete data/stream-viewers.json.
 * Local testing only: AFF_CLICK_RECORD_ALLOW_LOCAL=1 also allows http://localhost / http://127.0.0.1 origins, and
 * then (only then) AFF_STREAM_VIEWERS_TTL=<seconds> shortens the 75 s timeout for tests.
 * PHP 7.0+.
 */

const AF_SV_TTL_SEC = 75;           // a sid with no heartbeat for 75 s stops counting
const AF_SV_MAX_BODY = 256;         // bytes (413 above)
const AF_SV_MAX_SIDS = 2000;        // total entries (flood cap)
const AF_SV_MAX_SIDS_PER_IP = 10;   // entries per hashed IP
const AF_SV_RL_WINDOW_SEC = 60;     // 40 POSTs per 60 s per IP
const AF_SV_RL_MAX = 40;
const AF_SV_RL_MAX_KEYS = 5000;
const AF_SV_STREAMS = ['youtube', 'twitch', 'rumble'];

$dataDir = dirname(__DIR__) . '/data';
$storePath = $dataDir . '/stream-viewers.json';
$lockPath = $dataDir . '/stream-viewers.lock';
$hitsPath = $dataDir . '/stream-viewers-hits.json';
$saltPath = $dataDir . '/click-record-salt.php';
$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

function af_sv_fail($code, $msg, $extraHeaders = []) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function af_sv_local_allowed() {
    $v = getenv('AFF_CLICK_RECORD_ALLOW_LOCAL');
    if ($v === false && isset($_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'])) {
        $v = $_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'];
    }
    return (string) $v === '1';
}

function af_sv_ttl() {
    // Test-only override; production always uses AF_SV_TTL_SEC.
    if (!af_sv_local_allowed()) {
        return AF_SV_TTL_SEC;
    }
    $v = getenv('AFF_STREAM_VIEWERS_TTL');
    if ($v !== false && preg_match('/^[0-9]{1,3}$/', (string) $v) && (int) $v >= 1) {
        return (int) $v;
    }
    return AF_SV_TTL_SEC;
}

function af_sv_origin_ok($origin) {
    $origin = strtolower(rtrim(trim((string) $origin), '/'));
    if ($origin === 'https://afirstflag.com' || $origin === 'https://www.afirstflag.com') {
        return true;
    }
    return af_sv_local_allowed() && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin);
}

function af_sv_referer_origin($ref) {
    $p = parse_url((string) $ref);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function af_sv_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to the data store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

function af_sv_salt($path) {
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

/** Live entries only (bad shapes and anything older than the timeout are dropped). */
function af_sv_prune($raw, $now, $ttl) {
    $m = (is_string($raw) && trim($raw) !== '') ? json_decode($raw, true) : null;
    $out = [];
    if (!is_array($m)) {
        return $out;
    }
    foreach ($m as $sid => $e) {
        if (!is_string($sid) || !preg_match('/^[0-9a-f]{32}$/', $sid) || !is_array($e)
            || !isset($e['s'], $e['t'], $e['k']) || !in_array($e['s'], AF_SV_STREAMS, true)
            || !is_numeric($e['t']) || !is_string($e['k'])) {
            continue;
        }
        $t = (float) $e['t'];
        if ($now - $t < $ttl && $t <= $now + 5) {
            $out[$sid] = ['s' => $e['s'], 't' => $t, 'k' => $e['k']];
        }
    }
    return $out;
}

function af_sv_counts($entries) {
    $c = ['youtube' => 0, 'twitch' => 0, 'rumble' => 0];
    foreach ($entries as $e) {
        $c[$e['s']]++;
    }
    return $c;
}

// ---------- GET / HEAD: counts only
if ($method === 'GET' || $method === 'HEAD') {
    $raw = false;
    $lf = @fopen($lockPath, 'r');
    if ($lf !== false) {
        flock($lf, LOCK_SH);
    }
    if (is_file($storePath)) {
        $raw = @file_get_contents($storePath);
    }
    if ($lf !== false) {
        flock($lf, LOCK_UN);
        fclose($lf);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=5');
    if ($method !== 'HEAD') {
        echo json_encode(af_sv_counts(af_sv_prune($raw, microtime(true), af_sv_ttl())));
    }
    exit;
}

if ($method !== 'POST') {
    af_sv_fail(405, 'Method not allowed', ['Allow: GET, HEAD, POST']);
}

// ---------- POST: same-origin check
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
    $origin = af_sv_referer_origin($_SERVER['HTTP_REFERER']);
}
if ($origin === '' || !af_sv_origin_ok($origin)) {
    af_sv_fail(403, 'Forbidden');
}
$sfs = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($sfs !== '' && $sfs !== 'same-origin') {
    af_sv_fail(403, 'Forbidden');
}

// ---------- small JSON body only
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', (string) $_SERVER['CONTENT_TYPE'])[0])) : '';
if ($ctype !== 'application/json' && $ctype !== 'text/plain') {
    af_sv_fail(415, 'application/json only');
}
$len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($len > AF_SV_MAX_BODY) {
    af_sv_fail(413, 'Too big');
}
$body = file_get_contents('php://input', false, null, 0, AF_SV_MAX_BODY + 1);
if (!is_string($body) || $body === '') {
    af_sv_fail(400, 'Empty body');
}
if (strlen($body) > AF_SV_MAX_BODY) {
    af_sv_fail(413, 'Too big');
}
$in = json_decode($body, true);
if (!is_array($in) || !isset($in['sid'], $in['stream']) || !is_string($in['sid']) || !is_string($in['stream'])) {
    af_sv_fail(400, 'Bad request');
}
$sid = $in['sid'];
$stream = $in['stream'];
if (!preg_match('/^[0-9a-f]{32}$/', $sid)) {
    af_sv_fail(400, 'Bad sid');
}
if ($stream !== 'none' && !in_array($stream, AF_SV_STREAMS, true)) {
    af_sv_fail(400, 'Bad stream');
}

if (!af_sv_ensure_dir($dataDir)) {
    af_sv_fail(503, 'Store unavailable');
}
$salt = af_sv_salt($saltPath);
if ($salt === null) {
    af_sv_fail(503, 'Store unavailable');
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$key = substr(hash_hmac('sha256', 'stream-viewers|' . $ip, $salt), 0, 32);
$now = microtime(true);
$ttl = af_sv_ttl();

$lf = @fopen($lockPath, 'c');
if ($lf === false || !flock($lf, LOCK_EX)) {
    if ($lf !== false) {
        fclose($lf);
    }
    af_sv_fail(503, 'Store unavailable');
}
function af_sv_unlock($lf) {
    flock($lf, LOCK_UN);
    fclose($lf);
}

// ---------- rate limit per IP (salted hash only), counted before the store changes
$rawHits = is_file($hitsPath) ? @file_get_contents($hitsPath) : false;
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
        if (is_numeric($ts) && $now - (float) $ts < AF_SV_RL_WINDOW_SEC && (float) $ts <= $now + 5) {
            $recent[] = (float) $ts;
        }
    }
    if ($recent) {
        $kept[$k] = $recent;
    }
}
$mine = isset($kept[$key]) ? $kept[$key] : [];
if (count($mine) >= AF_SV_RL_MAX) {
    af_sv_unlock($lf);
    $retry = (int) ceil(AF_SV_RL_WINDOW_SEC - ($now - min($mine)));
    af_sv_fail(429, 'Too many requests', ['Retry-After: ' . max(1, $retry)]);
}
$mine[] = round($now, 3);
$kept[$key] = $mine;
if (count($kept) > AF_SV_RL_MAX_KEYS) {
    uasort($kept, function ($a, $b) {
        return max($b) <=> max($a);
    });
    $kept = array_slice($kept, 0, AF_SV_RL_MAX_KEYS, true);
}
$hj = json_encode($kept, JSON_UNESCAPED_SLASHES) . "\n";
$htmp = $hitsPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (@file_put_contents($htmp, $hj) !== strlen($hj) || !@rename($htmp, $hitsPath)) {
    @unlink($htmp);
}

// ---------- update the sid table
$entries = af_sv_prune(is_file($storePath) ? @file_get_contents($storePath) : false, $now, $ttl);
if ($stream === 'none') {
    unset($entries[$sid]);
} else {
    if (!isset($entries[$sid])) {
        if (count($entries) >= AF_SV_MAX_SIDS) {
            af_sv_unlock($lf);
            af_sv_fail(429, 'Too many viewers', ['Retry-After: 30']);
        }
        $perIp = 0;
        foreach ($entries as $e) {
            if ($e['k'] === $key) {
                $perIp++;
            }
        }
        if ($perIp >= AF_SV_MAX_SIDS_PER_IP) {
            af_sv_unlock($lf);
            af_sv_fail(429, 'Too many viewers', ['Retry-After: 30']);
        }
    } elseif ($entries[$sid]['k'] !== $key) {
        // a sid belongs to the network that created it; another IP cannot move or refresh it
        af_sv_unlock($lf);
        af_sv_fail(409, 'Conflict');
    }
    $entries[$sid] = ['s' => $stream, 't' => round($now, 3), 'k' => $key];
}
$json = json_encode((object) $entries, JSON_UNESCAPED_SLASHES) . "\n";
$tmp = $storePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
$ok = @file_put_contents($tmp, $json) === strlen($json) && @rename($tmp, $storePath);
@unlink($tmp);
$counts = af_sv_counts($entries);
af_sv_unlock($lf);
if (!$ok) {
    af_sv_fail(503, 'Store unavailable');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true] + $counts);
