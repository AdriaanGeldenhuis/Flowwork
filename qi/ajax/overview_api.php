<?php
// /qi/ajax/overview_api.php
//
// Provide aggregated dashboard metrics for the QI overview. This endpoint is
// consumed by assets/overview.js to populate KPI cards and charts. The data
// returned here should be fast to compute and scoped to the current
// company. To adjust thresholds or add new metrics, update the SQL below.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure we have a valid company context
$companyId = $_SESSION['company_id'] ?? 0;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid company context']);
    exit;
}

try {
    // Active quotes: draft, sent or viewed
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM quotes WHERE company_id = ? AND status IN ('draft','sent','viewed')"
    );
    $stmt->execute([$companyId]);
    $activeQuotes = (int) $stmt->fetchColumn();

    // Overdue invoices
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status = 'overdue'"
    );
    $stmt->execute([$companyId]);
    $overdueInvoices = (int) $stmt->fetchColumn();

    // Pending invoices: sent or viewed (not yet paid/overdue)
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status IN ('sent','viewed')"
    );
    $stmt->execute([$companyId]);
    $pendingInvoices = (int) $stmt->fetchColumn();

    // Active recurring invoices
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM recurring_invoices WHERE company_id = ? AND active = 1"
    );
    $stmt->execute([$companyId]);
    $recurringInvoices = (int) $stmt->fetchColumn();

    // Total credit notes
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM credit_notes WHERE company_id = ?"
    );
    $stmt->execute([$companyId]);
    $creditNotes = (int) $stmt->fetchColumn();

    // Monthly revenue for last 12 months (including current month). Only paid invoices count.
    $stmt = $DB->prepare(
        "SELECT DATE_FORMAT(paid_at, '%Y-%m') as ym, SUM(total) as revenue
         FROM invoices
         WHERE company_id = ? AND status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY ym
         ORDER BY ym"
    );
    $stmt->execute([$companyId]);
    $revRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Build an ordered array of the last 12 months for chart labels. We use
    // strtotime trickery to generate a date string for each month, then
    // extract the label and value from the query result (falling back to 0).
    $revenueLabels = [];
    $revenueData   = [];
    for ($i = 11; $i >= 0; $i--) {
        $date = new DateTime("-{$i} months");
        $key  = $date->format('Y-m');
        $revenueLabels[] = $date->format('M');
        $revenueData[]   = isset($revRaw[$key]) ? (float) $revRaw[$key] : 0;
    }

    // Quote conversion for last 12 months: accepted or converted vs total quotes
    $stmt = $DB->prepare(
        "SELECT DATE_FORMAT(issue_date, '%Y-%m') as ym,
                SUM(CASE WHEN status IN ('accepted','converted') THEN 1 ELSE 0 END) as won,
                COUNT(*) as total
         FROM quotes
         WHERE company_id = ? AND issue_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY ym
         ORDER BY ym"
    );
    $stmt->execute([$companyId]);
    $convRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Index by year-month for quick lookup
    $convMap = [];
    foreach ($convRaw as $row) {
        $convMap[$row['ym']] = [ (int)$row['won'], (int)$row['total'] ];
    }
    $conversionWon   = [];
    $conversionTotal = [];
    foreach ($revenueLabels as $idx => $monthLabel) {
        // Determine matching YM key for the same month/year
        $date = new DateTime("-" . (11 - $idx) . " months");
        $ym = $date->format('Y-m');
        if (isset($convMap[$ym])) {
            $conversionWon[]   = $convMap[$ym][0];
            $conversionTotal[] = $convMap[$ym][1];
        } else {
            $conversionWon[]   = 0;
            $conversionTotal[] = 0;
        }
    }

    // Month-to-date actual revenue (paid invoices). Use the current month range.
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(total),0) FROM invoices
         WHERE company_id = ? AND status = 'paid'
           AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND paid_at <= CURDATE()"
    );
    $stmt->execute([$companyId]);
    $mtdActual = (float) $stmt->fetchColumn();

    // Target revenue for MTD. Use a company setting if available; otherwise 0.
    // Attempt to read from company_settings table (if exists) or fallback.
    $mtdTarget = 0;
    try {
        $targetStmt = $DB->prepare(
            "SELECT value FROM company_settings WHERE company_id = ? AND name = 'qi_dashboard_monthly_target_cents'"
        );
        $targetStmt->execute([$companyId]);
        $value = $targetStmt->fetchColumn();
        if ($value !== false) {
            $mtdTarget = ((float) $value) / 100;
        }
    } catch (Exception $e) {
        // Table might not exist; ignore
        $mtdTarget = 0;
    }

    // Sales analytics: top customers by invoice total over last 30 days
    $stmt = $DB->prepare(
        "SELECT ca.name as customer, COUNT(*) as invoices, SUM(i.total) as total
         FROM invoices i
         JOIN crm_accounts ca ON ca.id = i.customer_id
         WHERE i.company_id = ? AND i.issue_date >= CURDATE() - INTERVAL 30 DAY
         GROUP BY ca.id, ca.name
         ORDER BY total DESC
         LIMIT 8"
    );
    $stmt->execute([$companyId]);
    $salesTable = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Region breakdown: customers by region
    $stmt = $DB->prepare(
        "SELECT COALESCE(a.region, 'Unknown') as region, COUNT(*) as customers
         FROM crm_accounts c
         LEFT JOIN crm_addresses a ON a.account_id = c.id AND a.type = 'head_office'
         WHERE c.company_id = ? AND c.type = 'customer'
         GROUP BY region"
    );
    $stmt->execute([$companyId]);
    $regionData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Volume vs Service: compare number of invoices and quotes in the last 30 days
    $stmt = $DB->prepare(
        "SELECT 'Invoices' as name, COUNT(*) as value FROM invoices WHERE company_id = ? AND issue_date >= CURDATE() - INTERVAL 30 DAY
         UNION ALL
         SELECT 'Quotes' as name, COUNT(*) as value FROM quotes WHERE company_id = ? AND issue_date >= CURDATE() - INTERVAL 30 DAY"
    );
    $stmt->execute([$companyId, $companyId]);
    $volumeData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'kpis' => [
            'active_quotes'     => $activeQuotes,
            'overdue_invoices'  => $overdueInvoices,
            'pending_invoices'  => $pendingInvoices,
            'recurring_invoices'=> $recurringInvoices,
            'credit_notes'      => $creditNotes,
        ],
        'revenue_monthly' => [
            'labels' => $revenueLabels,
            'series' => $revenueData,
        ],
        'quote_conversion' => [
            'labels' => $revenueLabels,
            'won'    => $conversionWon,
            'total'  => $conversionTotal,
        ],
        'mtd' => [
            'actual' => $mtdActual,
            'target' => $mtdTarget,
        ],
        'sales_table' => $salesTable,
        'region' => $regionData,
        'volume' => $volumeData,
    ]);

} catch (Exception $e) {
    error_log('QI overview_api error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load overview']);
}