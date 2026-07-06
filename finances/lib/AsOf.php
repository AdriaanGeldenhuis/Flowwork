<?php
// finances/lib/AsOf.php
// Utilities for "as of" financial reporting.

class AsOf
{
    private PDO $db;
    private int $companyId;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /** Return Y-m-d string, default to today if empty/invalid */
    public static function normalizeDate(?string $s): string
    {
        if (!$s) return date('Y-m-d');
        $t = strtotime($s);
        if ($t === false) return date('Y-m-d');
        return date('Y-m-d', $t);
    }

    /** Trial balance lines: account_id, code, name, debit, credit, balance (debit-positive) */
    public function trialBalance(string $asOf): array
    {
        // journal_lines has no company_id column: lines must be reached
        // THROUGH their (company-scoped) journal_entries row. Joining lines on
        // account_code alone pulls in other tenants' postings, because every
        // company shares the same seeded account codes.
        $sql = "
        SELECT a.account_id, a.account_code, a.account_name,
               COALESCE(t.total_debit, 0)  AS total_debit,
               COALESCE(t.total_credit, 0) AS total_credit
        FROM gl_accounts a
        LEFT JOIN (
            SELECT jl.account_code,
                   SUM(jl.debit)  AS total_debit,
                   SUM(jl.credit) AS total_credit
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = :jcid
              AND je.status = 'posted'
              AND je.entry_date <= :asof
            GROUP BY jl.account_code
        ) t ON t.account_code = a.account_code
        WHERE a.company_id = :cid
        ORDER BY a.account_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':cid'=>$this->companyId, ':jcid'=>$this->companyId, ':asof'=>$asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        $totDeb = 0; $totCred = 0;
        foreach ($rows as $r) {
            $deb = round(floatval($r['total_debit']), 2);
            $cred = round(floatval($r['total_credit']), 2);
            $bal = round($deb - $cred, 2);
            $out[] = [
                'account_id' => (int)$r['account_id'],
                'code' => $r['account_code'],
                'name' => $r['account_name'],
                'debit' => $deb,
                'credit' => $cred,
                'balance' => $bal,
            ];
            $totDeb += $deb; $totCred += $cred;
        }
        return ['rows'=>$out, 'total_debit'=>round($totDeb,2), 'total_credit'=>round($totCred,2)];
    }

    /** Sum balances for a set of account ids as of date, returning debit-positive decimal */
    public function sumAccounts(array $accountIds, string $asOf): float
    {
        if (!$accountIds) return 0.0;
        // Look up account_codes for the given account IDs
        $ph = implode(',', array_fill(0, count($accountIds), '?'));
        $codeSql = "SELECT account_code FROM gl_accounts WHERE account_id IN ($ph) AND company_id = ?";
        $codeStmt = $this->db->prepare($codeSql);
        $codeStmt->execute(array_merge($accountIds, [$this->companyId]));
        $codes = $codeStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$codes) return 0.0;

        $ph2 = implode(',', array_fill(0, count($codes), '?'));
        $sql = "
            SELECT COALESCE(SUM(jl.debit - jl.credit),0) AS bal
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = ? AND jl.account_code IN ($ph2)
              AND je.entry_date <= ? AND je.status = 'posted'
        ";
        $params = array_merge([$this->companyId], $codes, [$asOf]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return round(floatval($stmt->fetchColumn()), 2);
    }
}
