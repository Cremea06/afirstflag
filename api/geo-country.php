<?php
/**
 * Visitor country for the Live-state pills (afirstflag.com shop, CHG-076; remaining countries CHG-077).
 * GET / HEAD - { cc, name, remaining, visited, isNew } for the address this request comes from, e.g.
 *              {"cc":"US","name":"United States","remaining":241,"visited":8,"isNew":false}.
 *              Unknown, private or reserved address: cc and name null. Database missing: 503 (cc and name null).
 * Remaining countries (CHG-077): remaining = 249 - visited, where visited counts the ISO 3166-1 alpha-2 codes
 * (the 249 of the "Countries: 249" pill) that visitors have come from since the count started. After the lookup, a GET
 * whose code is one of the 249 and not yet in the set adds it to data/countries-visited.json (geo/visited.php).
 * The code always comes from this server's own lookup, never from the request. Only codes are stored: no address,
 * no time, nothing per visitor. HEAD, unknown addresses and other codes (XK, EU, ZZ, ...) never write.
 * isNew is true only for the one request that added its code. If the count cannot be read, remaining and visited are null.
 * Privacy:
 * - Uses REMOTE_ADDR only. X-Forwarded-For and similar headers are ignored (the site has no CDN or proxy in
 *   front; api/click-record.php does the same).
 * - The address is looked up in geo/ip-country.bin on this server (geo/lookup.php), so it is never sent to
 *   another service. It is not logged, not stored, and not echoed back.
 * - The answer is cached by the visitor's own browser only (private, 5 minutes), never by a shared cache.
 *   So remaining can be up to 5 minutes old in one browser; that is fine for a vague counter.
 * Data: DB-IP IP to Country Lite, CC BY 4.0. Attribution "IP Geolocation by DB-IP" is linked on the homepage.
 * Source release and refresh steps: geo/README.md and tools/geo-build/build.py.
 * PHP 7.0+.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-LiteSpeed-Cache-Control: no-cache'); // LiteSpeed (Namecheap): never cache a per-visitor answer on the server

require_once dirname(__DIR__) . '/geo/lookup.php';
require_once dirname(__DIR__) . '/geo/visited.php';

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

$dataDir = dirname(__DIR__) . '/data';

function af_geo_remaining($seen) {
    $n = $seen['visited'];
    return ['remaining' => $n === null ? null : AF_ISO_TOTAL - $n, 'visited' => $n, 'isNew' => $seen['isNew']];
}

if ($cc === false) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo json_encode(['cc' => null, 'name' => null] + af_geo_remaining(af_visited_note($dataDir, null, false)) + ['error' => 'Country lookup unavailable']);
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

// Only a GET from a real lookup can add a code; af_visited_note() also checks it is one of the 249.
$seen = af_visited_note($dataDir, $cc, $method === 'GET');

header('Cache-Control: private, max-age=300');
echo json_encode(['cc' => $cc, 'name' => $name] + af_geo_remaining($seen), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
