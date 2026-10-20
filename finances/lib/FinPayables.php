<?php
// finances/lib/FinPayables.php
//
// Payables: supplier bills, payments, supplier credit notes, aging,
// statements and three-way matching (purchase order ↔ goods received ↔ bill).
//
// Bills are kept exactly as the posting engine posts them: each line's net
// is rounded to the cent and its VAT is rounded per line, and the header
// subtotal / VAT / total are the sums of those — so the bill, the supplier's
// balance and the payables control account always agree to the cent (the
// old screen rounded VAT once over the whole bill and could differ).
// Lifecycle: draft (or 'review' when it came from Receipts) → posted (in the
// books, editable no more; corrected with a supplier credit note) → paid.
//
// Supplier payments: the payment amount is the sum of what is allocated to
// bills (never more), every bill is locked while its balance is checked, and
// an idempotency key stops a double click from paying twice.
//
// Supplier credit notes are posted once, in full (Dr payables, Cr the
// expense and input VAT), and then allocated to bills as often as needed
// without posting again. A posted credit with something left over is
// 'approved' (open), fully allocated it is 'applied'. Older credits that were
// re-posted per allocation by the previous screen are read by their journal,
// not their status.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/PostingService.php';
require_once __DIR__ . '/TaxCodes.php';
require_once __DIR__ . '/FinOverview.php';

final class FinPayables
{
    /** Bill balance subquery (posted / paid bills): total − payments − credits. */
    private const PAID = "COALESCE((SELECT SUM(apa.amount) FROM ap_payment_allocations apa WHERE apa.bill_id = b.id), 0)";
    private const CREDITED = "COALESCE((SELECT SUM(vca.amount) FROM vendor_credit_allocations vca WHERE vca.bill_id = b.id), 0)";

    // ----------------------------------------------------------------- bills

    /** $f: status (open|overdue|due|draft|paid|all), supplier, q, from, to, page, sort (due|issued|balance|number), dir */
    public static function bills(PDO $db, int $companyId, array $f, int $soonDays = 7): array
    {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime("+$soonDays days"));
        $where = ['b.company_id = ?'];
        $args = [$companyId];
        if ((int)($f['supplier'] ?? 0)) { $where[] = 'b.supplier_id = ?'; $args[] = (int)$f['supplier']; }
        if ($d = Fin::ymd($f['from'] ?? '')) { $where[] = 'b.issue_date >= ?'; $args[] = $d; }
        if ($d = Fin::ymd($f['to'] ?? '')) { $where[] = 'b.issue_date <= ?'; $args[] = $d; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . Fin::likeEscape($q) . '%';
            $c = preg_match('/^[R\s\-\d,.]+$/i', $q) ? Fin::cents($q) : null;
            $where[] = '(b.vendor_invoice_number LIKE ? OR ca.name LIKE ? OR b.notes LIKE ?' . ($c ? ' OR ROUND(b.total * 100) = ?' : '') . ')';
            array_push($args, $like, $like, $like);
            if ($c) $args[] = abs($c);
        }
        $bal = "(b.total - " . self::PAID . " - " . self::CREDITED . ")";
        $open = "b.status = 'posted' AND $bal > 0.005";
        $base = implode(' AND ', $where);
        $st = $db->prepare("SELECT SUM($open) o, SUM($open AND b.due_date < ?) l, SUM($open AND b.due_date BETWEEN ? AND ?) d,
                                   SUM(b.status IN ('draft','review','approved')) dr, SUM(b.status = 'paid') p, COUNT(*) n
                              FROM ap_bills b LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id WHERE $base");
        $st->execute(array_merge([$today, $today, $soon], $args));
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = ['open' => (int)$c['o'], 'overdue' => (int)$c['l'], 'due' => (int)$c['d'], 'draft' => (int)$c['dr'], 'paid' => (int)$c['p'], 'all' => (int)$c['n']];
        $status = (string)($f['status'] ?? 'open');
        if ($status === 'open') $where[] = $open;
        elseif ($status === 'overdue') { $where[] = "$open AND b.due_date < ?"; $args[] = $today; }
        elseif ($status === 'due') { $where[] = "$open AND b.due_date BETWEEN ? AND ?"; $args[] = $today; $args[] = $soon; }
        elseif ($status === 'draft') $where[] = "b.status IN ('draft','review','approved')";
        elseif ($status === 'paid') $where[] = "b.status = 'paid'";
        $sql = implode(' AND ', $where);
        $dir = strtolower((string)($f['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
        $order = ['due' => "b.due_date $dir, b.id", 'issued' => "b.issue_date $dir, b.id", 'balance' => "balance $dir, b.id", 'number' => "b.vendor_invoice_number $dir"][$f['sort'] ?? 'due'] ?? 'b.due_date ASC';
        $per = 50;
        $page = max(1, (int)($f['page'] ?? 1));
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN b.status IN ('posted','paid') THEN $bal ELSE b.total END), 0) FROM ap_bills b LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id WHERE $sql");
        $st->execute($args);
        [$total, $sum] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("SELECT b.id, b.supplier_id, b.vendor_invoice_number, b.issue_date, b.due_date, b.status, b.total, b.tax, b.journal_id, b.ocr_id, b.file_id,
                                   $bal balance, COALESCE(ca.name, '(deleted supplier)') supplier
                              FROM ap_bills b LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id WHERE $sql ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($args);
        require_once __DIR__ . '/FinFiles.php';
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $files = FinFiles::counts($db, $companyId, 'bill', array_column($rows, 'id'));
        $out = array_map(function ($r) use ($today, $files) {
            $posted = in_array($r['status'], ['posted', 'paid'], true);
            $late = $r['status'] === 'posted' && $r['due_date'] && $r['due_date'] < $today && (float)$r['balance'] > 0.005;
            return ['id' => (int)$r['id'], 'supplier_id' => (int)$r['supplier_id'], 'supplier' => (string)$r['supplier'], 'number' => (string)$r['vendor_invoice_number'],
                    'issued' => $r['issue_date'], 'due' => $r['due_date'], 'status' => $late ? 'overdue' : ($r['status'] === 'review' ? 'draft' : (string)$r['status']),
                    'from_receipts' => $r['status'] === 'review' || !empty($r['ocr_id']),
                    'total' => round((float)$r['total'], 2), 'tax' => round((float)$r['tax'], 2), 'balance' => $posted ? round((float)$r['balance'], 2) : round((float)$r['total'], 2),
                    'days_late' => $late ? (int)((strtotime($today) - strtotime($r['due_date'])) / 86400) : 0, 'files' => $files[(int)$r['id']] ?? 0];
        }, $rows);
        return ['rows' => $out, 'total' => (int)$total, 'sum' => round((float)$sum, 2), 'page' => $page, 'per' => $per, 'counts' => $counts];
    }

    public static function bill(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT b.*, COALESCE(ca.name, '(deleted supplier)') supplier, " . self::PAID . " paid, " . self::CREDITED . " credited
                              FROM ap_bills b LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id WHERE b.id = ? AND b.company_id = ?");
        $st->execute([$id, $companyId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new FinUserError('That bill was not found.');
        $st = $db->prepare("SELECT l.*, ga.account_code, ga.account_name FROM ap_bill_lines l LEFT JOIN gl_accounts ga ON ga.account_id = l.gl_account_id AND ga.company_id = ?
                             WHERE l.bill_id = ? ORDER BY l.sort_order, l.id");
        $st->execute([$companyId, $id]);
        $lines = array_map(function ($l) {
            $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)$l['discount']) * 100);
            $vatC = (int)round($netC * (float)$l['tax_rate'] / 100);
            return ['id' => (int)$l['id'], 'description' => (string)$l['item_description'], 'qty' => (float)$l['quantity'], 'unit' => (string)($l['unit'] ?? ''),
                    'unit_price' => (float)$l['unit_price'], 'discount' => (float)$l['discount'], 'tax_rate' => (float)$l['tax_rate'],
                    'account_id' => $l['gl_account_id'] ? (int)$l['gl_account_id'] : null, 'account_code' => (string)($l['account_code'] ?? ''), 'account_name' => (string)($l['account_name'] ?? ''),
                    'inventory_item_id' => $l['inventory_item_id'] ? (int)$l['inventory_item_id'] : null, 'board_id' => $l['project_board_id'] ? (int)$l['project_board_id'] : null,
                    'project_item_id' => $l['project_item_id'] ? (int)$l['project_item_id'] : null,
                    'net' => $netC / 100, 'vat' => $vatC / 100, 'total' => ($netC + $vatC) / 100];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT p.id, p.payment_date, p.method, p.reference, apa.amount, p.journal_id FROM ap_payment_allocations apa JOIN ap_payments p ON p.id = apa.ap_payment_id
                             WHERE apa.bill_id = ? AND p.company_id = ? ORDER BY p.payment_date");
        $st->execute([$id, $companyId]);
        $pays = array_map(fn($p) => ['id' => (int)$p['id'], 'date' => $p['payment_date'], 'method' => (string)$p['method'], 'reference' => (string)($p['reference'] ?? ''),
                                     'amount' => round((float)$p['amount'], 2), 'journal_id' => $p['journal_id'] ? (int)$p['journal_id'] : null], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT vc.id, vc.credit_number, vc.issue_date, vca.amount FROM vendor_credit_allocations vca JOIN vendor_credits vc ON vc.id = vca.credit_id
                             WHERE vca.bill_id = ? AND vc.company_id = ?");
        $st->execute([$id, $companyId]);
        $credits = array_map(fn($c) => ['id' => (int)$c['id'], 'number' => (string)$c['credit_number'], 'date' => $c['issue_date'], 'amount' => round((float)$c['amount'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
        require_once __DIR__ . '/FinFiles.php';
        $posted = in_array($b['status'], ['posted', 'paid'], true);
        $balance = $posted ? round((float)$b['total'] - (float)$b['paid'] - (float)$b['credited'], 2) : round((float)$b['total'], 2);
        $late = $b['status'] === 'posted' && $b['due_date'] && $b['due_date'] < date('Y-m-d') && $balance > 0.005;
        return [
            'bill' => ['id' => (int)$b['id'], 'supplier_id' => (int)$b['supplier_id'], 'supplier' => (string)$b['supplier'], 'number' => (string)$b['vendor_invoice_number'],
                       'vendor_vat' => (string)($b['vendor_vat'] ?? ''), 'issued' => $b['issue_date'], 'due' => $b['due_date'], 'status' => $late ? 'overdue' : (string)$b['status'],
                       'raw_status' => (string)$b['status'], 'notes' => (string)($b['notes'] ?? ''), 'subtotal' => round((float)$b['subtotal'], 2), 'tax' => round((float)$b['tax'], 2),
                       'total' => round((float)$b['total'], 2), 'paid' => round((float)$b['paid'], 2), 'credited' => round((float)$b['credited'], 2), 'balance' => $balance,
                       'journal_id' => $b['journal_id'] ? (int)$b['journal_id'] : null, 'from_receipts' => $b['status'] === 'review' || !empty($b['ocr_id']),
                       'receipt_url' => !empty($b['file_id']) ? '/receipts/review.php?id=' . (int)$b['file_id'] : (!empty($b['ocr_id']) ? '/receipts/' : null),
                       'editable' => in_array($b['status'], ['draft', 'review', 'approved'], true), 'postable' => in_array($b['status'], ['draft', 'review', 'approved'], true)],
            'lines' => $lines, 'payments' => $pays, 'credits' => $credits, 'files' => FinFiles::list($db, $companyId, 'bill', $id),
        ];
    }

    /** Lines exactly as the engine will post them. @return array{0: array, 1: int, 2: int} lines, net cents, VAT cents */
    private static function cleanBillLines(PDO $db, int $companyId, array $raw, bool $credit = false): array
    {
        $taxes = new TaxCodes($db, $companyId);
        $default = $taxes->standardRatePercent();
        $lines = [];
        $netT = 0; $vatT = 0;
        foreach (array_values($raw) as $i => $l) {
            if (!is_array($l)) continue;
            $desc = mb_substr(trim(strip_tags((string)($l['description'] ?? ''))), 0, 255);
            $qty = is_numeric($l['qty'] ?? null) ? round((float)$l['qty'], 3) : 1.0;
            $price = Fin::cents($l['unit_price'] ?? '');
            if ($desc === '' && $price === null) continue;
            $n = count($lines) + 1;
            if ($price === null) throw new FinUserError("Line $n: enter a price.");
            if ($qty <= 0) throw new FinUserError("Line $n: the quantity must be more than nil.");
            if ($price < 0) throw new FinUserError("Line $n: a price cannot be negative" . ($credit ? '.' : ' — record a supplier credit note instead.'));
            $disc = Fin::cents($l['discount'] ?? '0') ?? 0;
            if ($disc < 0) throw new FinUserError("Line $n: the discount cannot be negative.");
            $rate = isset($l['tax_rate']) && $l['tax_rate'] !== '' && $l['tax_rate'] !== null ? round((float)$l['tax_rate'], 2) : $default;
            if ($rate < 0 || $rate > 100) throw new FinUserError("Line $n: choose a VAT rate.");
            $netC = (int)round(($qty * $price / 100 - $disc / 100) * 100);
            if ($netC < 0) throw new FinUserError("Line $n: the discount is more than the line.");
            $vatC = (int)round($netC * $rate / 100);
            $acc = (int)($l['account_id'] ?? 0) ?: null;
            if ($acc) {
                $st = $db->prepare("SELECT is_active, is_control, account_type FROM gl_accounts WHERE account_id = ? AND company_id = ?");
                $st->execute([$acc, $companyId]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                if (!$a || !(int)$a['is_active']) throw new FinUserError("Line $n: choose an active account.");
                if ((int)$a['is_control']) throw new FinUserError("Line $n: a bill line cannot post to a control account.");
            }
            $item = (int)($l['inventory_item_id'] ?? 0) ?: null;
            if ($item && !$credit) {
                $st = $db->prepare("SELECT 1 FROM inventory_items WHERE id = ? AND company_id = ?");
                try { $st->execute([$item, $companyId]); if (!$st->fetchColumn()) throw new FinUserError("Line $n: that stock item was not found."); }
                catch (PDOException $e) { $item = null; }
            }
            $lines[] = ['description' => $desc ?: 'Item', 'qty' => $qty, 'unit' => mb_substr(trim((string)($l['unit'] ?? 'ea')), 0, 20) ?: 'ea', 'unit_price' => $price / 100,
                        'discount' => $disc / 100, 'tax_rate' => $rate, 'net' => $netC, 'account_id' => $acc, 'inventory_item_id' => $credit ? null : $item,
                        'board_id' => (int)($l['board_id'] ?? 0) ?: null, 'item_id' => (int)($l['project_item_id'] ?? 0) ?: null];
            $netT += $netC; $vatT += $vatC;
        }
        if (!$lines) throw new FinUserError('Add at least one line.');
        if (count($lines) > 200) throw new FinUserError('A document can have up to 200 lines.');
        // project tags: of this company and on a project
        $pl = dirname(__DIR__, 2) . '/projects/lib/autoload.php';
        if (is_file($pl) && array_filter(array_column($lines, 'board_id'))) {
            require_once $pl;
            foreach ($lines as $i => $l) {
                if (!$l['board_id']) continue;
                if (!ProjectLink::boardBelongs($db, $companyId, $l['board_id'], $l['item_id']) || !ProjectLink::projectOfBoard($db, $companyId, $l['board_id'])) {
                    throw new FinUserError('Line ' . ($i + 1) . ': that project board is not one of yours.');
                }
            }
        }
        return [$lines, $netT, $vatT];
    }

    /** Create or edit a draft bill. $in: id?, supplier_id, number, vendor_vat?, issued, due?, notes?, lines[] */
    public static function saveBill(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        $sup = (int)($in['supplier_id'] ?? 0);
        $st = $db->prepare("SELECT 1 FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
        $st->execute([$sup, $companyId]);
        if (!$st->fetchColumn()) throw new FinUserError('Choose the supplier.');
        $number = mb_substr(trim(strip_tags((string)($in['number'] ?? ''))), 0, 190);
        if ($number === '') throw new FinUserError("Enter the supplier's invoice number.");
        $issued = Fin::ymd($in['issued'] ?? '');
        if (!$issued) throw new FinUserError('Choose the invoice date.');
        $due = ($in['due'] ?? '') !== '' ? Fin::ymd($in['due']) : null;
        if (($in['due'] ?? '') !== '' && !$due) throw new FinUserError('Choose a valid due date.');
        if ($due && $due < $issued) throw new FinUserError('The due date is before the invoice date.');
        $vat = preg_replace('/\D/', '', (string)($in['vendor_vat'] ?? ''));
        if ($vat !== '' && !preg_match('/^4\d{9}$/', $vat)) throw new FinUserError('A South African VAT number has 10 digits and starts with 4.');
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000);
        [$lines, $netC, $vatC] = self::cleanBillLines($db, $companyId, (array)($in['lines'] ?? []));
        $totalC = $netC + $vatC;
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $sup, $number, $issued, $due, $vat, $notes, $lines, $netC, $vatC, $totalC) {
            $hash = sha1($number . '|' . $issued . '|' . number_format($totalC / 100, 2, '.', '') . '|' . $sup);
            $st = $db->prepare("SELECT id FROM ap_bills WHERE company_id = ? AND supplier_id = ? AND vendor_invoice_number = ? AND id <> ? AND status <> 'blocked' LIMIT 1");
            $st->execute([$companyId, $sup, $number, $id]);
            if ($dup = $st->fetchColumn()) throw new FinUserError("This supplier's invoice $number is already captured (bill #$dup).");
            if ($id) {
                $st = $db->prepare("SELECT status FROM ap_bills WHERE id = ? AND company_id = ? FOR UPDATE");
                $st->execute([$id, $companyId]);
                $s = $st->fetchColumn();
                if ($s === false) throw new FinUserError('That bill was not found.');
                if (!in_array($s, ['draft', 'review', 'approved'], true)) throw new FinUserError('A posted bill cannot change. Record a supplier credit note to correct it.', 409);
                $db->prepare("UPDATE ap_bills SET supplier_id = ?, vendor_invoice_number = ?, vendor_vat = ?, issue_date = ?, due_date = ?, subtotal = ?, tax = ?, total = ?,
                                     hash_fingerprint = ?, notes = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                   ->execute([$sup, $number, $vat ?: null, $issued, $due, Fin::rand($netC), Fin::rand($vatC), Fin::rand($totalC), $hash, $notes ?: null, $id, $companyId]);
                $db->prepare("DELETE FROM ap_bill_lines WHERE bill_id = ?")->execute([$id]);
            } else {
                $db->prepare("INSERT INTO ap_bills (company_id, supplier_id, vendor_invoice_number, vendor_vat, issue_date, due_date, currency, subtotal, tax, total, status, hash_fingerprint, notes, created_by, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'ZAR', ?, ?, ?, 'draft', ?, ?, ?, NOW())")
                   ->execute([$companyId, $sup, $number, $vat ?: null, $issued, $due, Fin::rand($netC), Fin::rand($vatC), Fin::rand($totalC), $hash, $notes ?: null, $userId]);
                $id = (int)$db->lastInsertId();
            }
            $ins = $db->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, sort_order, gl_account_id, project_board_id, project_item_id, inventory_item_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $i => $l) {
                $ins->execute([$id, $l['description'], $l['qty'], $l['unit'], $l['unit_price'], $l['discount'], $l['tax_rate'], Fin::rand($l['net']), $i, $l['account_id'], $l['board_id'], $l['item_id'], $l['inventory_item_id']]);
            }
            Fin::audit($db, $companyId, $userId, 'ap_bill_saved', 'ap_bill', $id, ['number' => $number, 'total_cents' => $totalC]);
            return $id;
        });
    }

    public static function postBill(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $st = $db->prepare("SELECT status, subtotal, tax, total FROM ap_bills WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new FinUserError('That bill was not found.');
            if (in_array($b['status'], ['posted', 'paid'], true)) throw new FinUserError('This bill is already in the books.', 409);
            // A bill from Receipts or the old screen may carry header totals that differ from its lines;
            // bring the header in line with what the engine posts so bill and books agree to the cent.
            $st = $db->prepare("SELECT quantity, unit_price, discount, tax_rate FROM ap_bill_lines WHERE bill_id = ?");
            $st->execute([$id]);
            $netC = 0; $vatC = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $n = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)$l['discount']) * 100);
                $netC += $n;
                $vatC += (int)round($n * (float)$l['tax_rate'] / 100);
            }
            if ($netC + $vatC !== (int)round((float)$b['total'] * 100)) {
                $db->prepare("UPDATE ap_bills SET subtotal = ?, tax = ?, total = ? WHERE id = ? AND company_id = ?")->execute([Fin::rand($netC), Fin::rand($vatC), Fin::rand($netC + $vatC), $id, $companyId]);
                Fin::audit($db, $companyId, $userId, 'ap_bill_totals_aligned', 'ap_bill', $id, ['from_total' => $b['total'], 'to_total' => Fin::rand($netC + $vatC)]);
            }
            (new PostingService($db, $companyId, $userId))->postApBill($id);
        });
    }

    public static function deleteBill(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $st = $db->prepare("SELECT status, journal_id, vendor_invoice_number FROM ap_bills WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new FinUserError('That bill was not found.');
            if (!in_array($b['status'], ['draft', 'review', 'approved'], true) || $b['journal_id']) throw new FinUserError('Only a draft bill can be deleted.', 409);
            $db->prepare("DELETE FROM ap_match_links WHERE company_id = ? AND bill_line_id IN (SELECT id FROM ap_bill_lines WHERE bill_id = ?)")->execute([$companyId, $id]);
            $db->prepare("DELETE FROM ap_bill_lines WHERE bill_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM ap_bills WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            require_once __DIR__ . '/FinFiles.php';
            FinFiles::detachAll($db, $companyId, $userId, 'bill', $id);
            Fin::audit($db, $companyId, $userId, 'ap_bill_deleted', 'ap_bill', $id, ['number' => $b['vendor_invoice_number']]);
        });
    }

    /** Open (posted) bills of a supplier with their balances — every one, not only the latest page. */
    public static function openBills(PDO $db, int $companyId, int $supplierId): array
    {
        $st = $db->prepare("SELECT b.id, b.vendor_invoice_number, b.issue_date, b.due_date, b.total, (b.total - " . self::PAID . " - " . self::CREDITED . ") balance
                              FROM ap_bills b WHERE b.company_id = ? AND b.supplier_id = ? AND b.status = 'posted' AND b.journal_id IS NOT NULL
                            HAVING balance > 0.005 ORDER BY COALESCE(b.due_date, b.issue_date), b.id");
        $st->execute([$companyId, $supplierId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'number' => (string)$r['vendor_invoice_number'], 'issued' => $r['issue_date'], 'due' => $r['due_date'],
                                    'total' => round((float)$r['total'], 2), 'balance' => round((float)$r['balance'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // -------------------------------------------------------------- payments

    public static function payments(PDO $db, int $companyId, array $f): array
    {
        $where = ['p.company_id = ?'];
        $args = [$companyId];
        if ((int)($f['supplier'] ?? 0)) { $where[] = 'p.supplier_id = ?'; $args[] = (int)$f['supplier']; }
        if ($d = Fin::ymd($f['from'] ?? '')) { $where[] = 'p.payment_date >= ?'; $args[] = $d; }
        if ($d = Fin::ymd($f['to'] ?? '')) { $where[] = 'p.payment_date <= ?'; $args[] = $d; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $where[] = '(p.reference LIKE ? OR ca.name LIKE ?)'; $args[] = '%' . Fin::likeEscape($q) . '%'; $args[] = '%' . Fin::likeEscape($q) . '%'; }
        $sql = implode(' AND ', $where);
        $per = 50;
        $page = max(1, (int)($f['page'] ?? 1));
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(p.amount), 0) FROM ap_payments p LEFT JOIN crm_accounts ca ON ca.id = p.supplier_id WHERE $sql");
        $st->execute($args);
        [$total, $sum] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("SELECT p.id, p.supplier_id, p.amount, p.payment_date, p.method, p.reference, p.journal_id, COALESCE(ca.name, '(deleted supplier)') supplier, ba.name bank,
                                   (SELECT GROUP_CONCAT(b.vendor_invoice_number SEPARATOR ', ') FROM ap_payment_allocations apa JOIN ap_bills b ON b.id = apa.bill_id WHERE apa.ap_payment_id = p.id) bills
                              FROM ap_payments p LEFT JOIN crm_accounts ca ON ca.id = p.supplier_id LEFT JOIN gl_bank_accounts ba ON ba.id = p.bank_account_id AND ba.company_id = p.company_id
                             WHERE $sql ORDER BY p.payment_date DESC, p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($args);
        $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'supplier_id' => (int)$r['supplier_id'], 'supplier' => (string)$r['supplier'], 'amount' => round((float)$r['amount'], 2),
                                     'date' => $r['payment_date'], 'method' => (string)$r['method'], 'reference' => (string)($r['reference'] ?? ''), 'journal_id' => $r['journal_id'] ? (int)$r['journal_id'] : null,
                                     'bank' => (string)($r['bank'] ?? ''), 'bills' => (string)($r['bills'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => (int)$total, 'sum' => round((float)$sum, 2), 'page' => $page, 'per' => $per];
    }

    /**
     * Pay one or more bills of a supplier. $in: supplier_id, date, bank_account_id?, method, reference?, notes?, allocations[{bill_id, amount}], key.
     * @return array{payment_id:int, duplicate:bool}
     */
    public static function pay(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $sup = (int)($in['supplier_id'] ?? 0);
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose the payment date.');
        if ($date > date('Y-m-d', strtotime('+1 day'))) throw new FinUserError('A payment cannot be dated in the future.');
        $method = in_array($in['method'] ?? '', ['eft', 'cash', 'cheque', 'card', 'other'], true) ? $in['method'] : 'eft';
        $ref = mb_substr(trim(strip_tags((string)($in['reference'] ?? ''))), 0, 190);
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000);
        $key = substr(preg_replace('/[^A-Za-z0-9:._-]/', '', (string)($in['key'] ?? '')), 0, 64);
        $bank = (int)($in['bank_account_id'] ?? 0) ?: null;
        $per = [];
        foreach ((array)($in['allocations'] ?? []) as $a) {
            $b = (int)($a['bill_id'] ?? 0);
            $c = Fin::cents($a['amount'] ?? '') ?? 0;
            if ($c < 0) throw new FinUserError('Amounts cannot be negative.');
            if ($b && $c > 0) $per[$b] = ($per[$b] ?? 0) + $c;
        }
        if (!$per) throw new FinUserError('Enter how much is paid against at least one bill.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $sup, $date, $method, $ref, $notes, $key, $bank, $per) {
            if ($key !== '') {
                $st = $db->prepare("SELECT id FROM ap_payments WHERE company_id = ? AND idempotency_key = ? LIMIT 1");
                $st->execute([$companyId, $key]);
                if ($id = $st->fetchColumn()) return ['payment_id' => (int)$id, 'duplicate' => true];
            }
            $st = $db->prepare("SELECT 1 FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'");
            $st->execute([$sup, $companyId]);
            if (!$st->fetchColumn()) throw new FinUserError('Choose the supplier.');
            if ($bank) {
                $st = $db->prepare("SELECT 1 FROM gl_bank_accounts WHERE id = ? AND company_id = ? AND is_active = 1");
                $st->execute([$bank, $companyId]);
                if (!$st->fetchColumn()) throw new FinUserError('Choose one of your bank accounts.');
            }
            // Lock every bill first (ascending id → no deadlocks), then read balances under the locks.
            ksort($per);
            $lock = $db->prepare("SELECT id, supplier_id, status, journal_id, total, vendor_invoice_number FROM ap_bills WHERE id = ? AND company_id = ? FOR UPDATE");
            $bills = [];
            foreach (array_keys($per) as $b) {
                $lock->execute([$b, $companyId]);
                $row = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new FinUserError("Bill #$b was not found.");
                $bills[$b] = $row;
            }
            $paid = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM ap_payment_allocations WHERE bill_id = ? FOR UPDATE");
            $cred = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM vendor_credit_allocations WHERE bill_id = ? FOR UPDATE");
            foreach ($per as $b => $c) {
                $row = $bills[$b];
                $label = $row['vendor_invoice_number'] ?: "#$b";
                if ((int)$row['supplier_id'] !== $sup) throw new FinUserError("Bill $label belongs to another supplier.");
                if ($row['status'] !== 'posted' || empty($row['journal_id'])) throw new FinUserError("Bill $label is " . ($row['status'] === 'paid' ? 'already paid' : 'not posted yet — post it first') . '.');
                $paid->execute([$b]);
                $cred->execute([$b]);
                $leftC = (int)round((float)$row['total'] * 100) - (int)round((float)$paid->fetchColumn() * 100) - (int)round((float)$cred->fetchColumn() * 100);
                if ($c > $leftC) throw new FinUserError('That is more than the ' . Fin::money($leftC / 100) . " still owing on bill $label.");
            }
            $total = array_sum($per);
            $db->prepare("INSERT INTO ap_payments (company_id, supplier_id, bank_account_id, amount, payment_date, method, reference, notes, idempotency_key, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $sup, $bank, Fin::rand($total), $date, $method, $ref !== '' ? $ref : null, $notes !== '' ? $notes : null, $key !== '' ? $key : null, $userId]);
            $pid = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($per as $b => $c) $ins->execute([$pid, $b, Fin::rand($c)]);
            (new PostingService($db, $companyId, $userId))->postSupplierPayment($pid);
            Fin::audit($db, $companyId, $userId, 'supplier_payment_recorded', 'ap_payment', $pid, ['supplier_id' => $sup, 'total_cents' => $total, 'bills' => array_keys($per)]);
            return ['payment_id' => $pid, 'duplicate' => false];
        });
    }

    // --------------------------------------------------------------- credits

    public static function credits(PDO $db, int $companyId, array $f): array
    {
        $where = ['vc.company_id = ?'];
        $args = [$companyId];
        if ((int)($f['supplier'] ?? 0)) { $where[] = 'vc.supplier_id = ?'; $args[] = (int)$f['supplier']; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $where[] = '(vc.credit_number LIKE ? OR ca.name LIKE ?)'; $args[] = '%' . Fin::likeEscape($q) . '%'; $args[] = '%' . Fin::likeEscape($q) . '%'; }
        $st = $db->prepare("SELECT vc.*, COALESCE(ca.name, '(deleted supplier)') supplier,
                                   COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE credit_id = vc.id), 0) allocated
                              FROM vendor_credits vc LEFT JOIN crm_accounts ca ON ca.id = vc.supplier_id WHERE " . implode(' AND ', $where) . " ORDER BY vc.issue_date DESC, vc.id DESC LIMIT 200");
        $st->execute($args);
        return ['rows' => array_map([self::class, 'creditRow'], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    private static function creditRow(array $r): array
    {
        $posted = !empty($r['journal_id']);
        $left = round((float)$r['total'] - (float)$r['allocated'], 2);
        $status = $r['status'] === 'cancelled' ? 'cancelled' : (!$posted ? 'draft' : ($left > 0.005 ? 'open' : 'applied'));
        return ['id' => (int)$r['id'], 'number' => (string)$r['credit_number'], 'supplier_id' => (int)$r['supplier_id'], 'supplier' => (string)($r['supplier'] ?? ''),
                'date' => $r['issue_date'], 'total' => round((float)$r['total'], 2), 'allocated' => round((float)$r['allocated'], 2), 'remaining' => $left,
                'status' => $status, 'posted' => $posted, 'journal_id' => $posted ? (int)$r['journal_id'] : null, 'reason' => (string)($r['reason_code'] ?? ''), 'notes' => (string)($r['notes'] ?? '')];
    }

    public static function credit(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT vc.*, COALESCE(ca.name, '') supplier, COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE credit_id = vc.id), 0) allocated
                              FROM vendor_credits vc LEFT JOIN crm_accounts ca ON ca.id = vc.supplier_id WHERE vc.id = ? AND vc.company_id = ?");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new FinUserError('That supplier credit was not found.');
        $st = $db->prepare("SELECT l.*, ga.account_code, ga.account_name FROM vendor_credit_lines l LEFT JOIN gl_accounts ga ON ga.account_id = l.gl_account_id AND ga.company_id = ? WHERE l.credit_id = ? ORDER BY l.sort_order, l.id");
        $st->execute([$companyId, $id]);
        $lines = array_map(function ($l) {
            $n = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)$l['discount']) * 100);
            return ['description' => (string)$l['item_description'], 'qty' => (float)$l['quantity'], 'unit_price' => (float)$l['unit_price'], 'discount' => (float)$l['discount'],
                    'tax_rate' => (float)$l['tax_rate'], 'account_id' => $l['gl_account_id'] ? (int)$l['gl_account_id'] : null, 'account_code' => (string)($l['account_code'] ?? ''),
                    'account_name' => (string)($l['account_name'] ?? ''), 'net' => $n / 100, 'vat' => (int)round($n * (float)$l['tax_rate'] / 100) / 100];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT vca.bill_id, vca.amount, b.vendor_invoice_number FROM vendor_credit_allocations vca JOIN ap_bills b ON b.id = vca.bill_id WHERE vca.credit_id = ? AND b.company_id = ?");
        $st->execute([$id, $companyId]);
        require_once __DIR__ . '/FinFiles.php';
        return ['credit' => self::creditRow($r), 'lines' => $lines,
                'allocations' => array_map(fn($a) => ['bill_id' => (int)$a['bill_id'], 'number' => (string)$a['vendor_invoice_number'], 'amount' => round((float)$a['amount'], 2)], $st->fetchAll(PDO::FETCH_ASSOC)),
                'open_bills' => self::openBills($db, $companyId, (int)$r['supplier_id']), 'files' => FinFiles::list($db, $companyId, 'vendor_credit', $id)];
    }

    /** Create or edit a draft supplier credit. $in: id?, supplier_id, number, date, reason?, notes?, lines[] */
    public static function saveCredit(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        $sup = (int)($in['supplier_id'] ?? 0);
        $st = $db->prepare("SELECT 1 FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'");
        $st->execute([$sup, $companyId]);
        if (!$st->fetchColumn()) throw new FinUserError('Choose the supplier.');
        $number = mb_substr(trim(strip_tags((string)($in['number'] ?? ''))), 0, 100);
        if ($number === '') throw new FinUserError("Enter the supplier's credit note number.");
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose the credit note date.');
        $reason = in_array($in['reason'] ?? '', ['return', 'discount', 'correction', 'damaged', 'cancellation', 'other'], true) ? $in['reason'] : 'other';
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000);
        [$lines, $netC, $vatC] = self::cleanBillLines($db, $companyId, (array)($in['lines'] ?? []), true);
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $sup, $number, $date, $reason, $notes, $lines, $netC, $vatC) {
            $st = $db->prepare("SELECT id FROM vendor_credits WHERE company_id = ? AND credit_number = ? AND id <> ?");
            $st->execute([$companyId, $number, $id]);
            if ($st->fetchColumn()) throw new FinUserError("Credit note $number is already captured.");
            if ($id) {
                $st = $db->prepare("SELECT status, journal_id FROM vendor_credits WHERE id = ? AND company_id = ? FOR UPDATE");
                $st->execute([$id, $companyId]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if (!$c) throw new FinUserError('That supplier credit was not found.');
                if ($c['journal_id'] || $c['status'] !== 'draft') throw new FinUserError('A posted credit cannot change.', 409);
                $db->prepare("UPDATE vendor_credits SET supplier_id = ?, credit_number = ?, issue_date = ?, subtotal = ?, tax = ?, total = ?, notes = ?, reason_code = ? WHERE id = ? AND company_id = ?")
                   ->execute([$sup, $number, $date, Fin::rand($netC), Fin::rand($vatC), Fin::rand($netC + $vatC), $notes ?: null, $reason, $id, $companyId]);
                $db->prepare("DELETE FROM vendor_credit_lines WHERE credit_id = ?")->execute([$id]);
            } else {
                $db->prepare("INSERT INTO vendor_credits (company_id, supplier_id, credit_number, issue_date, status, subtotal, tax, total, notes, reason_code, created_by, created_at)
                              VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, NOW())")
                   ->execute([$companyId, $sup, $number, $date, Fin::rand($netC), Fin::rand($vatC), Fin::rand($netC + $vatC), $notes ?: null, $reason, $userId]);
                $id = (int)$db->lastInsertId();
            }
            $ins = $db->prepare("INSERT INTO vendor_credit_lines (credit_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, gl_account_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $i => $l) $ins->execute([$id, $l['description'], $l['qty'], $l['unit'], $l['unit_price'], $l['discount'], $l['tax_rate'], Fin::rand($l['net']), $l['account_id'], $i]);
            Fin::audit($db, $companyId, $userId, 'vendor_credit_saved', 'vendor_credit', $id, ['number' => $number, 'total_cents' => $netC + $vatC]);
            return $id;
        });
    }

    /** Post a draft credit once, in full. Optional first allocations in the same step. */
    public static function postCredit(PDO $db, int $companyId, int $userId, string $role, int $id, array $allocations = []): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $role, $id, $allocations) {
            $st = $db->prepare("SELECT status, journal_id FROM vendor_credits WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new FinUserError('That supplier credit was not found.');
            if ($c['journal_id']) throw new FinUserError('This credit is already in the books.', 409);
            if ($c['status'] === 'cancelled') throw new FinUserError('This credit was cancelled.', 409);
            (new PostingService($db, $companyId, $userId))->postVendorCredit($id);
            self::syncCreditStatus($db, $companyId, $id);
            if ($allocations) self::allocateCredit($db, $companyId, $userId, $role, $id, $allocations);
        });
    }

    /** Apply a posted credit to bills of the same supplier (no new posting). */
    public static function allocateCredit(PDO $db, int $companyId, int $userId, string $role, int $id, array $allocations): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $per = [];
        foreach ($allocations as $a) {
            $b = (int)($a['bill_id'] ?? 0);
            $c = Fin::cents($a['amount'] ?? '') ?? 0;
            if ($b && $c > 0) $per[$b] = ($per[$b] ?? 0) + $c;
        }
        if (!$per) throw new FinUserError('Enter how much of the credit goes against each bill.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $per) {
            $st = $db->prepare("SELECT * FROM vendor_credits WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new FinUserError('That supplier credit was not found.');
            if (!$c['journal_id']) throw new FinUserError('Post the credit before applying it to bills.', 409);
            $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM vendor_credit_allocations WHERE credit_id = ? FOR UPDATE");
            $st->execute([$id]);
            $leftC = (int)round((float)$c['total'] * 100) - (int)round((float)$st->fetchColumn() * 100);
            if (array_sum($per) > $leftC) throw new FinUserError('Only ' . Fin::money($leftC / 100) . ' of this credit is left to apply.');
            ksort($per);
            $lock = $db->prepare("SELECT supplier_id, status, journal_id, total, vendor_invoice_number FROM ap_bills WHERE id = ? AND company_id = ? FOR UPDATE");
            $paid = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM ap_payment_allocations WHERE bill_id = ?");
            $cred = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM vendor_credit_allocations WHERE bill_id = ?");
            $ins = $db->prepare("INSERT INTO vendor_credit_allocations (credit_id, bill_id, amount, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($per as $b => $amt) {
                $lock->execute([$b, $companyId]);
                $row = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new FinUserError("Bill #$b was not found.");
                $label = $row['vendor_invoice_number'] ?: "#$b";
                if ((int)$row['supplier_id'] !== (int)$c['supplier_id']) throw new FinUserError("Bill $label belongs to another supplier.");
                if ($row['status'] !== 'posted' || !$row['journal_id']) throw new FinUserError("Bill $label is not open.");
                $paid->execute([$b]);
                $cred->execute([$b]);
                $owe = (int)round((float)$row['total'] * 100) - (int)round((float)$paid->fetchColumn() * 100) - (int)round((float)$cred->fetchColumn() * 100);
                if ($amt > $owe) throw new FinUserError('That is more than the ' . Fin::money($owe / 100) . " still owing on bill $label.");
                $ins->execute([$id, $b, Fin::rand($amt)]);
                if ($owe - $amt <= 0) {
                    $db->prepare("UPDATE ap_bills SET status = 'paid' WHERE id = ? AND company_id = ?")->execute([$b, $companyId]);
                    Fin::audit($db, $companyId, $userId, 'ap_bill_status_change', 'ap_bill', $b, ['from' => 'posted', 'to' => 'paid', 'credit_id' => $id]);
                }
            }
            self::syncCreditStatus($db, $companyId, $id);
            Fin::audit($db, $companyId, $userId, 'vendor_credit_allocated', 'vendor_credit', $id, ['allocations' => $per]);
        });
    }

    /** approved = posted with something left; applied = fully allocated. */
    private static function syncCreditStatus(PDO $db, int $companyId, int $id): void
    {
        $st = $db->prepare("SELECT total, COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE credit_id = ?), 0) a FROM vendor_credits WHERE id = ? AND company_id = ?");
        $st->execute([$id, $id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $status = (int)round((float)$r['total'] * 100) - (int)round((float)$r['a'] * 100) > 0 ? 'approved' : 'applied';
        $db->prepare("UPDATE vendor_credits SET status = ? WHERE id = ? AND company_id = ? AND journal_id IS NOT NULL")->execute([$status, $id, $companyId]);
    }

    public static function deleteCredit(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $st = $db->prepare("SELECT journal_id, status, credit_number FROM vendor_credits WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new FinUserError('That supplier credit was not found.');
            if ($c['journal_id']) throw new FinUserError('A posted credit stays in the books.', 409);
            $db->prepare("DELETE FROM vendor_credit_lines WHERE credit_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM vendor_credits WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'vendor_credit_deleted', 'vendor_credit', $id, ['number' => $c['credit_number']]);
        });
    }

    // ------------------------------------------------------ suppliers, aging

    public static function suppliers(PDO $db, int $companyId, string $q = ''): array
    {
        $today = date('Y-m-d');
        $args = [$today, $today, $companyId];
        $like = '';
        if (trim($q) !== '') { $like = ' AND ca.name LIKE ?'; $args[] = '%' . Fin::likeEscape(trim($q)) . '%'; }
        $st = $db->prepare("SELECT ca.id, ca.name, COUNT(*) n, SUM(t.balance) bal, SUM(CASE WHEN t.due_date < ? THEN t.balance ELSE 0 END) late, MIN(t.due_date) next_due,
                                   MAX(DATEDIFF(?, t.due_date)) worst
                              FROM (SELECT b.id, b.supplier_id, b.due_date, (b.total - " . self::PAID . " - " . self::CREDITED . ") balance FROM ap_bills b
                                     WHERE b.company_id = ? AND b.status = 'posted') t
                              JOIN crm_accounts ca ON ca.id = t.supplier_id
                             WHERE t.balance > 0.005$like GROUP BY ca.id, ca.name ORDER BY late DESC, bal DESC LIMIT 300");
        $st->execute($args);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'bills' => (int)$r['n'], 'balance' => round((float)$r['bal'], 2),
                                    'late' => round((float)$r['late'], 2), 'next_due' => $r['next_due'], 'worst_days' => max(0, (int)$r['worst'])], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Supplier aging as at a date: payments and credits dated on or before it, credits by their journal. */
    public static function aging(PDO $db, int $companyId, string $asOf): array
    {
        require_once __DIR__ . '/FinReceivables.php';
        $st = $db->prepare("
            SELECT b.id, b.supplier_id, b.due_date,
                   b.total - COALESCE((SELECT SUM(apa.amount) FROM ap_payment_allocations apa JOIN ap_payments p ON p.id = apa.ap_payment_id
                                        WHERE apa.bill_id = b.id AND p.payment_date <= ? AND p.journal_id IS NOT NULL), 0)
                           - COALESCE((SELECT SUM(vca.amount) FROM vendor_credit_allocations vca JOIN vendor_credits vc ON vc.id = vca.credit_id
                                        WHERE vca.bill_id = b.id AND vc.issue_date <= ? AND vc.journal_id IS NOT NULL AND vc.status <> 'cancelled'), 0) bal
              FROM ap_bills b
             WHERE b.company_id = ? AND b.issue_date <= ? AND b.journal_id IS NOT NULL AND b.status IN ('posted','paid')");
        $st->execute([$asOf, $asOf, $companyId, $asOf]);
        return FinReceivables::bucketise($db, $companyId, $st->fetchAll(PDO::FETCH_ASSOC), $asOf, 'supplier_id');
    }

    /** Supplier statement: bills, payments and credits (posted) with a running balance owed. */
    public static function statement(PDO $db, int $companyId, int $supplierId, string $from, string $to): array
    {
        $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$supplierId, $companyId]);
        $sup = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sup) throw new FinUserError('That supplier was not found.');
        $st = $db->prepare("SELECT d, k, kind, ref, descr, debit, credit FROM (
              SELECT b.issue_date d, 1 k, 'bill' kind, b.vendor_invoice_number ref, CONCAT('Bill ', b.vendor_invoice_number) descr, 0 debit, b.total credit
                FROM ap_bills b WHERE b.company_id = ? AND b.supplier_id = ? AND b.journal_id IS NOT NULL AND b.status IN ('posted','paid')
              UNION ALL
              SELECT p.payment_date, 2, 'payment', COALESCE(NULLIF(p.reference, ''), CONCAT('Payment ', p.id)), 'Payment', p.amount, 0
                FROM ap_payments p WHERE p.company_id = ? AND p.supplier_id = ? AND p.journal_id IS NOT NULL
              UNION ALL
              SELECT vc.issue_date, 3, 'credit', vc.credit_number, CONCAT('Credit note ', vc.credit_number), vc.total, 0
                FROM vendor_credits vc WHERE vc.company_id = ? AND vc.supplier_id = ? AND vc.journal_id IS NOT NULL AND vc.status <> 'cancelled'
            ) t ORDER BY d, k, ref");
        $st->execute([$companyId, $supplierId, $companyId, $supplierId, $companyId, $supplierId]);
        $open = 0; $run = 0; $lines = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $owe = (int)round((float)$r['credit'] * 100) - (int)round((float)$r['debit'] * 100);
            if ($r['d'] < $from) { $open += $owe; continue; }
            if ($r['d'] > $to) continue;
            if (!$lines) $run = $open;
            $run += $owe;
            $lines[] = ['date' => $r['d'], 'kind' => (string)$r['kind'], 'reference' => (string)$r['ref'], 'description' => (string)$r['descr'],
                        'debit' => round((float)$r['debit'], 2), 'credit' => round((float)$r['credit'], 2), 'balance' => $run / 100];
        }
        if (!$lines) $run = $open;
        $ag = self::aging($db, $companyId, $to);
        $mine = array_values(array_filter($ag['rows'], fn($r) => $r['id'] === $supplierId))[0] ?? null;
        return ['supplier' => ['id' => (int)$sup['id'], 'name' => (string)$sup['name']], 'from' => $from, 'to' => $to, 'opening' => $open / 100, 'closing' => $run / 100, 'lines' => $lines, 'aging' => $mine];
    }

    // ------------------------------------------------------ three-way match

    public static function matchLines(PDO $db, int $companyId, int $supplierId): array
    {
        $sum = function (string $col) use ($db, $companyId): array {
            $st = $db->prepare("SELECT $col, SUM(qty_matched) FROM ap_match_links WHERE company_id = ? AND $col IS NOT NULL GROUP BY $col");
            $st->execute([$companyId]);
            return array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        };
        try {
            $mp = $sum('po_line_id'); $mg = $sum('grn_line_id'); $mb = $sum('bill_line_id');
            $st = $db->prepare("SELECT pol.id, pol.description, pol.qty, pol.unit_price, po.po_number FROM purchase_order_lines pol JOIN purchase_orders po ON po.id = pol.po_id
                                 WHERE po.company_id = ? AND po.supplier_id = ? AND po.status <> 'cancelled' ORDER BY po.po_number, pol.id");
            $st->execute([$companyId, $supplierId]);
            $po = array_map(fn($r) => ['id' => (int)$r['id'], 'doc' => (string)$r['po_number'], 'description' => (string)$r['description'], 'qty' => (float)$r['qty'],
                                      'price' => (float)$r['unit_price'], 'left' => (float)$r['qty'] - ($mp[(int)$r['id']] ?? 0)], $st->fetchAll(PDO::FETCH_ASSOC));
            $st = $db->prepare("SELECT gl.id, gl.qty_received, grn.grn_number, pol.description FROM grn_lines gl JOIN goods_received_notes grn ON grn.id = gl.grn_id JOIN purchase_orders po ON po.id = grn.po_id
                             LEFT JOIN purchase_order_lines pol ON pol.id = gl.po_line_id WHERE po.company_id = ? AND po.supplier_id = ? AND grn.status <> 'cancelled' ORDER BY grn.grn_number, gl.id");
            $st->execute([$companyId, $supplierId]);
            $grn = array_map(fn($r) => ['id' => (int)$r['id'], 'doc' => (string)$r['grn_number'], 'description' => (string)($r['description'] ?? ''), 'qty' => (float)$r['qty_received'],
                                       'left' => (float)$r['qty_received'] - ($mg[(int)$r['id']] ?? 0)], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $po = []; $grn = []; $mb = $mb ?? [];
        }
        $st = $db->prepare("SELECT bl.id, bl.item_description, bl.quantity, bl.unit_price, b.vendor_invoice_number FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
                             WHERE b.company_id = ? AND b.supplier_id = ? AND b.status NOT IN ('blocked') ORDER BY b.vendor_invoice_number, bl.id");
        $st->execute([$companyId, $supplierId]);
        $bill = array_map(fn($r) => ['id' => (int)$r['id'], 'doc' => (string)$r['vendor_invoice_number'], 'description' => (string)$r['item_description'], 'qty' => (float)$r['quantity'],
                                    'price' => (float)$r['unit_price'], 'left' => (float)$r['quantity'] - ($mb[(int)$r['id']] ?? 0)], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['po' => $po, 'grn' => $grn, 'bill' => $bill];
    }

    /** $matches: [{po_line_id?, grn_line_id?, bill_line_id?, qty}] — every line of this company and supplier, never more than is left. */
    public static function applyMatches(PDO $db, int $companyId, int $userId, string $role, int $supplierId, array $matches): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        if (!$matches || count($matches) > 200) throw new FinUserError('Choose what to match.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $supplierId, $matches) {
            $q = [
                'po' => $db->prepare("SELECT po.supplier_id, pol.qty q, COALESCE((SELECT SUM(qty_matched) FROM ap_match_links WHERE company_id = ? AND po_line_id = pol.id), 0) m
                                       FROM purchase_order_lines pol JOIN purchase_orders po ON po.id = pol.po_id WHERE pol.id = ? AND po.company_id = ? AND po.status <> 'cancelled' FOR UPDATE"),
                'grn' => $db->prepare("SELECT po.supplier_id, gl.qty_received q, COALESCE((SELECT SUM(qty_matched) FROM ap_match_links WHERE company_id = ? AND grn_line_id = gl.id), 0) m
                                        FROM grn_lines gl JOIN goods_received_notes grn ON grn.id = gl.grn_id JOIN purchase_orders po ON po.id = grn.po_id WHERE gl.id = ? AND po.company_id = ? AND grn.status <> 'cancelled' FOR UPDATE"),
                'bill' => $db->prepare("SELECT b.supplier_id, bl.quantity q, COALESCE((SELECT SUM(qty_matched) FROM ap_match_links WHERE company_id = ? AND bill_line_id = bl.id), 0) m
                                         FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id WHERE bl.id = ? AND b.company_id = ? AND b.status <> 'blocked' FOR UPDATE"),
            ];
            $ins = $db->prepare("INSERT INTO ap_match_links (company_id, po_line_id, grn_line_id, bill_line_id, qty_matched, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $used = ['po' => [], 'grn' => [], 'bill' => []];
            $n = 0;
            foreach (array_values($matches) as $i => $m) {
                $ids = ['po' => (int)($m['po_line_id'] ?? 0) ?: null, 'grn' => (int)($m['grn_line_id'] ?? 0) ?: null, 'bill' => (int)($m['bill_line_id'] ?? 0) ?: null];
                $qty = round((float)($m['qty'] ?? 0), 3);
                if ($qty <= 0) throw new FinUserError('Match ' . ($i + 1) . ': the quantity must be more than nil.');
                if (count(array_filter($ids)) < 2) throw new FinUserError('Match ' . ($i + 1) . ': link at least two of order, delivery and bill.');
                foreach ($ids as $kind => $lid) {
                    if (!$lid) continue;
                    $q[$kind]->execute([$companyId, $lid, $companyId]);
                    $row = $q[$kind]->fetch(PDO::FETCH_ASSOC);
                    if (!$row) throw new FinUserError('Match ' . ($i + 1) . ': a line was not found.');
                    if ((int)$row['supplier_id'] !== $supplierId) throw new FinUserError('Match ' . ($i + 1) . ': the lines belong to different suppliers.');
                    $left = (float)$row['q'] - (float)$row['m'] - ($used[$kind][$lid] ?? 0);
                    if ($qty > $left + 0.0001) throw new FinUserError('Match ' . ($i + 1) . ': only ' . rtrim(rtrim(number_format(max(0, $left), 3, '.', ''), '0'), '.') . ' is left on that line.');
                }
                $ins->execute([$companyId, $ids['po'], $ids['grn'], $ids['bill'], $qty, $userId]);
                foreach ($ids as $kind => $lid) if ($lid) $used[$kind][$lid] = ($used[$kind][$lid] ?? 0) + $qty;
                $n++;
            }
            Fin::audit($db, $companyId, $userId, 'ap_three_way_matched', 'supplier', $supplierId, ['matches' => $n]);
            return $n;
        });
    }
}
