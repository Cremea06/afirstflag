<?php
/**
 * Collaborator submissions (afirstflag.com shop).
 * GET  — { items: [...] } approved only
 * POST — JSON { url, label? } → append pending; dedupe by normalized URL
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$path = dirname(__DIR__) . '/data/collaborators.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function af_collab_defaults() {
    return ['items' => []];
}

function af_collab_normalize_url($url) {
    $url = trim((string) $url);
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    $host = strtolower($parts['host']);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $path = isset($parts['path']) ? $parts['path'] : '';
    if ($path === '/') {
        $path = '';
    } elseif ($path !== '' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return $scheme . '://' . $host . $path . $query;
}

function af_collab_type($url) {
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    if ($host === 'x.com' || $host === 'twitter.com') {
        return 'x';
    }
    if ($host === 'youtube.com' || $host === 'youtu.be' || substr($host, -12) === '.youtube.com') {
        return 'youtube';
    }
    if ($host === 'facebook.com' || $host === 'fb.com' || $host === 'www.facebook.com' || substr($host, -13) === '.facebook.com') {
        return 'facebook';
    }
    return 'site';
}

function af_collab_type_label($type) {
    switch ($type) {
        case 'x': return 'X';
        case 'youtube': return 'YouTube';
        case 'facebook': return 'Facebook';
        default: return 'Site';
    }
}

function af_collab_read_locked($fp) {
    $raw = stream_get_contents($fp);
    $data = null;
    if (is_string($raw) && trim($raw) !== '') {
        $data = json_decode($raw, true);
    }
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return af_collab_defaults();
    }
    return $data;
}

function af_collab_write_locked($fp, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
}

$dir = dirname($path);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$fp = fopen($path, 'c+');
if ($fp === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot open collaborators store']);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Cannot lock collaborators store']);
    exit;
}

$data = af_collab_read_locked($fp);

if ($method === 'GET' || $method === 'HEAD') {
    $items = [];
    foreach ($data['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['status'] ?? '') !== 'approved') {
            continue;
        }
        $type = isset($item['type']) ? (string) $item['type'] : 'site';
        $items[] = [
            'url' => (string) ($item['url'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'blurb' => (string) ($item['blurb'] ?? ''),
            'type' => $type,
            'typeLabel' => af_collab_type_label($type),
        ];
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawIn = file_get_contents('php://input');
$payload = is_string($rawIn) ? json_decode($rawIn, true) : null;
if (!is_array($payload)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Honeypot — silently accept without storing
if (!empty($payload['company'])) {
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['ok' => true]);
    exit;
}

$url = isset($payload['url']) ? trim((string) $payload['url']) : '';
$label = isset($payload['label']) ? trim((string) $payload['label']) : '';

if ($url === '' || strlen($url) > 200) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(400);
    echo json_encode(['error' => 'URL required (max 200 characters)']);
    exit;
}
if (strlen($label) > 60) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(400);
    echo json_encode(['error' => 'Display name max 60 characters']);
    exit;
}

$normalized = af_collab_normalize_url($url);
if ($normalized === null) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(400);
    echo json_encode(['error' => 'URL must start with http:// or https://']);
    exit;
}

foreach ($data['items'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $existing = af_collab_normalize_url((string) ($item['url'] ?? ''));
    if ($existing !== null && $existing === $normalized) {
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }
}

$now = gmdate('Y-m-d\TH:i:s\Z');
$data['items'][] = [
    'id' => bin2hex(random_bytes(8)),
    'url' => $normalized,
    'label' => $label,
    'blurb' => '',
    'type' => af_collab_type($normalized),
    'status' => 'pending',
    'createdAt' => $now,
];

af_collab_write_locked($fp, $data);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true]);
