<?php
/**
 * Visitor country for the Live-state pill (afirstflag.com shop, CHG-076).
 * GET / HEAD - { cc, name } for the address this request comes from, e.g. {"cc":"US","name":"United States"}.
 *              Unknown, private or reserved address: {"cc":null,"name":null}. Database missing: 503.
 * Privacy:
 * - Uses REMOTE_ADDR only. X-Forwarded-For and similar headers are ignored (the site has no CDN or proxy in
 *   front; api/click-record.php does the same).
 * - The address is looked up in geo/ip-country.bin on this server (geo/lookup.php), so it is never sent to
 *   another service. It is not logged, not stored, and not echoed back.
 * - The answer is cached by the visitor's own browser only (private, 5 minutes), never by a shared cache.
 * Data: DB-IP IP to Country Lite, CC BY 4.0. Attribution "IP Geolocation by DB-IP" is linked on the homepage.
 * Source release and refresh steps: geo/README.md and tools/geo-build/build.py.
 * PHP 7.0+.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-LiteSpeed-Cache-Control: no-cache'); // LiteSpeed (Namecheap): never cache a per-visitor answer on the server

require_once dirname(__DIR__) . '/geo/lookup.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$geoDir = dirname(__DIR__) . '/geo';
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$cc = af_geo_lookup($ip, $geoDir . '/ip-country.bin');
unset($ip);

if ($cc === false) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo json_encode(['cc' => null, 'name' => null, 'error' => 'Country lookup unavailable']);
    exit;
}

$name = null;
if ($cc !== null) {
    $names = af_geo_names($geoDir . '/country-names.php');
    $name = (isset($names[$cc]) && is_string($names[$cc])) ? $names[$cc] : null;
    if ($name === null) {
        $cc = null; // a code without a name shows as unknown
    }
}

header('Cache-Control: private, max-age=300');
echo json_encode(['cc' => $cc, 'name' => $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
