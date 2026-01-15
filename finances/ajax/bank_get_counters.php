<?php
// /finances/ajax/bank_get_counters.php
// Returns counts of unmatched bank transactions grouped by bank account and month.
// This can be used to display how many transactions still need matching or
// reconciliation for each account and period. Only authenticated users can
// access this endpoint; permissions are read-only.

// Dynamically load init and auth. Project root is two levels above this file.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}

header('Content-Type: application/json');

// Determine company context
$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Select count of unmatched transactions grouped by bank_account and month (YYYY-MM)
    $stmt = $DB->prepare(
        "SELECT bank_account_id, DATE_FORMAT(tx_date, '%Y-%m') AS period, COUNT(*) AS count
         FROM gl_bank_transactions
         WHERE company_id = ? AND matched = 0
         GROUP BY bank_account_id, period
         ORDER BY bank_account_id, period"
    );
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counters = [];
    $total = 0;
    foreach ($rows as $r) {
        $accId = (int)$r['bank_account_id'];
        $period = $r['period'];
        $count = (int)$r['count'];
        if (!isset($counters[$accId])) {
            $counters[$accId] = [];
        }
        $counters[$accId][$period] = $count;
        $total += $count;
    }
    echo json_encode(['ok' => true, 'total_unmatched' => $total, 'counters' => $counters]);
} catch (Exception $e) {
    error_log('Bank get counters error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}