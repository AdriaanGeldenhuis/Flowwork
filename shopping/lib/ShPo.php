<?php
// /shopping/lib/ShPo.php — a list (or some of it) becomes a draft purchase
// order in Procurement, for admins and bookkeepers. Numbers come from the
// company's document sequence (PO-2026-0001 …) inside the same transaction,
// so two orders never share a number. Prices are typed as they would be paid
// (VAT included); the order holds them excluding VAT when the supplier has a
// VAT number, the way Procurement adds VAT back on its lines.

require_once dirname(__DIR__, 2) . '/finances/lib/Sequence.php';

final class ShPo
{
    public const VAT_RATE = 15.0;

    /** Suppliers in the CRM an order can go to. */
    public static function suppliers(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT id, name, vat_no, preferred FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' AND deleted_at IS NULL ORDER BY preferred DESC, name LIMIT 1000");
        $st->execute([$companyId]);
        return array_map(fn($a) => ['id' => (int)$a['id'], 'name' => (string)$a['name'], 'vat' => trim((string)$a['vat_no']) !== '', 'preferred' => (bool)$a['preferred']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array $lines [{id, price}] — price: rand for one, VAT included (empty: nought, filled in on the order later)
     * @return array{po_id:int, po_number:string, total:float, lines:int, url:string}
     */
    public static function create(PDO $db, int $companyId, int $userId, string $role, int $listId, int $supplierId, array $lines): array
    {
        if (!ShKit::canOrder($role)) throw new ShForbidden('Only admins and bookkeepers can make purchase orders.');
        if (!$db->query("SHOW TABLES LIKE 'purchase_orders'")->fetchColumn()) throw new ShProblem('Procurement is not set up for this company.', 409);
        $st = $db->prepare("SELECT id, name, vat_no FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND status = 'active' AND deleted_at IS NULL");
        $st->execute([$supplierId, $companyId]);
        $sup = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sup) throw new ShProblem('Choose a supplier from the CRM.', 422);
        $rate = trim((string)$sup['vat_no']) !== '' ? self::VAT_RATE : 0.0;
        $want = [];
        foreach ($lines as $l) {
            $id = is_array($l) ? (int)($l['id'] ?? 0) : 0;
            if ($id > 0) $want[$id] = ShKit::price($l['price'] ?? null, 'A price');
        }
        if (!$want) throw new ShProblem('Choose what goes on the order.', 422);

        $db->beginTransaction();
        try {
            ShLists::access($db, $companyId, $userId, $role, $listId, 'edit', true);
            $in = implode(',', array_map('intval', array_keys($want)));
            $st = $db->prepare("SELECT id, name_raw, qty, unit, po_id FROM shopping_items WHERE list_id = ? AND id IN ($in) AND status NOT IN ('bought', 'removed') ORDER BY order_index, id FOR UPDATE");
            $st->execute([$listId]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($items) !== count($want)) throw new ShProblem('Some of those items were bought or removed meanwhile — reload and choose again.', 409);
            foreach ($items as $i) if ($i['po_id']) throw new ShProblem('“' . $i['name_raw'] . '” is already on a purchase order.', 409);

            $seq = new Sequence($db, $companyId);
            $chk = $db->prepare("SELECT 1 FROM purchase_orders WHERE company_id = ? AND po_number = ?");
            $tries = 0;
            do {
                $number = $seq->issue('PO', ['prefix' => 'PO-{YYYY}-', 'period_key' => date('Y')]);
                $chk->execute([$companyId, $number]);
                $taken = (bool)$chk->fetchColumn();
            } while ($taken && ++$tries < 50);
            if ($taken) throw new ShProblem('Could not find a free purchase order number.', 409);

            $total = 0.0;
            $rows = [];
            foreach ($items as $i) {
                $incl = $want[(int)$i['id']];
                $unit = $incl === null ? 0.0 : round($incl / 100 / (1 + $rate / 100), 2);
                $qty = (float)$i['qty'];
                $total += $qty * $unit * (1 + $rate / 100);
                $rows[] = [$i['name_raw'], $qty, ShParse::unit((string)$i['unit']), $unit];
            }
            $db->prepare("INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at) VALUES (?, ?, ?, ?, 'draft', NOW())")
               ->execute([$companyId, $number, $supplierId, round($total, 2)]);
            $poId = (int)$db->lastInsertId();
            $ln = $db->prepare("INSERT INTO purchase_order_lines (po_id, description, qty, unit, unit_price, tax_rate, gl_account_id) VALUES (?, ?, ?, ?, ?, ?, NULL)");
            foreach ($rows as [$desc, $qty, $unit, $price]) $ln->execute([$poId, $desc, $qty, $unit, $price, $rate]);
            $db->prepare("UPDATE shopping_items SET po_id = ?, updated_at = NOW() WHERE list_id = ? AND id IN ($in)")->execute([$poId, $listId]);
            ShKit::act($db, $companyId, $listId, null, $userId, 'po', ['po_id' => $poId, 'po_number' => $number, 'count' => count($rows), 'supplier' => $sup['name']]);
            ShLists::touch($db, $listId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        ShKit::audit($db, $companyId, $userId, 'shopping_to_po', 'purchase_order', $poId, ['list_id' => $listId, 'supplier_id' => $supplierId, 'lines' => count($rows), 'po_number' => $number]);
        return ['po_id' => $poId, 'po_number' => $number, 'total' => round($total, 2), 'lines' => count($rows), 'supplier' => (string)$sup['name'], 'url' => '/procurement/po_view.php?id=' . $poId];
    }
}
