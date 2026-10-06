<?php
/**
 * Server-wide best click speed record (afirstflag.com shop, CHG-072).
 * GET  - { record } best clicks/s on the site (one decimal; 0 until someone sets one)
 * POST - JSON { t: [0, ms, ms, ...] } click times in whole ms, relative to the first click, of the
 *        clicks in one run's 3s window. The server recomputes the rate with the same formula as the
 *        homepage Best chip, rate = (n - 1) / ((t[n-1] - t[0]) / 1000), and keeps the highest valid
 *        rate. Rejected: fewer than 3 clicks, more than 100, not whole numbers, not strictly rising,
 *        span outside 60..3000 ms, rate over 30/s, malformed JSON.
 * Same-origin only (Origin, or Referer when Origin is missing), JSON only, body max 2 KB,
 * rate-limited per IP (salted HMAC of the IP, never the raw IP).
 * Store (created on first POST; data/.htaccess denies HTTP access; gitignored):
 *   data/click-record.json       { record, updatedAt }
 *   data/click-record-hits.json  recent POST times per hashed IP (pruned after 10 minutes)
 *   data/click-record-salt.php   random salt for the IP hash (a .php file, so it never prints)
 * Local testing only: AFF_CLICK_RECORD_ALLOW_LOCAL=1 (env or SetEnv) also allows http://localhost
 * and http://127.0.0.1 origins. Off by default; leave it unset on the live site.
 * PHP 7.0+.
 */
header('Content-Type: application/json; charset=utf-8');

const AF_CR_WINDOW_MS = 3000;      // same 3s window as the Speed chip
const AF_CR_MIN_SPAN_MS = 60;      // 3 clicks at 30/s span 66.7 ms
const AF_CR_MIN_CLICKS = 3;        // same as the Best chip
const AF_CR_MAX_CLICKS = 100;      // 30/s for 3s is at most 91 clicks
const AF_CR_MAX_RATE = 30;         // same cap as the Best chip
const AF_CR_MAX_BODY = 2048;       // bytes
const AF_CR_RL_GAP_SEC = 4;        // at most one POST per 4s per IP (the page sends at most one per 5s)
const AF_CR_RL_WINDOW_SEC = 600;   // and at most 20 POSTs per 10 minutes per IP
const AF_CR_RL_MAX = 20;
const AF_CR_RL_MAX_KEYS = 2000;    // keeps the hits file small

$dataDir = dirname(__DIR__) . '/data';
$recordPath = $dataDir . '/click-record.json';
$hitsPath = $dataDir . '/click-record-hits.json';
$saltPath = $dataDir . '/click-record-salt.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function af_cr_fail($code, $msg, $extraHeaders = []) {
    http_response_code($code);
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function af_cr_read_record($fp) {
    $raw = stream_get_contents($fp);
    $data = (is_string($raw) && trim($raw) !== '') ? json_decode($raw, true) : null;
    $rec = (is_array($data) && isset($data['record']) && is_numeric($data['record'])) ? (float) $data['record'] : 0.0;
    if ($rec < 0 || $rec > AF_CR_MAX_RATE) {
        $rec = 0.0;
    }
    return round($rec, 1);
}

function af_cr_write_locked($fp, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
}

function af_cr_local_allowed() {
    $v = getenv('AFF_CLICK_RECORD_ALLOW_LOCAL');
    if ($v === false && isset($_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'])) {
        $v = $_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'];
    }
    return (string) $v === '1';
}

function af_cr_origin_ok($origin) {
    $origin = strtolower(rtrim(trim((string) $origin), '/'));
    if ($origin === 'https://afirstflag.com' || $origin === 'https://www.afirstflag.com') {
        return true;
    }
    return af_cr_local_allowed() && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin);
}

function af_cr_referer_origin($ref) {
    $p = parse_url((string) $ref);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function af_cr_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Same deny rule as the committed data/.htaccess, in case the folder had to be created.
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to live-stats store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

function af_cr_salt($path) {
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

// ---------- GET / HEAD: current record (read-only, short public cache)
if ($method === 'GET' || $method === 'HEAD') {
    $record = 0.0;
    $fp = is_file($recordPath) ? @fopen($recordPath, 'r') : false;
    if ($fp !== false) {
        if (flock($fp, LOCK_SH)) {
            $record = af_cr_read_record($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    $body = json_encode(['record' => $record]);
    $etag = '"cr-' . substr(md5($body), 0, 16) . '"';
    header('Cache-Control: public, max-age=15');
    header('ETag: ' . $etag);
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    if ($inm !== '' && strpos($inm, $etag) !== false) {
        http_response_code(304);
        exit;
    }
    echo $body;
    exit;
}

if ($method !== 'POST') {
    af_cr_fail(405, 'Method not allowed', ['Allow: GET, HEAD, POST']);
}

// ---------- POST: same-origin check
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
    $origin = af_cr_referer_origin($_SERVER['HTTP_REFERER']);
}
if ($origin === '' || !af_cr_origin_ok($origin)) {
    af_cr_fail(403, 'Forbidden');
}
$sfs = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($sfs !== '' && $sfs !== 'same-origin') {
    af_cr_fail(403, 'Forbidden');
}

// ---------- JSON only, small body
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
if (strpos($ctype, 'application/json') !== 0) {
    af_cr_fail(415, 'JSON only');
}
$len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($len > AF_CR_MAX_BODY) {
    af_cr_fail(413, 'Body too large');
}
$rawIn = file_get_contents('php://input', false, null, 0, AF_CR_MAX_BODY + 1);
if (!is_string($rawIn) || $rawIn === '') {
    af_cr_fail(400, 'Empty body');
}
if (strlen($rawIn) > AF_CR_MAX_BODY) {
    af_cr_fail(413, 'Body too large');
}

if (!af_cr_ensure_dir($dataDir)) {
    af_cr_fail(503, 'Record store unavailable');
}

// ---------- rate limit per IP (salted hash only), counted before validation
$salt = af_cr_salt($saltPath);
if ($salt === null) {
    af_cr_fail(503, 'Record store unavailable');
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$key = substr(hash_hmac('sha256', $ip, $salt), 0, 32);
$now = microtime(true);

$hf = @fopen($hitsPath, 'c+');
if ($hf === false || !flock($hf, LOCK_EX)) {
    if ($hf !== false) {
        fclose($hf);
    }
    af_cr_fail(503, 'Record store unavailable');
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
        if (is_numeric($ts) && $now - (float) $ts < AF_CR_RL_WINDOW_SEC && (float) $ts <= $now + 5) {
            $recent[] = (float) $ts;
        }
    }
    if ($recent) {
        $kept[$k] = $recent;
    }
}
$mine = isset($kept[$key]) ? $kept[$key] : [];
$retry = 0;
if ($mine && $now - max($mine) < AF_CR_RL_GAP_SEC) {
    $retry = (int) ceil(AF_CR_RL_GAP_SEC - ($now - max($mine)));
} elseif (count($mine) >= AF_CR_RL_MAX) {
    $retry = (int) ceil(AF_CR_RL_WINDOW_SEC - ($now - min($mine)));
}
if ($retry > 0) {
    flock($hf, LOCK_UN);
    fclose($hf);
    af_cr_fail(429, 'Too many submissions', ['Retry-After: ' . max(1, $retry)]);
}
$mine[] = round($now, 3);
$kept[$key] = $mine;
if (count($kept) > AF_CR_RL_MAX_KEYS) {
    uasort($kept, function ($a, $b) {
        return max($b) <=> max($a);
    });
    $kept = array_slice($kept, 0, AF_CR_RL_MAX_KEYS, true);
}
$json = json_encode($kept, JSON_UNESCAPED_SLASHES) . "\n";
ftruncate($hf, 0);
rewind($hf);
fwrite($hf, $json);
fflush($hf);
flock($hf, LOCK_UN);
fclose($hf);

// ---------- validate the run and recompute the rate
$payload = json_decode($rawIn, false, 4); // objects stay objects, so {"t": {...}} is not taken for a list
if (!is_object($payload) || !property_exists($payload, 't') || count(get_object_vars($payload)) !== 1) {
    af_cr_fail(400, 'Expected {"t": [click times]}');
}
$t = $payload->t;
if (!is_array($t) || ($t && array_keys($t) !== range(0, count($t) - 1))) {
    af_cr_fail(400, 't must be a list');
}
$n = count($t);
if ($n < AF_CR_MIN_CLICKS) {
    af_cr_fail(422, 'Need at least 3 clicks');
}
if ($n > AF_CR_MAX_CLICKS) {
    af_cr_fail(422, 'Too many clicks');
}
$prev = -1;
foreach ($t as $i => $v) {
    if (!is_int($v) || $v < 0 || $v > AF_CR_WINDOW_MS) {
        af_cr_fail(422, 'Click times must be whole ms from 0 to 3000');
    }
    if ($i === 0 && $v !== 0) {
        af_cr_fail(422, 'First click time must be 0');
    }
    if ($v <= $prev) {
        af_cr_fail(422, 'Click times must rise');
    }
    $prev = $v;
}
$span = $t[$n - 1] - $t[0];
if ($span < AF_CR_MIN_SPAN_MS || $span > AF_CR_WINDOW_MS) {
    af_cr_fail(422, 'Run span out of range');
}
$rate = ($n - 1) / ($span / 1000);
if ($rate > AF_CR_MAX_RATE) {
    af_cr_fail(422, 'Too fast');
}
$rate = round($rate, 1);

// ---------- keep the max (atomic read-compare-write under flock)
$fp = @fopen($recordPath, 'c+');
if ($fp === false || !flock($fp, LOCK_EX)) {
    if ($fp !== false) {
        fclose($fp);
    }
    af_cr_fail(503, 'Record store unavailable');
}
$record = af_cr_read_record($fp);
$accepted = $rate > $record;
if ($accepted) {
    $record = $rate;
    af_cr_write_locked($fp, ['record' => $record, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
}
flock($fp, LOCK_UN);
fclose($fp);

header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'accepted' => $accepted, 'record' => $record, 'rate' => $rate]);
