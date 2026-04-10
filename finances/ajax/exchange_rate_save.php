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

if (!$currencyCode || strlen($currencyCode) !== 3) {
    echo json_encode(['ok' => false, 'error' => 'Invalid currency code']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rateDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date format']);
    exit;
}
if ($rateToZAR <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Rate must be positive']);
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
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
