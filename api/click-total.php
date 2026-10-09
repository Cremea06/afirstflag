<?php
/**
 * Server-wide total of Click counter presses (afirstflag.com shop, CHG-096).
 * GET  - { total } every visitor's Click counter presses added up (0 until the first POST)
 * POST - JSON { add: n } adds one batch of presses; n is a whole number 1..50 (the page buffers
 *        presses and sends at most one batch about every 2.5 s, split into batches of 50).
 *        Rejected: n missing, not an integer, below 1 or above 50, extra keys, malformed JSON.
 *        The per-visitor counter's reset (CHG-070) never sends anything, so it cannot lower the total.
 * Same-origin only (Origin, or Referer when Origin is missing), JSON only, body max 256 bytes,
 * rate-limited per IP (salted HMAC of the IP, never the raw IP): at most one POST per second and
 * 200 POSTs per 10 minutes, so one address can add at most 10,000 clicks per 10 minutes.
 * Store (created on first POST; data/.htaccess denies HTTP access; gitignored):
 *   data/click-total.json       { total, updatedAt }   written to a temp file and renamed (atomic)
 *   data/click-total.lock       flock target serialising read-add-write
 *   data/click-total-hits.json  recent POST times per hashed IP (pruned after 10 minutes)
 *   data/click-record-salt.php  shared with api/click-record.php (same salt, separate hits file)
 * Local testing only: AFF_CLICK_RECORD_ALLOW_LOCAL=1 (same flag as click-record) also allows
 * http://localhost and http://127.0.0.1 origins. Off by default; leave it unset on the live site.
 * PHP 7.0+.
 */
header('Content-Type: application/json; charset=utf-8');

const AF_CT_MAX_ADD = 50;          // clicks per POST
const AF_CT_MAX_BODY = 256;        // bytes
const AF_CT_MAX_TOTAL = 9007199254740991; // JS Number.MAX_SAFE_INTEGER; the total stops there
const AF_CT_RL_GAP_SEC = 1;        // at most one POST per second per IP (the page sends one per ~2.5s)
const AF_CT_RL_WINDOW_SEC = 600;   // and at most 200 POSTs per 10 minutes per IP
const AF_CT_RL_MAX = 200;
const AF_CT_RL_MAX_KEYS = 2000;

$dataDir = dirname(__DIR__) . '/data';
$totalPath = $dataDir . '/click-total.json';
$lockPath = $dataDir . '/click-total.lock';
$hitsPath = $dataDir . '/click-total-hits.json';
$saltPath = $dataDir . '/click-record-salt.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function af_ct_fail($code, $msg, $extraHeaders = []) {
    http_response_code($code);
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function af_ct_read_total($path) {
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $data = (is_string($raw) && trim($raw) !== '') ? json_decode($raw, true) : null;
    $t = (is_array($data) && isset($data['total']) && is_int($data['total'])) ? $data['total'] : 0;
    return ($t < 0 || $t > AF_CT_MAX_TOTAL) ? 0 : $t;
}

function af_ct_local_allowed() {
    $v = getenv('AFF_CLICK_RECORD_ALLOW_LOCAL');
    if ($v === false && isset($_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'])) {
        $v = $_SERVER['AFF_CLICK_RECORD_ALLOW_LOCAL'];
    }
    return (string) $v === '1';
}

function af_ct_origin_ok($origin) {
    $origin = strtolower(rtrim(trim((string) $origin), '/'));
    if ($origin === 'https://afirstflag.com' || $origin === 'https://www.afirstflag.com') {
        return true;
    }
    return af_ct_local_allowed() && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin);
}

function af_ct_referer_origin($ref) {
    $p = parse_url((string) $ref);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function af_ct_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to live-stats store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

function af_ct_salt($path) {
    // Same file and format as api/click-record.php, so both endpoints hash an IP the same way.
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

// ---------- GET / HEAD: current total (the file is only ever replaced by rename, so no lock needed)
if ($method === 'GET' || $method === 'HEAD') {
    $body = json_encode(['total' => af_ct_read_total($totalPath)]);
    $etag = '"ct-' . substr(md5($body), 0, 16) . '"';
    header('Cache-Control: public, max-age=5');
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
    af_ct_fail(405, 'Method not allowed', ['Allow: GET, HEAD, POST']);
}

// ---------- POST: same-origin check
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
    $origin = af_ct_referer_origin($_SERVER['HTTP_REFERER']);
}
if ($origin === '' || !af_ct_origin_ok($origin)) {
    af_ct_fail(403, 'Forbidden');
}
$sfs = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($sfs !== '' && $sfs !== 'same-origin') {
    af_ct_fail(403, 'Forbidden');
}

// ---------- JSON only, small body
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
if (strpos($ctype, 'application/json') !== 0) {
    af_ct_fail(415, 'JSON only');
}
$len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($len > AF_CT_MAX_BODY) {
    af_ct_fail(413, 'Body too large');
}
$rawIn = file_get_contents('php://input', false, null, 0, AF_CT_MAX_BODY + 1);
if (!is_string($rawIn) || $rawIn === '') {
    af_ct_fail(400, 'Empty body');
}
if (strlen($rawIn) > AF_CT_MAX_BODY) {
    af_ct_fail(413, 'Body too large');
}

if (!af_ct_ensure_dir($dataDir)) {
    af_ct_fail(503, 'Total store unavailable');
}

// ---------- rate limit per IP (salted hash only), counted before validation
$salt = af_ct_salt($saltPath);
if ($salt === null) {
    af_ct_fail(503, 'Total store unavailable');
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$key = substr(hash_hmac('sha256', $ip, $salt), 0, 32);
$now = microtime(true);

$hf = @fopen($hitsPath, 'c+');
if ($hf === false || !flock($hf, LOCK_EX)) {
    if ($hf !== false) {
        fclose($hf);
    }
    af_ct_fail(503, 'Total store unavailable');
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
        if (is_numeric($ts) && $now - (float) $ts < AF_CT_RL_WINDOW_SEC && (float) $ts <= $now + 5) {
            $recent[] = (float) $ts;
        }
    }
    if ($recent) {
        $kept[$k] = $recent;
    }
}
$mine = isset($kept[$key]) ? $kept[$key] : [];
$retry = 0;
if ($mine && $now - max($mine) < AF_CT_RL_GAP_SEC) {
    $retry = (int) ceil(AF_CT_RL_GAP_SEC - ($now - max($mine)));
} elseif (count($mine) >= AF_CT_RL_MAX) {
    $retry = (int) ceil(AF_CT_RL_WINDOW_SEC - ($now - min($mine)));
}
if ($retry > 0) {
    flock($hf, LOCK_UN);
    fclose($hf);
    af_ct_fail(429, 'Too many submissions', ['Retry-After: ' . max(1, $retry)]);
}
$mine[] = round($now, 3);
$kept[$key] = $mine;
if (count($kept) > AF_CT_RL_MAX_KEYS) {
    uasort($kept, function ($a, $b) {
        return max($b) <=> max($a);
    });
    $kept = array_slice($kept, 0, AF_CT_RL_MAX_KEYS, true);
}
ftruncate($hf, 0);
rewind($hf);
fwrite($hf, json_encode($kept, JSON_UNESCAPED_SLASHES) . "\n");
fflush($hf);
flock($hf, LOCK_UN);
fclose($hf);

// ---------- validate { add: n }
$payload = json_decode($rawIn, false, 4);
if (!is_object($payload) || !property_exists($payload, 'add') || count(get_object_vars($payload)) !== 1) {
    af_ct_fail(400, 'Expected {"add": n}');
}
$add = $payload->add;
if (!is_int($add)) {
    af_ct_fail(422, 'add must be a whole number');
}
if ($add < 1 || $add > AF_CT_MAX_ADD) {
    af_ct_fail(422, 'add must be 1 to 50');
}

// ---------- add under an exclusive lock; write temp file + rename (atomic replace)
$lf = @fopen($lockPath, 'c');
if ($lf === false || !flock($lf, LOCK_EX)) {
    if ($lf !== false) {
        fclose($lf);
    }
    af_ct_fail(503, 'Total store unavailable');
}
$total = min(AF_CT_MAX_TOTAL, af_ct_read_total($totalPath) + $add);
$tmp = $totalPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
$json = json_encode(['total' => $total, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$okWrite = @file_put_contents($tmp, $json) === strlen($json) && @rename($tmp, $totalPath);
if (!$okWrite) {
    @unlink($tmp);
}
flock($lf, LOCK_UN);
fclose($lf);
if (!$okWrite) {
    af_ct_fail(503, 'Total store unavailable');
}

header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'added' => $add, 'total' => $total]);
