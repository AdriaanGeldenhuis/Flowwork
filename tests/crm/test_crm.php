<?php
/**
 * CRM end to end through the libraries the pages and the API use: accounts
 * (rules, duplicates, names, owners, tags, contacts, addresses, delete /
 * restore / purge, bulk), lists (filters, chips, sorting, money from
 * invoices, health), custom fields, deals (stages, probability, moves,
 * permissions, custom stages, conversion guard), tasks (due, reminders,
 * counts, permissions), activity and the timeline, compliance (states,
 * blocking, documents), duplicates (scan, merge repoints the books),
 * import / export, reminders from the background tick, overview and boot.
 */
require __DIR__ . '/../lib/bootstrap.php';
foreach (['Crm', 'CrmSchema', 'CrmAccounts', 'CrmDeals', 'CrmTasks', 'CrmActivity', 'CrmCompliance', 'CrmDedupe', 'CrmTransfer', 'CrmNotify', 'CrmOverview', 'CrmBoot', 'CrmFields', 'CrmLinked'] as $lib) {
    require_once FLOWWORK_ROOT . '/crm/lib/' . $lib . '.php';
}

CrmSchema::reset();
CrmSchema::ensure($DB);
$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;      // admin
$M = 3;                 // a member in the same company
$tag = bin2hex(random_bytes(3));
$throws = function (callable $fn, string $message, string $class = CrmUserError::class) {
    try {
        $fn();
    } catch (Throwable $e) {
        assert_true($e instanceof $class, "$message (got " . get_class($e) . ': ' . $e->getMessage() . ')');
        return $e;
    }
    fail("ASSERT FAILED: $message (no error raised)");
};
$DB->exec("DELETE FROM crm_prefs WHERE company_id = $C");
$DB->exec("DELETE FROM crm_pipeline_stages WHERE company_id = $C");
$DB->exec("DELETE FROM crm_custom_values WHERE company_id = $C");
$DB->exec("DELETE FROM crm_custom_fields WHERE company_id = $C");
$DB->exec("DELETE FROM company_settings WHERE company_id = $C AND setting_key LIKE 'crm\\_%'");
Crm::flush();
CrmFields::flush();

// ------------------------------------------------------------------ schema
$cols = CrmSchema::columns($DB, 'crm_opportunities');
assert_true(isset($cols['sort_order'], $cols['contact_id'], $cols['notes']), 'deal columns provisioned');
$st = $DB->query("SHOW COLUMNS FROM crm_opportunities LIKE 'stage'")->fetch(PDO::FETCH_ASSOC);
assert_true(str_starts_with(strtolower($st['Type']), 'varchar'), 'deal stage is free text');
$st = $DB->query("SHOW COLUMNS FROM crm_interactions LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
assert_true(str_contains($st['Type'], 'whatsapp'), 'activity knows WhatsApp');
foreach (['crm_tasks', 'crm_pipeline_stages', 'crm_custom_fields', 'crm_custom_values', 'crm_prefs', 'crm_notify_log'] as $t) {
    assert_true((bool)CrmSchema::columns($DB, $t), "$t exists");
}

// ------------------------------------------------------------------ config
$cfg = Crm::config($DB, $C);
assert_eq('Customers', $cfg['labels']['customers'], 'default labels');
assert_eq(6, count(Crm::stages($DB, $C)), 'built-in stages');
Crm::writePrefs($DB, $C, 0, ['labels' => ['customers' => 'Clients'], 'accent' => '#123456', 'overview_off' => ['top'], 'cold_days' => 3]);
Crm::flush();
$cfg = Crm::config($DB, $C);
assert_eq('Clients', $cfg['labels']['customers'], 'company label override');
assert_eq('#123456', $cfg['accent'], 'company accent');
assert_eq(7, $cfg['cold_days'], 'cold days clamped to at least 7');
assert_eq(['top'], $cfg['overview_off'], 'overview panel switched off');
Crm::writePrefs($DB, $C, $U, ['density' => 'compact', 'columns' => ['customers' => ['phone', 'bogus', 'cf_9']]]);
$p = Crm::userPrefs($DB, $C, $U);
assert_eq('compact', $p['density'], 'user density');
assert_eq(['phone', 'cf_9'], $p['columns']['customers'], 'unknown list columns dropped');
assert_eq('+27825550101', Crm::phone('082 555 0101'), 'SA mobile to international');
assert_eq('0825550101', Crm::phone('+27 82 555 0101', 'national'), 'international to national');
assert_eq('+4420700000', Crm::phone('0044 20700000'), '00 prefix becomes +');

// ---------------------------------------------------------------- accounts
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => '']), 'name is required');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Bad $tag", 'email' => 'nope']), 'email is validated');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'x', 'name' => "Bad $tag"]), 'type is validated');
Crm::writePrefs($DB, $C, 0, ['required' => ['email', 'contact']]);
Crm::flush();
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Req $tag"]), 'required email enforced');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Req $tag", 'email' => 'a@b.co']), 'required contact enforced');
Crm::writePrefs($DB, $C, 0, ['required' => []]);
Crm::flush();

$res = CrmAccounts::save($DB, $C, $M, 'member', [
    'type' => 'customer', 'name' => "Acme Engineering $tag", 'email' => 'Accounts@Acme.example', 'phone' => '011 555 0100',
    'website' => 'acme.example', 'vat_no' => '4 123 456 789', 'tags' => ['Key account', 'Gauteng'],
    'contact' => ['first_name' => 'Thandi', 'last_name' => 'Mokoena', 'email' => 'thandi@acme.example', 'mobile' => '082 555 0101'],
    'address' => ['line1' => '12 Main Road', 'city' => 'Johannesburg', 'postal_code' => '2001'], 'confirm_duplicate' => 1,
]);
$acme = $res['id'];
assert_true($res['created'] && $acme > 0, 'customer created');
$row = CrmAccounts::row($DB, $C, $acme);
assert_eq('accounts@acme.example', $row['email'], 'email lower-cased');
assert_eq('+27115550100', $row['phone'], 'phone normalised');
assert_eq('https://acme.example', $row['website'], 'website gets a scheme');
assert_eq('4123456789', $row['vat_no'], 'VAT spaces removed');
assert_eq($M, (int)$row['owner_id'], 'creator owns a new account');
assert_eq('active', $row['status'], 'default status');
$full = CrmAccounts::full($DB, $C, $U, 'admin', $acme);
assert_eq(1, count($full['contact_list']), 'first contact saved');
assert_true($full['contact_list'][0]['primary'], 'first contact is primary');
assert_eq('+27825550101', $full['contact_list'][0]['mobile'], 'contact mobile normalised');
assert_eq(1, count($full['addresses']), 'address saved');
assert_eq(2, count($full['tags']), 'tags added');

// duplicate warnings and unique names
$dup = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Acme Engineering (Pty) Ltd $tag", 'email' => 'accounts@acme.example']);
$hit = array_values(array_filter($dup['duplicates'] ?? [], fn($d) => $d['id'] === $acme));
assert_true(count($hit) === 1, 'likely duplicate reported before creating');
assert_true(in_array('same email', $hit[0]['why'], true), 'duplicate reason given');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Acme Engineering $tag", 'confirm_duplicate' => 1]), 'same name refused');
$supplierSameName = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'supplier', 'name' => "Acme Engineering $tag", 'confirm_duplicate' => 1]);
assert_true($supplierSameName['id'] > 0, 'a supplier may share a customer name');

// owners: a member cannot hand someone else's account away
$throws(fn() => CrmAccounts::save($DB, $C, 4, 'member', ['id' => $acme, 'owner_id' => 4]), 'non-owner member cannot take an owned account', CrmForbidden::class);
CrmAccounts::save($DB, $C, $M, 'member', ['id' => $acme, 'owner_id' => 4]);
assert_eq(4, (int)CrmAccounts::row($DB, $C, $acme)['owner_id'], 'owner can hand the account over');
CrmAccounts::save($DB, $C, $U, 'admin', ['id' => $acme, 'owner_id' => $M]);
assert_eq($M, (int)CrmAccounts::row($DB, $C, $acme)['owner_id'], 'admin can reassign');
assert_true((int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = 4 AND type = 'crm_account_owner'")->fetchColumn() >= 1, 'new owner notified');

// contacts
$c2 = CrmAccounts::saveContact($DB, $C, $U, ['account_id' => $acme, 'first_name' => 'Sipho', 'email' => 'sipho@acme.example', 'primary' => 1]);
$prim = $DB->query("SELECT id FROM crm_contacts WHERE account_id = $acme AND is_primary = 1")->fetchAll(PDO::FETCH_COLUMN);
assert_eq([$c2], array_map('intval', $prim), 'only one primary contact');
$throws(fn() => CrmAccounts::saveContact($DB, $C, $U, ['account_id' => $acme]), 'contact needs a name or email');
CrmAccounts::deleteContact($DB, $C, $U, $c2);
$prim = $DB->query("SELECT COUNT(*) FROM crm_contacts WHERE account_id = $acme AND is_primary = 1")->fetchColumn();
assert_eq(1, (int)$prim, 'deleting the primary promotes another');

// tags
$t = CrmAccounts::addTag($DB, $C, $acme, '  Key   account ', null);
assert_eq('Key account', $t['name'], 'tag names trimmed and matched');
assert_eq(2, count(CrmAccounts::tagsFor($DB, [$acme])[$acme]), 'tag not duplicated');
CrmAccounts::removeTag($DB, $C, $acme, $t['id']);
assert_eq(1, count(CrmAccounts::tagsFor($DB, [$acme])[$acme]), 'tag removed');

// ------------------------------------------------------------------ lists
$beta = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Beta Bakery $tag", 'status' => 'prospect', 'confirm_duplicate' => 1])['id'];
$inv = make_invoice($DB, $acme, [[10, 1000, 15]], ['status' => 'sent', 'due_date' => date('Y-m-d', strtotime('-5 days'))]);
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'q' => $tag]);
assert_eq(2, $list['total'], 'search finds both customers');
assert_eq(1, $list['counts']['prospect'], 'chip counts');
$a = array_values(array_filter($list['rows'], fn($r) => $r['id'] === $acme))[0];
assert_cents(11500, $a['money']['balance'], 'balance from open invoice');
assert_cents(11500, $a['money']['overdue'], 'overdue from past due date');
assert_eq('Needs attention', $a['health']['label'] === 'Healthy' ? 'x' : 'Needs attention', 'overdue invoices lower health');
assert_eq('Thandi Mokoena', $a['contact']['name'], 'primary contact on the row');
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'q' => $tag, 'sort' => 'balance', 'dir' => 'desc']);
assert_eq($acme, $list['rows'][0]['id'], 'sort by balance');
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'q' => 'Thandi']);
assert_true(in_array($acme, array_column($list['rows'], 'id'), true), 'search by contact name');
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'q' => '0115550100']);
assert_true(in_array($acme, array_column($list['rows'], 'id'), true), 'search by phone digits');
$gTag = (int)$DB->query("SELECT id FROM crm_tags WHERE company_id = $C AND name = 'Gauteng'")->fetchColumn();
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'tag' => $gTag, 'q' => $tag]);
assert_eq(1, $list['total'], 'filter by tag');

// delete / restore / purge
CrmAccounts::setDeleted($DB, $C, $U, [$beta], true);
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'q' => $tag]);
assert_eq(1, $list['total'], 'deleted accounts hidden');
assert_eq(1, $list['counts']['deleted'], 'deleted chip');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Beta Bakery $tag", 'confirm_duplicate' => 1]), 'name of a deleted account explained');
CrmAccounts::setDeleted($DB, $C, $U, [$beta], false);
assert_true(CrmAccounts::row($DB, $C, $beta)['deleted_at'] === null, 'restored');
$throws(fn() => CrmAccounts::purge($DB, $C, $U, $beta), 'only deleted accounts can be purged');
CrmAccounts::setDeleted($DB, $C, $U, [$acme], true);
$throws(fn() => CrmAccounts::purge($DB, $C, $U, $acme), 'accounts with invoices are kept');
CrmAccounts::setDeleted($DB, $C, $U, [$acme], false);
$gone = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Gone Ltd $tag", 'contact' => ['first_name' => 'X'], 'confirm_duplicate' => 1])['id'];
CrmAccounts::setDeleted($DB, $C, $U, [$gone], true);
CrmAccounts::purge($DB, $C, $U, $gone);
assert_true(CrmAccounts::row($DB, $C, $gone) === null, 'purged');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM crm_contacts WHERE account_id = $gone")->fetchColumn(), 'purge removes contacts');

// bulk
assert_eq(2, CrmAccounts::bulk($DB, $C, $U, 'admin', [$acme, $beta], 'status', 'inactive'), 'bulk status');
$throws(fn() => CrmAccounts::bulk($DB, $C, $M, 'member', [$acme], 'owner', $U), 'bulk owner needs admin', CrmForbidden::class);
CrmAccounts::bulk($DB, $C, $U, 'admin', [$acme, $beta], 'tag_add', 'Bulk');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id WHERE t.name = 'Bulk' AND at.account_id IN ($acme, $beta)")->fetchColumn(), 'bulk tag');
CrmAccounts::bulk($DB, $C, $U, 'admin', [$acme, $beta], 'status', 'active');

// ----------------------------------------------------------- custom fields
$fSize = CrmFields::saveDef($DB, $C, ['entity' => 'account', 'applies_to' => 'customer', 'label' => 'Company size', 'type' => 'select', 'options' => "Small\nMedium\nLarge", 'show_in_list' => 1]);
$fSince = CrmFields::saveDef($DB, $C, ['entity' => 'account', 'label' => 'Client since', 'type' => 'date']);
$fSpend = CrmFields::saveDef($DB, $C, ['entity' => 'account', 'label' => 'Annual spend', 'type' => 'money', 'required' => 1]);
CrmFields::flush();
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Field Co $tag", 'confirm_duplicate' => 1]), 'required custom field on create');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['id' => $acme, 'custom' => [$fSize => 'Huge']]), 'select choices enforced');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['id' => $acme, 'custom' => [$fSince => 'not a date']]), 'dates validated');
CrmAccounts::save($DB, $C, $U, 'admin', ['id' => $acme, 'custom' => [$fSize => 'Large', $fSince => '2019-03-01', $fSpend => 'R 1 250 000,00']]);
$vals = CrmFields::valuesFor($DB, $C, CrmFields::forAccountType($DB, $C, 'customer'), $acme);
assert_eq('Large', $vals[$fSize], 'select stored');
assert_eq('2019-03-01', $vals[$fSince], 'date stored');
assert_eq('125000000', str_replace('.', '', $vals[$fSpend]), 'money stored as a number');
$throws(fn() => CrmAccounts::save($DB, $C, $U, 'admin', ['id' => $acme, 'custom' => [$fSpend => '']]), 'required custom field cannot be cleared');
$list = CrmAccounts::list($DB, $C, $U, ['type' => 'customer', 'q' => "Acme Engineering $tag"]);
assert_eq('Large', $list['rows'][0]['custom']['cf_' . $fSize], 'custom field in the list');
assert_eq(0, count(CrmFields::forAccountType($DB, $C, 'supplier')) - 2, 'customer-only field not on suppliers');
$throws(fn() => CrmFields::saveDef($DB, $C, ['id' => $fSince, 'label' => 'Client since', 'type' => 'number']), 'type change refused once values exist');
CrmFields::removeDef($DB, $C, $fSpend, true);
CrmFields::flush();

// ------------------------------------------------------------------- deals
$deal = CrmDeals::save($DB, $C, $M, 'member', ['account_id' => $acme, 'title' => 'Paving contract', 'amount' => '250 000']);
assert_eq('prospect', $deal['stage'], 'new deals start in the first stage');
assert_eq(10.0, $deal['probability'], 'probability from the stage');
assert_eq($M, $deal['owner_id'], 'creator owns the deal');
$throws(fn() => CrmDeals::save($DB, $C, 4, 'member', ['id' => $deal['id'], 'amount' => 1]), 'other members cannot change a deal', CrmForbidden::class);
$deal2 = CrmDeals::save($DB, $C, $U, 'admin', ['account_id' => $beta, 'title' => 'Bread supply', 'amount' => 1000, 'stage' => 'proposal']);
assert_eq(50.0, $deal2['probability'], 'stage default probability');
$moved = CrmDeals::move($DB, $C, $M, 'member', $deal['id'], 'proposal', [$deal['id'], $deal2['id']]);
assert_eq('proposal', $moved['stage'], 'moved on the board');
assert_eq(50.0, $moved['probability'], 'probability follows the stage');
assert_true($moved['stage_changed_at'] !== null, 'stage change stamped');
$order = $DB->query("SELECT id FROM crm_opportunities WHERE company_id = $C AND stage = 'proposal' ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
assert_eq([$deal['id'], $deal2['id']], array_map('intval', array_slice($order, 0, 2)), 'card order saved');
$lost = CrmDeals::move($DB, $C, $U, 'admin', $deal2['id'], 'lost', [], 'Price');
assert_eq('Price', $lost['loss_reason'], 'loss reason kept');
$back = CrmDeals::save($DB, $C, $U, 'admin', ['id' => $deal2['id'], 'stage' => 'proposal']);
assert_eq('', $back['loss_reason'], 'reopening clears the loss reason');
$throws(fn() => CrmDeals::save($DB, $C, $U, 'admin', ['id' => $deal['id'], 'stage' => 'nope']), 'unknown stage refused');
$throws(fn() => CrmDeals::save($DB, $C, $U, 'admin', ['id' => $deal['id'], 'probability' => 120]), 'probability range');
$throws(fn() => CrmDeals::convert($DB, $C, $U, 'admin', $deal['id'], ['template' => 'blank']), 'only won deals convert');
$hist = CrmDeals::history($DB, $C, $deal['id']);
assert_true(count($hist) >= 2 && str_contains($hist[0]['text'], 'Proposal'), 'deal history from the audit log');

// custom stages: remove one, its deals move to the first open stage
$stages = CrmDeals::saveStages($DB, $C, $U, [
    ['key' => 'lead', 'label' => 'New lead', 'color' => '#112233', 'probability' => 5],
    ['label' => 'Site visit', 'color' => '#445566', 'probability' => 30],
    ['key' => 'won', 'label' => 'Signed', 'kind' => 'won'],
    ['key' => 'lost', 'label' => 'Lost', 'kind' => 'lost'],
]);
assert_eq(['lead', 'site_visit', 'won', 'lost'], array_column($stages['stages'], 'key'), 'custom stages saved, terminal keys fixed');
assert_eq('lead', CrmDeals::get($DB, $C, $deal['id'])['stage'], 'deals on removed stages moved to the first');
$board = CrmDeals::list($DB, $C, $U, []);
assert_eq('Signed', Crm::stageMap($DB, $C)['won']['label'], 'won stage renamed');
assert_true(isset($board['totals']['lead']), 'board totals per stage');
$won = CrmDeals::move($DB, $C, $M, 'member', $deal['id'], 'won', []);
assert_eq(100.0, $won['probability'], 'won is 100%');
$throws(fn() => CrmDeals::saveStages($DB, $C, $U, [['key' => 'won', 'label' => 'Won', 'kind' => 'won']]), 'at least one open stage');

// ------------------------------------------------------------------- tasks
$task = CrmTasks::save($DB, $C, $U, 'admin', ['title' => 'Call Thandi', 'account_id' => $acme, 'due_date' => date('Y-m-d'), 'due_time' => '23:59',
                                              'assigned_to' => $M, 'remind' => '15m', 'priority' => 'high']);
assert_eq('today', $task['state'], 'due today');
assert_eq('15m', $task['remind'], 'reminder kept');
assert_eq($M, $task['assigned_to'], 'assigned');
assert_true((int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = $M AND type = 'crm_task_assigned'")->fetchColumn() >= 1, 'assignee notified');
$old = CrmTasks::save($DB, $C, $M, 'member', ['title' => 'Overdue thing', 'due_date' => date('Y-m-d', strtotime('-3 days'))]);
assert_eq('overdue', $old['state'], 'overdue');
$mine = CrmTasks::list($DB, $C, $M, ['scope' => 'mine', 'when' => 'open']);
assert_true($mine['counts']['overdue'] >= 1 && $mine['counts']['today'] >= 1, 'task counts');
$throws(fn() => CrmTasks::setDone($DB, $C, 4, 'member', $task['id'], true), 'strangers cannot tick tasks', CrmForbidden::class);
$done = CrmTasks::setDone($DB, $C, $M, 'member', $task['id'], true);
assert_true($done['done'], 'ticked');
assert_eq('done', CrmTasks::setDone($DB, $C, $M, 'member', $task['id'], true)['state'], 'state done');
CrmTasks::setDone($DB, $C, $M, 'member', $task['id'], false);
$throws(fn() => CrmTasks::save($DB, $C, $U, 'admin', ['title' => '']), 'task needs a title');
$throws(fn() => CrmTasks::save($DB, $C, $U, 'admin', ['title' => 'x', 'due_date' => '2026-02-31']), 'task date validated');

// reminders from the tick
$DB->prepare("UPDATE crm_tasks SET remind_at = NOW() - INTERVAL 1 MINUTE, reminded_at = NULL WHERE id = ?")->execute([$task['id']]);
$before = (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = $M AND type = 'crm_task_due'")->fetchColumn();
$r = CrmNotify::tasks($DB, microtime(true) + 10);
assert_true($r >= 1, 'task reminder sent');
assert_eq($before + 1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = $M AND type = 'crm_task_due'")->fetchColumn(), 'one reminder');
CrmNotify::tasks($DB, microtime(true) + 10);
assert_eq($before + 1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = $M AND type = 'crm_task_due'")->fetchColumn(), 'reminders are sent once');

// -------------------------------------------------------------- activity
$act = CrmActivity::save($DB, $C, $U, 'admin', ['account_id' => $acme, 'type' => 'call', 'subject' => 'Discussed paving', 'body' => 'Wants a quote by Friday',
                                                'opportunity_id' => $deal['id'], 'followup' => ['title' => 'Send quote', 'due_date' => date('Y-m-d', strtotime('+2 days'))]]);
assert_eq('call', $act['activity']['type'], 'call logged');
assert_eq($deal['id'], $act['activity']['deal']['id'], 'linked to the deal');
assert_true($act['task'] && $act['task']['account']['id'] === $acme, 'follow-up task created with the call');
$throws(fn() => CrmActivity::save($DB, $C, $U, 'admin', ['account_id' => $acme, 'type' => 'note']), 'empty activity refused');
$throws(fn() => CrmActivity::save($DB, $C, $U, 'admin', ['account_id' => $acme, 'type' => 'note', 'body' => 'x', 'at' => date('Y-m-d H:i', strtotime('+2 days'))]), 'future activity refused');
$throws(fn() => CrmActivity::save($DB, $C, 4, 'member', ['id' => $act['activity']['id'], 'body' => 'hijack']), 'only the author edits activity', CrmForbidden::class);
Crm::writePrefs($DB, $C, 0, ['activity' => ['whatsapp' => false]]);
Crm::flush();
$throws(fn() => CrmActivity::save($DB, $C, $U, 'admin', ['account_id' => $acme, 'type' => 'whatsapp', 'body' => 'hi']), 'switched-off activity type refused');
Crm::writePrefs($DB, $C, 0, ['activity' => null]);
Crm::flush();
$note = CrmActivity::save($DB, $C, $U, 'admin', ['account_id' => $acme, 'type' => 'note', 'body' => 'Gate code 1234', 'pinned' => 1])['activity'];
$row = CrmAccounts::row($DB, $C, $acme);
$row['created_by_name'] = 'Test';
$tl = CrmActivity::timeline($DB, $C, $U, $row, []);
assert_true(count($tl['pinned']) === 1 && $tl['pinned'][0]['id'] === $note['id'], 'pinned note on top');
$kinds = array_unique(array_column($tl['items'], 'kind'));
foreach (['interaction', 'deal', 'doc', 'created'] as $k) assert_true(in_array($k, $kinds, true), "timeline has $k items");
$only = CrmActivity::timeline($DB, $C, $U, $row, ['kind' => 'calls']);
assert_true(count($only['items']) >= 1 && !array_filter($only['items'], fn($i) => $i['kind'] !== 'interaction' || !in_array($i['type'], ['call', 'whatsapp'], true)), 'timeline filter');
$feed = CrmActivity::feed($DB, $C, $U, ['type' => 'call']);
assert_true(count($feed['rows']) >= 1, 'company activity feed');

// ------------------------------------------------------------- compliance
$sup = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'supplier', 'name' => "Steel Supplies $tag", 'vat_no' => '4999999999', 'confirm_duplicate' => 1])['id'];
$DB->exec("DELETE FROM crm_compliance_docs WHERE company_id = $C AND account_id = $sup");
$DB->exec("UPDATE crm_compliance_types SET required = 0 WHERE company_id = $C"); // only this test's type is required
$taxType = CrmCompliance::saveType($DB, $C, ['name' => "Tax clearance $tag", 'required' => 1, 'expiry_months' => 12]);
$s = CrmCompliance::state($DB, $C, $sup, 'supplier');
assert_eq('missing', $s['status'], 'required document missing');
assert_true(!$s['blocking'], 'not blocking while the policy is off');
Crm::setRule($DB, $C, 'crm_block_expired_suppliers', '1');
assert_true(CrmCompliance::state($DB, $C, $sup, 'supplier')['blocking'], 'blocking when the policy is on');
$doc = CrmCompliance::saveDoc($DB, $C, $U, ['account_id' => $sup, 'type_id' => $taxType, 'reference' => 'PIN-1', 'issue_date' => date('Y-m-d', strtotime('-400 days')), 'auto_expiry' => 1], null);
assert_eq('expired', CrmCompliance::state($DB, $C, $sup, 'supplier')['status'], 'expiry from the type default: expired');
CrmCompliance::saveDoc($DB, $C, $U, ['id' => $doc, 'account_id' => $sup, 'type_id' => $taxType, 'expiry_date' => date('Y-m-d', strtotime('+10 days'))], null);
assert_eq('expiring', CrmCompliance::state($DB, $C, $sup, 'supplier')['status'], 'expiring within the reminder window');
assert_eq('expiring', $DB->query("SELECT status FROM crm_compliance_docs WHERE id = $doc")->fetchColumn(), 'stored status kept in step (Receipts reads it)');
$throws(fn() => CrmCompliance::saveDoc($DB, $C, $U, ['account_id' => $sup, 'type_id' => $taxType, 'issue_date' => '2026-05-01', 'expiry_date' => '2026-01-01'], null), 'expiry before issue refused');
$throws(fn() => CrmCompliance::deleteType($DB, $C, $taxType), 'types in use cannot be deleted');
assert_true(CrmCompliance::absPath($C, '../../etc/passwd') === null, 'path traversal refused');
// compliance reminders: 10 days left → the 14-day step, once
$DB->exec("DELETE FROM crm_notify_log WHERE company_id = $C");
$DB->prepare("UPDATE crm_accounts SET owner_id = ? WHERE id = ?")->execute([4, $sup]);
$n0 = (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = 4 AND type = 'crm_compliance'")->fetchColumn();
CrmNotify::compliance($DB, microtime(true) + 10);
CrmNotify::compliance($DB, microtime(true) + 10);
assert_eq($n0 + 1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE user_id = 4 AND type = 'crm_compliance'")->fetchColumn(), 'compliance reminder sent once');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM crm_notify_log WHERE company_id = $C AND ref_id = $doc AND mark = 'd30'")->fetchColumn(), 'larger steps marked too');
CrmCompliance::deleteDoc($DB, $C, $U, $doc);
Crm::setRule($DB, $C, 'crm_block_expired_suppliers', '0');
$DB->prepare("UPDATE crm_compliance_types SET required = 0 WHERE id = ?")->execute([$taxType]);

// ----------------------------------------------------------- duplicates
$twin = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'supplier', 'name' => "Steel Supplies CC $tag", 'vat_no' => '4999999999', 'email' => 'sales@steel.example', 'confirm_duplicate' => 1])['id'];
CrmAccounts::saveContact($DB, $C, $U, ['account_id' => $twin, 'first_name' => 'Pieter', 'email' => 'pieter@steel.example']);
$billId = 0;
try {
    $DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, vendor_invoice_number, issue_date, due_date, total, status, created_by, created_at) VALUES (?, ?, 'B-1', CURDATE(), CURDATE(), 500, 'draft', ?, NOW())")
       ->execute([$C, $twin, $U]);
    $billId = (int)$DB->lastInsertId();
} catch (Throwable $e) { }
$scan = CrmDedupe::scan($DB, $C, 'supplier');
assert_true($scan['found'] >= 1, 'scan finds the twin');
// The open candidates for one pair, read directly: candidates() lists the top 500 by score, and a
// test database that has seen many runs holds more than that, so a pair could fall off its end.
$pairOf = function (int $a, int $b) use ($DB, $C): array {
    $st = $DB->prepare("SELECT id, reason FROM crm_merge_candidates WHERE company_id = ? AND resolved = 0
                         AND ((left_id = ? AND right_id = ?) OR (left_id = ? AND right_id = ?))");
    $st->execute([$C, $a, $b, $b, $a]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'why' => json_decode((string)$r['reason'], true) ?: []], $st->fetchAll(PDO::FETCH_ASSOC));
};
$cand = $pairOf($sup, $twin);
$list = CrmDedupe::candidates($DB, $C);
assert_true($list && count($list) <= 500 && isset($list[0]['left']['id'], $list[0]['right']['id'], $list[0]['why'], $list[0]['score']), 'the list shows both accounts side by side, best matches first');
assert_true(count($cand) === 1 && in_array('Same VAT number', $cand[0]['why'], true), 'candidate with reason');
$throws(fn() => CrmDedupe::merge($DB, $C, $U, $sup, $acme, []), 'customer and supplier never merge');
CrmDedupe::merge($DB, $C, $U, $sup, $twin, ['email' => 'other'], $cand[0]['id']);
assert_true(CrmAccounts::row($DB, $C, $twin)['deleted_at'] !== null, 'loser soft-deleted');
assert_eq('sales@steel.example', CrmAccounts::row($DB, $C, $sup)['email'], 'picked field taken from the other account');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM crm_contacts WHERE account_id = $sup AND first_name = 'Pieter'")->fetchColumn(), 'contacts moved');
if ($billId) assert_eq($sup, (int)$DB->query("SELECT supplier_id FROM ap_bills WHERE id = $billId")->fetchColumn(), 'supplier bills repointed');
assert_eq([], $pairOf($sup, $twin), 'candidate resolved');
$twin2 = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'supplier', 'name' => "Steel Supplies CC $tag", 'confirm_duplicate' => 1])['id'];
CrmDedupe::merge($DB, $C, $U, $sup, $twin2, []);
assert_true(CrmAccounts::row($DB, $C, $twin2)['deleted_at'] !== null, 'a second account with the same name merges into the same one');
$e1 = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Email Twin A $tag", 'email' => "same-$tag@twin.example", 'confirm_duplicate' => 1])['id'];
$e2 = CrmAccounts::save($DB, $C, $U, 'admin', ['type' => 'customer', 'name' => "Totally Other $tag", 'email' => "same-$tag@twin.example", 'confirm_duplicate' => 1])['id'];
CrmDedupe::scan($DB, $C, 'customer');
assert_eq(1, count($pairOf($e1, $e2)), 'the same email alone is worth a look');

// -------------------------------------------------------- import / export
$csv = "Company,Email,Phone,Contact,Contact email,City,Tags,Status,Company size\n"
     . "\"Gamma Gardens $tag\",info-$tag@gamma.example,021 555 0000,Lerato Dube,lerato@gamma.example,Cape Town,\"Garden, Cape\",Prospect,Small\n"
     . "\"Acme Engineering $tag\",accounts@acme.example,,,,,,Active,\n"
     . "\"Delta $tag\",not-an-email,,,,,,,\n"
     . "\"Echo $tag\",,,,,,,Lead,Huge\n";
$parsed = CrmTransfer::parseText($DB, $C, $U, $csv, 'test.csv');
assert_eq(4, $parsed['rows'], 'rows parsed');
assert_eq('name', $parsed['mapping'][0], 'name column guessed');
assert_eq('contact_email', $parsed['mapping'][4], 'contact email guessed');
assert_eq('cf_' . $fSize, $parsed['mapping'][8], 'custom field column guessed');
$dry = CrmTransfer::run($DB, $C, $U, 'admin', ['token' => $parsed['token'], 'type' => 'customer', 'mapping' => $parsed['mapping'], 'existing' => 'skip', 'dry' => 1]);
assert_eq(1, $dry['counts']['created'], 'dry run: one to create');
assert_eq(1, $dry['counts']['skipped'], 'dry run: existing skipped');
assert_eq(2, $dry['counts']['failed'], 'dry run: bad rows reported');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM crm_accounts WHERE name = " . $DB->quote("Gamma Gardens $tag"))->fetchColumn(), 'dry run writes nothing');
$real = CrmTransfer::run($DB, $C, $U, 'admin', ['token' => $parsed['token'], 'type' => 'customer', 'mapping' => $parsed['mapping'], 'existing' => 'update']);
assert_eq(1, $real['counts']['created'], 'import created');
assert_eq(1, $real['counts']['updated'], 'import updated the existing account');
$gid = (int)$DB->query("SELECT id FROM crm_accounts WHERE name = " . $DB->quote("Gamma Gardens $tag"))->fetchColumn();
$g = CrmAccounts::full($DB, $C, $U, 'admin', $gid);
assert_eq('prospect', $g['status'], 'imported status label mapped');
assert_eq('Lerato', $g['contact_list'][0]['first_name'], 'imported contact split');
assert_eq('Cape Town', $g['addresses'][0]['city'], 'imported address');
assert_eq(2, count($g['tags']), 'imported tags');
assert_eq('Small', $g['custom_values'][$fSize], 'imported custom field');
$throws(fn() => CrmTransfer::run($DB, $C, $U, 'admin', ['token' => $parsed['token'], 'type' => 'customer', 'mapping' => $parsed['mapping']]), 'used import token expires');
[$fname, $out] = CrmTransfer::export($DB, $C, 'customers', []);
assert_true(str_starts_with($fname, 'clients-'), 'export file named with the company label');
assert_true(str_contains($out, "Gamma Gardens $tag") && str_contains($out, 'Company size'), 'export has rows and custom fields');
assert_true(str_contains(CrmTransfer::csv([['=SUM(A1)', '-5', '+27 82']]), "'=SUM(A1)"), 'formula cells defused');
assert_true(str_contains(CrmTransfer::csv([['-5']]), "\n-5") || str_contains(CrmTransfer::csv([['-5']]), "-5\n"), 'numbers left alone');

// -------------------------------------------------- overview, boot, mail
$ov = CrmOverview::build($DB, $C, $M, 'member');
foreach (['kpis', 'funnel', 'forecast', 'tasks', 'attention', 'cold', 'compliance', 'activity'] as $panel) assert_true(array_key_exists($panel, $ov['panels']), "overview panel $panel");
assert_true(!array_key_exists('top', $ov['panels']), 'switched-off panel not built');
$boot = CrmBoot::payload($DB, $C, $U, 'admin');
assert_eq('Clients', $boot['config']['labels']['customers'], 'boot carries the labels');
assert_true($boot['is_admin'] && $boot['can_write'], 'admin flags');
assert_true(!CrmBoot::payload($DB, $C, $U, 'viewer')['can_write'], 'viewers read only');
assert_true(str_contains(CrmBoot::styleVars($boot), '--accent:#123456'), 'company accent becomes CSS');
require_once FLOWWORK_ROOT . '/crm/lib/CrmMail.php';
$compose = CrmMail::compose($DB, $C, $U, $acme);
assert_eq(['thandi@acme.example'], $compose['to'], 'email goes to the primary contact');
assert_true(count(array_filter($compose['templates'], fn($t) => $t['key'] === 'rfq')) === 0, 'no RFQ template for customers');
$throws(fn() => CrmMail::send($DB, $C, $U, ['account_id' => $acme, 'to' => 'x@y.example', 'subject' => '', 'body' => 'b']), 'subject required');

// calendar source: open tasks with a due date show for the assignee
$cal = CrmTasks::forCalendar($DB, $C, $M, date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('+7 days')));
assert_true(count($cal) >= 2, 'tasks for the calendar');

// the calendar shows them through its own follow-ups source, under the company's stage names for deals
require_once FLOWWORK_ROOT . '/calendar/lib/autoload.php';
assert_true(isset(CalendarSources::META['followups']) && isset(CalendarSettings::SOURCES['followups']), 'calendar knows CRM follow-ups');

// column guessing reads punctuation-insensitive headings
$g2 = CrmTransfer::guess($DB, $C, ['Company Name', 'E-mail', 'Cell phone', 'Postal Code']);
assert_eq(['name', 'email', 'mobile', 'postal_code'], $g2, 'headings with dashes and spaces matched');

// supplier delivery figures from purchase orders and goods received notes
try {
    $DB->prepare("INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, created_at) VALUES (?, ?, ?, 100, NOW() - INTERVAL 2 DAY), (?, ?, ?, 100, NOW() - INTERVAL 1 DAY)")
       ->execute([$C, 'PO-A-' . $tag, $sup, $C, 'PO-B-' . $tag, $sup]);
    $po = (int)$DB->lastInsertId();
    $DB->prepare("INSERT INTO goods_received_notes (company_id, grn_number, po_id, total, received_date, status, created_at) VALUES (?, ?, ?, 10, CURDATE(), 'completed', NOW())")->execute([$C, 'GRN-' . $tag, $po]);
    CrmNotify::supplierKpis($DB);
    $k = $DB->query("SELECT on_time_percent, defect_rate_percent FROM crm_accounts WHERE id = $sup")->fetch(PDO::FETCH_ASSOC);
    assert_eq('50.00', (string)$k['on_time_percent'], 'one delivery for two orders is 50% on time');
    assert_eq('0.00', (string)$k['defect_rate_percent'], 'no cancelled deliveries');
} catch (PDOException $e) {
    echo "  (supplier figures skipped: " . $e->getMessage() . ")\n";
}

echo "CRM: " . $GLOBALS['__assert_count'] . " assertions passed\n";
