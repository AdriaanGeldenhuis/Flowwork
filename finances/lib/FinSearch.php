<?php
// finances/lib/FinSearch.php
//
// Ctrl K search across the books: journals (reference, description or an
// exact amount), customer invoices and credit notes, supplier bills and
// credits, customers and suppliers (with what they owe or are owed), bank
// lines and fixed assets. Accounts are searched in the page itself (the
// chart is in the boot payload). Every result carries the route to open.
// A query that is only a number also matches amounts to the cent.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/FinOverview.php';

final class FinSearch
{
    public static function run(PDO $db, int $companyId, string $q, array $mods): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . Fin::likeEscape($q) . '%';
        $cents = preg_match('/^[R\s]*-?[\d\s,.]+$/i', $q) ? Fin::cents($q) : null;
        $out = [];
        $try = function (callable $fn) {
            try { $fn(); } catch (Throwable $e) { error_log('[finance/search] ' . $e->getMessage()); }
        };

        $try(function () use ($db, $companyId, $like, $cents, &$out) {
            $sql = "SELECT je.id, je.entry_date, je.reference, je.description, je.status, je.reversed_by_journal_id, je.reverses_journal_id,
                           (SELECT SUM(debit) FROM journal_lines WHERE journal_id = je.id) total
                      FROM journal_entries je WHERE je.company_id = ? AND (je.reference LIKE ? OR je.description LIKE ?";
            $args = [$companyId, $like, $like];
            if ($cents !== null && $cents !== 0) {
                $sql .= " OR EXISTS (SELECT 1 FROM journal_lines x WHERE x.journal_id = je.id AND (ROUND(x.debit * 100) = ? OR ROUND(x.credit * 100) = ?))";
                array_push($args, abs($cents), abs($cents));
            }
            $sql .= ") ORDER BY je.entry_date DESC, je.id DESC LIMIT 6";
            $st = $db->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['group' => 'Journals', 'icon' => 'if-journal', 'title' => ($r['reference'] ?: '#' . $r['id']) . ' · ' . ($r['description'] ?: 'Journal'),
                          'sub' => date('j M Y', strtotime($r['entry_date'])), 'amount' => round((float)$r['total'], 2), 'status' => FinJournalsStatus::of($r), 'href' => '#journals?id=' . $r['id']];
            }
        });
        if (!empty($mods['receivables'])) $try(function () use ($db, $companyId, $like, $cents, &$out) {
            $sql = "SELECT i.id, i.invoice_number, i.issue_date, i.total, i.balance_due, i.status, COALESCE(ca.name, '') who
                      FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
                     WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status <> 'draft' AND (i.invoice_number LIKE ? OR i.reference LIKE ? OR ca.name LIKE ?";
            $args = [$companyId, $like, $like, $like];
            if ($cents !== null && $cents !== 0) { $sql .= " OR ROUND(i.total * 100) = ? OR ROUND(i.balance_due * 100) = ?"; array_push($args, abs($cents), abs($cents)); }
            $st = $db->prepare($sql . ") ORDER BY i.issue_date DESC LIMIT 5");
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['group' => 'Customer invoices', 'icon' => 'iq-invoice', 'title' => $r['invoice_number'] . ' · ' . $r['who'],
                          'sub' => date('j M Y', strtotime($r['issue_date'])) . ((float)$r['balance_due'] > 0.004 ? ' · ' . Fin::money((float)$r['balance_due']) . ' open' : ''),
                          'amount' => round((float)$r['total'], 2), 'href' => '#receivables?status=all&id=' . $r['id']];
            }
        });
        if (!empty($mods['payables'])) $try(function () use ($db, $companyId, $like, $cents, &$out) {
            $sql = "SELECT b.id, b.vendor_invoice_number, b.issue_date, b.total, b.status, COALESCE(ca.name, '') who
                      FROM ap_bills b LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id
                     WHERE b.company_id = ? AND (b.vendor_invoice_number LIKE ? OR ca.name LIKE ? OR b.notes LIKE ?";
            $args = [$companyId, $like, $like, $like];
            if ($cents !== null && $cents !== 0) { $sql .= " OR ROUND(b.total * 100) = ?"; $args[] = abs($cents); }
            $st = $db->prepare($sql . ") ORDER BY b.issue_date DESC LIMIT 5");
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['group' => 'Supplier bills', 'icon' => 'if-out', 'title' => ($r['vendor_invoice_number'] ?: 'Bill #' . $r['id']) . ' · ' . $r['who'],
                          'sub' => date('j M Y', strtotime($r['issue_date'])), 'amount' => round((float)$r['total'], 2), 'status' => $r['status'], 'href' => '#payables?status=all&id=' . $r['id']];
            }
            $st = $db->prepare("SELECT vc.id, vc.credit_number, vc.issue_date, vc.total, COALESCE(ca.name, '') who FROM vendor_credits vc LEFT JOIN crm_accounts ca ON ca.id = vc.supplier_id
                                 WHERE vc.company_id = ? AND (vc.credit_number LIKE ? OR ca.name LIKE ?) ORDER BY vc.issue_date DESC LIMIT 3");
            $st->execute([$companyId, $like, $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['group' => 'Supplier bills', 'icon' => 'if-credit', 'title' => 'Credit ' . $r['credit_number'] . ' · ' . $r['who'],
                          'sub' => date('j M Y', strtotime($r['issue_date'])), 'amount' => -round((float)$r['total'], 2), 'href' => '#payables?view=credits&credit=' . $r['id']];
            }
        });
        $try(function () use ($db, $companyId, $like, $mods, &$out) {
            $st = $db->prepare("SELECT id, type, name, vat_no FROM crm_accounts WHERE company_id = ? AND deleted_at IS NULL AND (name LIKE ? OR legal_name LIKE ? OR vat_no LIKE ?)
                              ORDER BY name LIMIT 6");
            $st->execute([$companyId, $like, $like, $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cust = $r['type'] === 'customer';
                if ($cust && empty($mods['receivables'])) continue;
                if (!$cust && empty($mods['payables'])) continue;
                $out[] = ['group' => $cust ? 'Customers' : 'Suppliers', 'icon' => $cust ? 'if-in' : 'if-out', 'title' => $r['name'],
                          'sub' => ($cust ? 'Customer' : 'Supplier') . ($r['vat_no'] ? ' · VAT ' . $r['vat_no'] : '') . ' · open statement',
                          'href' => $cust ? '#receivables?customer=' . $r['id'] : '#payables?supplier=' . $r['id']];
            }
        });
        if (!empty($mods['bank'])) $try(function () use ($db, $companyId, $like, $cents, &$out) {
            $sql = "SELECT t.bank_tx_id, t.tx_date, t.description, t.amount_cents, t.matched, ba.name bank FROM gl_bank_transactions t
                      LEFT JOIN gl_bank_accounts ba ON ba.id = t.bank_account_id AND ba.company_id = t.company_id
                     WHERE t.company_id = ? AND (t.description LIKE ? OR t.reference LIKE ?";
            $args = [$companyId, $like, $like];
            if ($cents !== null && $cents !== 0) { $sql .= " OR ABS(t.amount_cents) = ?"; $args[] = abs($cents); }
            $st = $db->prepare($sql . ") ORDER BY t.tx_date DESC LIMIT 5");
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['group' => 'Bank lines', 'icon' => 'if-bank', 'title' => $r['description'] ?: 'Bank line', 'sub' => date('j M Y', strtotime($r['tx_date'])) . ' · ' . $r['bank'],
                          'amount' => ((int)$r['amount_cents']) / 100, 'status' => (int)$r['matched'] ? 'matched' : 'unmatched', 'href' => '#bank?status=all&txn=' . $r['bank_tx_id']];
            }
        });
        if (!empty($mods['assets'])) $try(function () use ($db, $companyId, $like, &$out) {
            $st = $db->prepare("SELECT asset_id, asset_name, category, purchase_cost_cents, status FROM gl_fixed_assets WHERE company_id = ? AND (asset_name LIKE ? OR category LIKE ?) LIMIT 4");
            $st->execute([$companyId, $like, $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['group' => 'Fixed assets', 'icon' => 'if-asset', 'title' => $r['asset_name'], 'sub' => (string)$r['category'],
                          'amount' => ((int)$r['purchase_cost_cents']) / 100, 'status' => $r['status'], 'href' => '#assets?status=all&id=' . $r['asset_id']];
            }
        });
        return $out;
    }
}
