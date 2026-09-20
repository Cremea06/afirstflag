<?php
/**
 * Homepage page-load counter (afirstflag.com shop).
 * GET ?hit=1  — increment homeVisits once, return JSON
 * GET         — read-only JSON { homeVisits, since, updatedAt }
 * First-party only. Optional light bot UA skip on hit.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$path = dirname(__DIR__) . '/data/live-stats.json';
$hit = isset($_GET['hit']) && (string) $_GET['hit'] === '1';

function af_live_defaults() {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return [
        'homeVisits' => 0,
        'since' => $now,
        'updatedAt' => $now,
    ];
}

function af_is_bot_ua($ua) {
    if ($ua === '') {
        return false;
    }
    return (bool) preg_match(
        '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|embedly|quora|pinterest|redditbot|whatsapp|telegram|discord|preview|headless|curl|wget|python-requests|scrapy/i',
        $ua
    );
}

function af_respond($data) {
    echo json_encode([
        'homeVisits' => (int) $data['homeVisits'],
        'since' => (string) $data['since'],
        'updatedAt' => (string) $data['updatedAt'],
    ], JSON_UNESCAPED_SLASHES);
}

$dir = dirname($path);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$fp = fopen($path, 'c+');
if ($fp === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot open live-stats store']);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Cannot lock live-stats store']);
    exit;
}

$raw = stream_get_contents($fp);
$data = null;
if (is_string($raw) && trim($raw) !== '') {
    $data = json_decode($raw, true);
}
if (!is_array($data)) {
    $data = af_live_defaults();
}

$visits = isset($data['homeVisits']) ? (int) $data['homeVisits'] : 0;
if ($visits < 0) {
    $visits = 0;
}
$since = isset($data['since']) && is_string($data['since']) && $data['since'] !== ''
    ? $data['since']
    : gmdate('Y-m-d\TH:i:s\Z');
$updatedAt = isset($data['updatedAt']) && is_string($data['updatedAt'])
    ? $data['updatedAt']
    : $since;

if ($hit) {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if (!af_is_bot_ua($ua)) {
        $visits += 1;
        $updatedAt = gmdate('Y-m-d\TH:i:s\Z');
    }
}

$data = [
    'homeVisits' => $visits,
    'since' => $since,
    'updatedAt' => $updatedAt,
];

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, $json);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

af_respond($data);
