<?php
/**
 * CHG-150: GET mug inventory stats for afirstflag.com.
 * Returns JSON: { run, sold, remaining, updatedAt }; remaining = max(0, run - sold).
 * data/mug-inventory.json is written by api/stripe-webhook-mug.php and is NOT tracked in git
 * (so `git pull` never clobbers live sales). When absent, defaults to run 12 / sold 0
 * (read-only here: this endpoint never creates or changes the file).
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

$inventoryPath = dirname(__DIR__) . '/data/mug-inventory.json';
$data = ['run' => 12, 'sold' => 0];
if (is_file($inventoryPath)) {
    $raw = @file_get_contents($inventoryPath);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid inventory file']);
        exit;
    }
}

$run = isset($data['run']) ? max(0, (int) $data['run']) : 12;
$sold = isset($data['sold']) ? max(0, (int) $data['sold']) : 0;
$updatedAt = isset($data['updatedAt']) && is_string($data['updatedAt']) && $data['updatedAt'] !== ''
    ? $data['updatedAt']
    : gmdate('Y-m-d\TH:i:s\Z');

echo json_encode([
    'run' => $run,
    'sold' => $sold,
    'remaining' => max(0, $run - $sold),
    'updatedAt' => $updatedAt,
], JSON_UNESCAPED_SLASHES);
