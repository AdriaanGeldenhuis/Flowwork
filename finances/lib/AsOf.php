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
        $sql = "
        SELECT a.id as account_id, a.code, a.name,
               COALESCE(SUM(jl.debit_cents),0) AS deb_c,
               COALESCE(SUM(jl.credit_cents),0) AS cred_c
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON jl.gl_account_id = a.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_id AND je.company_id = a.company_id
        WHERE a.company_id = :cid
          AND (je.entry_date IS NULL OR (je.entry_date <= :asof AND je.status = 'posted'))
        GROUP BY a.id, a.code, a.name
        ORDER BY a.code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':cid'=>$this->companyId, ':asof'=>$asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        $totDeb = 0; $totCred = 0;
        foreach ($rows as $r) {
            $deb = (int)$r['deb_c'] / 100.0;
            $cred = (int)$r['cred_c'] / 100.0;
            $bal = $deb - $cred;
            $out[] = [
                'account_id' => (int)$r['account_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'debit' => round($deb,2),
                'credit' => round($cred,2),
                'balance' => round($bal,2),
            ];
            $totDeb += $deb; $totCred += $cred;
        }
        return ['rows'=>$out, 'total_debit'=>round($totDeb,2), 'total_credit'=>round($totCred,2)];
    }

    /** Sum balances for a set of account ids as of date, returning debit-positive */
    public function sumAccounts(array $accountIds, string $asOf): float
    {
        if (!$accountIds) return 0.0;
        $ph = implode(',', array_fill(0, count($accountIds), '?'));
        $sql = "
            SELECT COALESCE(SUM(jl.debit_cents - jl.credit_cents),0) AS bal_c
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = ? AND jl.gl_account_id IN ($ph)
              AND je.entry_date <= ? AND je.status = 'posted'
        ";
        $params = array_merge([$this->companyId], $accountIds, [$asOf]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $cents = (int)$stmt->fetchColumn();
        return round($cents / 100.0, 2);
    }
}
