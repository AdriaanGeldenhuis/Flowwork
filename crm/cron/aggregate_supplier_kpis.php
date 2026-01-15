<?php
// Cron script to aggregate supplier performance KPIs.
// Calculates on‑time delivery percentage, average response hours and
// defect rate for each supplier account and writes the values into
// crm_accounts.on_time_percent, avg_response_hours and
// defect_rate_percent.

require_once __DIR__ . '/../init.php';

// This script does not rely on a logged‑in session. It should be
// executed via CLI or a cron job.

try {
    // Fetch all supplier accounts across all companies.
    $stmtSuppliers = $DB->prepare(
        "SELECT id, company_id FROM crm_accounts WHERE type = 'supplier'"
    );
    $stmtSuppliers->execute();
    $suppliers = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC);

    // Prepare statements for metric calculations.
    // Purchase order count per supplier.
    $stmtPoCount = $DB->prepare(
        "SELECT COUNT(*) AS po_count FROM purchase_orders
         WHERE company_id = ? AND supplier_id = ?"
    );
    // Goods received note count per supplier.
    $stmtGrnCount = $DB->prepare(
        "SELECT COUNT(*) AS grn_count FROM purchase_orders p
          JOIN goods_received_notes g
            ON g.po_id = p.id AND g.company_id = p.company_id
         WHERE p.company_id = ? AND p.supplier_id = ?"
    );
    // Average hours between PO creation and goods received date.
    $stmtAvgHours = $DB->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, p.created_at, g.received_date)) AS avg_hours
         FROM purchase_orders p
          JOIN goods_received_notes g
            ON g.po_id = p.id AND g.company_id = p.company_id
         WHERE p.company_id = ? AND p.supplier_id = ?
           AND g.received_date IS NOT NULL"
    );
    // Defect counts: cancelled GRNs vs total GRNs per supplier.
    $stmtDefects = $DB->prepare(
        "SELECT
             SUM(CASE WHEN g.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
             COUNT(*) AS total
         FROM purchase_orders p
          JOIN goods_received_notes g
            ON g.po_id = p.id AND g.company_id = p.company_id
         WHERE p.company_id = ? AND p.supplier_id = ?"
    );
    // Update statement for crm_accounts.
    $stmtUpdate = $DB->prepare(
        "UPDATE crm_accounts
            SET on_time_percent = ?, avg_response_hours = ?, defect_rate_percent = ?
          WHERE company_id = ? AND id = ?"
    );

    foreach ($suppliers as $supplier) {
        $companyId = (int)$supplier['company_id'];
        $accountId = (int)$supplier['id'];

        // Compute purchase order count.
        $stmtPoCount->execute([$companyId, $accountId]);
        $poRow = $stmtPoCount->fetch(PDO::FETCH_ASSOC);
        $poCount = (int)($poRow['po_count'] ?? 0);

        // Compute goods received count.
        $stmtGrnCount->execute([$companyId, $accountId]);
        $grnRow = $stmtGrnCount->fetch(PDO::FETCH_ASSOC);
        $grnCount = (int)($grnRow['grn_count'] ?? 0);

        // On‑time percentage: ratio of GRNs to POs. If no POs, leave NULL.
        $onTime = null;
        if ($poCount > 0) {
            $ratio = $grnCount / $poCount;
            $onTime = $ratio > 1 ? 100.0 : round($ratio * 100, 2);
        }

        // Average response hours: average time from PO creation to GRN received.
        $stmtAvgHours->execute([$companyId, $accountId]);
        $avgRow = $stmtAvgHours->fetch(PDO::FETCH_ASSOC);
        $avgHours = $avgRow && $avgRow['avg_hours'] !== null ? round((float)$avgRow['avg_hours'], 2) : null;

        // Defect rate percent: cancelled GRNs / total GRNs.
        $defectRate = null;
        $stmtDefects->execute([$companyId, $accountId]);
        $defRow = $stmtDefects->fetch(PDO::FETCH_ASSOC);
        $totalGrns = (int)($defRow['total'] ?? 0);
        if ($totalGrns > 0) {
            $cancelled = (int)($defRow['cancelled_count'] ?? 0);
            $defectRate = round(($cancelled / $totalGrns) * 100, 2);
        }

        // Perform the update. Use NULL where there is no value.
        $stmtUpdate->execute([
            $onTime,
            $avgHours,
            $defectRate,
            $companyId,
            $accountId
        ]);
    }

    echo "Supplier KPI aggregation completed.\n";
} catch (Exception $e) {
    // Log and rethrow for cron monitoring.
    error_log('Supplier KPI aggregation error: ' . $e->getMessage());
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
