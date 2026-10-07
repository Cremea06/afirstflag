<?php
/**
 * Countries seen so far, for the "Remaining countries" pill (afirstflag.com shop, CHG-077). Used by api/geo-country.php.
 * Store: data/countries-visited.json = {"countries":["AU","US",...]}, sorted ISO 3166-1 alpha-2 codes and nothing else.
 *   No IP addresses, no times, no counts, nothing per visitor. Created on the first new country; data/.htaccess denies
 *   HTTP access; gitignored. Delete the file to start the count again.
 * Only codes in the official ISO 3166-1 alpha-2 list (249 codes, the same 249 as the "Countries: 249" pill) are kept.
 *   XK (Kosovo, user-assigned), EU, ZZ, AP and anything else are never written.
 * Writes are rare: only when a code is new (or the file needs repair). The common path is one lock-free read; the file
 *   is always replaced whole with rename(), so a reader sees the old or the new file, never half of one.
 * Writers take an exclusive flock on data/countries-visited.lock, read again, add, write a temp file, rename it.
 * A damaged file is repaired on the next request that can write: any valid codes still in it are kept.
 * PHP 7.0+.
 */

const AF_ISO_TOTAL = 249;
// ISO 3166-1 alpha-2, all 249 officially assigned codes (checked against Debian iso-codes 4.18.0).
const AF_ISO_ALPHA2 = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ '
    . 'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR '
    . 'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP '
    . 'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT '
    . 'MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW '
    . 'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG '
    . 'UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

/** Set of the 249 codes, code => true. */
function af_iso_codes() {
    static $set = null;
    if ($set === null) {
        $set = array_fill_keys(explode(' ', AF_ISO_ALPHA2), true);
    }
    return $set;
}

/** True only for one of the 249 official codes, exactly as written ('US', not 'us'). */
function af_iso_valid($cc) {
    if (!is_string($cc) || strlen($cc) !== 2) {
        return false;
    }
    $set = af_iso_codes();
    return isset($set[$cc]);
}

/** The exact bytes the store holds for a set: {"countries":[...sorted codes...]} and a newline. */
function af_visited_encode($set) {
    $codes = array_keys($set);
    sort($codes, SORT_STRING);
    return json_encode(['countries' => $codes]) . "\n";
}

/**
 * Parse the store. $raw is the file contents, or false when there is no file.
 * Returns [set, clean]: set = code => true (valid codes only); clean = false when the file exists but is not exactly
 * what af_visited_encode() would write (damaged, hand-edited, or holding invalid codes), so it should be rewritten.
 * A damaged file keeps every valid quoted code it still contains, e.g. a cut-off {"countries":["US","CA" keeps US and CA.
 */
function af_visited_parse($raw) {
    if ($raw === false) {
        return [[], true];
    }
    $set = [];
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($data) && isset($data['countries']) && is_array($data['countries'])) {
        foreach ($data['countries'] as $cc) {
            if (is_string($cc) && af_iso_valid(strtoupper(trim($cc)))) {
                $set[strtoupper(trim($cc))] = true;
            }
        }
    } elseif (is_string($raw) && preg_match_all('/"([A-Z]{2})"/', $raw, $m)) {
        foreach ($m[1] as $cc) {
            if (af_iso_valid($cc)) {
                $set[$cc] = true;
            }
        }
    }
    return [$set, is_string($raw) && $raw === af_visited_encode($set)];
}

/** Read the store without a lock (it is only ever replaced whole, by rename). false = no file, null = could not read. */
function af_visited_read($path) {
    clearstatcache(true, $path);
    if (!file_exists($path)) {
        return false;
    }
    $raw = is_file($path) ? @file_get_contents($path, false, null, 0, 65536) : false;
    return is_string($raw) ? $raw : null;
}

/** Create data/ and its deny-all .htaccess if missing (same rule as the committed data/.htaccess). */
function af_visited_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to live-stats store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

/**
 * Note that a visitor came from $cc (only if it is one of the 249 codes and $mayWrite is true) and return
 * ['visited' => n, 'isNew' => bool]. isNew is true only for the one request that added the code.
 * Never throws; if the store cannot be written, the count is what could be read and isNew is false.
 * If the file exists but cannot be read, visited is null and nothing is written (so it is never overwritten blind).
 */
function af_visited_note($dataDir, $cc, $mayWrite) {
    $path = $dataDir . '/countries-visited.json';
    $raw = af_visited_read($path);
    if ($raw === null) {
        return ['visited' => null, 'isNew' => false];
    }
    list($set, $clean) = af_visited_parse($raw);
    $add = af_iso_valid($cc) && !isset($set[$cc]);
    if (!$mayWrite || (!$add && $clean)) {
        return ['visited' => count($set), 'isNew' => false];
    }
    if (!af_visited_ensure_dir($dataDir)) {
        return ['visited' => count($set), 'isNew' => false];
    }
    $lock = @fopen($dataDir . '/countries-visited.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if ($lock !== false) {
            fclose($lock);
        }
        return ['visited' => count($set), 'isNew' => false];
    }
    // Read again under the lock: another request may have added codes since the first read.
    $raw = af_visited_read($path);
    if ($raw === null) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return ['visited' => null, 'isNew' => false];
    }
    list($set, $clean) = af_visited_parse($raw);
    $isNew = af_iso_valid($cc) && !isset($set[$cc]);
    if ($isNew) {
        $set[$cc] = true;
    }
    if ($isNew || !$clean) {
        $json = af_visited_encode($set);
        $tmp = $path . '.tmp';
        $ok = @file_put_contents($tmp, $json) === strlen($json) && @rename($tmp, $path);
        if (!$ok) {
            @unlink($tmp);
            if ($isNew) {
                unset($set[$cc]);
                $isNew = false;
            }
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return ['visited' => count($set), 'isNew' => $isNew];
}
