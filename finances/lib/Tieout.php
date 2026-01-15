<?php
// finances/lib/Tieout.php
require_once __DIR__ . '/AsOf.php';

class Tieout
{
    private PDO $db;
    private int $companyId;
    private AsOf $asof;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        $this->asof = new AsOf($db, $companyId);
    }

    // Get GL balance from mapped control accounts (e.g. 'AR', 'AP')
    public function glBalance(string $groupKey, string $asOf): float
    {
        $stmt = $this->db->prepare("
            SELECT account_id
            FROM gl_report_map
            WHERE company_id = ? AND report = 'CF' AND group_key = ?
        ");
        $stmt->execute([$this->companyId, $groupKey]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $this->asof->sumAccounts($ids, $asOf);
    }

    // --- Accounts Receivable subledger total as of a date ---
    public function arSubledger(string $asOf): float
    {
        $inv = (float)$this->scalar("
            SELECT COALESCE(SUM(total),0)
            FROM invoices
            WHERE company_id = ? AND issue_date <= ?
        ", [$this->companyId, $asOf]);

        $pay = (float)$this->scalar("
            SELECT COALESCE(SUM(pa.amount),0)
            FROM payment_allocations pa
            JOIN payments p ON pa.payment_id = p.id
            JOIN invoices i ON pa.invoice_id = i.id
            WHERE i.company_id = ? AND p.payment_date <= ?
        ", [$this->companyId, $asOf]);

        $crn = (float)$this->scalar("
            SELECT COALESCE(SUM(total),0)
            FROM credit_notes
            WHERE company_id = ? AND issue_date <= ? AND status != 'cancelled'
        ", [$this->companyId, $asOf]);

        return round($inv - $pay - $crn, 2);
    }

    // --- Accounts Payable subledger total as of a date ---
    public function apSubledger(string $asOf): float
    {
        $bills = (float)$this->scalar("
            SELECT COALESCE(SUM(total),0)
            FROM ap_bills
            WHERE company_id = ? AND issue_date <= ?
        ", [$this->companyId, $asOf]);

        $pay = (float)$this->scalar("
            SELECT COALESCE(SUM(apa.amount),0)
            FROM ap_payment_allocations apa
            JOIN ap_payments p ON apa.ap_payment_id = p.id
            JOIN ap_bills b ON apa.bill_id = b.id
            WHERE b.company_id = ? AND p.payment_date <= ?
        ", [$this->companyId, $asOf]);

        $vcr = (float)$this->scalar("
            SELECT COALESCE(SUM(total),0)
            FROM vendor_credits
            WHERE company_id = ? AND issue_date <= ? AND status != 'cancelled'
        ", [$this->companyId, $asOf]);

        return round($bills - $pay - $vcr, 2);
    }

    private function scalar(string $sql, array $params)
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
