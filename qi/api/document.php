<?php
// /qi/api/document.php — the document editor's API (quotes, invoices, credit
// notes and recurring invoices).
//   GET  ?type=&id=                               → editor payload (QiEditor::payload)
//   GET  ?action=customers&q=                     → customer picker
//   GET  ?action=customer&id=                     → customer card (contacts, address, owing)
//   GET  ?action=items&q=                         → line suggestions from past documents
//   GET  ?action=invoices&customer_id=&except=    → invoices a credit note can be raised against
//   GET  ?action=invoice&id=&except=              → one of those, with what can still be credited
//   GET  ?action=rate&currency=&date=             → exchange rate (read only)
//   POST {action:'preview', type, id, doc}        → {html, totals, number}
//   POST {action:'save', type, id, version, force, client_key, doc} → saved document
//   POST {action:'customer_create', customer}     → new CRM customer (card)
//   POST {action:'save_default', field, value}    → default terms / notes (admins)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiEditor.php';
require_once __DIR__ . '/../lib/QiWriter.php';
require_once __DIR__ . '/../lib/DocHtml.php';

$in = qi_is_post() ? qi_input() : $_GET;
$action = qi_str($in, 'action', 30) ?: (qi_is_post() ? '' : 'load');
$type = qi_str($in, 'type', 20);
if ($type !== '' && !in_array($type, QiWriter::TYPES, true)) qi_error('Unknown document type');

/** Links for a saved document. */
function qi_doc_urls(string $type, int $id): array
{
    return ['view' => '/qi/view.php?type=' . $type . '&id=' . $id, 'edit' => '/qi/edit.php?type=' . $type . '&id=' . $id];
}

try {
    if ($action === 'load') {
        $data = QiEditor::payload($DB, $QI_COMPANY_ID, $QI_ROLE, $type ?: 'invoice', qi_int($in, 'id'), [
            'customer_id' => qi_int($in, 'customer_id'), 'project_id' => qi_int($in, 'project_id'),
            'invoice_id' => qi_int($in, 'invoice_id'), 'from' => qi_str($in, 'from', 40),
        ]);
        if ($data === null) qi_error('Document not found', 404);
        qi_json($data);
    }

    if ($action === 'customers') {
        qi_json(['customers' => QiEditor::searchCustomers($DB, $QI_COMPANY_ID, qi_str($in, 'q', 100))]);
    }

    if ($action === 'customer') {
        $card = QiEditor::customerCard($DB, $QI_COMPANY_ID, qi_int($in, 'id'));
        if ($card === null) qi_error('Customer not found', 404);
        qi_json(['customer' => $card]);
    }

    if ($action === 'items') {
        qi_json(['items' => QiEditor::itemSuggestions($DB, $QI_COMPANY_ID, qi_str($in, 'q', 100))]);
    }

    if ($action === 'invoices') {
        qi_json(['invoices' => QiEditor::creditableInvoices($DB, $QI_COMPANY_ID, qi_int($in, 'customer_id'), qi_int($in, 'except'))]);
    }

    if ($action === 'invoice') {
        $card = QiEditor::invoiceCard($DB, $QI_COMPANY_ID, qi_int($in, 'id'), qi_int($in, 'except'));
        if ($card === null) qi_error('Invoice not found', 404);
        $inv = QiWriter::lockRow($DB, $QI_COMPANY_ID, 'invoice', $card['id']);
        $rows = [];
        if ($inv) {
            $copy = QiEditor::fromRow($DB, $QI_COMPANY_ID, 'invoice', $inv, true);
            foreach ($copy['rows'] as $r) {
                if ($r['kind'] !== 'item') continue;
                $r['src'] = $r['ref'];
                unset($r['ref']);
                $rows[] = $r;
            }
            $card['customer_id'] = (int)$inv['customer_id'];
            $card['exchange_rate'] = $copy['exchange_rate'];
        }
        qi_json(['invoice' => $card, 'rows' => $rows]);
    }

    if ($action === 'rate') {
        $currency = strtoupper(qi_str($in, 'currency', 3));
        $date = QiWriter::dateOf(qi_str($in, 'date', 10)) ?? date('Y-m-d');
        if (!Currencies::isValid($currency)) qi_error('Unknown currency');
        if ($currency === Currencies::BASE) qi_json(['rate' => 1.0, 'date' => $date]);
        require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
        $rate = (new CurrencyService($DB, $QI_COMPANY_ID))->getRate($currency, $date);
        qi_json(['rate' => $rate !== null ? (float)$rate : null, 'date' => $date]);
    }

    if ($action === 'preview') {
        qi_require_post();
        qi_rate_limit('qi_doc_preview', 300, 60);
        $type = $type ?: 'invoice';
        $id = qi_int($in, 'id');
        $doc = is_array($in['doc'] ?? null) ? $in['doc'] : [];
        $row = $id > 0 ? QiWriter::lockRow($DB, $QI_COMPANY_ID, $type, $id) : null;
        if ($id > 0 && !$row) qi_error('Document not found', 404);
        $lock = $row ? QiWriter::lockState($DB, $QI_COMPANY_ID, $type, $row) : ['level' => 'none'];

        if ($row && $type !== 'recurring' && $lock['level'] !== 'none') {
            // amounts are locked: the stored document with the editable wording on top
            $m = QiDocument::load($DB, $QI_COMPANY_ID, $type, $id);
            if ($lock['level'] === 'wording') {
                $m['reference'] = QiWriter::text($doc['reference'] ?? '', 120);
                $m['notes'] = QiWriter::text($doc['customer_notes'] ?? '', 5000, true);
                $m['terms'] = QiWriter::text($doc['terms'] ?? '', 10000, true);
                if ($type === 'invoice' && ($due = QiWriter::dateOf($doc['due_date'] ?? ''))) $m['due_date'] = $due;
            }
        } else {
            $d = QiWriter::normalize($DB, $QI_COMPANY_ID, $type, $doc, $row, false);
            $number = $row ? ($type === 'recurring' ? '' : QiDocument::numberOf($type, $row)) : '';
            if ($number === '') {
                $number = (new SequenceAllocator($DB))->peek($QI_COMPANY_ID, $type === 'recurring' ? 'invoice' : $type, $d['issue_date']);
            }
            $m = QiDocument::fromDraft($DB, $QI_COMPANY_ID, $d, $row, $number);
        }
        $m['company']['raw'] = qi_company();
        $design = QiTheme::load($DB, $QI_COMPANY_ID, qi_company());
        qi_json([
            'html' => DocHtml::render($m, $design, ['editable' => true]),
            'number' => $m['number'],
            'totals' => $m['totals'],
            'symbol' => $m['symbol'],
        ]);
    }

    if ($action === 'save') {
        qi_require_post();
        qi_require_writer();
        qi_rate_limit('qi_doc_save', 60, 60);
        $type = $type ?: 'invoice';
        $doc = is_array($in['doc'] ?? null) ? $in['doc'] : [];
        $doc['id'] = qi_int($in, 'id');
        $doc['version'] = qi_str($in, 'version', 40);
        $doc['force'] = !empty($in['force']);
        $doc['client_key'] = qi_str($in, 'client_key', 64);
        $r = QiWriter::save($DB, $QI_COMPANY_ID, $QI_USER_ID, $type, $doc);
        $row = QiWriter::lockRow($DB, $QI_COMPANY_ID, $type, (int)$r['id']) ?? [];
        $status = $type === 'recurring'
            ? (!empty($row['active']) ? ['key' => 'active', 'label' => 'Active', 'tone' => 'success'] : ['key' => 'paused', 'label' => 'Paused', 'tone' => 'muted'])
            : QiDocument::status($type, $row);
        qi_json($r + ['type' => $type, 'status' => $status, 'urls' => qi_doc_urls($type, (int)$r['id'])]);
    }

    if ($action === 'customer_create') {
        qi_require_post();
        qi_require_writer();
        qi_rate_limit('qi_customer_create', 30, 600);
        $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
        $name = QiWriter::text($c['name'] ?? '', 255);
        if ($name === '') throw new QiInvalid('Enter the customer\'s name.', 'name');
        $email = QiWriter::text($c['email'] ?? '', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new QiInvalid('That email address does not look right.', 'email');
        $vat = QiWriter::text($c['vat_no'] ?? '', 64);
        $reg = QiWriter::text($c['reg_no'] ?? '', 64);
        if ($vat !== '' && !preg_match('/^4\d{9}$/', preg_replace('/\s+/', '', $vat))) throw new QiInvalid('A South African VAT number has 10 digits and starts with 4.', 'vat_no');
        if (function_exists('isVATRequired') && isVATRequired() && $vat === '') throw new QiInvalid('Your CRM settings require a VAT number for new accounts.', 'vat_no');
        if (function_exists('isRegNumberRequired') && isRegNumberRequired() && $reg === '') throw new QiInvalid('Your CRM settings require a registration number for new accounts.', 'reg_no');
        $phone = QiWriter::text($c['phone'] ?? '', 64);
        if ($phone !== '' && !preg_match('/^\+/', $phone)) $phone = '+27' . ltrim(preg_replace('/\s+/', '', $phone), '0');
        $currency = strtoupper(QiWriter::text($c['currency'] ?? '', 3));
        if (!Currencies::isValid($currency)) $currency = null;

        $st = $DB->prepare("SELECT id FROM crm_accounts WHERE company_id = ? AND type = 'customer' AND name = ? AND deleted_at IS NULL");
        $st->execute([$QI_COMPANY_ID, $name]);
        if ($st->fetchColumn()) throw new QiInvalid('A customer called "' . $name . '" already exists — search for it instead.', 'name');

        $DB->beginTransaction();
        try {
            $DB->prepare("INSERT INTO crm_accounts (company_id, type, name, legal_name, reg_no, vat_no, phone, email, currency, status, created_by, created_at)
                          VALUES (?, 'customer', ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())")
               ->execute([$QI_COMPANY_ID, $name, QiWriter::text($c['legal_name'] ?? '', 255) ?: null, $reg ?: null, preg_replace('/\s+/', '', $vat) ?: null,
                          $phone ?: null, $email ?: null, $currency, $QI_USER_ID]);
            $accountId = (int)$DB->lastInsertId();
            $a = is_array($c['address'] ?? null) ? $c['address'] : [];
            $line1 = QiWriter::text($a['line1'] ?? '', 255);
            if ($line1 !== '') {
                $DB->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, line2, city, region, postal_code, country, created_at)
                              VALUES (?, ?, 'billing', ?, ?, ?, ?, ?, 'ZA', NOW())")
                   ->execute([$QI_COMPANY_ID, $accountId, $line1, QiWriter::text($a['line2'] ?? '', 255) ?: null, QiWriter::text($a['city'] ?? '', 120) ?: null,
                              QiWriter::text($a['region'] ?? '', 120) ?: null, QiWriter::text($a['postal_code'] ?? '', 20) ?: null]);
            }
            $p = is_array($c['contact'] ?? null) ? $c['contact'] : [];
            $first = QiWriter::text($p['first_name'] ?? '', 120);
            $last = QiWriter::text($p['last_name'] ?? '', 120);
            $pEmail = QiWriter::text($p['email'] ?? '', 190);
            if ($pEmail !== '' && !filter_var($pEmail, FILTER_VALIDATE_EMAIL)) throw new QiInvalid('The contact\'s email address does not look right.', 'contact_email');
            if ($first !== '' || $last !== '') {
                $DB->prepare("INSERT INTO crm_contacts (company_id, account_id, first_name, last_name, email, phone, is_primary, created_by, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())")
                   ->execute([$QI_COMPANY_ID, $accountId, $first ?: null, $last ?: null, $pEmail ?: null, null, $QI_USER_ID]);
            }
            $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at)
                          VALUES (?, ?, 'crm_account_create', 'crm_account', ?, ?, ?, NOW())")
               ->execute([$QI_COMPANY_ID, $QI_USER_ID, $accountId, json_encode(['name' => $name, 'type' => 'customer', 'source' => 'qi_editor']), $_SERVER['REMOTE_ADDR'] ?? null]);
            $DB->commit();
        } catch (Throwable $e) {
            if ($DB->inTransaction()) $DB->rollBack();
            throw $e;
        }
        qi_json(['customer' => QiEditor::customerCard($DB, $QI_COMPANY_ID, $accountId)]);
    }

    if ($action === 'save_default') {
        qi_require_post();
        qi_require_admin();
        $field = qi_str($in, 'field', 10);
        $column = ['terms' => 'default_terms', 'notes' => 'default_notes'][$field] ?? null;
        if ($column === null) qi_error('Unknown setting');
        $value = QiWriter::text($in['value'] ?? '', $field === 'terms' ? 10000 : 5000, true);
        $DB->prepare("INSERT INTO qi_settings (company_id, $column) VALUES (?, ?) ON DUPLICATE KEY UPDATE $column = VALUES($column), updated_at = NOW()")
           ->execute([$QI_COMPANY_ID, $value]);
        qi_json(['saved' => true]);
    }

    qi_error('Unknown action', 400);
} catch (QiInvalid $e) {
    qi_error($e->getMessage(), $e->getCode() ?: 422, ['field' => $e->field] + $e->extra);
}
