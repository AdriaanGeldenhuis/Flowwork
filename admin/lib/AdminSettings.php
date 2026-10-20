<?php
// /admin/lib/AdminSettings.php — every company setting the admin console
// shows, in one registry: label, help, control, default, limits and where
// the module that uses it actually reads it from ("store"):
//   cs       company_settings (setting_key = the field key)
//   payroll  a payroll_settings column (Payroll reads that table)
//   ai       an ai_settings column (Suppliers AI reads that table)
//   finance  Finance's engine settings, saved through FinSettings::saveEngine
//            so its own safeguards apply (closed years, prepared VAT returns)
//   company  a companies column (Yoco keys)
// Only settings some module really reads are listed here; the pages are
// drawn from this registry and /admin/api/settings.php validates against it,
// so what you see, what is saved and what is used cannot drift apart.

final class AdminInvalid extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(count($errors) === 1 ? (string)reset($errors) : 'Some values need a second look.');
    }
}

final class AdminSettings
{
    public const TIMEZONES = ['Africa/Johannesburg', 'Africa/Windhoek', 'Africa/Gaborone', 'Africa/Maputo', 'Africa/Harare', 'Africa/Lusaka', 'Africa/Maseru', 'Africa/Mbabane',
        'Africa/Nairobi', 'Africa/Lagos', 'Africa/Cairo', 'Africa/Casablanca', 'Indian/Mauritius', 'Europe/London', 'Europe/Amsterdam', 'Europe/Berlin', 'Europe/Paris',
        'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Shanghai', 'Asia/Tokyo', 'Australia/Perth', 'Australia/Sydney', 'Pacific/Auckland',
        'America/Sao_Paulo', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'UTC'];
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private static ?array $areas = null;

    /** [area => ['cards' => [id => [title, description, icon]], 'fields' => [...]]] */
    public static function areas(): array
    {
        if (self::$areas !== null) return self::$areas;
        $toggle = fn(string $key, string $label, string $hint, string $default, string $card, array $extra = []) =>
            ['key' => $key, 'label' => $label, 'hint' => $hint, 'type' => 'toggle', 'default' => $default, 'card' => $card] + $extra;
        $months = array_combine(self::MONTHS, self::MONTHS);

        return self::$areas = [
            'calendar' => [
                'cards' => [
                    'cal-general' => ['Company defaults', 'Where everyone starts. People can still change their own view, hours and reminders in Calendar settings.', 'i-calendar'],
                    'cal-hours' => ['Working hours', 'Shaded on day and week views, and used when people look for a free slot.', 'i-clock'],
                    'cal-sources' => ['Dates from Flowwork', 'Shown live on the calendar — nothing is copied, so they always match the record. Each person can still hide them for themselves.', 'i-grid'],
                ],
                'fields' => [
                    ['key' => 'calendar_timezone', 'label' => 'Time zone', 'hint' => 'Event times, “today” and reminders run in this zone for the whole company.', 'type' => 'select', 'default' => 'Africa/Johannesburg',
                     'options' => array_combine(self::TIMEZONES, array_map(fn($z) => str_replace(['_', '/'], [' ', ' · '], $z), self::TIMEZONES)), 'card' => 'cal-general', 'kw' => 'timezone zone clock'],
                    ['key' => 'calendar_week_start', 'label' => 'Week starts on', 'hint' => '', 'type' => 'select', 'default' => '1', 'options' => ['1' => 'Monday', '0' => 'Sunday'], 'card' => 'cal-general'],
                    ['key' => 'calendar_default_view', 'label' => 'Opens on', 'hint' => 'The view the calendar shows first.', 'type' => 'select', 'default' => 'week',
                     'options' => ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year', 'agenda' => 'Schedule'], 'card' => 'cal-general'],
                    ['key' => 'calendar_default_reminder', 'label' => 'Default reminder', 'hint' => 'Added to new events; people can change it per event.', 'type' => 'select', 'default' => '15',
                     'options' => ['0' => 'No reminder', '5' => '5 minutes before', '10' => '10 minutes before', '15' => '15 minutes before', '30' => '30 minutes before',
                                   '60' => '1 hour before', '120' => '2 hours before', '1440' => '1 day before', '10080' => '1 week before'], 'card' => 'cal-general', 'kw' => 'alert notification'],
                    ['key' => 'calendar_work_hours_start', 'label' => 'Day starts', 'hint' => '', 'type' => 'time', 'default' => '08:00', 'card' => 'cal-hours', 'kw' => 'work hours office'],
                    ['key' => 'calendar_work_hours_end', 'label' => 'Day ends', 'hint' => '', 'type' => 'time', 'default' => '17:00', 'card' => 'cal-hours', 'kw' => 'work hours office'],
                    $toggle('calendar_show_weekends', 'Show weekends', 'Saturday and Sunday on week and month views.', '1', 'cal-hours'),
                    $toggle('calendar_sync_invoice_due', 'Invoices due', 'Unpaid invoices on their due date.', '1', 'cal-sources', ['kw' => 'qi billing']),
                    $toggle('calendar_sync_quotes', 'Quotes expiring', 'Sent quotes on the day they expire.', '1', 'cal-sources'),
                    $toggle('calendar_sync_project_dates', 'Project dates', 'Start and due dates, for the people on the project.', '1', 'cal-sources'),
                    $toggle('calendar_sync_tasks', 'Board tasks', 'Board items on their due date, for the person they are assigned to.', '1', 'cal-sources', ['kw' => 'items projects']),
                    $toggle('calendar_sync_deals', 'Deals closing', 'Open CRM deals on their expected close date, for their owner.', '1', 'cal-sources', ['kw' => 'crm pipeline']),
                    $toggle('calendar_sync_crm_tasks', 'CRM follow-ups', 'Open CRM tasks on their due date, for the person they are assigned to.', '1', 'cal-sources'),
                    $toggle('calendar_sync_payroll_dates', 'Pay days', 'Payroll pay dates — only admins and bookkeepers see them.', '0', 'cal-sources', ['kw' => 'salary wages']),
                ],
            ],

            'security' => [
                'cards' => [
                    'sec-signin' => ['Sign-in protection', 'Slows down anyone guessing passwords. Applies to everyone whose main company is this one.', 'i-lock'],
                    'sec-passwords' => ['Password rules', 'Checked whenever someone sets a password: accepting an invitation or resetting a forgotten one.', 'i-shield'],
                ],
                'fields' => [
                    ['key' => 'security_max_login_attempts', 'label' => 'Wrong passwords before a pause', 'hint' => 'After this many failed tries from one place, sign-in pauses for that account.', 'type' => 'number',
                     'default' => '5', 'min' => 3, 'max' => 20, 'unit' => 'tries', 'card' => 'sec-signin', 'kw' => 'lockout attempts brute force'],
                    ['key' => 'security_lockout_duration', 'label' => 'Pause length', 'hint' => 'How long sign-in stays paused. Failed tries older than this are forgotten.', 'type' => 'number',
                     'default' => '15', 'min' => 1, 'max' => 60, 'unit' => 'minutes', 'card' => 'sec-signin', 'kw' => 'lockout duration'],
                    $toggle('security_single_device', 'One device per person', 'Signing in on a new phone or computer signs that person out everywhere else. Off: people stay signed in on phone, tablet and computer at once.', '0', 'sec-signin', ['kw' => 'sessions single session']),
                    ['key' => 'security_min_password_length', 'label' => 'Minimum length', 'hint' => 'Longer passwords are the single best protection.', 'type' => 'number',
                     'default' => '8', 'min' => 8, 'max' => 64, 'unit' => 'characters', 'card' => 'sec-passwords', 'kw' => 'password length'],
                    $toggle('security_require_uppercase', 'Upper and lower case', 'At least one capital and one small letter.', '1', 'sec-passwords', ['kw' => 'password uppercase capital']),
                    $toggle('security_require_numbers', 'A number', 'At least one digit (0–9).', '1', 'sec-passwords', ['kw' => 'password digit']),
                    $toggle('security_require_special', 'A symbol', 'At least one character such as ! @ # $ % or &.', '0', 'sec-passwords', ['kw' => 'password special character']),
                ],
            ],

            'data' => [
                'cards' => [
                    'data-retention' => ['Sign-in records', 'Who signed in or out, when, from which address and on which device — personal information POPIA asks you not to keep longer than you need. Older records are removed automatically once an hour.', 'i-history'],
                ],
                'fields' => [
                    ['key' => 'signin_retention_days', 'label' => 'Keep sign-in records for', 'hint' => 'Only sign-ins, sign-outs and company switches are removed. Everything else in the audit trail — settings, people, invoices, the books — is always kept.', 'type' => 'select', 'default' => '0',
                     'options' => ['0' => 'Forever', '90' => '3 months', '180' => '6 months', '365' => '1 year', '730' => '2 years'], 'card' => 'data-retention', 'kw' => 'retention delete popia history cleanup audit login'],
                ],
            ],

            'payroll' => [
                'cards' => [
                    'pay-schedule' => ['Pay schedule', 'A new pay run starts with these. You can change the dates on any run.', 'i-calendar'],
                    'pay-finance' => ['Finance', 'What happens in Finance once a run is done.', 'if-ledger'],
                ],
                'fields' => [
                    ['key' => 'default_frequency', 'store' => 'payroll', 'label' => 'Usual pay frequency', 'hint' => '', 'type' => 'select', 'default' => 'monthly',
                     'options' => ['monthly' => 'Monthly', 'fortnight' => 'Every two weeks', 'weekly' => 'Weekly'], 'card' => 'pay-schedule', 'kw' => 'frequency'],
                    ['key' => 'monthly_anchor_day', 'store' => 'payroll', 'label' => 'Monthly pay day', 'hint' => 'Day of the month salaries are paid. Months that are shorter use their last day.', 'type' => 'number',
                     'default' => '25', 'min' => 1, 'max' => 31, 'unit' => 'of the month', 'card' => 'pay-schedule', 'kw' => 'pay day salary date'],
                    ['key' => 'weekly_anchor_day', 'store' => 'payroll', 'label' => 'Weekly and two-weekly pay day', 'hint' => 'Weekly and two-weekly periods end on this day and are paid on it.', 'type' => 'select',
                     'default' => '5', 'options' => ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'], 'card' => 'pay-schedule', 'kw' => 'weekly fortnight'],
                    $toggle('auto_post_to_finance', 'Put runs in the books when they are finalised', 'Their wages, PAYE, UIF and SDL journal goes to Finance as soon as a run is finalised.', '0', 'pay-finance', ['store' => 'payroll', 'kw' => 'journal ledger gl post']),
                ],
            ],

            'suppliers' => [
                'cards' => [
                    'sup-search' => ['Supplier search', 'How Suppliers AI looks for and ranks suppliers.', 'i-search'],
                    'sup-compliance' => ['Compliance documents', 'BEE certificates, tax clearance and the like, kept on each supplier in the CRM.', 'i-shield'],
                ],
                'fields' => [
                    ['key' => 'default_radius_km', 'store' => 'ai', 'label' => 'Search radius', 'hint' => 'How far from the site to look when no radius is given.', 'type' => 'number',
                     'default' => '25', 'min' => 1, 'max' => 500, 'unit' => 'km', 'card' => 'sup-search', 'kw' => 'distance radius'],
                    ['key' => 'min_score_threshold', 'store' => 'ai', 'label' => 'Minimum match', 'hint' => 'Results that match less well than this are left out (0 shows everything, 1 only perfect matches).', 'type' => 'number',
                     'default' => '0.55', 'min' => 0, 'max' => 1, 'step' => 0.05, 'unit' => 'of 1', 'card' => 'sup-search', 'kw' => 'score relevance'],
                    ['key' => 'rfq_max_recipients', 'store' => 'ai', 'label' => 'Suppliers per request for quote', 'hint' => 'The most suppliers one RFQ is sent to.', 'type' => 'number',
                     'default' => '8', 'min' => 1, 'max' => 50, 'unit' => 'suppliers', 'card' => 'sup-search', 'kw' => 'rfq quote request'],
                    ['key' => 'per_hour_cap', 'store' => 'ai', 'label' => 'AI searches per hour', 'hint' => 'A ceiling for the whole company, so a runaway script cannot run up costs.', 'type' => 'number',
                     'default' => '20', 'min' => 1, 'max' => 500, 'unit' => 'per hour', 'card' => 'sup-search', 'kw' => 'limit cap'],
                    $toggle('crm_block_expired_suppliers', 'Block suppliers with expired documents', 'They cannot be chosen on new purchase orders, receipts or RFQs until the document is renewed.', '0', 'sup-compliance', ['kw' => 'bee tax clearance block']),
                    ['key' => 'crm_reminder_days', 'label' => 'Remind before expiry', 'hint' => 'Days before a document expires that its owner is reminded, separated by commas.', 'type' => 'text',
                     'default' => '30,14,7', 'max' => 40, 'placeholder' => '30,14,7', 'unit' => 'days', 'card' => 'sup-compliance', 'kw' => 'reminder expiry warning'],
                ],
            ],

            'finance' => [
                'cards' => [
                    'fin-year' => ['Financial year & VAT', 'The engine settings Finance books with. Changes are refused when they would clash with a closed year or a VAT return in progress.', 'if-vat'],
                    'fin-controls' => ['Controls', 'Checks that protect the books.', 'if-approve'],
                ],
                'fields' => [
                    ['key' => 'fiscal_year_start', 'store' => 'finance', 'label' => 'Financial year starts in', 'hint' => 'Most South African companies start in March.', 'type' => 'select', 'default' => 'March',
                     'options' => $months, 'card' => 'fin-year', 'kw' => 'fiscal year end tax year'],
                    ['key' => 'vat_basis', 'store' => 'finance', 'label' => 'VAT basis', 'hint' => 'Invoice basis pays VAT when you invoice; payments basis when you are paid. Use what SARS registered you on.', 'type' => 'select', 'default' => 'invoice',
                     'options' => ['invoice' => 'Invoice basis', 'payments' => 'Payments basis'], 'card' => 'fin-year', 'kw' => 'vat cash accrual'],
                    $toggle('require_sod', 'Two people for journals', 'Whoever creates a manual journal cannot also approve it (segregation of duties).', '0', 'fin-controls', ['store' => 'finance', 'kw' => 'approval segregation duties journal']),
                ],
            ],

            'payments' => [
                'cards' => [
                    'pay-yoco' => ['Yoco card payments', 'Adds a “Pay now” button to invoices you send. Customers pay by card on Yoco’s secure page and the payment is recorded on the invoice by itself.', 'i-wallet'],
                ],
                'fields' => [
                    ['key' => 'yoco_sec_key', 'store' => 'company', 'label' => 'Secret key', 'hint' => 'From the API keys section of your Yoco portal. Starts with sk_live_ (or sk_test_ to try things out).', 'type' => 'secret',
                     'default' => '', 'max' => 255, 'placeholder' => 'sk_live_…', 'card' => 'pay-yoco', 'kw' => 'yoco api key secret'],
                    ['key' => 'yoco_webhook_secret', 'store' => 'company', 'label' => 'Webhook signing secret', 'hint' => 'Yoco shows it once, when you register the webhook address below. Starts with whsec_.', 'type' => 'secret',
                     'default' => '', 'max' => 255, 'placeholder' => 'whsec_…', 'card' => 'pay-yoco', 'kw' => 'yoco webhook'],
                ],
            ],
        ];
    }

    public static function area(string $area): array
    {
        $a = self::areas()[$area] ?? null;
        if (!$a) throw new AdminInvalid(['area' => 'Unknown settings area.']);
        return $a;
    }

    /** Current values of an area as strings (secrets: '__set__' or ''). */
    public static function load(PDO $db, int $companyId, string $area): array
    {
        $def = self::area($area);
        $raw = [];
        $byStore = [];
        foreach ($def['fields'] as $f) $byStore[$f['store'] ?? 'cs'][] = $f['key'];

        if (!empty($byStore['cs'])) {
            $keys = $byStore['cs'];
            $st = $db->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ? AND setting_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
            $st->execute(array_merge([$companyId], $keys));
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) if ((string)$v !== '') $raw[$k] = (string)$v;
        }
        foreach (['payroll' => 'payroll_settings', 'ai' => 'ai_settings'] as $store => $table) {
            if (empty($byStore[$store])) continue;
            try {
                $st = $db->prepare("SELECT * FROM `$table` WHERE company_id = ? LIMIT 1");
                $st->execute([$companyId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach ($byStore[$store] as $k) if (isset($row[$k]) && $row[$k] !== '') $raw[$k] = (string)$row[$k];
            } catch (Throwable $e) {
                error_log('[admin] settings load ' . $table . ': ' . $e->getMessage());
            }
        }
        if (!empty($byStore['finance'])) {
            require_once __DIR__ . '/../../finances/lib/Fin.php';
            require_once __DIR__ . '/../../finances/lib/CoaSchema.php';
            $eng = Fin::engineSettings($db, $companyId);
            $eng['fiscal_year_start'] = self::MONTHS[CoaSchema::parseFiscalMonth($eng['fiscal_year_start']) - 1];
            foreach ($byStore['finance'] as $k) if (isset($eng[$k])) $raw[$k] = (string)$eng[$k];
        }
        if (!empty($byStore['company'])) {
            $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
            $st->execute([$companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($byStore['company'] as $k) $raw[$k] = trim((string)($row[$k] ?? ''));
        }

        $out = [];
        foreach ($def['fields'] as $f) {
            $v = $raw[$f['key']] ?? $f['default'];
            if ($f['type'] === 'secret') { $out[$f['key']] = $v !== '' ? '__set__' : ''; continue; }
            try {
                $out[$f['key']] = self::clean($f, $v);
            } catch (AdminInvalid $e) {
                $out[$f['key']] = (string)$f['default']; // a stored value that no longer fits shows the default
            }
        }
        return $out;
    }

    /**
     * Validate and save $values (key => string) for one area.
     * @return array{changed: string[], values: array}
     * @throws AdminInvalid with per-field messages
     */
    public static function save(PDO $db, int $companyId, int $userId, string $role, string $area, array $values): array
    {
        $def = self::area($area);
        $fields = [];
        foreach ($def['fields'] as $f) $fields[$f['key']] = $f;
        $current = self::load($db, $companyId, $area);

        $errors = [];
        $clean = [];
        foreach ($values as $k => $v) {
            if (!isset($fields[$k])) continue;
            if (!is_scalar($v) && $v !== null) { $errors[$k] = 'Check this value.'; continue; }
            $f = $fields[$k];
            $v = (string)$v;
            if ($f['type'] === 'secret') {
                if ($v === '' || $v === '__set__') continue;            // keep what is stored
                if ($v === '__clear__') { $clean[$k] = ''; continue; }  // remove it
            }
            try {
                $clean[$k] = self::clean($f, $v);
            } catch (AdminInvalid $e) {
                $errors[$k] = (string)(array_values($e->errors)[0] ?? "Check this value.");
            }
        }
        // Rules across fields
        $merged = array_merge($current, $clean);
        if ($area === 'calendar' && $merged['calendar_work_hours_end'] <= $merged['calendar_work_hours_start']) {
            $errors[isset($clean['calendar_work_hours_end']) ? 'calendar_work_hours_end' : 'calendar_work_hours_start'] = 'The day has to end after it starts.';
        }
        if ($errors) throw new AdminInvalid($errors);

        $changed = [];
        foreach ($clean as $k => $v) {
            $was = $fields[$k]['type'] === 'secret' ? ($current[$k] === '__set__' ? '__set__' : '') : $current[$k];
            if ($fields[$k]['type'] === 'secret' ? ($v !== '' || $was !== '') : $v !== $was) $changed[$k] = $v;
        }
        if (!$changed) return ['changed' => [], 'values' => $current];

        $byStore = [];
        foreach ($changed as $k => $v) $byStore[$fields[$k]['store'] ?? 'cs'][$k] = $v;

        if (isset($byStore['finance'])) {
            // Finance runs its own checks and transaction (and writes its own audit line).
            require_once __DIR__ . '/../../finances/lib/FinSettings.php';
            try {
                FinSettings::saveEngine($db, $companyId, $userId, $role, $byStore['finance']);
            } catch (FinUserError | FinForbidden $e) {
                throw new AdminInvalid([array_key_first($byStore['finance']) => $e->getMessage()]);
            }
            unset($byStore['finance']);
        }
        if ($byStore) {
            $db->beginTransaction();
            try {
                foreach ($byStore as $store => $set) {
                    if ($store === 'cs') {
                        $st = $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW())
                                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                        foreach ($set as $k => $v) $st->execute([$companyId, $k, $v]);
                    } elseif ($store === 'payroll' || $store === 'ai') {
                        $table = $store === 'payroll' ? 'payroll_settings' : 'ai_settings';
                        $cols = array_keys($set);
                        $db->prepare("INSERT INTO `$table` (company_id, " . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ") VALUES (?" . str_repeat(', ?', count($cols)) . ")
                                      ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols)) . ", updated_at = NOW()")
                           ->execute(array_merge([$companyId], array_values($set)));
                    } elseif ($store === 'company') {
                        $sets = [];
                        $params = [];
                        foreach ($set as $k => $v) { $sets[] = "`$k` = ?"; $params[] = $v === '' ? null : $v; }
                        if (isset($set['yoco_sec_key'])) {
                            // The key itself says which Yoco mode it is; keep the label in step.
                            $sets[] = 'yoco_mode = ?';
                            $params[] = str_starts_with($set['yoco_sec_key'], 'sk_live_') ? 'live' : 'test';
                        }
                        $params[] = $companyId;
                        $db->prepare("UPDATE companies SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?")->execute($params);
                    }
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        $log = [];
        foreach ($changed as $k => $v) {
            $f = $fields[$k];
            if ($f['type'] === 'secret') { $log[$f['label']] = $v === '' ? 'removed' : 'replaced'; continue; }
            $show = fn($x) => $f['type'] === 'toggle' ? ($x === '1' ? 'on' : 'off') : (string)($f['options'][$x] ?? $x);
            $log[$f['label']] = $show($current[$k]) . ' → ' . $show($v);
        }
        AdminKit::audit($db, $companyId, $userId, 'settings_updated', ['area' => $area] + $log, 'settings');
        return ['changed' => array_keys($changed), 'values' => self::load($db, $companyId, $area)];
    }

    /** One value, checked and normalised to its stored string form. */
    public static function clean(array $f, string $v): string
    {
        $v = trim($v);
        $fail = fn(string $m) => throw new AdminInvalid([$f['key'] => $m]);
        switch ($f['type']) {
            case 'toggle':
                return in_array($v, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
            case 'select':
                if ($f['key'] === 'calendar_timezone') {
                    if (!in_array($v, DateTimeZone::listIdentifiers(), true)) $fail('Choose a time zone from the list.');
                    return $v;
                }
                if (!array_key_exists($v, $f['options'])) $fail('Choose one of the options.');
                return $v;
            case 'number':
                if ($v === '' || !is_numeric($v)) $fail('Enter a number.');
                $n = (float)$v;
                if ($n < $f['min'] || $n > $f['max']) $fail('Use a number from ' . self::num($f['min']) . ' to ' . self::num($f['max']) . '.');
                $step = (float)($f['step'] ?? 1);
                if ($step >= 1 && floor($n) != $n) $fail('Use a whole number.');
                return self::num($step >= 1 ? (int)$n : round($n, 4));
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $fail('Use a time like 08:00.');
                return $v;
            case 'text':
                if (mb_strlen($v) > ($f['max'] ?? 255)) $fail('That is too long.');
                if ($f['key'] === 'crm_reminder_days') {
                    $days = array_filter(array_map('trim', preg_split('/[,;\s]+/', $v)), 'strlen');
                    if (!$days) $fail('Enter at least one number of days, like 30,14,7.');
                    foreach ($days as $d) if (!ctype_digit($d) || (int)$d < 1 || (int)$d > 365) $fail('Use whole days from 1 to 365, separated by commas.');
                    $days = array_values(array_unique(array_map('intval', $days)));
                    rsort($days);
                    if (count($days) > 6) $fail('Six reminders at most.');
                    return implode(',', $days);
                }
                return $v;
            case 'secret':
                if (preg_match('/\s/', $v) || mb_strlen($v) > ($f['max'] ?? 255)) $fail('Paste the key exactly as Yoco shows it, without spaces.');
                if ($f['key'] === 'yoco_sec_key' && !preg_match('/^sk_(live|test)_[A-Za-z0-9_]{8,}$/', $v)) {
                    if (preg_match('/^pk_/', $v)) $fail('That is the public key. Flowwork needs the secret key (sk_…).');
                    $fail('A Yoco secret key starts with sk_live_ or sk_test_.');
                }
                if ($f['key'] === 'yoco_webhook_secret' && !preg_match('/^whsec_[A-Za-z0-9+\/=]{8,}$/', $v)) $fail('A Yoco webhook secret starts with whsec_.');
                return $v;
        }
        return $v;
    }

    private static function num($n): string
    {
        $s = is_float($n) ? rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') : (string)$n;
        return $s === '-0' ? '0' : $s;
    }

    // ------------------------------------------------------------ rendering

    /** The cards and rows for an area (inside <form data-settings="$area">). $only: card ids to draw. */
    public static function render(string $area, array $values, ?array $only = null): string
    {
        $def = self::area($area);
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '';
        foreach ($def['cards'] as $cardId => [$title, $desc, $icon]) {
            if ($only !== null && !in_array($cardId, $only, true)) continue;
            $rows = '';
            foreach ($def['fields'] as $f) {
                if ($f['card'] !== $cardId) continue;
                $rows .= self::row($f, $values[$f['key']] ?? $f['default'], $e);
            }
            $html .= '<section class="fwa-card" id="' . $e($cardId) . '"><header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#' . $e($icon) . '"/></svg></span>'
                   . '<div class="fwa-card__title"><h3>' . $e($title) . '</h3>' . ($desc !== '' ? '<p>' . $e($desc) . '</p>' : '') . '</div></header>'
                   . '<div class="fwa-card--flush"><div class="fwa-card__body">' . $rows . '</div></div></section>';
        }
        return $html;
    }

    private static function row(array $f, string $value, callable $e): string
    {
        $id = 'set-' . $f['key'];
        $name = $e($f['key']);
        $label = '<span class="fwa-set__label">' . $e($f['label']) . '</span>' . ($f['hint'] !== '' ? '<span class="fwa-set__hint">' . $e($f['hint']) . '</span>' : '');
        $requires = isset($f['requires']) ? ' data-requires="' . $e($f['requires']) . '"' : '';
        if ($f['type'] === 'toggle') {
            return '<label class="fwa-set fwa-set--toggle" id="' . $id . '"' . $requires . '><span class="fwa-set__text">' . $label . '</span>'
                 . '<span class="fwa-set__control"><span class="switch"><input type="checkbox" name="' . $name . '" value="1"' . ($value === '1' ? ' checked' : '') . '><span class="switch__track" aria-hidden="true"></span></span></span></label>';
        }
        $ctlId = 'f-' . $f['key'];
        $control = '';
        switch ($f['type']) {
            case 'select':
                $opts = $f['options'];
                if (!array_key_exists($value, $opts)) $opts[$value] = str_replace('_', ' ', $value);
                $control = '<select class="select" id="' . $ctlId . '" name="' . $name . '">';
                foreach ($opts as $k => $l) $control .= '<option value="' . $e($k) . '"' . ((string)$k === $value ? ' selected' : '') . '>' . $e($l) . '</option>';
                $control .= '</select>';
                break;
            case 'number':
                $input = '<input class="input" type="number" inputmode="decimal" id="' . $ctlId . '" name="' . $name . '" value="' . $e($value) . '" min="' . $e($f['min']) . '" max="' . $e($f['max']) . '" step="' . $e($f['step'] ?? 1) . '" required>';
                $control = !empty($f['unit']) ? '<span class="fwa-affix">' . $input . '<span>' . $e($f['unit']) . '</span></span>' : $input;
                break;
            case 'time':
                $control = '<input class="input" type="time" id="' . $ctlId . '" name="' . $name . '" value="' . $e($value) . '" required>';
                break;
            case 'secret':
                $control = '<span class="fwa-affix"><input class="input" type="password" data-secret="1" id="' . $ctlId . '" name="' . $name . '" value="" autocomplete="off" spellcheck="false" placeholder="'
                         . $e($value === '__set__' ? '•••••••• saved' : ($f['placeholder'] ?? '')) . '"><button type="button" data-reveal aria-label="Show"><svg class="i i--sm"><use href="#i-eye"/></svg></button></span>';
                break;
            default:
                $input = '<input class="input" type="text" id="' . $ctlId . '" name="' . $name . '" value="' . $e($value) . '" maxlength="' . (int)($f['max'] ?? 255) . '" placeholder="' . $e($f['placeholder'] ?? '') . '">';
                $control = !empty($f['unit']) ? '<span class="fwa-affix">' . $input . '<span>' . $e($f['unit']) . '</span></span>' : $input;
        }
        if ($f['type'] === 'secret') {
            $label .= '<span class="fwa-set__hint" data-secret-state>' . ($value === '__set__' ? 'Saved — hidden for safety. Paste a new one to replace it.' : 'Not set yet.') . '</span>';
        }
        return '<div class="fwa-set" id="' . $id . '"' . $requires . '><label class="fwa-set__text" for="' . $ctlId . '">' . $label . '</label><div class="fwa-set__control">' . $control . '</div></div>';
    }
}
