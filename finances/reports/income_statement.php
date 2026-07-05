<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/AsOf.php';
require_once __DIR__ . '/../lib/http.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$from = AsOf::normalizeDate($_GET['from'] ?? date('Y-01-01'));
$to   = AsOf::normalizeDate($_GET['to']   ?? date('Y-m-d'));

// Pull mapping for IS groups
$stmt = $DB->prepare("SELECT group_key, account_id FROM gl_report_map WHERE company_id = ? AND report = 'IS'");
$stmt->execute([$companyId]);
$map = ['REVENUE'=>[], 'COST_OF_SALES'=>[], 'OPERATING_EXPENSES'=>[], 'OTHER_INCOME'=>[], 'OTHER_EXPENSES'=>[]];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $g = strtoupper($r['group_key']);
  if (!isset($map[$g])) $map[$g] = [];
  $map[$g][] = (int)$r['account_id'];
}

// Range sum helper
function sumRange(PDO $db, int $cid, array $accounts, string $from, string $to): float {
  if (!$accounts) return 0.0;
  // Look up account_codes for the given account IDs
  $ph = implode(',', array_fill(0, count($accounts), '?'));
  $codeSql = "SELECT account_code FROM gl_accounts WHERE account_id IN ($ph) AND company_id = ?";
  $codeStmt = $db->prepare($codeSql);
  $codeStmt->execute(array_merge($accounts, [$cid]));
  $codes = $codeStmt->fetchAll(PDO::FETCH_COLUMN);
  if (!$codes) return 0.0;
  $ph2 = implode(',', array_fill(0, count($codes), '?'));
  $sql = "SELECT COALESCE(SUM(jl.debit - jl.credit),0) FROM journal_lines jl
          JOIN journal_entries je ON je.id = jl.journal_id
          WHERE je.company_id = ? AND jl.account_code IN ($ph2)
            AND je.entry_date BETWEEN ? AND ? AND je.status = 'posted'";
  $params = array_merge([$cid], $codes, [$from, $to]);
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return round(floatval($stmt->fetchColumn()), 2);
}

$rev  = sumRange($DB, $companyId, $map['REVENUE'] ?? [], $from, $to) * -1; // revenue usually credit
$cogs = sumRange($DB, $companyId, $map['COST_OF_SALES'] ?? [], $from, $to);
$opex = sumRange($DB, $companyId, $map['OPERATING_EXPENSES'] ?? [], $from, $to);
$oi   = sumRange($DB, $companyId, $map['OTHER_INCOME'] ?? [], $from, $to) * -1;
$oe   = sumRange($DB, $companyId, $map['OTHER_EXPENSES'] ?? [], $from, $to);

$gross = $rev - $cogs;
$ebit  = $gross - $opex;
$net   = $ebit + $oi - $oe;

define('ASSET_VERSION', FIN_ASSET_VERSION);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Income Statement <?=$from?> to <?=$to?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
<style>
h1{margin:0 0 12px 0}
table{border-collapse:collapse;width:100%}
td{padding:6px 10px;border-bottom:1px solid #eee}
td:first-child{text-align:left}
td:last-child{text-align:right}
.group{font-weight:600}
.total{font-weight:700}
.small{color:#666;font-size:0.9em}
</style></head><body class="fw-finance">
<div class="fw-finance__container">
<?php $finTitle = 'Income Statement'; $finBack = '/finances/reports.php'; include __DIR__ . '/../partials/header.php'; ?>
<main class="fw-finance__main">
<div class="fw-finance__paper">
<h1>Income Statement <span class="small"><?=$from?> → <?=$to?></span></h1>
<form method="get">
  <label>From <input type="date" name="from" value="<?=$from?>"></label>
  <label>To <input type="date" name="to" value="<?=$to?>"></label>
  <button type="submit">Run</button>
</form>
<table>
  <tr><td class="group">Revenue</td><td><?=number_format($rev,2)?></td></tr>
  <tr><td class="group">Cost of Sales</td><td><?=number_format($cogs,2)?></td></tr>
  <tr><td class="group">Gross Profit</td><td class="total"><?=number_format($gross,2)?></td></tr>
  <tr><td class="group">Operating Expenses</td><td><?=number_format($opex,2)?></td></tr>
  <tr><td class="group">EBIT</td><td class="total"><?=number_format($ebit,2)?></td></tr>
  <tr><td class="group">Other Income</td><td><?=number_format($oi,2)?></td></tr>
  <tr><td class="group">Other Expenses</td><td><?=number_format($oe,2)?></td></tr>
  <tr><td class="group">Net Profit</td><td class="total"><?=number_format($net,2)?></td></tr>
</table>
</div><!-- /fw-finance__paper -->
</main>
<footer class="fw-finance__footer">
    <span>Income Statement v<?= ASSET_VERSION ?></span>
    <span id="themeIndicator">Theme: Light</span>
</footer>
</div><!-- /fw-finance__container -->
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body></html>
