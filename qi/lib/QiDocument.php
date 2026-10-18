<?php
/**
 * QiDocument — one in-memory model of a quote, invoice or credit note.
 *
 * Every renderer (DocHtml for the screen, print page, public link and the
 * designer preview; DocPdf for the emailed / downloaded / Drive PDF) reads this
 * model, so they can never disagree about what the document says. Amounts are
 * recomputed from the lines with QiMoney (the ledger's rounding) instead of
 * trusting stored line totals, which older code paths filled inconsistently
 * (net on quotes, VAT-inclusive on invoices).
 *
 * All joins are tenant-safe: the customer, contact, project and allocations
 * must belong to the document's company.
 */

require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/LineHeadings.php';
require_once __DIR__ . '/Currencies.php';

final class QiDocument
{
    public const TYPES = ['quote', 'invoice', 'credit_note'];

    /** Load a document for rendering; null when it does not exist in this company. */
    public static function load(PDO $db, int $companyId, string $type, int $id): ?array
    {
        if (!in_array($type, self::TYPES, true) || $id <= 0) return null;
        $table = self::table($type);
        $st = $db->prepare("SELECT * FROM `$table` WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return null;

        $company = self::company($db, $companyId);
        $customer = self::customer($db, $companyId, (int)$doc['customer_id'], (int)($doc['contact_id'] ?? 0));

        $lineTable = self::lineTable($type);
        $fk = $type === 'credit_note' ? 'credit_note_id' : ($type . '_id');
        $st = $db->prepare("SELECT l.*, tc.code AS tax_code FROM `$lineTable` l
                              LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = l.tax_code_id AND tc.company_id = ?
                             WHERE l.`$fk` = ? ORDER BY l.sort_order, l.id");
        $st->execute([$companyId, $id]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $headings = $type === 'credit_note' ? [] : LineHeadings::fetch($db, $type, $id);

        $m = self::base($type, $doc, $company, $customer);
        $m['rows'] = self::rows(LineHeadings::merge($lines, $headings));
        $m['totals'] = self::totals($m['rows'], $doc, $type);

        if ($type === 'quote') {
            $m['expiry_date'] = $doc['expiry_date'] ?? null;
            $m['accepted'] = !empty($doc['accepted_at']) ? ['name' => (string)($doc['accepted_by_name'] ?? ''), 'at' => $doc['accepted_at']] : null;
            $m['links']['public'] = !empty($doc['public_token']) ? self::url('/qi/p/quote.php?token=' . rawurlencode($doc['public_token'])) : null;
            $st = $db->prepare("SELECT id, invoice_number FROM invoices WHERE quote_id = ? AND company_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $st->execute([$id, $companyId]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            $m['invoice'] = $inv ? ['id' => (int)$inv['id'], 'number' => $inv['invoice_number']] : null;
        } elseif ($type === 'invoice') {
            $m['due_date'] = $doc['due_date'] ?? null;
            $m['payments'] = self::payments($db, $companyId, $id);
            $m['credits'] = self::credits($db, $companyId, $id);
            $m['links']['public'] = !empty($doc['public_token']) ? self::url('/qi/p/invoice.php?token=' . rawurlencode($doc['public_token'])) : null;
            // "Pay now" opens the customer page, which starts a card checkout for the balance at that moment
            require_once __DIR__ . '/QiYoco.php';
            $m['links']['pay'] = !empty($doc['public_token']) && QiYoco::payable($doc) && QiYoco::configured($db, $companyId)
                ? self::url('/qi/p/invoice.php?token=' . rawurlencode($doc['public_token']) . '&pay=1') : null;
            if (!empty($doc['quote_id'])) {
                $st = $db->prepare("SELECT id, quote_number FROM quotes WHERE id = ? AND company_id = ?");
                $st->execute([(int)$doc['quote_id'], $companyId]);
                $q = $st->fetch(PDO::FETCH_ASSOC);
                $m['quote'] = $q ? ['id' => (int)$q['id'], 'number' => $q['quote_number']] : null;
            }
        } else {
            $m['reason'] = (string)($doc['reason'] ?? '');
            if (!empty($doc['invoice_id'])) {
                $st = $db->prepare("SELECT id, invoice_number, issue_date FROM invoices WHERE id = ? AND company_id = ?");
                $st->execute([(int)$doc['invoice_id'], $companyId]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                $m['invoice'] = $inv ? ['id' => (int)$inv['id'], 'number' => $inv['invoice_number'], 'date' => $inv['issue_date']] : null;
            }
        }
        if ($type !== 'credit_note' && !empty($doc['has_milestones'])) {
            $m['milestones'] = self::milestones($db, $companyId, $type, $id);
        }
        if (!empty($doc['project_id'])) {
            $st = $db->prepare("SELECT name FROM projects WHERE project_id = ? AND company_id = ?");
            try { $st->execute([(int)$doc['project_id'], $companyId]); $m['project'] = $st->fetchColumn() ?: null; } catch (Throwable $e) { $m['project'] = null; }
        }
        $m['status'] = self::status($type, $doc, $m['totals']);
        return $m;
    }

    /**
     * A believable example document for the designer, in this company's name.
     * $company is a companyBlock() (or a raw companies row).
     */
    public static function sample(array $company, string $type): array
    {
        if (!isset($company['address']) || !is_array($company['address'])) $company = self::companyBlock($company);
        $type = in_array($type, self::TYPES, true) ? $type : 'quote';
        $today = date('Y-m-d');
        $doc = [
            'id' => 0, 'currency' => 'ZAR', 'exchange_rate' => 1, 'issue_date' => $today,
            'expiry_date' => date('Y-m-d', strtotime('+14 days')), 'due_date' => date('Y-m-d', strtotime('+30 days')),
            'status' => 'sent', 'reference' => 'PO 4471-B',
            'terms' => "A 50% deposit secures the start date; the balance is due on completion.\nPrices are valid until the date shown above.",
            'customer_notes' => 'Thank you for the opportunity — we look forward to working with you.',
            'quote_number' => 'Q' . date('Y') . '-0042', 'invoice_number' => 'INV' . date('Y') . '-0108', 'credit_note_number' => 'CN' . date('Y') . '-0007',
            'reason' => 'Two balustrade panels returned unused.',
        ];
        $customer = [
            'id' => 0, 'name' => 'Riverside Lofts', 'legal_name' => 'Riverside Lofts (Pty) Ltd', 'contact' => 'Thandi Mokoena', 'email' => 'accounts@riversidelofts.co.za',
            'phone' => '021 555 0142', 'vat' => '4987654321', 'reg' => '2018/123456/07',
            'address' => ['12 Dock Road', 'V&A Waterfront', 'Cape Town, 8001'],
        ];
        $m = self::base($type, $doc, $company, $customer);
        $rows = [
            ['_kind' => 'heading', 'title' => 'Site preparation'],
            ['_kind' => 'item', 'item_description' => "Site clearing and levelling\nIncludes removal of rubble to an approved dump site", 'quantity' => 1, 'unit' => 'job', 'unit_price' => 18500, 'discount' => 0, 'tax_rate' => 15],
            ['_kind' => 'item', 'item_description' => 'Compaction to 95% Mod AASHTO', 'quantity' => 240, 'unit' => 'm²', 'unit_price' => 38.5, 'discount' => 0, 'tax_rate' => 15],
            ['_kind' => 'heading', 'title' => 'Concrete works'],
            ['_kind' => 'item', 'item_description' => '25 MPa ready-mix, supplied and placed', 'quantity' => 36, 'unit' => 'm³', 'unit_price' => 2150, 'discount' => 3870, 'tax_rate' => 15],
            ['_kind' => 'item', 'item_description' => 'Steel mesh reinforcement (Ref 193)', 'quantity' => 120, 'unit' => 'm²', 'unit_price' => 96, 'discount' => 0, 'tax_rate' => 15],
            ['_kind' => 'item', 'item_description' => 'Engineer inspection and sign-off', 'quantity' => 2, 'unit' => 'visit', 'unit_price' => 2400, 'discount' => 0, 'tax_rate' => 0, 'tax_code' => 'ZERO'],
        ];
        if ($type === 'credit_note') {
            $rows = [
                ['_kind' => 'item', 'item_description' => 'Glass balustrade panel 1.2 m (returned)', 'quantity' => 2, 'unit' => 'ea', 'unit_price' => 3250, 'discount' => 0, 'tax_rate' => 15],
            ];
        }
        $m['rows'] = self::rows($rows);
        $m['totals'] = self::totals($m['rows'], ['total' => null], $type);
        if ($type === 'invoice') {
            $paid = round($m['totals']['total'] * 0.5, 2);
            $m['payments'] = [['date' => date('Y-m-d', strtotime('-3 days')), 'method' => 'EFT', 'reference' => 'Deposit', 'amount' => $paid]];
            $m['credits'] = [];
            $m['totals']['paid'] = $paid;
            $m['totals']['balance'] = round($m['totals']['total'] - $paid, 2);
            $m['due_date'] = $doc['due_date'];
            $m['links']['pay'] = 'https://pay.yoco.com/';
        }
        if ($type === 'quote') {
            $m['expiry_date'] = $doc['expiry_date'];
            $total = $m['totals']['total'];
            $m['milestones'] = [
                ['label' => 'Deposit on acceptance', 'percentage' => 50, 'amount' => round($total / 2, 2), 'due_date' => null, 'status' => 'due', 'paid' => 0],
                ['label' => 'On completion', 'percentage' => 50, 'amount' => round($total - round($total / 2, 2), 2), 'due_date' => null, 'status' => 'pending', 'paid' => 0],
            ];
            $m['links']['public'] = self::url('/qi/p/quote.php?token=sample');
        }
        if ($type === 'credit_note') {
            $m['reason'] = $doc['reason'];
            $m['invoice'] = ['id' => 0, 'number' => 'INV' . date('Y') . '-0101', 'date' => date('Y-m-d', strtotime('-20 days'))];
        }
        $m['project'] = 'Riverside Lofts — Phase 2';
        $m['status'] = self::status($type, $doc + ['balance_due' => $m['totals']['balance'] ?? null], $m['totals']);
        $m['sample'] = true;
        return $m;
    }

    /**
     * The model of an unsaved editor draft, for the live preview. $d is
     * QiWriter::normalize() output (lenient); $row the stored document when
     * editing; $number the document's number or the next one. A recurring
     * template previews as the invoice it will generate.
     */
    public static function fromDraft(PDO $db, int $companyId, array $d, ?array $row, string $number): array
    {
        $type = $d['type'] === 'recurring' ? 'invoice' : $d['type'];
        $company = self::company($db, $companyId);
        $customer = !empty($d['customer_id'])
            ? self::customer($db, $companyId, (int)$d['customer_id'], (int)($d['contact_id'] ?? 0))
            : ['id' => 0, 'name' => 'Your customer', 'legal_name' => '', 'contact' => '', 'email' => '', 'phone' => '', 'vat' => '', 'reg' => '', 'address' => [], 'found' => false];
        $numberKey = ['quote' => 'quote_number', 'invoice' => 'invoice_number', 'credit_note' => 'credit_note_number'][$type];
        $doc = [
            'id' => (int)($row['id'] ?? 0), $numberKey => $number, 'issue_date' => $d['issue_date'] ?? date('Y-m-d'),
            'reference' => $d['reference'] ?? '', 'currency' => $d['currency'] ?? Currencies::BASE, 'exchange_rate' => $d['exchange_rate'] ?? 1,
            'terms' => $d['terms'] ?? '', 'customer_notes' => $d['customer_notes'] ?? '',
            'status' => $d['type'] === 'recurring' ? 'sent' : (string)($row['status'] ?? 'draft'),
            'expiry_date' => $d['expiry_date'] ?? null, 'accepted_at' => null,
        ];
        if ($d['type'] === 'recurring') {
            $days = (int)($d['payment_terms_days'] ?? 30);
            $doc['due_date'] = date('Y-m-d', strtotime($doc['issue_date'] . ' +' . $days . ' days'));
        } else {
            $doc['due_date'] = $d['due_date'] ?? null;
        }
        $m = self::base($type, $doc, $company, $customer);
        $merged = [];
        foreach ($d['rows'] ?? [] as $r) {
            if ($r['kind'] === 'heading') {
                $merged[] = ['_kind' => 'heading', 'title' => $r['title']];
            } else {
                $merged[] = ['_kind' => 'item', 'item_description' => $r['description'], 'quantity' => $r['qty'], 'unit' => $r['unit'],
                             'unit_price' => $r['price'], 'discount' => $r['discount'], 'tax_rate' => $r['rate'], 'tax_code' => $r['tax_code']];
            }
        }
        $m['rows'] = self::rows($merged);
        $m['totals'] = self::totals($m['rows'], ['total' => null], $type);
        if ($type === 'quote') {
            $m['expiry_date'] = $doc['expiry_date'];
            $m['accepted'] = null;
            $m['links']['public'] = !empty($row['public_token']) ? self::url('/qi/p/quote.php?token=' . rawurlencode($row['public_token'])) : null;
        } elseif ($type === 'invoice') {
            $m['due_date'] = $doc['due_date'];
            $m['links']['public'] = !empty($row['public_token']) ? self::url('/qi/p/invoice.php?token=' . rawurlencode($row['public_token'])) : null;
            $m['links']['pay'] = null;
        } else {
            $m['reason'] = (string)($d['reason'] ?? '');
            if (!empty($d['invoice'])) {
                $m['invoice'] = ['id' => (int)$d['invoice']['id'], 'number' => $d['invoice']['invoice_number'], 'date' => $d['invoice']['issue_date']];
            }
        }
        foreach ($d['milestones'] ?? [] as $i => $ms) {
            $m['milestones'][] = ['label' => $ms['label'], 'percentage' => (float)$ms['percentage'], 'amount' => $ms['amount_c'] / 100,
                                  'due_date' => $ms['due_date'], 'status' => $i === 0 ? 'due' : 'pending', 'paid' => 0.0];
        }
        if (!empty($d['project_id'])) {
            $st = $db->prepare("SELECT name FROM projects WHERE project_id = ? AND company_id = ?");
            try { $st->execute([(int)$d['project_id'], $companyId]); $m['project'] = $st->fetchColumn() ?: null; } catch (Throwable $e) { $m['project'] = null; }
        }
        $m['status'] = self::status($type, $doc + ['balance_due' => $m['totals']['balance'] ?? null], $m['totals']);
        return $m;
    }

    // ------------------------------------------------------------- pieces

    public static function table(string $type): string
    {
        return ['quote' => 'quotes', 'invoice' => 'invoices', 'credit_note' => 'credit_notes'][$type];
    }

    public static function lineTable(string $type): string
    {
        return ['quote' => 'quote_lines', 'invoice' => 'invoice_lines', 'credit_note' => 'credit_note_lines'][$type];
    }

    public static function numberOf(string $type, array $row): string
    {
        return (string)($row[['quote' => 'quote_number', 'invoice' => 'invoice_number', 'credit_note' => 'credit_note_number'][$type]] ?? '');
    }

    /** The company's letterhead values (tenant row already fetched). */
    public static function company(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        try {
            $st = $db->prepare("SELECT bank_account_holder, bank_name, bank_account_number, bank_branch_code FROM qi_settings WHERE company_id = ?");
            $st->execute([$companyId]);
            $q = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $q = [];
        }
        return self::companyBlock($c, $q);
    }

    public static function companyBlock(array $c, array $qi = []): array
    {
        $root = dirname(__DIR__, 2);
        $logoUrl = (string)($c['logo_url'] ?? '');
        $logoPath = null;
        if ($logoUrl !== '' && str_starts_with($logoUrl, '/uploads/')) {
            $p = realpath($root . $logoUrl);
            if ($p && str_starts_with($p, realpath($root . '/uploads') ?: '/nonexistent') && is_file($p)) $logoPath = $p;
        }
        $cityLine = trim(implode(', ', array_filter([trim((string)($c['city'] ?? '')), trim((string)($c['postal'] ?? ''))])));
        return [
            'id' => (int)($c['id'] ?? 0),
            'name' => (string)($c['name'] ?? 'Company'),
            'logo_url' => $logoPath ? $logoUrl : null,
            'logo_path' => $logoPath,
            'address' => array_values(array_filter([trim((string)($c['address_line1'] ?? '')), trim((string)($c['address_line2'] ?? '')), $cityLine, trim((string)($c['region'] ?? ''))])),
            'phone' => trim((string)($c['phone'] ?? '')),
            'email' => trim((string)($c['email'] ?? '')),
            'website' => trim((string)($c['website'] ?? '')),
            'vat' => trim((string)($c['vat_number'] ?? '')),
            'tax' => trim((string)($c['tax_number'] ?? '')),
            'reg' => trim((string)($c['reg_number'] ?? '')),
            'bank' => [
                'holder' => trim((string)($qi['bank_account_holder'] ?? '')) ?: (string)($c['name'] ?? ''),
                'name' => trim((string)($c['bank_name'] ?? '')) ?: trim((string)($qi['bank_name'] ?? '')),
                'account' => trim((string)($c['bank_account_number'] ?? '')) ?: trim((string)($qi['bank_account_number'] ?? '')),
                'branch' => trim((string)($c['bank_branch_code'] ?? '')) ?: trim((string)($qi['bank_branch_code'] ?? '')),
            ],
            'raw' => $c,
        ];
    }

    /** Customer + billing address + contact, only within this company. */
    public static function customer(PDO $db, int $companyId, int $customerId, int $contactId = 0): array
    {
        $st = $db->prepare("SELECT * FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$customerId, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $addr = [];
        if ($a) {
            $st = $db->prepare("SELECT * FROM crm_addresses WHERE account_id = ? AND company_id = ?
                                 ORDER BY FIELD(type, 'billing', 'head_office', 'shipping', 'site'), id LIMIT 1");
            $st->execute([$customerId, $companyId]);
            $addr = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $contact = null;
        if ($contactId > 0 && $a) {
            $st = $db->prepare("SELECT * FROM crm_contacts WHERE id = ? AND account_id = ? AND company_id = ?");
            $st->execute([$contactId, $customerId, $companyId]);
            $contact = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $cityLine = trim(implode(', ', array_filter([trim((string)($addr['city'] ?? '')), trim((string)($addr['postal_code'] ?? ''))])));
        return [
            'id' => $customerId,
            'name' => (string)($a['name'] ?? '') !== '' ? (string)$a['name'] : 'Customer',
            'legal_name' => trim((string)($a['legal_name'] ?? '')) !== '' && strcasecmp(trim((string)$a['legal_name']), trim((string)($a['name'] ?? ''))) !== 0 ? trim((string)$a['legal_name']) : '',
            'contact' => $contact ? trim($contact['first_name'] . ' ' . $contact['last_name']) : '',
            'email' => $contact && !empty($contact['email']) ? (string)$contact['email'] : (string)($a['email'] ?? ''),
            'phone' => $contact && !empty($contact['phone']) ? (string)$contact['phone'] : (string)($a['phone'] ?? ''),
            'vat' => trim((string)($a['vat_no'] ?? '')),
            'reg' => trim((string)($a['reg_no'] ?? '')),
            'address' => array_values(array_filter([trim((string)($addr['line1'] ?? '')), trim((string)($addr['line2'] ?? '')), $cityLine, trim((string)($addr['region'] ?? ''))])),
            'found' => (bool)$a,
        ];
    }

    private static function base(string $type, array $doc, array $company, array $customer): array
    {
        $currency = Currencies::isValid($doc['currency'] ?? null) ? strtoupper($doc['currency']) : Currencies::BASE;
        return [
            'type' => $type,
            'id' => (int)($doc['id'] ?? 0),
            'number' => self::numberOf($type, $doc),
            'issue_date' => $doc['issue_date'] ?? null,
            'reference' => trim((string)($doc['reference'] ?? '')),
            'currency' => $currency,
            'symbol' => Currencies::symbol($currency),
            'exchange_rate' => (float)($doc['exchange_rate'] ?? 1) ?: 1.0,
            'terms' => trim((string)($doc['terms'] ?? '')),
            'notes' => trim((string)($doc['customer_notes'] ?? '')),
            'company' => $company,
            'customer' => $customer,
            'links' => ['public' => null, 'pay' => null],
            'milestones' => [],
            'payments' => [],
            'credits' => [],
            'project' => null,
            'raw' => $doc,
        ];
    }

    /** Normalise merged rows and recompute every line with the ledger's rounding. */
    private static function rows(array $merged): array
    {
        $out = [];
        $section = null;
        $flush = function () use (&$out, &$section) {
            if ($section !== null && $section['count'] > 0) {
                $out[] = ['kind' => 'section_total', 'title' => $section['title'], 'net' => $section['net'] / 100];
            }
            $section = null;
        };
        foreach ($merged as $r) {
            if (($r['_kind'] ?? 'item') === 'heading') {
                $flush();
                $title = (string)($r['title'] ?? $r['item_description'] ?? '');
                $out[] = ['kind' => 'heading', 'title' => $title];
                $section = ['title' => $title, 'net' => 0, 'count' => 0];
                continue;
            }
            $l = QiMoney::line($r['quantity'] ?? 0, $r['unit_price'] ?? 0, $r['discount'] ?? 0, $r['tax_rate'] ?? 0);
            $out[] = [
                'kind' => 'item',
                'description' => (string)($r['item_description'] ?? ''),
                'qty' => (float)($r['quantity'] ?? 0),
                'unit' => trim((string)($r['unit'] ?? '')),
                'price' => (float)($r['unit_price'] ?? 0),
                'discount' => (float)($r['discount'] ?? 0),
                'rate' => (float)($r['tax_rate'] ?? 0),
                'tax_code' => (string)($r['tax_code'] ?? ''),
                'net' => $l['net'] / 100,
                'vat' => $l['vat'] / 100,
                'gross' => $l['gross'] / 100,
            ];
            if ($section !== null) { $section['net'] += $l['net']; $section['count']++; }
        }
        $flush();
        return $out;
    }

    private static function totals(array $rows, array $doc, string $type): array
    {
        $items = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'item') $items[] = ['quantity' => $r['qty'], 'unit_price' => $r['price'], 'discount' => $r['discount'], 'tax_rate' => $r['rate']];
        }
        $t = QiMoney::totals($items);
        $out = [
            'before' => $t['before'] / 100,
            'discount' => $t['discount'] / 100,
            'net' => $t['net'] / 100,
            'vat' => $t['vat'] / 100,
            'total' => $t['total'] / 100,
            'by_rate' => array_map(fn($v) => ['net' => $v['net'] / 100, 'vat' => $v['vat'] / 100], $t['by_rate']),
        ];
        // The stored header total is what the ledger posted; if it differs from
        // the lines (older rounding), the header wins so the document matches the GL.
        if (isset($doc['total']) && $doc['total'] !== null && abs((float)$doc['total'] - $out['total']) >= 0.005 && isset($doc['tax'], $doc['subtotal'])) {
            $out['vat'] = (float)$doc['tax'];
            $out['net'] = (float)$doc['subtotal'];
            $out['total'] = (float)$doc['total'];
            $out['by_rate'] = [];
        }
        if ($type === 'invoice') {
            $balance = isset($doc['balance_due']) && $doc['balance_due'] !== null ? (float)$doc['balance_due'] : $out['total'];
            $out['balance'] = $balance;
            $out['paid'] = 0.0;
            $out['credited'] = 0.0;
        }
        return $out;
    }

    private static function payments(PDO $db, int $companyId, int $invoiceId): array
    {
        $st = $db->prepare("SELECT p.payment_date, p.method, p.reference, pa.amount
                              FROM payment_allocations pa
                              JOIN payments p ON p.id = pa.payment_id AND p.company_id = ?
                             WHERE pa.invoice_id = ? ORDER BY p.payment_date, p.id");
        $st->execute([$companyId, $invoiceId]);
        $methods = ['eft' => 'EFT', 'card' => 'Card', 'cash' => 'Cash', 'cheque' => 'Cheque', 'yoco' => 'Yoco', 'other' => 'Other'];
        return array_map(fn($r) => ['date' => $r['payment_date'], 'method' => $methods[$r['method']] ?? ucfirst((string)$r['method']),
                                    'reference' => (string)($r['reference'] ?? ''), 'amount' => (float)$r['amount']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function credits(PDO $db, int $companyId, int $invoiceId): array
    {
        try {
            $st = $db->prepare("SELECT cn.id, cn.credit_note_number, cn.issue_date, a.amount
                                  FROM credit_note_allocations a
                                  JOIN credit_notes cn ON cn.id = a.credit_note_id AND cn.company_id = a.company_id
                                 WHERE a.invoice_id = ? AND a.company_id = ? ORDER BY cn.issue_date, cn.id");
            $st->execute([$invoiceId, $companyId]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'number' => $r['credit_note_number'], 'date' => $r['issue_date'], 'amount' => (float)$r['amount']], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function milestones(PDO $db, int $companyId, string $type, int $id): array
    {
        $st = $db->prepare("SELECT label, percentage, amount, due_date, status, amount_paid FROM payment_milestones
                             WHERE entity_type = ? AND entity_id = ? AND company_id = ? ORDER BY sort_order, id");
        $st->execute([$type, $id, $companyId]);
        return array_map(fn($r) => ['label' => (string)$r['label'], 'percentage' => (float)$r['percentage'], 'amount' => (float)$r['amount'],
                                    'due_date' => $r['due_date'], 'status' => (string)$r['status'], 'paid' => (float)($r['amount_paid'] ?? 0)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Status for display: key, label, tone (for pills) and the stamp the
     * document shows (null = none). Overdue / expired are derived from dates
     * because nothing stores them.
     */
    public static function status(string $type, array $doc, array $totals = []): array
    {
        $s = (string)($doc['status'] ?? 'draft');
        $today = date('Y-m-d');
        if ($type === 'quote') {
            $expired = in_array($s, ['sent', 'viewed', 'draft'], true) && !empty($doc['expiry_date']) && $doc['expiry_date'] < $today && $s !== 'draft';
            if ($expired) return ['key' => 'expired', 'label' => 'Expired', 'tone' => 'muted', 'stamp' => 'Expired'];
            return match ($s) {
                'draft' => ['key' => 'draft', 'label' => 'Draft', 'tone' => 'muted', 'stamp' => 'Draft'],
                'sent' => ['key' => 'sent', 'label' => 'Sent', 'tone' => 'info', 'stamp' => null],
                'viewed' => ['key' => 'viewed', 'label' => 'Viewed', 'tone' => 'info', 'stamp' => null],
                'accepted' => ['key' => 'accepted', 'label' => 'Accepted', 'tone' => 'success', 'stamp' => 'Accepted'],
                'converted' => ['key' => 'converted', 'label' => 'Invoiced', 'tone' => 'success', 'stamp' => 'Accepted'],
                'declined' => ['key' => 'declined', 'label' => 'Declined', 'tone' => 'danger', 'stamp' => 'Declined'],
                'expired' => ['key' => 'expired', 'label' => 'Expired', 'tone' => 'muted', 'stamp' => 'Expired'],
                'cancelled' => ['key' => 'cancelled', 'label' => 'Cancelled', 'tone' => 'muted', 'stamp' => 'Cancelled'],
                default => ['key' => $s, 'label' => ucfirst($s), 'tone' => 'muted', 'stamp' => null],
            };
        }
        if ($type === 'invoice') {
            if (!empty($doc['deleted_at'])) return ['key' => 'deleted', 'label' => 'Deleted', 'tone' => 'muted', 'stamp' => 'Void'];
            $balance = (float)($doc['balance_due'] ?? ($totals['balance'] ?? 0));
            $open = in_array($s, ['sent', 'viewed', 'part-paid', 'overdue'], true);
            if ($open && $balance > 0.004 && !empty($doc['due_date']) && $doc['due_date'] < $today) {
                return ['key' => 'overdue', 'label' => 'Overdue', 'tone' => 'danger', 'stamp' => null];
            }
            return match ($s) {
                'draft' => ['key' => 'draft', 'label' => 'Draft', 'tone' => 'muted', 'stamp' => 'Draft'],
                'sent', 'viewed', 'overdue' => ['key' => 'due', 'label' => $s === 'viewed' ? 'Viewed' : 'Awaiting payment', 'tone' => 'info', 'stamp' => null],
                'part-paid' => ['key' => 'part-paid', 'label' => 'Part paid', 'tone' => 'warning', 'stamp' => null],
                'paid' => ['key' => 'paid', 'label' => 'Paid', 'tone' => 'success', 'stamp' => 'Paid'],
                'cancelled' => ['key' => 'void', 'label' => 'Void', 'tone' => 'muted', 'stamp' => 'Void'],
                'written_off' => ['key' => 'written_off', 'label' => 'Written off', 'tone' => 'muted', 'stamp' => 'Written off'],
                'uncollectible' => ['key' => 'uncollectible', 'label' => 'Uncollectible', 'tone' => 'danger', 'stamp' => null],
                'refunded' => ['key' => 'refunded', 'label' => 'Refunded', 'tone' => 'warning', 'stamp' => 'Refunded'],
                default => ['key' => $s, 'label' => ucfirst($s), 'tone' => 'muted', 'stamp' => null],
            };
        }
        return match ($s) {
            'draft' => ['key' => 'draft', 'label' => 'Draft', 'tone' => 'muted', 'stamp' => 'Draft'],
            'approved' => ['key' => 'approved', 'label' => 'Approved', 'tone' => 'info', 'stamp' => null],
            'applied' => ['key' => 'applied', 'label' => 'Applied', 'tone' => 'success', 'stamp' => null],
            'cancelled' => ['key' => 'cancelled', 'label' => 'Cancelled', 'tone' => 'muted', 'stamp' => 'Cancelled'],
            default => ['key' => $s, 'label' => ucfirst($s), 'tone' => 'muted', 'stamp' => null],
        };
    }

    public static function url(string $path): string
    {
        $base = defined('APP_BASE_URL') ? rtrim((string)APP_BASE_URL, '/') : '';
        return $base . $path;
    }
}
