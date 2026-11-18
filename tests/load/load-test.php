<?php

/**
 * Repeatable HTTP load test with nothing to install (PHP curl only).
 *
 *   php tests/load/load-test.php --base=http://127.0.0.1:8000 --concurrency=20 --seconds=30
 *   php tests/load/load-test.php --base=... --paths=/,/login,/prescribers --cookie="madina_session=..."
 *
 * Run it against a LOCAL server on a COPY of the data (never the live site, never madina_sim itself):
 *   docker exec madina-qa-ab6d68 createdb -U madina -T madina_sim madina_loadtest_fj
 *   DB_DATABASE=madina_loadtest_fj PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8000
 * `php artisan serve` is single-threaded unless PHP_CLI_SERVER_WORKERS is set, so set it for any
 * concurrency above 1. For a signed-in portal run, copy a session cookie from a local sign-in and pass it
 * with --cookie. Prints requests/second, error count and p50/p95/p99 latency per path, as plain text, and
 * with --json=file also writes the numbers for a before/after comparison.
 */
$options = getopt('', ['base::', 'concurrency::', 'seconds::', 'paths::', 'cookie::', 'host::', 'json::']);
$base = rtrim((string) ($options['base'] ?? 'http://127.0.0.1:8000'), '/');
$concurrency = max(1, (int) ($options['concurrency'] ?? 10));
$seconds = max(1, (int) ($options['seconds'] ?? 20));
$paths = array_values(array_filter(explode(',', (string) ($options['paths'] ?? '/,/login,/prescribers,/feedback,/batch-check,/search/products?q=a'))));
$cookie = (string) ($options['cookie'] ?? '');
$hostHeader = (string) ($options['host'] ?? '');

if (preg_match('~(madinanhc\.com|madinanaturalhealthcare)~i', $base)) {
    fwrite(STDERR, "Refusing to load-test a live address. Use a local server on a copy of the data.\n");
    exit(2);
}

$multi = curl_multi_init();
$inFlight = [];
$samples = [];
$errors = [];
$started = microtime(true);
$issued = 0;

$launch = function () use (&$inFlight, $multi, $base, $paths, $cookie, $hostHeader, &$issued): void {
    $path = $paths[$issued++ % count($paths)];
    $handle = curl_init($base.$path);
    $headers = ['Accept: text/html,application/json', 'Accept-Encoding: gzip'];
    if ($hostHeader !== '') {
        $headers[] = 'Host: '.$hostHeader;
    }
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($cookie !== '') {
        curl_setopt($handle, CURLOPT_COOKIE, $cookie);
    }
    curl_multi_add_handle($multi, $handle);
    $inFlight[(int) $handle] = [$handle, $path];
};

for ($i = 0; $i < $concurrency; $i++) {
    $launch();
}
while ($inFlight !== []) {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);
    while (($done = curl_multi_info_read($multi)) !== false) {
        [$handle, $path] = $inFlight[(int) $done['handle']];
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $ms = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
        if ($done['result'] !== CURLE_OK || $code >= 500 || $code === 0) {
            $errors[$path] = ($errors[$path] ?? 0) + 1;
        } else {
            $samples[$path][] = $ms;
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        unset($inFlight[(int) $done['handle']]);
        if (microtime(true) - $started < $seconds) {
            $launch();
        }
    }
}
$elapsed = microtime(true) - $started;

$percentile = function (array $values, float $p): float {
    sort($values);

    return $values === [] ? 0.0 : $values[(int) min(count($values) - 1, floor(count($values) * $p))];
};
$report = ['base' => $base, 'concurrency' => $concurrency, 'seconds' => round($elapsed, 1), 'paths' => []];
$total = 0;
printf("%-34s %7s %6s %8s %8s %8s\n", 'path', 'ok', 'err', 'p50 ms', 'p95 ms', 'p99 ms');
foreach ($paths as $path) {
    $values = $samples[$path] ?? [];
    $total += count($values);
    $row = ['ok' => count($values), 'errors' => $errors[$path] ?? 0, 'p50' => round($percentile($values, 0.50)), 'p95' => round($percentile($values, 0.95)), 'p99' => round($percentile($values, 0.99))];
    $report['paths'][$path] = $row;
    printf("%-34s %7d %6d %8d %8d %8d\n", $path, $row['ok'], $row['errors'], $row['p50'], $row['p95'], $row['p99']);
}
$report['requests_per_second'] = round($total / max($elapsed, 0.001), 1);
printf("\n%d successful requests in %.1fs = %.1f requests/second at concurrency %d (%d errors)\n", $total, $elapsed, $report['requests_per_second'], $concurrency, array_sum($errors));
if (isset($options['json'])) {
    file_put_contents((string) $options['json'], json_encode($report, JSON_PRETTY_PRINT));
}
exit(array_sum($errors) > 0 ? 1 : 0);
