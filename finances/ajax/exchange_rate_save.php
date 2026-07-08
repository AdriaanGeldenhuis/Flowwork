<?php
// /finances/ajax/exchange_rate_save.php
// Save or update an exchange rate.

$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
}

require_once __DIR__ . '/../lib/Csrf.php';
// Single source of truth for valid ISO currency codes (shared with the invoice
// form). Guarded so a layout without qi/ still loads (falls back to length check).
$curLib = __DIR__ . '/../../qi/lib/Currencies.php';
if (file_exists($curLib)) { require_once $curLib; }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$currencyCode = strtoupper(trim($input['currency_code'] ?? ''));
$rateDate     = $input['rate_date'] ?? '';
$rateToZAR    = isset($input['rate_to_zar']) ? (float)$input['rate_to_zar'] : 0;

// Currency: validate against the ISO whitelist when available, and never store a
// rate for the base currency (ZAR is always 1 and is ignored by getRate()).
if (class_exists('Currencies')) {
    if (!Currencies::isValid($currencyCode) || $currencyCode === Currencies::BASE) {
        echo json_encode(['ok' => false, 'error' => 'Invalid currency code']);
        exit;
    }
} elseif (!$currencyCode || strlen($currencyCode) !== 3 || $currencyCode === 'ZAR') {
    echo json_encode(['ok' => false, 'error' => 'Invalid currency code']);
    exit;
}
// Date: shape + real calendar date (rejects 2026-13-45, 2026-02-30, 0000-00-00).
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rateDate, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date']);
    exit;
}
// Rate: positive AND within a sane band. A fat-finger like 1850 instead of 18.50
// would silently ~100x every amount that later resolves this rate, so bound it.
if ($rateToZAR <= 0 || $rateToZAR > 100000) {
    echo json_encode(['ok' => false, 'error' => 'Rate must be a positive number within a sensible range']);
    exit;
}

try {
    $stmt = $DB->prepare(
        "INSERT INTO gl_exchange_rates (company_id, currency_code, rate_date, rate_to_zar, source)
         VALUES (?, ?, ?, ?, 'manual')
         ON DUPLICATE KEY UPDATE rate_to_zar = VALUES(rate_to_zar), source = 'manual'"
    );
    $stmt->execute([$companyId, $currencyCode, $rateDate, $rateToZAR]);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('Exchange rate save error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Could not save exchange rate' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}
