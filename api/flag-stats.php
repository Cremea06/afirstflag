<?php
/**
 * GET flag inventory stats for afirstflag.com (Namecheap shared hosting).
 * Returns JSON: { edition, sold, remaining, updatedAt }
 * remaining = max(0, edition - sold)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$inventoryPath = dirname(__DIR__) . '/data/flag-inventory.json';
$defaults = [
    'edition' => 200,
    'sold' => 0,
    'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
];

if (!is_file($inventoryPath)) {
    $dir = dirname($inventoryPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($inventoryPath, $json, LOCK_EX);
    $data = $defaults;
} else {
    $raw = @file_get_contents($inventoryPath);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid inventory file']);
        exit;
    }
}

$edition = isset($data['edition']) ? (int) $data['edition'] : 200;
$sold = isset($data['sold']) ? (int) $data['sold'] : 0;
if ($edition < 0) {
    $edition = 0;
}
if ($sold < 0) {
    $sold = 0;
}
$remaining = max(0, $edition - $sold);
$updatedAt = isset($data['updatedAt']) && is_string($data['updatedAt']) && $data['updatedAt'] !== ''
    ? $data['updatedAt']
    : gmdate('Y-m-d\TH:i:s\Z');

$payload = [
    'edition' => $edition,
    'sold' => $sold,
    'remaining' => $remaining,
    'updatedAt' => $updatedAt,
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
