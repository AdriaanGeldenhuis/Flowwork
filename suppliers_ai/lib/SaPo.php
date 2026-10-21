<?php
// /suppliers_ai/lib/SaPo.php — the awarded quote becomes a draft purchase order
// in Procurement, for admins and bookkeepers. The number comes from the
// company's document sequence (PO-2026-0001 …) inside the same transaction, so
// two orders never share one (the old module used rand() and an "RFQ-" number,
// and every order was R 0.00). The line holds the price without VAT when the
// supplier is VAT registered, the way Procurement adds VAT back on its lines.

require_once dirname(__DIR__, 2) . '/finances/lib/Sequence.php';

final class SaPo
{
    public const VAT_RATE = 15.0;

    public static function create(PDO $db, int $companyId, int $userId, string $role, int $requestId): array
    {
        if (!SaKit::canOrder($role)) throw new SaForbidden('Only admins and bookkeepers can make purchase orders.');
        if (!$db->query("SHOW TABLES LIKE 'purchase_orders'")->fetchColumn()) throw new SaProblem('Procurement is not set up for this company.', 409);
        $db->beginTransaction();
        try {
            $r = SaRequests::row($db, $companyId, $requestId, false, true);
            if ($r['status'] !== 'awarded' || !$r['awarded_id']) throw new SaProblem('Choose the supplier first.', 409);
            if ($r['po_id']) throw new SaProblem('This request already has a purchase order.', 409);
            $st = $db->prepare("SELECT rs.*, a.vat_no, a.status AS account_status FROM sai_request_suppliers rs JOIN crm_accounts a ON a.id = rs.account_id AND a.company_id = rs.company_id AND a.deleted_at IS NULL
                                 WHERE rs.id = ? AND rs.request_id = ? AND rs.company_id = ?");
            $st->execute([(int)$r['awarded_id'], $requestId, $companyId]);
            $w = $st->fetch(PDO::FETCH_ASSOC);
            if (!$w) throw new SaProblem('The chosen supplier is no longer in the CRM.', 409);
            if ($w['quote_cents'] === null) throw new SaProblem('Write down ' . $w['name'] . '’s quote first — the order is made from it.', 422);
            $state = CrmCompliance::states($db, $companyId, [(int)$w['account_id'] => 'supplier'])[(int)$w['account_id']] ?? null;
            if (!empty($state['blocking'])) throw new SaProblem($w['name'] . '’s documents are ' . $state['status'] . ' — your company’s policy blocks new orders until they are renewed.', 409);

            $registered = trim((string)$w['vat_no']) !== '';
            $vat = (string)$w['quote_vat'];
            $rate = $registered && $vat !== 'none' ? self::VAT_RATE : 0.0;
            $cents = (int)$w['quote_cents'];
            // The line's unit price excludes VAT; "VAT included" quotes from registered suppliers are taken back to the price before VAT
            $unit = $rate > 0 && $vat === 'incl' ? round($cents / 100 / (1 + $rate / 100), 2) : round($cents / 100, 2);
            $total = round($unit * (1 + $rate / 100), 2);
            $desc = trim($r['title'] . (trim((string)$r['quantity']) !== '' && stripos((string)$r['title'], (string)$r['quantity']) === false ? ' — ' . $r['quantity'] : ''));
            if (trim((string)$r['details']) !== '') $desc .= "\n" . mb_substr((string)$r['details'], 0, 1500);
            $desc .= "\nQuote from " . $w['name'] . ($w['quoted_at'] ? ' on ' . date('j M Y', strtotime($w['quoted_at'])) : '') . ($w['lead_days'] !== null ? ', lead time ' . (int)$w['lead_days'] . ' days' : '') . ' (Suppliers AI request #' . $requestId . ')';

            $seq = new Sequence($db, $companyId);
            $chk = $db->prepare("SELECT 1 FROM purchase_orders WHERE company_id = ? AND po_number = ?");
            $tries = 0;
            do {
                $number = $seq->issue('PO', ['prefix' => 'PO-{YYYY}-', 'period_key' => date('Y')]);
                $chk->execute([$companyId, $number]);
                $taken = (bool)$chk->fetchColumn();
            } while ($taken && ++$tries < 50);
            if ($taken) throw new SaProblem('Could not find a free purchase order number.', 409);

            $db->prepare("INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at) VALUES (?, ?, ?, ?, 'draft', NOW())")
               ->execute([$companyId, $number, (int)$w['account_id'], $total]);
            $poId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO purchase_order_lines (po_id, description, qty, unit, unit_price, tax_rate, gl_account_id) VALUES (?, ?, 1, 'ea', ?, ?, NULL)")
               ->execute([$poId, SaKit::latin1($desc), $unit, $rate]);
            $db->prepare("UPDATE sai_requests SET po_id = ?, updated_at = NOW() WHERE id = ?")->execute([$poId, $requestId]);
            $db->prepare("UPDATE crm_accounts SET status = 'active', updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'prospect'")->execute([(int)$w['account_id'], $companyId]);
            SaRequests::event($db, $companyId, $requestId, (int)$w['id'], $userId, 'po', ['po_id' => $poId, 'number' => $number, 'total' => (int)round($total * 100)]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        SaKit::audit($db, $companyId, $userId, 'sai_request_po', 'purchase_order', $poId, ['request_id' => $requestId, 'supplier_id' => (int)$w['account_id'], 'po_number' => $number, 'total' => $total]);
        return ['po_id' => $poId, 'number' => $number, 'total' => (int)round($total * 100), 'url' => '/procurement/po_view.php?id=' . $poId, 'supplier' => (string)$w['name']];
    }
}
