<?php
/**
 * QiEditor — what the document editor (qi/edit.php) starts from and looks up:
 * the document as editable rows, defaults for a new one (blank, from a
 * customer or project, a copy of another document, a credit note for an
 * invoice), customer search and cards, line suggestions from past documents
 * and the invoices a credit note can be raised against. Read-only; saving is
 * QiWriter.
 */

require_once __DIR__ . '/QiWriter.php';
require_once __DIR__ . '/QiDocument.php';
require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/LineHeadings.php';
require_once __DIR__ . '/Currencies.php';
require_once __DIR__ . '/SequenceAllocator.php';

final class QiEditor
{
    public const UNITS = ['ea', 'hr', 'day', 'week', 'month', 'job', 'lot', 'set', 'visit', 'm', 'm²', 'm³', 'km', 'kg', 't', 'l', 'pack', 'roll', 'bag'];
    public const REASONS = [
        'return' => 'Goods returned', 'discount' => 'Discount after invoicing', 'correction' => 'Invoice correction', 'damaged' => 'Damaged or faulty',
        'cancellation' => 'Order cancelled', 'vat_adjustment' => 'VAT adjustment', 'other' => 'Other',
    ];

    /**
     * Everything the editor needs to open. null when the document does not exist.
     * $params (new documents): customer_id, project_id, contact_id, invoice_id (credit note), from = "quote:12" (copy)
     */
    public static function payload(PDO $db, int $companyId, string $role, string $type, int $id, array $params = []): ?array
    {
        if (!in_array($type, QiWriter::TYPES, true)) return null;
        $defaults = QiWriter::defaults($db, $companyId);
        $tax = QiWriter::taxOptions($db, $companyId);
        $defaultCode = QiWriter::defaultTaxCode($tax, $defaults['vat_registered']);

        $row = null;
        $lock = ['level' => 'none', 'reason' => '', 'repost' => false];
        if ($id > 0) {
            $row = QiWriter::lockRow($db, $companyId, $type, $id);
            if (!$row) return null;
            $lock = QiWriter::lockState($db, $companyId, $type, $row);
            $doc = self::fromRow($db, $companyId, $type, $row, true);
        } else {
            $doc = self::blank($db, $companyId, $type, $defaults, $defaultCode, $params);
        }

        $number = $row ? ($type === 'recurring' ? (string)$row['template_name'] : QiDocument::numberOf($type, $row))
                       : ($type === 'recurring' ? '' : (new SequenceAllocator($db))->peek($companyId, $type, $doc['issue_date']));
        $statusType = $type === 'recurring' ? 'invoice' : $type;
        $status = $type === 'recurring'
            ? ($row ? (!empty($row['active']) ? ['key' => 'active', 'label' => 'Active', 'tone' => 'success'] : ['key' => 'paused', 'label' => 'Paused', 'tone' => 'muted'])
                    : ['key' => 'new', 'label' => 'New', 'tone' => 'muted'])
            : ($row ? QiDocument::status($statusType, $row) : ['key' => 'new', 'label' => 'New', 'tone' => 'muted']);

        $projects = [];
        try {
            $projects = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)($p['label'] ?? $p['name']), 'client_id' => $p['client_id']],
                ProjectLink::pickerProjects($db, $companyId, (int)($_SESSION['user_id'] ?? 0), $role, $doc['project_id'] ? [(int)$doc['project_id']] : []));
            $projects = array_values(array_filter($projects, fn($p) => $p['id'] > 0));
        } catch (Throwable $e) {
            $projects = [];
        }

        $currencies = [];
        foreach (['ZAR', 'USD', 'EUR', 'GBP', 'BWP', 'NAD', 'MZN', 'ZMW', 'ZWG', 'CDF'] as $c) $currencies[$c] = true;
        $all = Currencies::all();
        ksort($all);
        $currencyList = [];
        foreach (array_keys($currencies + $all) as $c) {
            if (isset($all[$c])) $currencyList[] = ['code' => $c, 'name' => $all[$c]['name'], 'symbol' => $all[$c]['symbol']];
        }

        $company = QiDocument::company($db, $companyId);
        return [
            'type' => $type,
            'id' => $row ? (int)$row['id'] : 0,
            'number' => $number,
            'status' => $status,
            'version' => $row ? (string)$row['updated_at'] : '',
            'lock' => $lock,
            'doc' => $doc,
            'customer' => $doc['customer_id'] ? self::customerCard($db, $companyId, (int)$doc['customer_id']) : null,
            'invoice' => $type === 'credit_note' && !empty($doc['invoice_id']) ? self::invoiceCard($db, $companyId, (int)$doc['invoice_id'], $row ? (int)$row['id'] : 0) : null,
            'options' => [
                'tax' => array_values($tax),
                'default_tax_code' => $defaultCode,
                'currencies' => $currencyList,
                'base_currency' => Currencies::BASE,
                'units' => self::UNITS,
                'reasons' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v], array_keys(self::REASONS), self::REASONS),
                'frequencies' => [['code' => 'weekly', 'label' => 'Week'], ['code' => 'monthly', 'label' => 'Month'], ['code' => 'quarterly', 'label' => 'Quarter'], ['code' => 'yearly', 'label' => 'Year']],
                'projects' => $projects,
            ],
            'defaults' => $defaults,
            'company' => ['name' => $company['name'], 'vat_registered' => $defaults['vat_registered'], 'logo_url' => $company['logo_url']],
            'can_edit' => $role !== 'viewer',
            'is_admin' => in_array($role, ['admin', 'bookkeeper'], true),
            'today' => date('Y-m-d'),
        ];
    }

    /** A stored document as editor fields. $withRefs keeps line ids so ledger links survive a save. */
    public static function fromRow(PDO $db, int $companyId, string $type, array $row, bool $withRefs): array
    {
        $tax = QiWriter::taxOptions($db, $companyId);
        $byId = [];
        foreach ($tax as $code => $o) $byId[(int)$o['tax_code_id']] = $code;
        $codeFor = function (array $l) use ($byId, $tax) {
            $id = (int)($l['tax_code_id'] ?? 0);
            if ($id && isset($byId[$id])) return $byId[$id];
            $rate = (float)($l['tax_rate'] ?? 0);
            foreach ($tax as $code => $o) if (abs($o['rate'] - $rate) < 0.005 && $code !== 'EXEMPT') return $code;
            return $rate > 0 ? 'STD' : 'ZERO';
        };

        $doc = [
            'customer_id' => (int)($row['customer_id'] ?? 0),
            'contact_id' => isset($row['contact_id']) && $row['contact_id'] ? (int)$row['contact_id'] : null,
            'project_id' => isset($row['project_id']) && $row['project_id'] ? (int)$row['project_id'] : null,
            'issue_date' => (string)($row['issue_date'] ?? date('Y-m-d')),
            'reference' => (string)($row['reference'] ?? ''),
            'currency' => Currencies::isValid($row['currency'] ?? null) ? strtoupper($row['currency']) : Currencies::BASE,
            'exchange_rate' => isset($row['exchange_rate']) && (float)$row['exchange_rate'] > 0 ? (float)$row['exchange_rate'] : null,
            'terms' => (string)($row['terms'] ?? ''),
            'customer_notes' => (string)($row['customer_notes'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'milestones' => null,
        ];
        $lines = [];
        $headings = [];
        if ($type === 'recurring') {
            $st = $db->prepare("SELECT * FROM recurring_invoice_lines WHERE recurring_invoice_id = ? ORDER BY sort_order, id");
            $st->execute([(int)$row['id']]);
            $lines = $st->fetchAll(PDO::FETCH_ASSOC);
            $tpl = json_decode((string)($row['template_data'] ?? ''), true);
            $tpl = is_array($tpl) ? $tpl : [];
            $headings = is_array($tpl['headings'] ?? null) ? $tpl['headings'] : [];
            $doc['contact_id'] = !empty($tpl['contact_id']) ? (int)$tpl['contact_id'] : null;
            $doc['project_id'] = !empty($tpl['project_id']) ? (int)$tpl['project_id'] : null;
            $doc['reference'] = (string)($tpl['reference'] ?? '');
            $doc['customer_notes'] = (string)($tpl['customer_notes'] ?? '');
            $doc['issue_date'] = (string)$row['next_run_date'];
            $doc['exchange_rate'] = null;
            $doc['currency'] = Currencies::isValid($row['currency'] ?? null) ? strtoupper($row['currency']) : Currencies::BASE;
            $doc += [
                'template_name' => (string)($row['template_name'] ?? ''),
                'frequency' => (string)$row['frequency'],
                'interval_count' => max(1, (int)$row['interval_count']),
                'next_run_date' => (string)$row['next_run_date'],
                'end_date' => !empty($row['end_date']) && $row['end_date'] > '1000-01-01' ? (string)$row['end_date'] : null,
                'payment_terms_days' => $row['payment_terms_days'] !== null ? (int)$row['payment_terms_days'] : null,
                'auto_send' => (bool)($row['auto_send'] ?? false),
                'active' => (bool)$row['active'],
            ];
        } else {
            $lineTable = QiDocument::lineTable($type);
            $fk = $type === 'credit_note' ? 'credit_note_id' : $type . '_id';
            $st = $db->prepare("SELECT * FROM `$lineTable` WHERE `$fk` = ? ORDER BY sort_order, id");
            $st->execute([(int)$row['id']]);
            $lines = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($type !== 'credit_note') $headings = LineHeadings::fetch($db, $type, (int)$row['id']);
        }
        $rows = [];
        foreach (LineHeadings::merge($lines, $headings) as $l) {
            if (($l['_kind'] ?? 'item') === 'heading') {
                $rows[] = ['kind' => 'heading', 'title' => (string)$l['title']];
                continue;
            }
            $item = [
                'kind' => 'item',
                'description' => (string)$l['item_description'],
                'qty' => (float)$l['quantity'],
                'unit' => trim((string)($l['unit'] ?? '')),
                'price' => (float)$l['unit_price'],
                'discount' => (float)($l['discount'] ?? 0),
                'tax_code' => $codeFor($l),
            ];
            if ($withRefs && $type !== 'recurring') $item['ref'] = (int)$l['id'];
            $rows[] = $item;
        }
        $doc['rows'] = $rows;

        if ($type === 'quote') {
            $doc['expiry_date'] = (string)($row['expiry_date'] ?? '') ?: null;
        } elseif ($type === 'invoice') {
            $doc['due_date'] = (string)($row['due_date'] ?? '');
        } elseif ($type === 'credit_note') {
            $doc['invoice_id'] = !empty($row['invoice_id']) ? (int)$row['invoice_id'] : null;
            $doc['reason'] = (string)($row['reason'] ?? '');
            $doc['reason_code'] = (string)($row['reason_code'] ?? 'other') ?: 'other';
            unset($doc['notes']);
        }
        if (in_array($type, ['quote', 'invoice'], true) && !empty($row['has_milestones'])) {
            $st = $db->prepare("SELECT label, percentage, amount, due_date FROM payment_milestones WHERE entity_type = ? AND entity_id = ? AND company_id = ? ORDER BY sort_order, id");
            $st->execute([$type, (int)$row['id'], $companyId]);
            $total = (float)($row['total'] ?? 0);
            $ms = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $pct = (float)$m['percentage'];
                $amount = (float)$m['amount'];
                $isPercent = abs(round($total * $pct / 100, 2) - $amount) <= 0.011;
                $ms[] = ['label' => (string)$m['label'], 'mode' => $isPercent ? 'percent' : 'amount', 'percentage' => $pct, 'amount' => $amount, 'due_date' => $m['due_date']];
            }
            $doc['milestones'] = $ms ?: null;
        }
        return $doc;
    }

    /** A new document: defaults, optionally for a customer/project, a copy, or a credit note for an invoice. */
    private static function blank(PDO $db, int $companyId, string $type, array $defaults, string $defaultCode, array $params): array
    {
        $today = date('Y-m-d');
        $doc = [
            'customer_id' => 0, 'contact_id' => null, 'project_id' => null, 'issue_date' => $today, 'reference' => '',
            'currency' => $defaults['currency'], 'exchange_rate' => null,
            'terms' => $defaults['terms'], 'customer_notes' => $defaults['notes'], 'notes' => '',
            'rows' => [['kind' => 'item', 'description' => '', 'qty' => 1, 'unit' => '', 'price' => 0, 'discount' => 0, 'tax_code' => $defaultCode]],
            'milestones' => null,
        ];

        // a copy of another document (duplicate, or "make recurring" from an invoice)
        if (preg_match('/^(quote|invoice|credit_note|recurring):(\d+)$/', (string)($params['from'] ?? ''), $mm)) {
            $src = QiWriter::lockRow($db, $companyId, $mm[1], (int)$mm[2]);
            if ($src) {
                $copy = self::fromRow($db, $companyId, $mm[1], $src, false);
                foreach (['customer_id', 'contact_id', 'project_id', 'reference', 'currency', 'terms', 'customer_notes', 'notes', 'rows'] as $k) {
                    if (array_key_exists($k, $copy)) $doc[$k] = $copy[$k];
                }
                if (in_array($type, ['quote', 'invoice'], true) && !empty($copy['milestones'])) {
                    $doc['milestones'] = array_map(fn($m) => array_merge($m, ['due_date' => null]), $copy['milestones']);
                }
                if ($mm[1] === 'recurring') $doc['issue_date'] = $today;
                if ($type === 'credit_note') $doc['rows'] = array_values(array_filter($doc['rows'], fn($r) => $r['kind'] === 'item'));
                if ($type === 'recurring') {
                    $doc['reference'] = '';
                }
            }
        }

        $cid = (int)($params['customer_id'] ?? 0);
        if ($cid > 0) {
            $st = $db->prepare("SELECT id, currency FROM crm_accounts WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $st->execute([$cid, $companyId]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                $doc['customer_id'] = (int)$c['id'];
                if (Currencies::isValid($c['currency'] ?? null)) $doc['currency'] = strtoupper($c['currency']);
            }
        }
        $pid = (int)($params['project_id'] ?? 0);
        if ($pid > 0 && ProjectLink::belongs($db, $companyId, $pid)) {
            $doc['project_id'] = $pid;
            if (!$doc['customer_id']) {
                // a project's client becomes the customer
                $st = $db->prepare("SELECT a.id, a.currency FROM projects p JOIN crm_accounts a ON a.id = p.client_id AND a.company_id = p.company_id AND a.deleted_at IS NULL
                                     WHERE p.project_id = ? AND p.company_id = ?");
                $st->execute([$pid, $companyId]);
                if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                    $doc['customer_id'] = (int)$c['id'];
                    if (Currencies::isValid($c['currency'] ?? null)) $doc['currency'] = strtoupper($c['currency']);
                }
            }
        }

        if ($type === 'quote') {
            $doc['expiry_date'] = date('Y-m-d', strtotime($today . ' +' . $defaults['quote_validity'] . ' days'));
        } elseif ($type === 'invoice') {
            $doc['due_date'] = date('Y-m-d', strtotime($today . ' +' . $defaults['payment_terms'] . ' days'));
        } elseif ($type === 'credit_note') {
            unset($doc['notes'], $doc['milestones']);
            $doc += ['invoice_id' => null, 'reason' => '', 'reason_code' => 'other'];
            $doc['customer_notes'] = '';
            $doc['terms'] = '';
            $iid = (int)($params['invoice_id'] ?? 0);
            if ($iid > 0) {
                $inv = QiWriter::lockRow($db, $companyId, 'invoice', $iid);
                if ($inv && empty($inv['deleted_at']) && !in_array($inv['status'], ['draft', 'cancelled'], true)) {
                    $copy = self::fromRow($db, $companyId, 'invoice', $inv, false);
                    $doc['invoice_id'] = (int)$inv['id'];
                    $doc['customer_id'] = (int)$inv['customer_id'];
                    $doc['currency'] = $copy['currency'];
                    $doc['exchange_rate'] = $copy['exchange_rate'];
                    // invoice lines keep their ledger accounts through "src"
                    $st = $db->prepare("SELECT id FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, id");
                    $st->execute([(int)$inv['id']]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    $rows = [];
                    $k = 0;
                    foreach ($copy['rows'] as $r) {
                        if ($r['kind'] !== 'item') continue;
                        $r['src'] = (int)($ids[$k++] ?? 0);
                        $rows[] = $r;
                    }
                    if ($rows) $doc['rows'] = $rows;
                }
            }
        } else { // recurring
            unset($doc['exchange_rate']);
            $next = date('Y-m-d', strtotime($today . ' +1 month'));
            $doc += [
                'template_name' => '', 'frequency' => 'monthly', 'interval_count' => 1, 'next_run_date' => $next, 'end_date' => null,
                'payment_terms_days' => $defaults['payment_terms'], 'auto_send' => false, 'active' => true,
            ];
            $doc['issue_date'] = $next;
            $doc['milestones'] = null;
            if ($doc['customer_id']) {
                $st = $db->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
                $st->execute([$doc['customer_id'], $companyId]);
                $doc['template_name'] = trim((string)$st->fetchColumn() . ' — monthly');
            }
        }
        return $doc;
    }

    // ------------------------------------------------------------ lookups

    /** Customer details for the editor's customer card (only within the company). */
    public static function customerCard(PDO $db, int $companyId, int $customerId): ?array
    {
        $st = $db->prepare("SELECT id, type, name, legal_name, email, phone, vat_no, reg_no, currency, status, deleted_at FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$customerId, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;
        $c = QiDocument::customer($db, $companyId, $customerId);
        $st = $db->prepare("SELECT id, first_name, last_name, email, phone, role_title, is_primary FROM crm_contacts WHERE account_id = ? AND company_id = ? ORDER BY is_primary DESC, first_name, last_name LIMIT 100");
        $st->execute([$customerId, $companyId]);
        $contacts = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['email'] ?: 'Contact'),
            'email' => (string)($r['email'] ?? ''), 'phone' => (string)($r['phone'] ?? ''), 'role' => (string)($r['role_title'] ?? ''), 'primary' => (bool)$r['is_primary'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT COALESCE(SUM(balance_due), 0), COUNT(*) FROM invoices
                             WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL AND status IN ('sent','viewed','part-paid','overdue') AND balance_due > 0");
        $st->execute([$companyId, $customerId]);
        [$owing, $open] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return [
            'id' => (int)$a['id'], 'name' => (string)$a['name'], 'legal_name' => $c['legal_name'], 'kind' => (string)$a['type'],
            'email' => (string)($a['email'] ?? ''), 'phone' => (string)($a['phone'] ?? ''), 'vat' => (string)($a['vat_no'] ?? ''), 'reg' => (string)($a['reg_no'] ?? ''),
            'address' => $c['address'], 'currency' => Currencies::isValid($a['currency'] ?? null) ? strtoupper($a['currency']) : null,
            'status' => (string)$a['status'], 'deleted' => !empty($a['deleted_at']),
            'contacts' => $contacts, 'owing' => round((float)$owing, 2), 'open_invoices' => (int)$open,
            'crm_url' => '/crm/account_view.php?id=' . (int)$a['id'],
        ];
    }

    /** Customer picker: matching accounts (customers first), or the most recently billed when $q is empty. */
    public static function searchCustomers(PDO $db, int $companyId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        $limit = max(1, min(50, $limit));
        if ($q === '') {
            $st = $db->prepare("SELECT a.id, a.name, a.legal_name, a.email, a.type, a.status, a.currency,
                                       GREATEST(COALESCE((SELECT MAX(i.created_at) FROM invoices i WHERE i.company_id = a.company_id AND i.customer_id = a.id), '1970-01-01'),
                                                COALESCE((SELECT MAX(q.created_at) FROM quotes q WHERE q.company_id = a.company_id AND q.customer_id = a.id), '1970-01-01')) AS last_doc
                                  FROM crm_accounts a
                                 WHERE a.company_id = ? AND a.deleted_at IS NULL
                                 ORDER BY last_doc DESC, (a.type = 'customer') DESC, a.name LIMIT $limit");
            $st->execute([$companyId]);
        } else {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $start = addcslashes($q, '%_\\') . '%';
            $st = $db->prepare("SELECT a.id, a.name, a.legal_name, a.email, a.type, a.status, a.currency
                                  FROM crm_accounts a
                                 WHERE a.company_id = ? AND a.deleted_at IS NULL
                                   AND (a.name LIKE ? OR a.legal_name LIKE ? OR a.email LIKE ? OR a.vat_no LIKE ? OR a.phone LIKE ?)
                                 ORDER BY (a.name LIKE ?) DESC, (a.type = 'customer') DESC, a.name LIMIT $limit");
            $st->execute([$companyId, $like, $like, $like, $like, $like, $start]);
        }
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => (string)$r['name'], 'legal_name' => (string)($r['legal_name'] ?? ''), 'email' => (string)($r['email'] ?? ''),
            'kind' => (string)$r['type'], 'status' => (string)$r['status'], 'currency' => Currencies::isValid($r['currency'] ?? null) ? strtoupper($r['currency']) : null,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Lines used before (newest first, one per description) and stock items, for the line autocomplete. */
    public static function itemSuggestions(PDO $db, int $companyId, string $q, int $limit = 10): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $tax = QiWriter::taxOptions($db, $companyId);
        $byId = [];
        foreach ($tax as $code => $o) $byId[(int)$o['tax_code_id']] = $code;
        $st = $db->prepare("(SELECT l.item_description AS d, l.unit, l.unit_price AS price, l.tax_code_id, l.tax_rate, i.issue_date AS at
                               FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id AND i.company_id = ?
                              WHERE l.item_description LIKE ? ORDER BY i.issue_date DESC, l.id DESC LIMIT 60)
                             UNION ALL
                             (SELECT l.item_description, l.unit, l.unit_price, l.tax_code_id, l.tax_rate, q.issue_date
                               FROM quote_lines l JOIN quotes q ON q.id = l.quote_id AND q.company_id = ?
                              WHERE l.item_description LIKE ? ORDER BY q.issue_date DESC, l.id DESC LIMIT 60)");
        $st->execute([$companyId, $like, $companyId, $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
        $out = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string)$r['d']));
            if ($key === '' || isset($out[$key])) continue;
            $code = $byId[(int)($r['tax_code_id'] ?? 0)] ?? ((float)$r['tax_rate'] > 0 ? 'STD' : 'ZERO');
            $out[$key] = ['description' => (string)$r['d'], 'unit' => trim((string)($r['unit'] ?? '')), 'price' => (float)$r['price'],
                          'tax_code' => isset($tax[$code]) ? $code : null, 'source' => 'Used ' . self::shortDate((string)$r['at'])];
            if (count($out) >= $limit) break;
        }
        if (count($out) < $limit) {
            try {
                $st = $db->prepare("SELECT name, uom, sku FROM inventory_items WHERE company_id = ? AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 10");
                $st->execute([$companyId, $like, $like]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $key = mb_strtolower(trim((string)$r['name']));
                    if ($key === '' || isset($out[$key])) continue;
                    $out[$key] = ['description' => (string)$r['name'], 'unit' => trim((string)($r['uom'] ?? '')), 'price' => null, 'tax_code' => null,
                                  'source' => 'Stock item' . (!empty($r['sku']) ? ' · ' . $r['sku'] : '')];
                    if (count($out) >= $limit) break;
                }
            } catch (Throwable $e) {
                // no stock module
            }
        }
        return array_values($out);
    }

    /** Issued invoices of a customer that can still be credited (credit note picker). */
    public static function creditableInvoices(PDO $db, int $companyId, int $customerId, int $exceptCreditNote = 0): array
    {
        $st = $db->prepare("SELECT i.id, i.invoice_number, i.issue_date, i.total, i.balance_due, i.currency, i.status,
                                   COALESCE((SELECT SUM(cn.total) FROM credit_notes cn WHERE cn.invoice_id = i.id AND cn.company_id = i.company_id
                                              AND cn.status <> 'cancelled' AND cn.id <> ?), 0) AS credited
                              FROM invoices i
                             WHERE i.company_id = ? AND i.customer_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('draft', 'cancelled')
                             ORDER BY i.issue_date DESC, i.id DESC LIMIT 60");
        $st->execute([$exceptCreditNote, $companyId, $customerId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $left = round((float)$r['total'] - (float)$r['credited'], 2);
            if ($left <= 0.004) continue;
            $out[] = ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'date' => (string)$r['issue_date'], 'total' => (float)$r['total'],
                      'balance' => (float)$r['balance_due'], 'creditable' => $left, 'currency' => (string)($r['currency'] ?: Currencies::BASE)];
        }
        return $out;
    }

    /** "4 Oct 2025" (same as the paper). */
    private static function shortDate(string $ymd): string
    {
        $ts = strtotime($ymd);
        return $ts ? date('j M Y', $ts) : $ymd;
    }

    public static function invoiceCard(PDO $db, int $companyId, int $invoiceId, int $exceptCreditNote = 0): ?array
    {
        $st = $db->prepare("SELECT i.id, i.invoice_number, i.issue_date, i.total, i.balance_due, i.currency, i.status, i.customer_id,
                                   COALESCE((SELECT SUM(cn.total) FROM credit_notes cn WHERE cn.invoice_id = i.id AND cn.company_id = i.company_id
                                              AND cn.status <> 'cancelled' AND cn.id <> ?), 0) AS credited
                              FROM invoices i WHERE i.id = ? AND i.company_id = ?");
        $st->execute([$exceptCreditNote, $invoiceId, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'date' => (string)$r['issue_date'], 'total' => (float)$r['total'],
                'balance' => (float)$r['balance_due'], 'creditable' => max(0, round((float)$r['total'] - (float)$r['credited'], 2)),
                'currency' => (string)($r['currency'] ?: Currencies::BASE), 'status' => (string)$r['status']];
    }
}
