<?php
// /finances/api/bank.php — bank accounts, statement lines, matching, rules, import and reconciliation.
//   GET  ?op=accounts                                   bank accounts with balances
//   GET  ?op=lines&account=&status=&from=&to=&q=&dir=&page=   statement lines
//   GET  ?op=line&id=                                   one line with match suggestions
//   GET  ?op=rules                                      rules
//   GET  ?op=reconcile&account=&date=&statement=        reconciliation summary
//   POST {op: account_save, …} · {op: match_existing, id, journal_id} · {op: categorise, id, lines[], memo?}
//        {op: transfer, id, to_account, other_line?} · {op: unmatch, id}
//        {op: rule_save, …} · {op: rule_toggle|rule_delete, id} · {op: rule_order, ids[]} · {op: apply_rules, account?, dry?}
//        multipart {op: parse, account, file, map?} · {op: import, account, rows[], file_name}
//        {op: reconcile_close, account, date, statement} · {op: reconcile_reopen, account, reason} (admin)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinBank.php';
require_once __DIR__ . '/../lib/FinBoot.php';

fin_require_module('bank');
$C = $FIN_COMPANY_ID;
if (!fin_is_post()) {
    switch ($_GET['op'] ?? 'accounts') {
        case 'accounts': fin_json(['accounts' => FinBank::accounts($DB, $C, !empty($_GET['all']))]);
        case 'lines':
            if (($_GET['format'] ?? '') === 'csv') {
                require_once __DIR__ . '/../lib/FinExport.php';
                $f = $_GET; $rows = [];
                for ($p = 1; $p <= 100; $p++) {
                    $f['page'] = $p;
                    $d = FinBank::lines($DB, $C, $f);
                    foreach ($d['rows'] as $r) $rows[] = [$r['date'], $r['bank'], $r['description'], $r['reference'], number_format($r['amount'], 2, '.', ''), $r['matched'] ? 'matched' : 'open', $r['match']['reference'] ?? ''];
                    if ($p * $d['per'] >= $d['total']) break;
                }
                FinExport::csv('bank-lines-' . date('Y-m-d') . '.csv', ['Date', 'Bank account', 'Description', 'Reference', 'Amount', 'Status', 'Journal'], $rows);
            }
            fin_json(FinBank::lines($DB, $C, $_GET));
        case 'line': fin_json(FinBank::line($DB, $C, fin_int($_GET['id'] ?? 0)));
        case 'rules': fin_json(['rules' => FinBank::rules($DB, $C)]);
        case 'reconcile':
            $date = Fin::ymd($_GET['date'] ?? '') ?: date('Y-m-d');
            $stmt = isset($_GET['statement']) && $_GET['statement'] !== '' ? Fin::cents($_GET['statement']) : null;
            fin_json(FinBank::reconcileSummary($DB, $C, fin_int($_GET['account'] ?? 0), $date, $stmt));
    }
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
$id = fin_int($in['id'] ?? 0);
switch (fin_op()) {
    case 'account_save':
        $aid = FinBank::saveAccount($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        fin_json(['id' => $aid, 'accounts' => FinBank::accounts($DB, $C, true), 'banks' => FinBoot::banks($DB, $C)]);
    case 'match_existing':
        FinBank::matchExisting($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, fin_int($in['journal_id'] ?? 0));
        break;
    case 'categorise':
        FinBank::categorise($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, (array)($in['lines'] ?? []), 'bank_tx', fin_str($in['memo'] ?? '', 255) ?: null);
        if (!empty($in['make_rule']) && is_array($in['make_rule'])) FinBank::saveRule($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in['make_rule']);
        break;
    case 'transfer':
        FinBank::transfer($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, fin_int($in['to_account'] ?? 0), fin_int($in['other_line'] ?? 0));
        break;
    case 'unmatch':
        $r = FinBank::unmatch($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        fin_json($r + FinBank::line($DB, $C, $id));
    case 'rule_save':
        FinBank::saveRule($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        fin_json(['rules' => FinBank::rules($DB, $C)]);
    case 'rule_toggle':
    case 'rule_delete':
    case 'rule_order':
        FinBank::ruleAction($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_op(), $in);
        fin_json(['rules' => FinBank::rules($DB, $C)]);
    case 'apply_rules':
        fin_rate_limit('bank_rules', 10, 60);
        fin_json(FinBank::applyRules($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['account'] ?? 0), !empty($in['dry'])));
    case 'parse':
        fin_rate_limit('bank_parse', 20, 300);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) throw new FinUserError('Choose a statement file to upload.');
        $map = is_array($in['map'] ?? null) ? $in['map'] : (json_decode((string)($_POST['map'] ?? ''), true) ?: []);
        fin_json(FinBank::parse($DB, $C, fin_int($_POST['account'] ?? 0), $_FILES['file']['tmp_name'], (string)$_FILES['file']['name'], $map));
    case 'import':
        fin_rate_limit('bank_import', 20, 300);
        fin_json(FinBank::import($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['account'] ?? 0), (array)($in['rows'] ?? []), fin_str($in['file_name'] ?? 'statement', 200)));
    case 'reconcile_close':
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose the statement date.');
        $stmt = Fin::cents($in['statement'] ?? '');
        if ($stmt === null) throw new FinUserError('Enter the closing balance on the statement.');
        fin_json(FinBank::closeReconciliation($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['account'] ?? 0), $date, $stmt));
    case 'reconcile_reopen':
        FinBank::reopen($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['account'] ?? 0), (string)($in['reason'] ?? ''));
        fin_json(['accounts' => FinBank::accounts($DB, $C)]);
    default:
        fin_error('Unknown action');
}
fin_json(FinBank::line($DB, $C, $id));
