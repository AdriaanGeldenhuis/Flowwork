<?php
// /qi/ajax/get_exchange_rate.php
// Returns the ZAR exchange rate for a currency on a date (1 unit = X ZAR).
// Order of preference:
//   1. Company's own gl_exchange_rates table (manual/SARB entries win)
//   2. Live fetch from a public rates API, cached into gl_exchange_rates
// If neither source has a rate, rate is null and the UI asks for manual entry.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Currencies.php';
require_once __DIR__ . '/../../finances/lib/CurrencyService.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$currency = strtoupper(trim($_GET['currency'] ?? ''));
$date     = $_GET['date'] ?? date('Y-m-d');

if (!Currencies::isValid($currency)) {
    echo json_encode(['ok' => false, 'error' => 'Unknown currency code']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

if ($currency === Currencies::BASE) {
    echo json_encode(['ok' => true, 'currency' => $currency, 'date' => $date, 'rate' => 1.0, 'source' => 'base']);
    exit;
}

$service = new CurrencyService($DB, (int)$companyId);

// 1) Company's own rates table (exact date, falling back to the most recent before it)
$rate = $service->getRate($currency, $date);
if ($rate !== null && $rate > 0) {
    echo json_encode(['ok' => true, 'currency' => $currency, 'date' => $date, 'rate' => round($rate, 6), 'source' => 'table']);
    exit;
}

// 2) Live API fallback (only useful for current rates; cached for re-use)
$apiRate = fetchLiveRateToZar($currency);
if ($apiRate !== null && $apiRate > 0) {
    try {
        $service->saveRate($currency, date('Y-m-d'), $apiRate, 'api');
    } catch (Exception $e) {
        error_log('Could not cache API exchange rate: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'currency' => $currency, 'date' => $date, 'rate' => round($apiRate, 6), 'source' => 'api']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'currency' => $currency,
    'date'     => $date,
    'rate'     => null,
    'source'   => null,
    'message'  => 'No rate found. Enter the rate manually or add one under Finances → Exchange Rates.',
]);

/**
 * Fetch 1 unit of $currency = X ZAR from a free public API.
 * open.er-api.com covers 160+ ISO codes (incl. CDF, ZWG/ZWL) with no API key.
 */
function fetchLiveRateToZar(string $currency): ?float
{
    $url = 'https://open.er-api.com/v6/latest/' . urlencode($currency);
    $body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Flowwork-QI/1.0',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    }
    if (!$body) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'Flowwork-QI/1.0']]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!$body) {
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['result'] ?? '') !== 'success') {
        return null;
    }
    $zarPerUnit = $data['rates']['ZAR'] ?? null;
    return (is_numeric($zarPerUnit) && $zarPerUnit > 0) ? (float)$zarPerUnit : null;
}
