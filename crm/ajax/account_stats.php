<?php
// /crm/ajax/account_stats.php
// Real per-account analytics for the account Overview charts: last-12-month
// interaction counts, quote/invoice value per month, opportunity pipeline and
// compliance doc status. Replaces the old placeholder charts.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

try {
    if (!$accountId) {
        throw new Exception('account_id is required');
    }

    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    // Last 12 calendar months, oldest first
    $months = [];
    $monthIndex = [];
    for ($i = 11; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("first day of -{$i} months"));
        $monthIndex[$key] = count($months);
        $months[] = $key;
    }
    $rangeStart = $months[0] . '-01';

    $zeroSeries = function () use ($months) {
        return array_fill(0, count($months), 0);
    };

    // Interactions per month
    $interactions = $zeroSeries();
    $stmt = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM crm_interactions
        WHERE company_id = ? AND account_id = ? AND created_at >= ?
        GROUP BY ym
    ");
    $stmt->execute([$companyId, $accountId, $rangeStart]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthIndex[$row['ym']])) {
            $interactions[$monthIndex[$row['ym']]] = (int)$row['cnt'];
        }
    }

    // Quote / invoice value per month (probe tables defensively — same style
    // as account_linked.php; environments without /qi keep zero series)
    $quoteTotals = $zeroSeries();
    try {
        $stmt = $DB->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(total), 0) AS total
            FROM quotes
            WHERE company_id = ? AND customer_id = ? AND created_at >= ?
            GROUP BY ym
        ");
        $stmt->execute([$companyId, $accountId, $rangeStart]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($monthIndex[$row['ym']])) {
                $quoteTotals[$monthIndex[$row['ym']]] = round((float)$row['total'], 2);
            }
        }
    } catch (Throwable $e) {
        $quoteTotals = $zeroSeries();
    }

    $invoiceTotals = $zeroSeries();
    try {
        $stmt = $DB->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(total), 0) AS total
            FROM invoices
            WHERE company_id = ? AND customer_id = ? AND created_at >= ?
            GROUP BY ym
        ");
        $stmt->execute([$companyId, $accountId, $rangeStart]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($monthIndex[$row['ym']])) {
                $invoiceTotals[$monthIndex[$row['ym']]] = round((float)$row['total'], 2);
            }
        }
    } catch (Throwable $e) {
        $invoiceTotals = $zeroSeries();
    }

    // Opportunity pipeline for this account
    $stmt = $DB->prepare("
        SELECT stage, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount
        FROM crm_opportunities
        WHERE company_id = ? AND account_id = ?
        GROUP BY stage
    ");
    $stmt->execute([$companyId, $accountId]);
    $opps = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $opps[] = [
            'stage' => $row['stage'],
            'count' => (int)$row['cnt'],
            'amount' => round((float)$row['amount'], 2),
        ];
    }

    // Compliance docs by effective status (compute from expiry so the chart
    // doesn't depend on the stored status column being fresh)
    $compliance = ['valid' => 0, 'expiring' => 0, 'expired' => 0];
    $stmt = $DB->prepare("
        SELECT expiry_date
        FROM crm_compliance_docs
        WHERE company_id = ? AND account_id = ?
    ");
    $stmt->execute([$companyId, $accountId]);
    $now = new DateTime('today');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (empty($row['expiry_date'])) {
            $compliance['valid']++;
            continue;
        }
        $diff = (int)$now->diff(new DateTime($row['expiry_date']))->format('%r%a');
        if ($diff < 0) {
            $compliance['expired']++;
        } elseif ($diff <= 30) {
            $compliance['expiring']++;
        } else {
            $compliance['valid']++;
        }
    }

    echo json_encode([
        'ok' => true,
        'months' => $months,
        'interactions' => $interactions,
        'quote_totals' => $quoteTotals,
        'invoice_totals' => $invoiceTotals,
        'opps' => $opps,
        'compliance' => $compliance,
    ]);

} catch (Throwable $e) {
    error_log('CRM account_stats error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}
