<?php
/**
 * Stripe webhook — afirstflag.com (Namecheap shared hosting, pure PHP).
 * Processes checkout.session.completed for Flag product prod_V4ZdXSKifGrzaL.
 * Increments data/flag-inventory.json sold atomically; idempotent on event.id.
 *
 * TEST mode: use sk_test_ / whsec_ from Stripe TEST Dashboard.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const FLAG_PRODUCT_ID = 'prod_V4ZdXSKifGrzaL';
const SIG_TOLERANCE_SEC = 300;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$dataDir = dirname(__DIR__) . '/data';
$secretsPath = $dataDir . '/stripe-secrets.php';
$inventoryPath = $dataDir . '/flag-inventory.json';
$processedPath = $dataDir . '/stripe-processed-events.json';

if (!is_file($secretsPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Missing data/stripe-secrets.php — copy from stripe-secrets.php.example']);
    exit;
}

$secrets = include $secretsPath;
if (!is_array($secrets)) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid stripe-secrets.php']);
    exit;
}

$webhookSecret = isset($secrets['STRIPE_WEBHOOK_SECRET']) ? (string) $secrets['STRIPE_WEBHOOK_SECRET'] : '';
$apiKey = isset($secrets['STRIPE_SECRET_KEY']) ? (string) $secrets['STRIPE_SECRET_KEY'] : '';

if ($webhookSecret === '' || strpos($webhookSecret, 'REPLACE') !== false) {
    http_response_code(500);
    echo json_encode(['error' => 'STRIPE_WEBHOOK_SECRET not configured']);
    exit;
}

$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
if ($sigHeader === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Stripe-Signature']);
    exit;
}

if (!af_stripe_verify_signature($payload, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type']) || empty($event['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event JSON']);
    exit;
}

$eventId = (string) $event['id'];
$type = (string) $event['type'];

// Ignore everything except checkout.session.completed
if ($type !== 'checkout.session.completed') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => $type]);
    exit;
}

$session = isset($event['data']['object']) && is_array($event['data']['object'])
    ? $event['data']['object']
    : null;
if ($session === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing session object']);
    exit;
}

$paymentStatus = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
if ($paymentStatus !== 'paid') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => 'payment_status:' . $paymentStatus]);
    exit;
}

if ($apiKey === '' || strpos($apiKey, 'REPLACE') !== false) {
    http_response_code(500);
    echo json_encode(['error' => 'STRIPE_SECRET_KEY not configured (needed to expand line_items)']);
    exit;
}

$sessionId = isset($session['id']) ? (string) $session['id'] : '';
if ($sessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing session id']);
    exit;
}

$lineItems = af_stripe_fetch_line_items($sessionId, $apiKey);
if ($lineItems === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not retrieve session line_items']);
    exit;
}

$qty = af_flag_quantity_from_line_items($lineItems, FLAG_PRODUCT_ID);
if ($qty <= 0) {
    // Not our Flag product — acknowledge, do not increment
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => 'no_flag_product']);
    exit;
}

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

$result = af_apply_sale_idempotent($processedPath, $inventoryPath, $eventId, $qty);
if ($result['ok'] !== true) {
    http_response_code(500);
    echo json_encode(['error' => $result['error']]);
    exit;
}

http_response_code(200);
echo json_encode([
    'received' => true,
    'duplicate' => !empty($result['duplicate']),
    'sold' => $result['sold'],
    'added' => $result['added'],
]);
exit;

/**
 * Verify Stripe-Signature (t=…,v1=…) per Stripe docs. Pure PHP, no Composer.
 */
function af_stripe_verify_signature($payload, $header, $secret)
{
    $parts = [];
    foreach (explode(',', $header) as $item) {
        $item = trim($item);
        $kv = explode('=', $item, 2);
        if (count($kv) !== 2) {
            continue;
        }
        $parts[$kv[0]][] = $kv[1];
    }
    if (empty($parts['t'][0]) || empty($parts['v1'])) {
        return false;
    }
    $timestamp = (int) $parts['t'][0];
    if ($timestamp <= 0) {
        return false;
    }
    if (abs(time() - $timestamp) > SIG_TOLERANCE_SEC) {
        return false;
    }
    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    foreach ($parts['v1'] as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

/**
 * GET Checkout Session line items from Stripe API.
 * @return array|null list of line item arrays
 */
function af_stripe_fetch_line_items($sessionId, $apiKey)
{
    $url = 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId)
        . '/line_items?limit=100';
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

/**
 * Sum quantities for line items whose price.product matches Flag product
 * (or price id string equals product id if mis-shaped — product is canonical).
 */
function af_flag_quantity_from_line_items(array $lineItems, $productId)
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
        $product = null;
        if (isset($item['price']['product'])) {
            $product = $item['price']['product'];
        } elseif (isset($item['price']) && is_string($item['price'])) {
            // Unexpanded price id only — cannot match product; skip
            $product = null;
        }
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
 * Idempotent sold bump under file locks.
 * @return array{ok:bool,error?:string,duplicate?:bool,sold?:int,added?:int}
 */
function af_apply_sale_idempotent($processedPath, $inventoryPath, $eventId, $qty)
{
    $processedFp = fopen($processedPath, 'c+');
    if ($processedFp === false) {
        return ['ok' => false, 'error' => 'Cannot open processed events file'];
    }
    if (!flock($processedFp, LOCK_EX)) {
        fclose($processedFp);
        return ['ok' => false, 'error' => 'Cannot lock processed events'];
    }

    $processedRaw = stream_get_contents($processedFp);
    $processed = ['ids' => []];
    if (is_string($processedRaw) && trim($processedRaw) !== '') {
        $decoded = json_decode($processedRaw, true);
        if (is_array($decoded)) {
            if (isset($decoded['ids']) && is_array($decoded['ids'])) {
                $processed = ['ids' => array_values($decoded['ids'])];
            } elseif ($decoded === [] || array_keys($decoded) === range(0, count($decoded) - 1)) {
                // bare list of event ids
                $processed = ['ids' => array_values($decoded)];
            }
        }
    }

    if (in_array($eventId, $processed['ids'], true)) {
        flock($processedFp, LOCK_UN);
        fclose($processedFp);
        // Read current sold for response
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
    $inv = null;
    if (is_string($invRaw) && trim($invRaw) !== '') {
        $inv = json_decode($invRaw, true);
    }
    if (!is_array($inv)) {
        $inv = [
            'edition' => 200,
            'sold' => 0,
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    $edition = isset($inv['edition']) ? (int) $inv['edition'] : 200;
    $sold = isset($inv['sold']) ? (int) $inv['sold'] : 0;
    if ($edition < 0) {
        $edition = 0;
    }
    if ($sold < 0) {
        $sold = 0;
    }
    $sold += (int) $qty;
    $inv = [
        'edition' => $edition,
        'sold' => $sold,
        'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $invJson = json_encode($inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    ftruncate($invFp, 0);
    rewind($invFp);
    fwrite($invFp, $invJson);
    fflush($invFp);

    $processed['ids'][] = $eventId;
    // Keep file from growing forever — retain last 5000 ids
    if (count($processed['ids']) > 5000) {
        $processed['ids'] = array_slice($processed['ids'], -5000);
    }
    $procJson = json_encode($processed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    ftruncate($processedFp, 0);
    rewind($processedFp);
    fwrite($processedFp, $procJson);
    fflush($processedFp);

    flock($invFp, LOCK_UN);
    fclose($invFp);
    flock($processedFp, LOCK_UN);
    fclose($processedFp);

    return ['ok' => true, 'duplicate' => false, 'sold' => $sold, 'added' => (int) $qty];
}
