<?php
/**
 * IP to country lookup in the bundled database (afirstflag.com shop, CHG-076). Used by api/geo-country.php.
 * No network calls: the address is only compared against geo/ip-country.bin on this server.
 * Nothing is logged or stored.
 * geo/ip-country.bin (built by tools/geo-build/build.py from DB-IP IP to Country Lite, CC BY 4.0), big-endian:
 *   header, 64 bytes: "AFFGEO01", IPv4 count (uint32), IPv6 count (uint32), source tag (32 bytes), 16 zero bytes
 *   IPv4 block: count x 6 bytes  = range start (4 bytes) + country code (2 ASCII bytes)
 *   IPv6 block: count x 10 bytes = first 64 bits of range start (8 bytes) + country code (2 ASCII bytes)
 *   Each block is sorted and starts at address 0, so every address falls in exactly one range
 *   (a range ends where the next starts). "ZZ" = unknown, private or reserved.
 * Starts are compared as raw big-endian bytes (strcmp), so no 64-bit integers are needed.
 * PHP 7.0+.
 */

const AF_GEO_MAGIC = 'AFFGEO01';
const AF_GEO_HEADER = 64;
const AF_GEO_V4_REC = 6;
const AF_GEO_V6_REC = 10;

/**
 * Country code for an IP address.
 * Returns a 2-letter code ('US'), null when the address is unknown, private, reserved or not an IP address,
 * or false when the database file is missing or damaged.
 */
function af_geo_lookup($ip, $binPath) {
    $packed = (is_string($ip) && $ip !== '' && strlen($ip) <= 45) ? @inet_pton($ip) : false;
    if ($packed === false) {
        return null;
    }
    if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
        $packed = substr($packed, 12); // IPv4-mapped IPv6 (::ffff:a.b.c.d) is the IPv4 address
    }
    $fp = @fopen($binPath, 'rb');
    if ($fp === false) {
        return false;
    }
    $hdr = fread($fp, AF_GEO_HEADER);
    $st = fstat($fp);
    if (!is_string($hdr) || strlen($hdr) !== AF_GEO_HEADER || substr($hdr, 0, 8) !== AF_GEO_MAGIC || !is_array($st)) {
        fclose($fp);
        return false;
    }
    $n = unpack('Nv4/Nv6', substr($hdr, 8, 8));
    if ($n['v4'] < 1 || $n['v6'] < 1 || $st['size'] !== AF_GEO_HEADER + $n['v4'] * AF_GEO_V4_REC + $n['v6'] * AF_GEO_V6_REC) {
        fclose($fp);
        return false;
    }
    if (strlen($packed) === 4) {
        $key = $packed;
        $rec = AF_GEO_V4_REC;
        $base = AF_GEO_HEADER;
        $count = $n['v4'];
    } else {
        $key = substr($packed, 0, 8);
        $rec = AF_GEO_V6_REC;
        $base = AF_GEO_HEADER + $n['v4'] * AF_GEO_V4_REC;
        $count = $n['v6'];
    }
    $klen = strlen($key);
    stream_set_read_buffer($fp, 0); // each probe reads only the bytes it needs
    // Binary search for the last range whose start <= key (range 0 starts at address 0).
    $lo = 0;
    $hi = $count - 1;
    while ($lo < $hi) {
        $mid = ($lo + $hi + 1) >> 1;
        fseek($fp, $base + $mid * $rec);
        $start = fread($fp, $klen);
        if (!is_string($start) || strlen($start) !== $klen) {
            fclose($fp);
            return false;
        }
        if (strcmp($start, $key) <= 0) {
            $lo = $mid;
        } else {
            $hi = $mid - 1;
        }
    }
    fseek($fp, $base + $lo * $rec + $klen);
    $cc = fread($fp, 2);
    fclose($fp);
    if (!is_string($cc) || !preg_match('/^[A-Z]{2}$/', $cc)) {
        return false;
    }
    return $cc === 'ZZ' ? null : $cc;
}

/** Code to name map (geo/country-names.php); empty array if it cannot be read. */
function af_geo_names($path) {
    $names = is_file($path) ? @include $path : null;
    return is_array($names) ? $names : [];
}
