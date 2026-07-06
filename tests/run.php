<?php
/**
 * Finance test runner. Each tests/finance/test_*.php runs in its own PHP
 * subprocess against the flowwork_test database (see tests/db/setup.sh).
 * Transactional tables are reset before every test via tests/db/reset.php.
 *
 * Usage:  php tests/run.php [substring-filter]
 */

$root = __DIR__;
$filter = $argv[1] ?? '';
$files = glob($root . '/finance/test_*.php');
sort($files);
if ($filter !== '') {
    $files = array_values(array_filter($files, fn($f) => str_contains(basename($f), $filter)));
}
if (!$files) {
    fwrite(STDERR, "No test files matched.\n");
    exit(1);
}

$failures = [];
$t0 = microtime(true);
foreach ($files as $file) {
    $name = basename($file);
    // Reset DB state, then run the test in a clean subprocess.
    passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/db/reset.php'), $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "FATAL: db reset failed before $name\n");
        exit(1);
    }
    $output = [];
    exec(PHP_BINARY . ' ' . escapeshellarg($file) . ' 2>&1', $output, $rc);
    if ($rc === 0) {
        foreach ($output as $line) {
            echo "  $line\n";
        }
    } else {
        $failures[] = $name;
        echo "  FAIL: $name\n";
        foreach ($output as $line) {
            echo "        $line\n";
        }
    }
}

$dt = number_format(microtime(true) - $t0, 1);
$total = count($files);
$failed = count($failures);
echo "\n" . ($total - $failed) . "/$total test files passed ({$dt}s)\n";
if ($failed) {
    echo "Failed: " . implode(', ', $failures) . "\n";
    exit(1);
}
