<?php
/**
 * CHG-076: check geo/lookup.php against geo/ip-country.bin. Run after every rebuild:
 *   php tools/geo-build/test-lookup.php
 * Exit code 0 = all passed. Command line only (tools/.htaccess also blocks HTTP access).
 * Expected countries are what DB-IP Lite says (checked against other public sources in CHG-076);
 * if a refresh moves one, check it by hand before changing the expectation here.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(dirname(__DIR__));
require_once $root . '/geo/lookup.php';
$bin = $root . '/geo/ip-country.bin';
$names = af_geo_names($root . '/geo/country-names.php');

$cases = [
    // public addresses with a well-known home
    ['8.8.8.8', 'US'],             // Google Public DNS
    ['9.9.9.9', 'US'],             // Quad9 DNS
    ['1.1.1.1', 'AU'],             // Cloudflare DNS (anycast); 1.1.1.0/24 is APNIC's research prefix, DB-IP says AU
    ['81.2.69.142', 'GB'],         // UK ISP address (also used as a GB sample in other geo tests)
    ['212.58.244.20', 'GB'],       // BBC, London
    ['193.99.144.80', 'DE'],       // heise.de, Hannover
    ['217.160.0.1', 'DE'],         // IONOS, Karlsruhe
    ['133.242.0.1', 'JP'],         // Sakura Internet
    ['210.140.92.1', 'JP'],        // IDC Frontier
    ['200.160.2.3', 'BR'],         // NIC.br
    ['177.0.0.1', 'BR'],           // Brazilian ISP block
    ['1.128.0.1', 'AU'],           // Telstra mobile
    ['203.2.218.1', 'AU'],         // Australian block
    ['2a00:1450:4001::1', 'DE'],   // Google Frankfurt
    ['2001:240::1', 'JP'],         // IIJ
    ['2804:14c::1', 'BR'],         // Brazilian ISP
    ['2001:44b8::1', 'AU'],        // Internode
    ['2a02:8000::1', 'DE'],        // German ISP
    ['2001:67c:2e8::1', 'NL'],     // RIPE NCC, Amsterdam
    ['2600::1', 'US'],             // Sprint / T-Mobile US
    ['::ffff:8.8.8.8', 'US'],      // IPv4-mapped IPv6 is looked up as IPv4
    ['2001:4860:4860::8888', 'CA'], // anycast; DB-IP Lite says CA (others say US): documents the source, not a bug here
    // range edges (first and last address of neighboring DB-IP rows)
    ['1.0.0.0', 'AU'], ['1.0.0.255', 'AU'], ['1.0.1.0', 'CN'], ['0.255.255.255', null],
    // private, loopback, link-local, shared, documentation, multicast, reserved: always unknown
    ['10.1.2.3', null], ['172.16.5.4', null], ['172.31.255.255', null], ['192.168.1.1', null], ['127.0.0.1', null],
    ['100.64.1.1', null], ['169.254.1.1', null], ['0.0.0.0', null], ['192.0.2.1', null], ['198.51.100.7', null],
    ['203.0.113.5', null], ['198.18.0.1', null], ['224.0.0.251', null], ['240.0.0.1', null], ['255.255.255.255', null],
    ['::1', null], ['::', null], ['fe80::1', null], ['fc00::1', null], ['fd12:3456::1', null], ['ff02::1', null],
    ['2001:db8::1', null], ['2002:c000:201::1', null], ['2001:0:4136:e378::1', null], ['64:ff9b::808:808', null],
    ['3fff::1', null], ['::ffff:192.168.1.1', null], ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', null],
    // not an IP address
    ['', null], ['abc', null], ['1.2.3', null], ['1.2.3.4.5', null], ['256.1.1.1', null], ['fe80::1%eth0', null],
    [' 8.8.8.8', null], ['8.8.8.8 ', null], [str_repeat('1', 100), null], [null, null], [['8.8.8.8'], null],
];

$pass = 0;
$fail = 0;
foreach ($cases as $c) {
    $got = af_geo_lookup($c[0], $bin);
    $ok = $got === $c[1];
    $ok ? $pass++ : $fail++;
    printf("%s %-42s want %-5s got %s\n", $ok ? 'ok  ' : 'FAIL', json_encode($c[0]), var_export($c[1], true), var_export($got, true));
}

// every code in the database has a name, names are short and plain
$fp = fopen($bin, 'rb');
$hdr = fread($fp, 64);
$n = unpack('Nv4/Nv6', substr($hdr, 8, 8));
$seen = [];
for ($i = 0; $i < $n['v4']; $i++) {
    $seen[substr(fread($fp, 6), 4, 2)] = 1;
}
for ($i = 0; $i < $n['v6']; $i++) {
    $seen[substr(fread($fp, 10), 8, 2)] = 1;
}
fclose($fp);
unset($seen['ZZ']);
$unnamed = array_diff(array_keys($seen), array_keys($names));
$check = [
    'database header tag ' . trim(substr($hdr, 16, 32), "\0") => strpos($hdr, 'dbip-country-lite-') === 16,
    'codes in database (' . count($seen) . ') all have names' => !$unnamed,
    'names list has 250 entries (249 ISO 3166-1 + XK)' => count($names) === 250,
    'US is "United States"' => isset($names['US']) && $names['US'] === 'United States',
    'every name is 1 to 40 characters' => count(array_filter($names, function ($v) { return !is_string($v) || $v === '' || strlen($v) > 40; })) === 0,
    'missing database returns false' => af_geo_lookup('8.8.8.8', $bin . '.missing') === false,
];
$tmp = tempnam(sys_get_temp_dir(), 'geo');
file_put_contents($tmp, substr(file_get_contents($bin), 0, 100000));
$check['truncated database returns false'] = af_geo_lookup('8.8.8.8', $tmp) === false;
file_put_contents($tmp, 'XXXXXXXX' . substr(file_get_contents($bin), 8));
$check['wrong magic returns false'] = af_geo_lookup('8.8.8.8', $tmp) === false;
unlink($tmp);
foreach ($check as $label => $ok) {
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? 'ok  ' : 'FAIL', $label);
}
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
