<?php
/**
 * JournalPoster
 * Minimal GL posting for invoices: DR AR, CR Sales, CR VAT Output.
 *
 * Example use after creating/sending an invoice:
 *   require_once __DIR__ . '/../lib/JournalPoster.php';
 *   JournalPoster::postInvoice($pdo, (int)$invoice_id);
 */
class JournalPoster {
    public static function postInvoice(PDO $db, int $invoiceId, int $userId = 0): ?int {
        $stmt = $db->prepare("SELECT company_id, invoice_number, issue_date, subtotal, tax, total, customer_id FROM invoices WHERE id=?");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $companyId = (int)$row['company_id'];
        if (!$userId) { $userId = (int)($_SESSION['user_id'] ?? 1); }
        $map = self::getFinanceMap($db, $companyId);
        if (!$map['ar'] || !$map['sales'] || !$map['vat_output']) {
            // Missing required mappings, do nothing gracefully
            return null;
        }

        $db->beginTransaction();
        try {
            $ins = $db->prepare("INSERT INTO journal_entries (company_id, entry_date, reference, description, module, ref_type, ref_id, source_type, source_id, created_by, created_at, status, posted_by, posted_at)
                                 VALUES (?, ?, ?, ?, 'qi', 'invoice', ?, 'invoice', ?, ?, NOW(), 'posted', ?, NOW())");
            $ins->execute([$companyId, $row['issue_date'], $row['invoice_number'], 'Invoice posting', $invoiceId, $invoiceId, $userId, $userId]);
            $journalId = (int)$db->lastInsertId();

            // DR Accounts Receivable (total)
            self::line($db, $journalId, $map['ar_code'], 'AR for '.$row['invoice_number'], (float)$row['total'], 0.00, (int)$row['customer_id']);

            // CR Sales (subtotal)
            if ((float)$row['subtotal'] > 0) {
                self::line($db, $journalId, $map['sales_code'], 'Sales '.$row['invoice_number'], 0.00, (float)$row['subtotal'], (int)$row['customer_id']);
            }

            // CR VAT Output (tax) — resolve SARS tax_code_id for VAT201
            if ((float)$row['tax'] > 0) {
                $outputTaxCodeId = self::resolveOutputTaxCodeId($db, $companyId);
                self::line($db, $journalId, $map['vat_output_code'], 'VAT Output '.$row['invoice_number'], 0.00, (float)$row['tax'], (int)$row['customer_id'], $outputTaxCodeId);
            }

            $upd = $db->prepare("UPDATE invoices SET journal_id=? WHERE id=?");
            $upd->execute([$journalId, $invoiceId]);

            $db->commit();
            return $journalId;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private static function getFinanceMap(PDO $db, int $companyId): array {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM company_settings
                              WHERE company_id=?
                                AND setting_key IN ('finance_ar_account_id','finance_sales_account_id','finance_vat_output_account_id')");
        $stmt->execute([$companyId]);
        $ids = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids[$r['setting_key']] = (int)$r['setting_value'];
        }

        $codes = [];
        foreach ($ids as $key => $id) {
            if (!$id) continue;
            $acc = $db->prepare("SELECT account_code FROM gl_accounts WHERE account_id=?");
            $acc->execute([$id]);
            $accRow = $acc->fetch(PDO::FETCH_ASSOC);
            if ($accRow) {
                $base = str_replace(['finance_','_account_id'],'',$key);
                $codes[$base.'_code'] = $accRow['account_code'];
            }
        }

        return [
            'ar' => $ids['finance_ar_account_id'] ?? null,
            'sales' => $ids['finance_sales_account_id'] ?? null,
            'vat_output' => $ids['finance_vat_output_account_id'] ?? null,
            'ar_code' => $codes['ar_code'] ?? null,
            'sales_code' => $codes['sales_code'] ?? null,
            'vat_output_code' => $codes['vat_output_code'] ?? null
        ];
    }

    private static function resolveOutputTaxCodeId(PDO $db, int $companyId): ?int {
        $stmt = $db->prepare("SELECT tax_code_id FROM gl_tax_codes WHERE company_id = ? AND code IN ('STD','STANDARD') AND is_active = 1 LIMIT 1");
        $stmt->execute([$companyId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private static function line(PDO $db, int $journalId, string $accountCode, string $desc, float $debit, float $credit, ?int $customerId=null, ?int $taxCodeId=null): void {
        $stmt = $db->prepare("INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, tax_code_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$journalId, $accountCode, $desc, $debit, $credit, $customerId, $taxCodeId]);
    }
}
