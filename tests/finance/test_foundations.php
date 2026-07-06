<?php
/** WS1: audit trail writes, AsOf tenant isolation, period lock/unlock. */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/Audit.php';
require_once FLOWWORK_ROOT . '/finances/lib/AsOf.php';
require_once FLOWWORK_ROOT . '/finances/lib/PeriodService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

// --- Audit trail actually writes rows with the real schema -----------------
Audit::setPdo($DB);
Audit::log('test_event', ['foo' => 'bar'], 'journal', 42);
$row = $DB->query("SELECT company_id, user_id, action, entity_type, entity_id, details
                   FROM audit_log WHERE action = 'test_event'")->fetch();
assert_true((bool)$row, 'Audit::log wrote a row');
assert_eq(1, $row['company_id'], 'audit company from session');
assert_eq('journal', $row['entity_type'], 'audit entity_type stored');
assert_eq(42, $row['entity_id'], 'audit entity_id stored');
assert_eq('bar', json_decode($row['details'], true)['foo'] ?? null, 'audit details JSON');

// --- AsOf trial balance is tenant-isolated ---------------------------------
// A second company posting to the SAME account code must not leak into
// company 1's trial balance (journal_lines has no company_id; the old query
// summed other tenants' lines).
$cid2 = (int)($DB->query("SELECT id FROM companies WHERE name = 'Tenant B'")->fetchColumn() ?: 0);
if (!$cid2) {
    $DB->exec("INSERT INTO companies (name, plan_id, created_at) VALUES ('Tenant B', 1, NOW())");
    $cid2 = (int)$DB->lastInsertId();
}

function post_manual_journal(PDO $db, int $companyId, string $code, float $amount): int
{
    $db->prepare("INSERT INTO journal_entries
        (company_id, entry_date, reference, description, status, created_by, created_at, posted_by, posted_at)
        VALUES (?, CURDATE(), 'TB-TEST', 'tb test', 'posted', 1, NOW(), 1, NOW())")
       ->execute([$companyId]);
    $jid = (int)$db->lastInsertId();
    $ins = $db->prepare("INSERT INTO journal_lines (journal_id, account_code, debit, credit) VALUES (?,?,?,?)");
    $ins->execute([$jid, $code, $amount, 0]);
    $ins->execute([$jid, '3100', 0, $amount]);
    return $jid;
}

post_manual_journal($DB, TEST_COMPANY_ID, '1020', 100.00);
post_manual_journal($DB, $cid2, '1020', 999.00); // other tenant, same code

$asof = new AsOf($DB, TEST_COMPANY_ID);
$tb = $asof->trialBalance(date('Y-m-d'));
$bank = null;
foreach ($tb['rows'] as $r) {
    if ($r['code'] === '1020') { $bank = $r; break; }
}
assert_true($bank !== null, 'TB includes account 1020');
assert_cents(100.00, $bank['debit'], 'TB excludes other tenant postings');
assert_cents($tb['total_debit'], $tb['total_credit'], 'TB balances');

// --- Period lock: soft-deleted locks no longer lock -------------------------
$DB->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active)
              VALUES (?, ?, 'test', 1, NOW(), 1)")
   ->execute([TEST_COMPANY_ID, date('Y-m-d')]);
$lockId = (int)$DB->lastInsertId();
$ps = new PeriodService($DB, TEST_COMPANY_ID);
assert_true($ps->isLocked(date('Y-m-d')), 'active lock blocks the period');
assert_true(!$ps->isLocked(date('Y-m-d', strtotime('+1 day'))), 'dates after the lock are open');
$DB->exec("UPDATE gl_period_locks SET is_active = 0, deleted_at = NOW() WHERE lock_id = $lockId");
assert_true(!$ps->isLocked(date('Y-m-d')), 'soft-deleted lock releases the period');

// --- AccountsMap::code() resolves via settings then DEFAULTS ----------------
$map = new AccountsMap($DB, TEST_COMPANY_ID);
assert_eq('2120', $map->code('finance_vat_output_account_id'), 'VAT output resolves from seeded settings (company 1 chart)');
assert_eq('2101', $map->code('finance_vat_control_account_id'), 'VAT control resolves to the ensured account');

test_done('foundations');
