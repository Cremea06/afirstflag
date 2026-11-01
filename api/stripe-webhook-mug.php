<?php
/**
 * CHG-150: Mug inventory Stripe webhook — afirstflag.com (Namecheap shared hosting, pure PHP).
 *
 * Stripe event destination: "mug-inventory" (Your account, Snapshot payload, API 2026-07-29.dahlia)
 *   Event:    checkout.session.completed
 *   Endpoint: https://afirstflag.com/api/stripe-webhook-mug.php
 *   Product:  prod_VY8Xg2BHr7qYhw (handmade AF mug, $40)
 *
 * Independent of the flag webhook (api/stripe-webhook.php): own signing secret, own API key,
 * own inventory file and own processed-events file. Nothing here touches the flag files.
 *
 * data/stripe-secrets.php (not in git) must return an array that also contains:
 *   'MUG_WEBHOOK_SECRET'    => 'whsec_...'   (signing secret of the mug-inventory destination)
 *   'MUG_STRIPE_SECRET_KEY' => 'rk_live_...' (restricted key: Checkout Sessions read + Products read)
 *
 * Writes data/mug-inventory.json { run, sold, updatedAt } (created on first sale, default run 12 / sold 0).
 * Idempotent on both event.id and checkout session id (data/mug-processed-events.json).
 * Misconfiguration returns 500 (so Stripe retries) and logs without revealing secrets.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MUG_PRODUCT_ID = 'prod_VY8Xg2BHr7qYhw';
const MUG_RUN_SIZE = 12;
const MUG_SIG_TOLERANCE_SEC = 300;

function af_mug_fail($code, $msg)
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    af_mug_fail(405, 'Method not allowed');
}

$dataDir = dirname(__DIR__) . '/data';
$secretsPath = $dataDir . '/stripe-secrets.php';
$inventoryPath = $dataDir . '/mug-inventory.json';
$processedPath = $dataDir . '/mug-processed-events.json';

if (!is_file($secretsPath)) {
    error_log('[mug-webhook] data/stripe-secrets.php missing');
    af_mug_fail(500, 'Webhook not configured');
}
$secrets = include $secretsPath;
if (!is_array($secrets)) {
    error_log('[mug-webhook] data/stripe-secrets.php did not return an array');
    af_mug_fail(500, 'Webhook not configured');
}

$webhookSecret = isset($secrets['MUG_WEBHOOK_SECRET']) ? (string) $secrets['MUG_WEBHOOK_SECRET'] : '';
$apiKey = isset($secrets['MUG_STRIPE_SECRET_KEY']) ? (string) $secrets['MUG_STRIPE_SECRET_KEY'] : '';
// Local testing only: point line-item fetch at a loopback mock. Ignored unless it is http://127.0.0.1:<port>.
$apiBase = 'https://api.stripe.com';
if (isset($secrets['MUG_STRIPE_API_BASE']) && preg_match('#^http://127\.0\.0\.1:\d+$#', (string) $secrets['MUG_STRIPE_API_BASE'])) {
    $apiBase = (string) $secrets['MUG_STRIPE_API_BASE'];
}

if ($webhookSecret === '' || strpos($webhookSecret, 'REPLACE') !== false || strpos($webhookSecret, '...') !== false) {
    error_log('[mug-webhook] MUG_WEBHOOK_SECRET not configured');
    af_mug_fail(500, 'Webhook not configured');
}

$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    af_mug_fail(400, 'Empty body');
}
$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
if ($sigHeader === '') {
    af_mug_fail(400, 'Missing Stripe-Signature');
}
if (!af_mug_verify_signature($payload, $sigHeader, $webhookSecret)) {
    af_mug_fail(400, 'Invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type']) || empty($event['id'])) {
    af_mug_fail(400, 'Invalid event JSON');
}
$eventId = (string) $event['id'];
$type = (string) $event['type'];

if ($type !== 'checkout.session.completed') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => $type]);
    exit;
}

$session = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : null;
if ($session === null) {
    af_mug_fail(400, 'Missing session object');
}
$paymentStatus = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
if ($paymentStatus !== 'paid') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => 'payment_status:' . $paymentStatus]);
    exit;
}

if ($apiKey === '' || strpos($apiKey, 'REPLACE') !== false || strpos($apiKey, '...') !== false) {
    error_log('[mug-webhook] MUG_STRIPE_SECRET_KEY not configured');
    af_mug_fail(500, 'Webhook not configured');
}

$sessionId = isset($session['id']) ? (string) $session['id'] : '';
if ($sessionId === '') {
    af_mug_fail(400, 'Missing session id');
}

$lineItems = af_mug_fetch_line_items($apiBase, $sessionId, $apiKey);
if ($lineItems === null) {
    error_log('[mug-webhook] could not fetch line items for ' . $sessionId);
    af_mug_fail(500, 'Could not retrieve session line_items');
}

$qty = af_mug_quantity_from_line_items($lineItems, MUG_PRODUCT_ID);
if ($qty <= 0) {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => 'no_mug_product']);
    exit;
}

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

$result = af_mug_apply_sale_idempotent($processedPath, $inventoryPath, $eventId, $sessionId, $qty);
if ($result['ok'] !== true) {
    error_log('[mug-webhook] ' . $result['error']);
    af_mug_fail(500, $result['error']);
}

http_response_code(200);
echo json_encode([
    'received' => true,
    'duplicate' => !empty($result['duplicate']),
    'sold' => $result['sold'],
    'added' => $result['added'],
]);
exit;

/** Verify Stripe-Signature (t=…,v1=…) per Stripe docs. Pure PHP, no Composer. */
function af_mug_verify_signature($payload, $header, $secret)
{
    $parts = [];
    foreach (explode(',', $header) as $item) {
        $kv = explode('=', trim($item), 2);
        if (count($kv) !== 2) {
            continue;
        }
        $parts[$kv[0]][] = $kv[1];
    }
    if (empty($parts['t'][0]) || empty($parts['v1'])) {
        return false;
    }
    $timestamp = (int) $parts['t'][0];
    if ($timestamp <= 0 || abs(time() - $timestamp) > MUG_SIG_TOLERANCE_SEC) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($parts['v1'] as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

/** GET Checkout Session line items. @return array|null */
function af_mug_fetch_line_items($apiBase, $sessionId, $apiKey)
{
    $url = $apiBase . '/v1/checkout/sessions/' . rawurlencode($sessionId) . '/line_items?limit=100';
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $apiKey . ':',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
        return null;
    }
    return $json['data'];
}

/** Sum quantities of line items whose price.product is the mug product. */
function af_mug_quantity_from_line_items(array $lineItems, $productId)
{
    $total = 0;
    foreach ($lineItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qty = isset($item['quantity']) ? (int) $item['quantity'] : 1;
        if ($qty < 1) {
            $qty = 1;
        }
        $product = isset($item['price']['product']) ? $item['price']['product'] : null;
        if (is_array($product) && isset($product['id'])) {
            $product = $product['id'];
        }
        if (is_string($product) && $product === $productId) {
            $total += $qty;
        }
    }
    return $total;
}

/**
 * Idempotent sold bump under file locks (event id AND session id).
 * @return array{ok:bool,error?:string,duplicate?:bool,sold?:int,added?:int}
 */
function af_mug_apply_sale_idempotent($processedPath, $inventoryPath, $eventId, $sessionId, $qty)
{
    $processedFp = fopen($processedPath, 'c+');
    if ($processedFp === false) {
        return ['ok' => false, 'error' => 'Cannot open processed events file'];
    }
    if (!flock($processedFp, LOCK_EX)) {
        fclose($processedFp);
        return ['ok' => false, 'error' => 'Cannot lock processed events'];
    }
    $processed = ['ids' => [], 'sessions' => []];
    $raw = stream_get_contents($processedFp);
    if (is_string($raw) && trim($raw) !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            if (isset($d['ids']) && is_array($d['ids'])) {
                $processed['ids'] = array_values($d['ids']);
            }
            if (isset($d['sessions']) && is_array($d['sessions'])) {
                $processed['sessions'] = array_values($d['sessions']);
            }
        }
    }

    if (in_array($eventId, $processed['ids'], true) || in_array($sessionId, $processed['sessions'], true)) {
        flock($processedFp, LOCK_UN);
        fclose($processedFp);
        $sold = 0;
        if (is_file($inventoryPath)) {
            $inv = json_decode((string) file_get_contents($inventoryPath), true);
            if (is_array($inv) && isset($inv['sold'])) {
                $sold = (int) $inv['sold'];
            }
        }
        return ['ok' => true, 'duplicate' => true, 'sold' => $sold, 'added' => 0];
    }

    $invFp = fopen($inventoryPath, 'c+');
    if ($invFp === false) {
        flock($processedFp, LOCK_UN);
        fclose($processedFp);
        return ['ok' => false, 'error' => 'Cannot open inventory'];
    }
    if (!flock($invFp, LOCK_EX)) {
        fclose($invFp);
        flock($processedFp, LOCK_UN);
        fclose($processedFp);
        return ['ok' => false, 'error' => 'Cannot lock inventory'];
    }
    $invRaw = stream_get_contents($invFp);
    $inv = (is_string($invRaw) && trim($invRaw) !== '') ? json_decode($invRaw, true) : null;
    if (!is_array($inv)) {
        $inv = ['run' => MUG_RUN_SIZE, 'sold' => 0];
    }
    $run = isset($inv['run']) ? max(0, (int) $inv['run']) : MUG_RUN_SIZE;
    $sold = isset($inv['sold']) ? max(0, (int) $inv['sold']) : 0;
    $sold += (int) $qty;
    $inv = ['run' => $run, 'sold' => $sold, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')];

    ftruncate($invFp, 0);
    rewind($invFp);
    fwrite($invFp, json_encode($inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($invFp);

    $processed['ids'][] = $eventId;
    $processed['sessions'][] = $sessionId;
    $processed['ids'] = array_slice($processed['ids'], -5000);
    $processed['sessions'] = array_slice($processed['sessions'], -5000);
    ftruncate($processedFp, 0);
    rewind($processedFp);
    fwrite($processedFp, json_encode($processed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($processedFp);

    flock($invFp, LOCK_UN);
    fclose($invFp);
    flock($processedFp, LOCK_UN);
    fclose($processedFp);

    return ['ok' => true, 'duplicate' => false, 'sold' => $sold, 'added' => (int) $qty];
}
