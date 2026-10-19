<?php
// /finances/api/vat.php — VAT periods and the VAT201 return.
//   GET  ?op=periods                       every period with live figures for those not filed
//   GET  ?op=period&id=                    the VAT201 boxes, adjustments, settlement, payments
//   GET  ?op=range&from=&to=               VAT201 boxes for any dates (no period needed)
//   GET  ?op=audit&id=&format=csv          SARS audit file: every contributing line, foots to Box 5 / 9
//   POST {op: create, start, end} · {op: delete, id} · {op: prepare, id} · {op: reopen, id}
//        {op: adjust, id, lines[{side, amount, memo}], contra_account_id, memo?, key}
//        {op: undo_adjust, id, journal_id} · {op: file, id} (admin)
//        {op: pay, id, bank_account_id, date, amount?, reference?, key} · {op: undo_pay, id, journal_id}
//        {op: notes, id, notes}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinVat.php';

fin_require_module('vat');
$C = $FIN_COMPANY_ID;

if (!fin_is_post()) {
    switch ($_GET['op'] ?? 'periods') {
        case 'periods':
            fin_json(FinVat::periods($DB, $C));
        case 'period':
            fin_json(FinVat::period($DB, $C, fin_int($_GET['id'] ?? 0)));
        case 'range':
            $from = Fin::ymd($_GET['from'] ?? '');
            $to = Fin::ymd($_GET['to'] ?? '');
            if (!$from || !$to || $from > $to) fin_error('Choose a valid date range.');
            $basis = in_array($_GET['basis'] ?? '', ['invoice', 'payments'], true) ? $_GET['basis'] : VatCalculator::companyBasis($DB, $C);
            $b = FinVat::boxes($DB, $C, $from, $to, $basis);
            fin_json(['from' => $from, 'to' => $to, 'basis' => $basis, 'boxes' => FinVat::boxList($b), 'raw' => $b]);
        case 'audit':
            require_once __DIR__ . '/../lib/VatAuditFile.php';
            require_once __DIR__ . '/../lib/FinExport.php';
            fin_rate_limit('vat_audit', 20, 300);
            $id = fin_int($_GET['id'] ?? 0);
            $st = $DB->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ?");
            $st->execute([$id, $C]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) fin_error('That VAT period was not found.', 404);
            [$out, $in] = FinVat::codes($DB, $C);
            $rows = VatAuditFile::rows($DB, $C, $p['period_start'], $p['period_end'], $out, $in, VatCalculator::periodBasis($DB, $C, $p));
            $n = fn($c) => number_format(((int)$c) / 100, 2, '.', '');
            $data = array_map(fn($r) => [$r['entry_date'], $r['journal_id'], $r['reference'], $r['source_type'], $r['description'], $r['account_code'], $r['tax_code'],
                                         $r['rate_percent'], $r['is_capital_goods'] ? 'yes' : '', $r['debit'] !== null ? number_format((float)$r['debit'], 2, '.', '') : '',
                                         $r['credit'] !== null ? number_format((float)$r['credit'], 2, '.', '') : '', $r['allocation'] !== null ? number_format((float)$r['allocation'], 2, '.', '') : '',
                                         $n($r['net_cents']), $n($r['vat_cents']), $r['section']], $rows);
            $t = VatAuditFile::totals($rows);
            $data[] = ['', '', '', '', 'Total output VAT (Box 5)', '', '', '', '', '', '', '', '', $n($t['output_vat_cents']), 'output'];
            $data[] = ['', '', '', '', 'Total input VAT (Box 9)', '', '', '', '', '', '', '', '', $n($t['input_vat_cents']), 'input'];
            FinExport::csv('vat-audit-' . $p['period_start'] . '-to-' . $p['period_end'] . '.csv',
                ['Date', 'Journal', 'Reference', 'Source', 'Description', 'Account', 'Tax code', 'Rate %', 'Capital goods', 'Debit', 'Credit', 'Allocation', 'Net', 'VAT', 'Section'], $data);
    }
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
$id = fin_int($in['id'] ?? 0);
switch (fin_op()) {
    case 'create':
        $id = FinVat::create($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_str($in['start'] ?? '', 10), fin_str($in['end'] ?? '', 10));
        fin_json(['id' => $id] + FinVat::periods($DB, $C));
    case 'delete':
        FinVat::delete($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        fin_json(FinVat::periods($DB, $C));
    case 'prepare':
        FinVat::prepare($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        break;
    case 'reopen':
        FinVat::reopen($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        break;
    case 'adjust':
        FinVat::adjust($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, (array)($in['lines'] ?? []), fin_int($in['contra_account_id'] ?? 0), (string)($in['key'] ?? ''), (string)($in['memo'] ?? ''));
        break;
    case 'undo_adjust':
        FinVat::undoAdjustment($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, fin_int($in['journal_id'] ?? 0));
        break;
    case 'file':
        fin_rate_limit('vat_file', 10, 300);
        FinVat::file($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        break;
    case 'pay':
        FinVat::pay($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, $in);
        break;
    case 'undo_pay':
        FinVat::undoPayment($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, fin_int($in['journal_id'] ?? 0));
        break;
    case 'notes':
        FinVat::saveNotes($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, (string)($in['notes'] ?? ''));
        break;
    default:
        fin_error('Unknown action');
}
fin_json(FinVat::period($DB, $C, $id));
