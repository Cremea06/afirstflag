<?php
/**
 * CHG-077: check geo/visited.php (the countries-seen set behind "Remaining countries") in a temp folder.
 *   php tools/geo-build/test-visited.php
 * Exit code 0 = all passed. Command line only (tools/.htaccess also blocks HTTP access). Never touches data/.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(dirname(__DIR__));
require_once $root . '/geo/visited.php';
$pass = 0;
$fail = 0;
function t($label, $ok) {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? 'ok  ' : 'FAIL', $label);
}
function rmtree($d) {
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$d/$f") ? rmtree("$d/$f") : unlink("$d/$f");
        }
    }
    rmdir($d);
}

// the code list
$codes = array_keys(af_iso_codes());
t('249 codes, unique, 2 capital letters each', count($codes) === 249 && AF_ISO_TOTAL === 249 && count(array_unique($codes)) === 249 && !preg_grep('/^[A-Z]{2}$/', $codes, PREG_GREP_INVERT));
$sorted = $codes;
sort($sorted, SORT_STRING);
t('codes listed in order', $codes === $sorted);
$names = include $root . '/geo/country-names.php';
$diff = array_merge(array_diff(array_keys($names), $codes), array_diff($codes, array_keys($names)));
t('same as geo/country-names.php apart from XK', $diff === ['XK']);
foreach (['US', 'GB', 'AQ', 'AX', 'BQ', 'SS', 'ZW'] as $cc) {
    t("valid: $cc", af_iso_valid($cc));
}
foreach (['XK', 'EU', 'ZZ', 'AP', 'UK', 'A1', 'O1', 'us', 'Us', 'USA', 'U', '', ' US', "US\n", null, false, 0, 12, ['US']] as $cc) {
    t('not valid: ' . json_encode($cc), !af_iso_valid($cc));
}

$dir = sys_get_temp_dir() . '/aff-visited-' . bin2hex(random_bytes(4)) . '/data';
$path = $dir . '/countries-visited.json';

// missing folder and file: created on the first new code, with the deny-all .htaccess
$r = af_visited_note($dir, 'US', true);
t('first code: isNew, visited 1', $r === ['visited' => 1, 'isNew' => true]);
t('data folder and .htaccess created', is_file($dir . '/.htaccess') && strpos(file_get_contents($dir . '/.htaccess'), 'Require all denied') !== false);
t('file holds codes only', file_get_contents($path) === "{\"countries\":[\"US\"]}\n");
$m = filemtime($path);
$ino = fileinode($path);
clearstatcache();
$r = af_visited_note($dir, 'US', true);
t('repeat: isNew false, visited 1', $r === ['visited' => 1, 'isNew' => false]);
clearstatcache();
t('repeat: file not rewritten (same inode)', fileinode($path) === $ino);
$r = af_visited_note($dir, 'GB', true);
t('second code: isNew, visited 2', $r === ['visited' => 2, 'isNew' => true]);
t('codes kept sorted', file_get_contents($path) === "{\"countries\":[\"GB\",\"US\"]}\n");
foreach (['XK', 'EU', 'ZZ', 'AP', 'us', null, '', 'FR '] as $cc) {
    clearstatcache();
    $ino = fileinode($path);
    $r = af_visited_note($dir, $cc, true);
    clearstatcache();
    t('not written: ' . json_encode($cc), $r === ['visited' => 2, 'isNew' => false] && fileinode($path) === $ino);
}
$r = af_visited_note($dir, 'FR', false);
t('mayWrite false (HEAD, 503): new code not written', $r === ['visited' => 2, 'isNew' => false] && strpos(file_get_contents($path), 'FR') === false);
t('no temp file left', !file_exists($path . '.tmp'));

// damaged or odd files
$cases = [
    'cut-off JSON keeps its codes' => ['{"countries":["US","CA"', ['CA', 'US']],
    'garbage' => ["\x00\xffnot json", []],
    'empty file' => ['', []],
    'JSON with invalid codes' => ['{"countries":["US","XK","EU","ZZ",5,null,"Narnia"]}', ['US']],
    'lowercase codes' => ['{"countries":["us","gb"]}', ['GB', 'US']],
    'pretty printed (hand edit)' => ["{\n  \"countries\": [\n    \"JP\",\n    \"DE\"\n  ]\n}\n", ['DE', 'JP']],
    'wrong shape' => ['{"visited":["US"]}', ['US']],
    'JSON list' => ['["US","US","BR"]', ['BR', 'US']],
    'duplicates' => ['{"countries":["US","US"]}', ['US']],
];
foreach ($cases as $label => $c) {
    file_put_contents($path, $c[0]);
    $r = af_visited_note($dir, null, true); // a request with no code still repairs the file
    $want = json_encode(['countries' => $c[1]]) . "\n";
    t("damaged ($label): repaired to " . trim($want), $r === ['visited' => count($c[1]), 'isNew' => false] && file_get_contents($path) === $want);
    file_put_contents($path, $c[0]);
    $r = af_visited_note($dir, 'NZ', true);
    $set = $c[1];
    $set[] = 'NZ';
    sort($set);
    t("damaged ($label): new code added on top", $r === ['visited' => count($set), 'isNew' => true] && file_get_contents($path) === json_encode(['countries' => $set]) . "\n");
}
file_put_contents($path, '{"countries":["US"');
$r = af_visited_note($dir, 'US', false);
t('damaged file, mayWrite false: read only, not repaired', $r === ['visited' => 1, 'isNew' => false] && file_get_contents($path) === '{"countries":["US"');

// all 249, then nothing more
unlink($path);
$news = 0;
foreach ($codes as $cc) {
    $r = af_visited_note($dir, $cc, true);
    $news += $r['isNew'] ? 1 : 0;
}
t('all 249 codes: 249 isNew, visited 249 (remaining 0)', $news === 249 && $r['visited'] === 249);
t('XK after all 249: still 249', af_visited_note($dir, 'XK', true) === ['visited' => 249, 'isNew' => false]);
t('file size stays small (' . filesize($path) . ' bytes)', filesize($path) < 1300);

// cannot write: read-only folder, unreadable path
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    t('skip read-only checks (running as root)', true);
} else {
    file_put_contents($path, "{\"countries\":[\"US\"]}\n");
    chmod($dir, 0555);
    $r = af_visited_note($dir, 'CA', true);
    t('read-only data folder: visited 1, isNew false, file unchanged', $r === ['visited' => 1, 'isNew' => false] && file_get_contents($path) === "{\"countries\":[\"US\"]}\n");
    chmod($dir, 0755);
    chmod($path, 0000);
    $r = af_visited_note($dir, 'CA', true);
    chmod($path, 0644);
    t('unreadable file: visited null, file not overwritten', $r === ['visited' => null, 'isNew' => false] && file_get_contents($path) === "{\"countries\":[\"US\"]}\n");
}
unlink($path);
mkdir($path);
$r = af_visited_note($dir, 'CA', true);
t('store path is a folder: visited null, nothing written', $r === ['visited' => null, 'isNew' => false] && is_dir($path));
rmdir($path);

// missing folder that cannot be created
$r = af_visited_note('/proc/aff-no-such-dir/data', 'US', true);
t('data folder cannot be created: visited 0, isNew false', $r === ['visited' => 0, 'isNew' => false]);

rmtree(dirname($dir));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
