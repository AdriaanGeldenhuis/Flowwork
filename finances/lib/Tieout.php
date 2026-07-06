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

    // Get GL balance from mapped control accounts (e.g. 'AR', 'AP').
    // Prefers the finance_* account mapping (always seeded per company by
    // finances/tools/finance_setup.php); falls back to the legacy
    // gl_report_map config where one exists.
    //
    // Returned in the account's NATURAL sign so it compares directly to the
    // subledger: AR is debit-positive, AP is credit-positive. (AsOf sums
    // debit-positive; a healthy AP control used to come back negative, so
    // every non-zero AP tie-out reported a spurious difference.)
    public function glBalance(string $groupKey, string $asOf): float
    {
        require_once __DIR__ . '/AccountsMap.php';
        $map = new AccountsMap($this->db, $this->companyId);
        $settingKey = $groupKey === 'AR' ? 'finance_ar_account_id'
            : ($groupKey === 'AP' ? 'finance_ap_account_id' : null);
        $sign = $groupKey === 'AP' ? -1.0 : 1.0;
        if ($settingKey && ($accountId = $map->getAccountId($settingKey))) {
            return $sign * $this->asof->sumAccounts([$accountId], $asOf);
        }
        $stmt = $this->db->prepare("
            SELECT account_id
            FROM gl_report_map
            WHERE company_id = ? AND report = 'CF' AND group_key = ?
        ");
        $stmt->execute([$this->companyId, $groupKey]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $sign * $this->asof->sumAccounts($ids, $asOf);
    }

    // --- Accounts Receivable subledger total as of a date ---
    // Must mirror what the posting engine puts INTO the AR control, in ZAR:
    //   + invoices (issued, at the invoice's captured rate — s25D)
    //   − receipts: allocations at the INVOICE's rate (the engine relieves AR
    //     at the historic rate; any payment-date rate difference goes to FX
    //     gain/loss, not AR) + the UNALLOCATED remainder of posted payments
    //     (an unapplied receipt keeps its posted Cr AR — it is a customer
    //     credit the subledger must count)
    //   − credit notes with a posted journal, at their rate
    //   − write-offs (the write-off journal credits AR; balance_due already
    //     reflects it, invoices.total does not)
    // Drafts are excluded on both sides: they are not posted (post-on-issue)
    // and are not receivables.
    public function arSubledger(string $asOf): float
    {
        $fx = "COALESCE(NULLIF(exchange_rate, 0), 1)";

        $inv = (float)$this->scalar("
            SELECT COALESCE(SUM(total * $fx),0)
            FROM invoices
            WHERE company_id = ? AND issue_date <= ?
              AND status NOT IN ('draft','cancelled','void')
              AND deleted_at IS NULL
        ", [$this->companyId, $asOf]);

        $writeOffs = (float)$this->scalar("
            SELECT COALESCE(SUM(write_off_amount * $fx),0)
            FROM invoices
            WHERE company_id = ? AND issue_date <= ?
              AND COALESCE(write_off_amount,0) > 0
              AND status NOT IN ('draft','cancelled','void')
              AND deleted_at IS NULL
        ", [$this->companyId, $asOf]);

        $alloc = (float)$this->scalar("
            SELECT COALESCE(SUM(pa.amount * COALESCE(NULLIF(i.exchange_rate,0),1)),0)
            FROM payment_allocations pa
            JOIN payments p ON pa.payment_id = p.id
            JOIN invoices i ON pa.invoice_id = i.id
            WHERE p.company_id = ? AND p.payment_date <= ?
              AND p.journal_id IS NOT NULL
        ", [$this->companyId, $asOf]);

        $unallocated = (float)$this->scalar("
            SELECT COALESCE(SUM(
                (p.amount - COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa
                                       WHERE pa.payment_id = p.id), 0))
                * COALESCE(NULLIF(p.exchange_rate,0),1)
            ),0)
            FROM payments p
            WHERE p.company_id = ? AND p.payment_date <= ?
              AND p.journal_id IS NOT NULL
        ", [$this->companyId, $asOf]);

        $crn = (float)$this->scalar("
            SELECT COALESCE(SUM(total * $fx),0)
            FROM credit_notes
            WHERE company_id = ? AND issue_date <= ?
              AND journal_id IS NOT NULL
              AND status NOT IN ('draft','cancelled')
        ", [$this->companyId, $asOf]);

        return round($inv - $writeOffs - $alloc - $unallocated - $crn, 2);
    }

    // --- Accounts Payable subledger total as of a date ---
    // Only documents that reached the GL count: posted/paid bills, posted
    // supplier payments (full amount — an unallocated remainder still sits as
    // a Dr AP prepayment), vendor credits with a posted journal. Draft
    // documents used to leak in (draft vendor credits showed spurious
    // differences before ever being posted).
    public function apSubledger(string $asOf): float
    {
        $bills = (float)$this->scalar("
            SELECT COALESCE(SUM(total),0)
            FROM ap_bills
            WHERE company_id = ? AND issue_date <= ?
              AND journal_id IS NOT NULL
              AND status NOT IN ('draft','cancelled','void','blocked')
        ", [$this->companyId, $asOf]);

        $pay = (float)$this->scalar("
            SELECT COALESCE(SUM(amount),0)
            FROM ap_payments
            WHERE company_id = ? AND payment_date <= ?
              AND journal_id IS NOT NULL
        ", [$this->companyId, $asOf]);

        $vcr = (float)$this->scalar("
            SELECT COALESCE(SUM(total),0)
            FROM vendor_credits
            WHERE company_id = ? AND issue_date <= ?
              AND journal_id IS NOT NULL
              AND status != 'cancelled'
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
