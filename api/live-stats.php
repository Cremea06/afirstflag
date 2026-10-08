<?php
/**
 * Homepage page-load counter (afirstflag.com shop; bot tally and per-address limit CHG-082).
 * GET ?hit=1 - count one homepage visit and return the numbers (JSON, always 200 unless the store is broken):
 *              a bot user agent adds 1 to botHits instead (never to homeVisits);
 *              a hit from an address already counted in the last 30 minutes adds 1 to limited instead.
 * GET / HEAD - read-only JSON { homeVisits, since, updatedAt, botHits, limited, botSince }. HEAD never counts.
 * homeVisits   visits counted since `since` (updatedAt = time of the last counted visit)
 * botHits      ?hit=1 requests skipped because the user agent looks like a bot (CHG-082)
 * limited      ?hit=1 requests not counted because the same address was counted in the last 30 minutes (CHG-082)
 * botSince     when botHits and limited started (the first counting request after CHG-082 went live)
 * Only clients that request ?hit=1 can be counted at all. The homepage script does that once per browser; crawlers that
 * do not run JavaScript never do, so botHits is a small sample of bot traffic, not all of it.
 * Per-address limit (CHG-082): each address (REMOTE_ADDR only; IPv6 grouped by its /64) counts at most one visit and,
 * separately, one bot hit per 30 minutes. Visitors behind one shared address (office, school, carrier NAT) are counted
 * once per 30 minutes between them.
 * Store (data/.htaccess denies HTTP access):
 *   data/live-stats.json        the numbers above (tracked in git since before CHG-082 and written here; never commit it)
 *   data/live-stats-hits.json   {"<key>": [last counted visit, last counted bot hit]} unix seconds, pruned after
 *                               30 minutes, at most 2000 keys. key = HMAC-SHA256(salt, address)[:32]: no raw IPs,
 *                               no user agents
 *   data/live-stats-salt.php    random salt for the address hash (a .php file, so it never prints)
 *   data/live-stats.lock        empty flock target; *.tmp only during a write; live-stats.json.bad only after damage
 * The last four are gitignored and created on the first counting request (data/ and its .htaccess too, if missing).
 * Writers take an exclusive flock on data/live-stats.lock, read again, change, write a temp file and rename it over the
 * store, so a reader without the lock sees the old or the new file, never half of one. A file in the older format
 * (homeVisits, since, updatedAt only) is read as is and gains botHits, limited and botSince on the next counting
 * request; homeVisits, since and updatedAt are kept exactly. A damaged file keeps every number it still holds (a copy
 * of it is saved as live-stats.json.bad first); a file that exists but cannot be read is never overwritten.
 * PHP 7.0+.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-LiteSpeed-Cache-Control: no-cache'); // LiteSpeed (Namecheap): never cache a counting answer on the server

const AF_LS_RL_WINDOW_SEC = 1800; // an address counts at most one visit (and one bot hit) per 30 minutes
const AF_LS_RL_MAX_KEYS = 2000;   // keeps the hits file small (about 100 KB at most)
const AF_LS_MAX_READ = 262144;    // bytes; the stats file is about 200 bytes, the hits file at most about 100 KB

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$dataDir = dirname(__DIR__) . '/data';
$path = $dataDir . '/live-stats.json';
$hitsPath = $dataDir . '/live-stats-hits.json';
$saltPath = $dataDir . '/live-stats-salt.php';
$lockPath = $dataDir . '/live-stats.lock';
$hit = $method === 'GET' && isset($_GET['hit']) && is_string($_GET['hit']) && $_GET['hit'] === '1';

function af_live_now() {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function af_is_bot_ua($ua) {
    if ($ua === '') {
        return false;
    }
    return (bool) preg_match(
        '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|embedly|quora|pinterest|redditbot|whatsapp|telegram|discord|preview|headless|curl|wget|python-requests|scrapy/i',
        $ua
    );
}

/** A whole number 0 or more from a stored value, else null. */
function af_live_count($v) {
    if (is_int($v)) {
        return $v >= 0 ? $v : 0;
    }
    if (is_float($v) || (is_string($v) && preg_match('/^\s*-?[0-9]+(\.[0-9]+)?\s*$/', $v))) {
        $n = (int) $v;
        return $n >= 0 ? $n : 0;
    }
    return null;
}

function af_live_stamp($v) {
    return (is_string($v) && $v !== '' && strlen($v) <= 64) ? $v : null;
}

/**
 * Parse the stats file. $raw is its contents, or false when there is no file.
 * Returns [data, damaged]. data always has all six keys. damaged = true when the file has content that is not a JSON
 * object; every number and stamp still found in it is kept. Missing botHits / limited (the older format) read as 0.
 */
function af_live_parse($raw) {
    $now = af_live_now();
    $d = ['homeVisits' => 0, 'since' => $now, 'updatedAt' => null, 'botHits' => 0, 'limited' => 0, 'botSince' => null];
    $damaged = false;
    $src = null;
    if (is_string($raw) && trim($raw) !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $src = $j;
        } else {
            $damaged = true;
            $src = [];
            if (preg_match_all('/"(homeVisits|botHits|limited)"\s*:\s*([0-9]+)/', $raw, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    if (!isset($src[$x[1]])) {
                        $src[$x[1]] = $x[2];
                    }
                }
            }
            if (preg_match_all('/"(since|updatedAt|botSince)"\s*:\s*"([^"\\\\]{1,64})"/', $raw, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    if (!isset($src[$x[1]])) {
                        $src[$x[1]] = $x[2];
                    }
                }
            }
        }
    }
    if (is_array($src)) {
        foreach (['homeVisits', 'botHits', 'limited'] as $k) {
            $n = isset($src[$k]) ? af_live_count($src[$k]) : null;
            if ($n !== null) {
                $d[$k] = $n;
            }
        }
        foreach (['since', 'updatedAt', 'botSince'] as $k) {
            $s = isset($src[$k]) ? af_live_stamp($src[$k]) : null;
            if ($s !== null) {
                $d[$k] = $s;
            }
        }
    }
    if ($d['updatedAt'] === null) {
        $d['updatedAt'] = $d['since'];
    }
    return [$d, $damaged];
}

/** Read a store file without a lock (it is only ever replaced whole, by rename). false = no file, null = cannot read. */
function af_live_read($p) {
    clearstatcache(true, $p);
    if (!file_exists($p)) {
        return false;
    }
    $raw = is_file($p) ? @file_get_contents($p, false, null, 0, AF_LS_MAX_READ) : false;
    return is_string($raw) ? $raw : null;
}

/** Write $bytes to $p.tmp, then rename it over $p. */
function af_live_write($p, $bytes) {
    $tmp = $p . '.tmp';
    if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $p)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function af_live_encode($d) {
    $out = [
        'homeVisits' => (int) $d['homeVisits'],
        'since' => (string) $d['since'],
        'updatedAt' => (string) $d['updatedAt'],
        'botHits' => (int) $d['botHits'],
        'limited' => (int) $d['limited'],
    ];
    if ($d['botSince'] !== null) {
        $out['botSince'] = (string) $d['botSince'];
    }
    return $out;
}

function af_live_respond($d, $code = 200, $error = null) {
    http_response_code($code);
    $out = af_live_encode($d);
    if (!isset($out['botSince'])) {
        $out['botSince'] = null;
    }
    if ($error !== null) {
        $out['error'] = $error;
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Create data/ and its deny-all .htaccess if missing (same rule as the committed data/.htaccess). */
function af_live_ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# Block direct HTTP access to live-stats store\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", LOCK_EX);
    }
    return is_dir($dir) && is_writable($dir);
}

/** Salt for the address hash; created once (call with the lock held). */
function af_live_salt($p) {
    $salt = is_file($p) ? @include $p : null;
    if (is_string($salt) && preg_match('/^[0-9a-f]{64}$/', $salt)) {
        return $salt;
    }
    $salt = bin2hex(random_bytes(32));
    $php = "<?php\n// CHG-082: random salt for hashing visitor addresses (visit counter limit). Created by api/live-stats.php. Never commit.\nreturn '" . $salt . "';\n";
    return af_live_write($p, $php) ? $salt : null;
}

/** The address as counted: IPv4 as is, IPv6 by its /64 (one home or phone), IPv4-mapped IPv6 as IPv4. */
function af_live_addr($ip) {
    $bin = @inet_pton($ip);
    if ($bin === false || $bin === null) {
        return 'x:' . $ip;
    }
    if (strlen($bin) === 16) {
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return '4:' . inet_ntop(substr($bin, 12));
        }
        return '6:' . bin2hex(substr($bin, 0, 8));
    }
    return '4:' . inet_ntop($bin);
}

// ---------- plain GET / HEAD: read-only, no lock, no write
if (!$hit) {
    $raw = af_live_read($path);
    if ($raw === null) {
        list($d) = af_live_parse(false);
        af_live_respond($d, 503, 'Cannot read live-stats store');
    }
    list($d) = af_live_parse($raw);
    af_live_respond($d);
}

// ---------- GET ?hit=1: count under the lock
if (!af_live_ensure_dir($dataDir)) {
    list($d) = af_live_parse(af_live_read($path));
    af_live_respond($d, 503, 'Live-stats store unavailable');
}
$lf = @fopen($lockPath, 'c');
if ($lf === false || !flock($lf, LOCK_EX)) {
    if ($lf !== false) {
        fclose($lf);
    }
    list($d) = af_live_parse(af_live_read($path));
    af_live_respond($d, 503, 'Cannot lock live-stats store');
}

$raw = af_live_read($path);
if ($raw === null) {
    flock($lf, LOCK_UN);
    fclose($lf);
    list($d) = af_live_parse(false);
    af_live_respond($d, 503, 'Cannot read live-stats store');
}
list($d, $damaged) = af_live_parse($raw);
if ($damaged) {
    @file_put_contents($path . '.bad', $raw); // keep the damaged file for a look by hand (overwrites an older copy)
}
$now = time();
if ($d['botSince'] === null) {
    $d['botSince'] = af_live_now(); // older file (before CHG-082) or a new store: the bot and limited tallies start now
}

$salt = af_live_salt($saltPath);
if ($salt === null) {
    flock($lf, LOCK_UN);
    fclose($lf);
    af_live_respond($d, 503, 'Live-stats store unavailable');
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$key = substr(hash_hmac('sha256', af_live_addr($ip), $salt), 0, 32);
unset($ip);

$rawHits = af_live_read($hitsPath);
$hits = is_string($rawHits) && trim($rawHits) !== '' ? json_decode($rawHits, true) : null;
$kept = [];
if (is_array($hits)) {
    foreach ($hits as $k => $pair) {
        if (!is_string($k) || !preg_match('/^[0-9a-f]{32}$/', $k) || !is_array($pair)) {
            continue;
        }
        $v = isset($pair[0]) && is_int($pair[0]) && $now - $pair[0] < AF_LS_RL_WINDOW_SEC && $pair[0] <= $now + 5 ? $pair[0] : 0;
        $b = isset($pair[1]) && is_int($pair[1]) && $now - $pair[1] < AF_LS_RL_WINDOW_SEC && $pair[1] <= $now + 5 ? $pair[1] : 0;
        if ($v || $b) {
            $kept[$k] = [$v, $b];
        }
    }
}

$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
$slot = af_is_bot_ua($ua) ? 1 : 0;
unset($ua);
$mine = isset($kept[$key]) ? $kept[$key] : [0, 0];
$counted = $mine[$slot] === 0;
if ($counted) {
    $mine[$slot] = $now;
    $kept[$key] = $mine;
    if ($slot === 0) {
        $d['homeVisits'] += 1;
        $d['updatedAt'] = af_live_now();
    } else {
        $d['botHits'] += 1;
    }
} else {
    $d['limited'] += 1;
}

if (!af_live_write($path, json_encode(af_live_encode($d), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n")) {
    flock($lf, LOCK_UN);
    fclose($lf);
    list($old) = af_live_parse($raw);
    af_live_respond($old, 503, 'Cannot write live-stats store');
}
if ($counted || $rawHits === false || !is_array($hits)) {
    if (count($kept) > AF_LS_RL_MAX_KEYS) {
        uasort($kept, function ($a, $b) {
            return max($b) <=> max($a);
        });
        $kept = array_slice($kept, 0, AF_LS_RL_MAX_KEYS, true);
    }
    af_live_write($hitsPath, ($kept ? json_encode($kept, JSON_UNESCAPED_SLASHES) : '{}') . "\n");
}
flock($lf, LOCK_UN);
fclose($lf);

af_live_respond($d);
