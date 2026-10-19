<?php
// /qi/api/settings.php — Quotes & Invoices settings.
//   GET                                  → everything the settings page shows
//   POST {action:'save', section, values} → save one section (admins / bookkeepers)
//        sections: business, banking, numbering, defaults, emails
// Bank details decide where customers send money: a change is audited and
// every other administrator is told about it.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiWriter.php';
require_once __DIR__ . '/../lib/SequenceAllocator.php';
require_once __DIR__ . '/../lib/QiDocument.php';
require_once __DIR__ . '/../lib/Currencies.php';
require_once __DIR__ . '/../../includes/company_validation.php';

const QI_TEMPLATE_TYPES = ['quote' => 'quote', 'invoice' => 'invoice', 'reminder' => 'payment_reminder'];

function qi_settings_row(): array
{
    global $DB, $QI_COMPANY_ID;
    $st = $DB->prepare("SELECT * FROM qi_settings WHERE company_id = ?");
    $st->execute([$QI_COMPANY_ID]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function qi_settings_payload(): array
{
    global $DB, $QI_COMPANY_ID;
    $c = qi_company();
    $s = qi_settings_row();
    $alloc = new SequenceAllocator($DB);
    $numbering = ['quote_prefix' => $s['quote_prefix'] ?? 'Q', 'invoice_prefix' => $s['invoice_prefix'] ?? 'INV', 'credit_prefix' => $s['credit_prefix'] ?? 'CN',
                  'number_year' => (int)($s['number_year'] ?? 1) === 1, 'number_digits' => (int)($s['number_digits'] ?? 4) ?: 4, 'number_sep' => (string)($s['number_sep'] ?? '-')];
    $next = [];
    foreach (['quote', 'invoice', 'credit_note'] as $t) {
        $p = $alloc->peekParts($QI_COMPANY_ID, $t);
        $next[$t] = ['code' => $p['code'], 'number' => $p['num']];
    }
    $templates = [];
    foreach (QI_TEMPLATE_TYPES as $k => $dbType) {
        $st = $DB->prepare("SELECT subject, body_plain, body_html FROM qi_email_templates WHERE company_id = ? AND template_type = ? AND active = 1 ORDER BY id DESC LIMIT 1");
        $st->execute([$QI_COMPANY_ID, $dbType]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $templates[$k] = $r ? ['subject' => (string)$r['subject'], 'body' => trim((string)($r['body_plain'] ?? '')) ?: trim(strip_tags((string)$r['body_html'])), 'custom' => true]
                            : ['subject' => '', 'body' => '', 'custom' => false];
    }
    $st = $DB->prepare("SELECT COUNT(*) FROM email_accounts WHERE company_id = ? AND user_id = ? AND is_active = 1 AND smtp_server <> ''");
    try { $st->execute([$QI_COMPANY_ID, $GLOBALS['QI_USER_ID']]); $mailbox = (int)$st->fetchColumn() > 0; } catch (Throwable $e) { $mailbox = false; }
    return [
        'business' => [
            'name' => (string)($c['name'] ?? ''), 'vat_number' => (string)($c['vat_number'] ?? ''), 'tax_number' => (string)($c['tax_number'] ?? ''),
            'reg_number' => (string)($c['reg_number'] ?? ''), 'tax_reference' => (string)($c['tax_reference'] ?? ''), 'phone' => (string)($c['phone'] ?? ''),
            'email' => (string)($c['email'] ?? ''), 'website' => (string)($c['website'] ?? ''), 'address_line1' => (string)($c['address_line1'] ?? ''),
            'address_line2' => (string)($c['address_line2'] ?? ''), 'city' => (string)($c['city'] ?? ''), 'region' => (string)($c['region'] ?? ''), 'postal' => (string)($c['postal'] ?? ''),
        ],
        'banking' => [
            'bank_account_holder' => (string)($s['bank_account_holder'] ?? ''), 'bank_name' => (string)($c['bank_name'] ?? ''),
            'bank_account_number' => (string)($c['bank_account_number'] ?? ''), 'bank_branch_code' => (string)($c['bank_branch_code'] ?? ''),
        ],
        'numbering' => $numbering,
        'next' => $next,
        'defaults' => [
            'default_payment_terms' => (int)($s['default_payment_terms'] ?? 30), 'quote_validity_days' => (int)($s['quote_validity_days'] ?? 14),
            'default_currency' => Currencies::isValid($s['default_currency'] ?? null) ? strtoupper($s['default_currency']) : Currencies::BASE,
            'default_terms' => (string)($s['default_terms'] ?? ''), 'default_notes' => (string)($s['default_notes'] ?? ''),
        ],
        'emails' => $templates,
        'email_defaults' => [
            'quote' => ['subject' => 'Quote {number} from {company}', 'body' => "Hi {first_name},\n\nThank you for the opportunity. Please find our quote {number} for {total} attached — it is valid until {valid_until}.\n\nYou can view and accept it online: {link}\n\nKind regards\n{sender}"],
            'invoice' => ['subject' => 'Invoice {number} from {company}', 'body' => "Hi {first_name},\n\nPlease find invoice {number} for {total} attached, due on {due_date}.\n\nYou can view it online: {link}\n\nThank you for your business.\n\nKind regards\n{sender}"],
            'reminder' => ['subject' => 'Reminder: invoice {number} — {balance} outstanding', 'body' => "Hi {first_name},\n\nThis is a friendly reminder that invoice {number} for {balance} was due on {due_date}. If you have already paid, thank you and please ignore this message.\n\nYou can view it online: {link}\n\nKind regards\n{sender}"],
        ],
        'currencies' => array_map(fn($k, $v) => ['code' => $k, 'name' => $v['name']], array_keys(Currencies::all()), Currencies::all()),
        'yoco' => ['configured' => trim((string)($c['yoco_sec_key'] ?? '')) !== '', 'webhook' => trim((string)($c['yoco_webhook_secret'] ?? '')) !== '',
                   'mode' => (string)($c['yoco_mode'] ?? 'test'), 'webhook_url' => QiDocument::url('/webhooks/yoco.php')],
        'mailbox' => $mailbox,
        'can_edit' => qi_is_admin(),
    ];
}

function qi_settings_upsert(array $cols): void
{
    global $DB, $QI_COMPANY_ID;
    $names = array_keys($cols);
    $sql = "INSERT INTO qi_settings (company_id, " . implode(', ', $names) . ") VALUES (?" . str_repeat(', ?', count($names)) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($n) => "$n = VALUES($n)", $names)) . ", updated_at = NOW()";
    $DB->prepare($sql)->execute(array_merge([$QI_COMPANY_ID], array_values($cols)));
}

if (!qi_is_post()) qi_json(qi_settings_payload());

qi_require_post();
qi_require_admin();
qi_rate_limit('qi_settings', 40, 600);
$in = qi_input();
$section = qi_str($in, 'section', 20);
$v = is_array($in['values'] ?? null) ? $in['values'] : [];
$t = fn(string $k, int $max, bool $multi = false) => QiWriter::text($v[$k] ?? '', $max, $multi);

try {
    switch ($section) {
        case 'business':
            $name = $t('name', 255);
            if ($name === '') throw new QiInvalid('Enter the business name.', 'name');
            $vat = preg_replace('/\s+/', '', $t('vat_number', 20));
            $taxRef = preg_replace('/\s+/', '', $t('tax_reference', 20));
            if ($errors = validate_company_profile(['vat_number' => $vat, 'tax_reference' => $taxRef])) {
                throw new QiInvalid(implode('. ', $errors) . '.', $vat !== '' && !preg_match('/^4\d{9}$/', $vat) ? 'vat_number' : 'tax_reference');
            }
            $email = $t('email', 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new QiInvalid('That email address does not look right.', 'email');
            $DB->prepare("UPDATE companies SET name = ?, vat_number = ?, tax_number = ?, reg_number = ?, tax_reference = ?, phone = ?, email = ?, website = ?,
                                               address_line1 = ?, address_line2 = ?, city = ?, region = ?, postal = ?, updated_at = NOW()
                           WHERE id = ?")
               ->execute([$name, $vat ?: null, $t('tax_number', 50) ?: null, $t('reg_number', 50) ?: null, $taxRef ?: null, $t('phone', 50) ?: null, $email ?: null,
                          $t('website', 190) ?: null, $t('address_line1', 190) ?: null, $t('address_line2', 190) ?: null, $t('city', 100) ?: null,
                          $t('region', 100) ?: null, $t('postal', 20) ?: null, $QI_COMPANY_ID]);
            break;

        case 'banking':
            $before = qi_settings_payload()['banking'];
            $account = preg_replace('/[\s-]+/', '', $t('bank_account_number', 30));
            if ($account !== '' && !preg_match('/^\d{5,20}$/', $account)) throw new QiInvalid('An account number is 5 to 20 digits.', 'bank_account_number');
            $branch = preg_replace('/\s+/', '', $t('bank_branch_code', 20));
            if ($branch !== '' && !preg_match('/^\d{4,10}$/', $branch)) throw new QiInvalid('A branch code is 4 to 10 digits.', 'bank_branch_code');
            $DB->beginTransaction();
            try {
                $DB->prepare("UPDATE companies SET bank_name = ?, bank_account_number = ?, bank_branch_code = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$t('bank_name', 100) ?: null, $account ?: null, $branch ?: null, $QI_COMPANY_ID]);
                qi_settings_upsert(['bank_account_holder' => $t('bank_account_holder', 190) ?: null, 'bank_name' => $t('bank_name', 100) ?: null,
                                    'bank_account_number' => $account ?: null, 'bank_branch_code' => $branch ?: null]);
                $after = ['bank_account_holder' => $t('bank_account_holder', 190), 'bank_name' => $t('bank_name', 100), 'bank_account_number' => $account, 'bank_branch_code' => $branch];
                $changed = $before['bank_account_number'] !== $after['bank_account_number'] || $before['bank_branch_code'] !== $after['bank_branch_code'];
                $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at) VALUES (?, ?, 'qi_bank_details_changed', 'company', ?, ?, ?, NOW())")
                   ->execute([$QI_COMPANY_ID, $QI_USER_ID, $QI_COMPANY_ID, json_encode([
                       'from' => ['bank' => $before['bank_name'], 'account' => $before['bank_account_number'] !== '' ? '…' . substr($before['bank_account_number'], -4) : '', 'branch' => $before['bank_branch_code']],
                       'to' => ['bank' => $after['bank_name'], 'account' => $account !== '' ? '…' . substr($account, -4) : '', 'branch' => $branch],
                   ]), $_SERVER['REMOTE_ADDR'] ?? null]);
                $DB->commit();
            } catch (Throwable $e) {
                if ($DB->inTransaction()) $DB->rollBack();
                throw $e;
            }
            if ($changed && function_exists('fw_notify')) {
                // anyone else who can change money settings hears about it
                $st = $DB->prepare("SELECT DISTINCT uc.user_id FROM user_companies uc WHERE uc.company_id = ? AND uc.user_id <> ? AND LOWER(uc.role) IN ('admin', 'owner', 'bookkeeper')");
                try { $st->execute([$QI_COMPANY_ID, $QI_USER_ID]); $others = $st->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $others = []; }
                $st = $DB->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM users WHERE id = ?");
                $st->execute([$QI_USER_ID]);
                $who = (string)$st->fetchColumn() ?: 'Someone';
                foreach ($others as $uid) {
                    fw_notify($DB, $QI_COMPANY_ID, (int)$uid, 'qi_bank_changed', 'Bank details on invoices were changed',
                        $who . ' changed the account that customers pay into (now ending ' . substr($account, -4) . '). If this was not expected, check it now.', '/qi/settings.php#banking');
                }
            }
            break;

        case 'numbering':
            $prefix = fn($k, $def) => preg_replace('/[^A-Za-z0-9\/._-]/', '', $t($k, 12));
            $cols = [
                'quote_prefix' => $prefix('quote_prefix', 'Q'), 'invoice_prefix' => $prefix('invoice_prefix', 'INV'), 'credit_prefix' => $prefix('credit_prefix', 'CN'),
                'number_year' => !empty($v['number_year']) ? 1 : 0,
                'number_digits' => max(1, min(8, (int)($v['number_digits'] ?? 4))),
                'number_sep' => in_array((string)($v['number_sep'] ?? '-'), ['', '-', '/', '.', '_'], true) ? (string)$v['number_sep'] : '-',
            ];
            if ($cols['invoice_prefix'] === '' && $cols['quote_prefix'] === '') throw new QiInvalid('Give quotes and invoices different prefixes so their numbers cannot be confused.', 'invoice_prefix');
            if ($cols['invoice_prefix'] !== '' && strcasecmp($cols['invoice_prefix'], $cols['credit_prefix']) === 0) throw new QiInvalid('Invoices and credit notes need different prefixes.', 'credit_prefix');
            qi_settings_upsert($cols);
            $alloc = new SequenceAllocator($DB);
            foreach (['quote' => 'next_quote', 'invoice' => 'next_invoice', 'credit_note' => 'next_credit'] as $type => $key) {
                if (!isset($v[$key]) || $v[$key] === '') continue;
                $n = (int)$v[$key];
                if ($n < 1 || $n > 99999999) throw new QiInvalid('The next number must be between 1 and 99999999.', $key);
                $alloc->setNext($QI_COMPANY_ID, $type, $n);
            }
            break;

        case 'defaults':
            $cur = strtoupper($t('default_currency', 3));
            qi_settings_upsert([
                'default_payment_terms' => max(0, min(365, (int)($v['default_payment_terms'] ?? 30))),
                'quote_validity_days' => max(1, min(365, (int)($v['quote_validity_days'] ?? 14))),
                'default_currency' => Currencies::isValid($cur) ? $cur : Currencies::BASE,
                'default_terms' => $t('default_terms', 10000, true),
                'default_notes' => $t('default_notes', 5000, true),
            ]);
            break;

        case 'emails':
            foreach (QI_TEMPLATE_TYPES as $k => $dbType) {
                $tpl = is_array($v[$k] ?? null) ? $v[$k] : null;
                if ($tpl === null) continue;
                $subject = QiWriter::text($tpl['subject'] ?? '', 255);
                $body = QiWriter::text($tpl['body'] ?? '', 10000, true);
                $DB->prepare("UPDATE qi_email_templates SET active = 0, updated_at = NOW() WHERE company_id = ? AND template_type = ?")->execute([$QI_COMPANY_ID, $dbType]);
                if ($subject !== '' || $body !== '') {
                    $html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'), false) . '</p>';
                    $DB->prepare("INSERT INTO qi_email_templates (company_id, template_type, subject, body_html, body_plain, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())")
                       ->execute([$QI_COMPANY_ID, $dbType, $subject, $html, $body]);
                }
            }
            break;

        default:
            qi_error('Unknown section');
    }
    if ($section !== 'banking') {
        $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at) VALUES (?, ?, ?, 'company', ?, NULL, ?, NOW())")
           ->execute([$QI_COMPANY_ID, $QI_USER_ID, 'qi_settings_' . $section, $QI_COMPANY_ID, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
    qi_json(['saved' => $section] + qi_settings_payload());
} catch (QiInvalid $e) {
    qi_error($e->getMessage(), 422, ['field' => $e->field]);
}
