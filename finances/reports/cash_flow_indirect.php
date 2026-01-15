<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/AsOf.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$from = AsOf::normalizeDate($_GET['from'] ?? date('Y-01-01'));
$to   = AsOf::normalizeDate($_GET['to']   ?? date('Y-m-d'));

// Map tells us which accounts are cash/bank and which are working capital
$stmt = $DB->prepare("SELECT group_key, account_id FROM gl_report_map WHERE company_id = ? AND report = 'CF'");
$stmt->execute([$companyId]);
$groups = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $g = strtoupper($r['group_key']);
  if (!isset($groups[$g])) $groups[$g] = [];
  $groups[$g][] = (int)$r['account_id'];
}

// Helper: period delta for accounts (ending - beginning)
function delta(PDO $db, int $cid, array $accounts, string $from, string $to): float {
  if (!$accounts) return 0.0;
  $ph = implode(',', array_fill(0, count($accounts), '?'));
  $sql = "SELECT SUM(CASE WHEN je.entry_date <= ? THEN (jl.debit_cents - jl.credit_cents) ELSE 0 END) AS at_end,
                 SUM(CASE WHEN je.entry_date <  ? THEN (jl.debit_cents - jl.credit_cents) ELSE 0 END) AS at_begin
          FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
          WHERE je.company_id = ? AND jl.gl_account_id IN ($ph) AND je.status = 'posted'";
  $params = array_merge([$to, $from, $cid], $accounts);
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $end = (int)($row['at_end'] ?? 0) / 100.0;
  $beg = (int)($row['at_begin'] ?? 0) / 100.0;
  return round($end - $beg, 2);
}

// Indirect method: start with net profit from IS mapping groups
function sumRange(PDO $db, int $cid, array $accounts, string $from, string $to): float {
  if (!$accounts) return 0.0;
  $ph = implode(',', array_fill(0, count($accounts), '?'));
  $sql = "SELECT COALESCE(SUM(jl.debit_cents - jl.credit_cents),0) FROM journal_lines jl
          JOIN journal_entries je ON je.id = jl.journal_id
          WHERE je.company_id = ? AND jl.gl_account_id IN ($ph)
            AND je.entry_date BETWEEN ? AND ? AND je.status = 'posted'";
  $params = array_merge([$cid], $accounts, [$from, $to]);
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return round(((int)$stmt->fetchColumn())/100.0, 2);
}

$rev  = sumRange($DB, $companyId, $groups['REVENUE'] ?? [], $from, $to) * -1;
$cogs = sumRange($DB, $companyId, $groups['COST_OF_SALES'] ?? [], $from, $to);
$opex = sumRange($DB, $companyId, $groups['OPERATING_EXPENSES'] ?? [], $from, $to);
$oi   = sumRange($DB, $companyId, $groups['OTHER_INCOME'] ?? [], $from, $to) * -1;
$oe   = sumRange($DB, $companyId, $groups['OTHER_EXPENSES'] ?? [], $from, $to);
$net  = $rev - $cogs - $opex + $oi - $oe;

// Working capital deltas
$deltaAR = delta($DB, $companyId, $groups['AR'] ?? [], $from, $to);   // increase in AR reduces cash
$deltaAP = delta($DB, $companyId, $groups['AP'] ?? [], $from, $to);   // increase in AP increases cash
$deltaInv = delta($DB, $companyId, $groups['INV'] ?? [], $from, $to); // increase in Inv reduces cash

$cashOps = $net - $deltaAR + $deltaAP - $deltaInv;

// Cash and equivalents movement
$cashDelta = delta($DB, $companyId, $groups['CASH'] ?? [], $from, $to);

// Stub placeholders for investing/financing if you want to map them later
$cashInv = delta($DB, $companyId, $groups['INVESTING'] ?? [], $from, $to);
$cashFin = delta($DB, $companyId, $groups['FINANCING'] ?? [], $from, $to);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Cash Flow (Indirect) <?=$from?> to <?=$to?></title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:20px}
h1{margin:0 0 12px 0}
table{border-collapse:collapse;width:100%}
td{padding:6px 10px;border-bottom:1px solid #eee}
td:first-child{text-align:left}
td:last-child{text-align:right}
.total{font-weight:700}
.small{color:#666;font-size:0.9em}
</style></head><body>
<h1>Cash Flow (Indirect) <span class="small"><?=$from?> → <?=$to?></span></h1>
<form method="get">
  <label>From <input type="date" name="from" value="<?=$from?>"></label>
  <label>To <input type="date" name="to" value="<?=$to?>"></label>
  <button type="submit">Run</button>
</form>
<table>
  <tr><td>Net Profit</td><td><?=number_format($net,2)?></td></tr>
  <tr><td>Change in Accounts Receivable</td><td><?=number_format(-$deltaAR,2)?></td></tr>
  <tr><td>Change in Accounts Payable</td><td><?=number_format($deltaAP,2)?></td></tr>
  <tr><td>Change in Inventory</td><td><?=number_format(-$deltaInv,2)?></td></tr>
  <tr><td class="total">Net Cash from Operating Activities</td><td class="total"><?=number_format($cashOps,2)?></td></tr>
  <tr><td>Net Cash from Investing</td><td><?=number_format($cashInv,2)?></td></tr>
  <tr><td>Net Cash from Financing</td><td><?=number_format($cashFin,2)?></td></tr>
  <tr><td class="total">Net Increase in Cash and Equivalents</td><td class="total"><?=number_format($cashDelta,2)?></td></tr>
</table>
</body></html>
