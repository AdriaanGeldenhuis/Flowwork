<?php
/**
 * Admin console: every setting the console shows is saved where the module
 * that uses it reads it (Calendar, sign-in limits, Payroll, Suppliers AI and
 * CRM compliance, Finance's engine, Yoco on the company), bad values are
 * refused field by field, secrets never come back, and the password rules,
 * audit-trail retention, audit filters and readable audit labels behave.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/includes/auth_sessions.php';
require_once FLOWWORK_ROOT . '/includes/password_policy.php';
require_once FLOWWORK_ROOT . '/includes/audit_retention.php';
require_once FLOWWORK_ROOT . '/admin/lib/AdminKit.php';
require_once FLOWWORK_ROOT . '/admin/lib/AdminSettings.php';
require_once FLOWWORK_ROOT . '/admin/lib/AdminAudit.php';
require_once FLOWWORK_ROOT . '/calendar/lib/autoload.php';
require_once FLOWWORK_ROOT . '/crm/lib/Crm.php';
require_once FLOWWORK_ROOT . '/crm/lib/CrmCompliance.php';
require_once FLOWWORK_ROOT . '/finances/lib/Fin.php';

$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
$keys = ['calendar_timezone', 'calendar_work_hours_start', 'calendar_work_hours_end', 'calendar_sync_payroll_dates', 'calendar_default_view',
         'security_max_login_attempts', 'security_lockout_duration', 'security_min_password_length', 'security_require_uppercase', 'security_require_numbers', 'security_require_special',
         'crm_block_expired_suppliers', 'crm_reminder_days', 'audit_retention_days', 'finance_vat_basis', 'finance_require_sod', 'finance_fiscal_year_start'];
$saved = $DB->query("SELECT setting_key, setting_value FROM company_settings WHERE company_id = $C AND setting_key IN ('" . implode("','", $keys) . "')")->fetchAll(PDO::FETCH_KEY_PAIR);
$savedPayroll = $DB->query("SELECT * FROM payroll_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC) ?: null;
$savedAi = $DB->query("SELECT * FROM ai_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC) ?: null;
$savedYoco = $DB->query("SELECT yoco_mode, yoco_sec_key, yoco_webhook_secret FROM companies WHERE id = $C")->fetch(PDO::FETCH_ASSOC);
$invalid = function (callable $fn, string $key, string $message) {
    try {
        $fn();
    } catch (AdminInvalid $e) {
        assert_true(isset($e->errors[$key]), "$message (error on $key, got " . json_encode($e->errors) . ')');
        return;
    }
    fail("ASSERT FAILED: $message (nothing refused)");
};

// Start from the defaults (the originals are put back at the end).
$DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key IN ('" . implode("','", $keys) . "')")->execute([$C]);
Fin::flush();

try {
    // ------------------------------------------------------------------ registry
    foreach (AdminSettings::areas() as $area => $def) {
        foreach ($def['fields'] as $f) {
            assert_true(isset($def['cards'][$f['card']]), "$area.{$f['key']} sits on a card that exists");
            if ($f['type'] !== 'secret') assert_eq($f['default'], AdminSettings::clean($f, (string)$f['default']), "$area.{$f['key']} default passes its own check");
        }
    }

    // ------------------------------------------------------------------ calendar
    $r = AdminSettings::save($DB, $C, $U, 'admin', 'calendar', ['calendar_timezone' => 'Africa/Windhoek', 'calendar_default_view' => 'month', 'calendar_sync_payroll_dates' => '1']);
    assert_eq(3, count($r['changed']), 'three calendar settings changed');
    assert_eq('Africa/Windhoek', CalendarRepo::companyTz($DB, $C)->getName(), 'Calendar reads the new time zone');
    assert_eq('month', CalendarSettings::companyDefaults($DB, $C)['default_view'], 'Calendar opens on the new view');
    assert_true(CalendarSettings::availableSources($DB, $C, 'admin')['payroll'], 'pay days shown to admins');
    assert_true(!CalendarSettings::availableSources($DB, $C, 'member')['payroll'], 'pay days still hidden from members');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'calendar', ['calendar_work_hours_start' => '18:00', 'calendar_work_hours_end' => '09:00']), 'calendar_work_hours_end', 'a day that ends before it starts is refused');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'calendar', ['calendar_timezone' => 'Mars/Olympus']), 'calendar_timezone', 'an unknown time zone is refused');
    assert_eq([], AdminSettings::save($DB, $C, $U, 'admin', 'calendar', ['calendar_default_view' => 'month'])['changed'], 'saving the same value changes nothing');

    // ------------------------------------------------------------------ security
    AdminSettings::save($DB, $C, $U, 'admin', 'security', ['security_max_login_attempts' => '7', 'security_lockout_duration' => '30']);
    assert_eq([7, 30], fw_login_limits($DB, $C), 'sign-in limits read the new values');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'security', ['security_max_login_attempts' => '2']), 'security_max_login_attempts', 'fewer than 3 tries is refused');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'security', ['security_min_password_length' => '9.5']), 'security_min_password_length', 'a fractional length is refused');
    AdminSettings::save($DB, $C, $U, 'admin', 'security', ['security_min_password_length' => '12', 'security_require_special' => '1', 'security_require_numbers' => '0']);
    $p = fw_password_policy($DB, $C);
    assert_eq(['min' => 12, 'case' => true, 'number' => false, 'symbol' => true], $p, 'password policy follows the settings');
    assert_eq([], fw_password_problems('Sunny-Morning!', $p), 'a long mixed-case password with a symbol passes');
    assert_eq(['at least 12 characters', 'a symbol such as ! @ # or $'], fw_password_problems('Short1aA', $p), 'a short password without a symbol fails twice');
    assert_eq('Use at least 12 characters, with upper and lower case letters and a symbol.', fw_password_rules_text($p), 'rules read as a sentence');
    assert_eq(['min' => 8, 'case' => true, 'number' => true, 'symbol' => false], fw_password_policy($DB, null), 'without a company the defaults apply');

    // ------------------------------------------------------------------ payroll
    AdminSettings::save($DB, $C, $U, 'admin', 'payroll', ['default_frequency' => 'fortnight', 'monthly_anchor_day' => '28', 'auto_post_to_finance' => '1']);
    $row = $DB->query("SELECT default_frequency, monthly_anchor_day, auto_post_to_finance FROM payroll_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC);
    assert_eq(['default_frequency' => 'fortnight', 'monthly_anchor_day' => 28, 'auto_post_to_finance' => 1], $row, 'Payroll reads the new schedule from payroll_settings');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'payroll', ['default_frequency' => 'fortnightly']), 'default_frequency', 'a frequency Payroll does not know is refused');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'payroll', ['monthly_anchor_day' => '32']), 'monthly_anchor_day', 'day 32 is refused');

    // ------------------------------------------------------------------ suppliers
    AdminSettings::save($DB, $C, $U, 'admin', 'suppliers', ['default_radius_km' => '40', 'min_score_threshold' => '0.6', 'crm_block_expired_suppliers' => '1', 'crm_reminder_days' => '7; 30,14 7']);
    $row = $DB->query("SELECT default_radius_km, min_score_threshold FROM ai_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC);
    assert_eq(40.0, (float)$row['default_radius_km'], 'Suppliers AI reads the new radius');
    assert_eq(0.6, (float)$row['min_score_threshold'], 'Suppliers AI reads the new minimum match');
    $pol = CrmCompliance::policy($DB, $C);
    assert_true($pol['block'], 'the CRM blocks expired suppliers');
    assert_eq([30, 14, 7], $pol['reminder_days'], 'reminder days cleaned, de-duplicated and sorted');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'suppliers', ['crm_reminder_days' => '0,abc']), 'crm_reminder_days', 'nonsense reminder days are refused');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'suppliers', ['min_score_threshold' => '1.5']), 'min_score_threshold', 'a match above 1 is refused');

    // ------------------------------------------------------------------ finance (through FinSettings::saveEngine)
    AdminSettings::save($DB, $C, $U, 'admin', 'finance', ['fiscal_year_start' => 'July', 'vat_basis' => 'payments', 'require_sod' => '1']);
    Fin::flush();
    $eng = Fin::engineSettings($DB, $C);
    assert_eq(['fiscal_year_start' => 'July', 'vat_basis' => 'payments', 'require_sod' => '1'], $eng, 'Finance books with the new engine settings');
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'finance', ['fiscal_year_start' => 'Smarch']), 'fiscal_year_start', 'a month that does not exist is refused');

    // ------------------------------------------------------------------ Yoco keys on the company
    $invalid(fn() => AdminSettings::save($DB, $C, $U, 'admin', 'payments', ['yoco_sec_key' => 'pk_live_abcdefgh123']), 'yoco_sec_key', 'the public key is refused with a hint');
    AdminSettings::save($DB, $C, $U, 'admin', 'payments', ['yoco_sec_key' => 'sk_live_abcdef123456', 'yoco_webhook_secret' => 'whsec_QUJDREVGR0hJSktM']);
    $co = $DB->query("SELECT yoco_mode, yoco_sec_key, yoco_webhook_secret FROM companies WHERE id = $C")->fetch(PDO::FETCH_ASSOC);
    assert_eq(['yoco_mode' => 'live', 'yoco_sec_key' => 'sk_live_abcdef123456', 'yoco_webhook_secret' => 'whsec_QUJDREVGR0hJSktM'], $co, 'keys stored where QiYoco and the webhook read them; mode follows the key');
    assert_eq(['yoco_sec_key' => '__set__', 'yoco_webhook_secret' => '__set__'], AdminSettings::load($DB, $C, 'payments'), 'secrets never come back, only that they are set');
    assert_eq([], AdminSettings::save($DB, $C, $U, 'admin', 'payments', ['yoco_sec_key' => '', 'yoco_webhook_secret' => '__set__'])['changed'], 'a blank secret keeps the stored one');
    AdminSettings::save($DB, $C, $U, 'admin', 'payments', ['yoco_sec_key' => '__clear__', 'yoco_webhook_secret' => '__clear__']);
    assert_eq(null, $DB->query("SELECT yoco_sec_key FROM companies WHERE id = $C")->fetchColumn(), 'disconnect clears the key');

    // ------------------------------------------------------------------ audit trail: retention, labels, filters
    $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, timestamp, created_at) VALUES (?, ?, 'old_thing', 'x', '2020-01-01', '2020-01-01'), (?, ?, 'recent_thing', 'x', NOW(), NOW())")->execute([$C, $U, $C, $U]);
    AdminSettings::save($DB, $C, $U, 'admin', 'data', ['audit_retention_days' => '0']);
    fw_audit_retention_cleanup($DB);
    assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM audit_log WHERE company_id = $C AND action = 'old_thing'")->fetchColumn(), '"forever" keeps everything');
    AdminSettings::save($DB, $C, $U, 'admin', 'data', ['audit_retention_days' => '365']);
    assert_true(fw_audit_retention_cleanup($DB) >= 1, 'a one-year limit removes older entries');
    assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM audit_log WHERE company_id = $C AND action = 'old_thing'")->fetchColumn(), 'the old entry is gone');
    assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM audit_log WHERE company_id = $C AND action = 'recent_thing'")->fetchColumn(), 'recent entries stay');
    assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM audit_log WHERE company_id = $C AND action = 'audit_pruned'")->fetchColumn(), 'the pruning itself is recorded');
    $last = $DB->query("SELECT details FROM audit_log WHERE company_id = $C AND action = 'settings_updated' ORDER BY id DESC LIMIT 1")->fetchColumn();
    assert_true(str_contains((string)$last, 'Keep the audit trail for') && str_contains((string)$last, 'Forever → 1 year'), 'settings changes are logged in words');

    assert_eq(['Signed in', 'success'], AdminKit::actionLabel('user_login'), 'known actions read as words');
    assert_eq(['Crm account update', 'muted'], AdminKit::actionLabel('crm_account_update'), 'unknown actions are turned into words');
    assert_eq('email: a@b.co · role: viewer', AdminKit::detailText('{"email":"a@b.co","role":"viewer"}'), 'JSON details read as pairs');
    [$w, $params] = AdminAudit::where($C, AdminAudit::filters(['kind' => 'signin', 'q' => '50%_off', 'from' => '2026-01-01', 'to' => 'nope', 'user_id' => '1']));
    assert_true(str_contains($w, 'al.user_id = ?') && str_contains($w, 'al.action LIKE ?') && !str_contains($w, '<= ?'), 'filters build the right clauses (bad dates ignored)');
    assert_true(in_array('%50\\%\\_off%', $params, true), 'search words are escaped for LIKE');
} finally {
    // Leave the shared test company as it was.
    $DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key IN ('" . implode("','", $keys) . "')")->execute([$C]);
    $ins = $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?)");
    foreach ($saved as $k => $v) $ins->execute([$C, $k, $v]);
    if ($savedPayroll) {
        $DB->prepare("UPDATE payroll_settings SET default_frequency = ?, monthly_anchor_day = ?, auto_post_to_finance = ? WHERE company_id = ?")
           ->execute([$savedPayroll['default_frequency'], $savedPayroll['monthly_anchor_day'], $savedPayroll['auto_post_to_finance'], $C]);
    } else {
        $DB->exec("DELETE FROM payroll_settings WHERE company_id = $C");
    }
    if ($savedAi) {
        $DB->prepare("UPDATE ai_settings SET default_radius_km = ?, min_score_threshold = ? WHERE company_id = ?")->execute([$savedAi['default_radius_km'], $savedAi['min_score_threshold'], $C]);
    } else {
        $DB->exec("DELETE FROM ai_settings WHERE company_id = $C");
    }
    $DB->prepare("UPDATE companies SET yoco_mode = ?, yoco_sec_key = ?, yoco_webhook_secret = ? WHERE id = ?")
       ->execute([$savedYoco['yoco_mode'], $savedYoco['yoco_sec_key'], $savedYoco['yoco_webhook_secret'], $C]);
    $DB->exec("DELETE FROM audit_log WHERE company_id = $C AND action IN ('old_thing', 'recent_thing', 'audit_pruned', 'settings_updated', 'finance_settings_saved')");
}

echo "Admin: " . $GLOBALS['__assert_count'] . " assertions passed\n";
