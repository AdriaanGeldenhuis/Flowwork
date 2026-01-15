<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../lib/AsOf.php';
require_once __DIR__ . '/../lib/http.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$asOf = AsOf::normalizeDate($_GET['as_of'] ?? null);

// Pull mapping
$stmt = $DB->prepare("SELECT group_key, account_id FROM gl_report_map WHERE company_id = ? AND report = 'BS'");
$stmt->execute([$companyId]);
$map = ['ASSETS'=>[], 'LIABILITIES'=>[], 'EQUITY'=>[]];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $g = strtoupper($r['group_key']);
  if (!isset($map[$g])) $map[$g] = [];
  $map[$g][] = (int)$r['account_id'];
}

$asof = new AsOf($DB, $companyId);
$assets = $asof->sumAccounts($map['ASSETS'] ?? [], $asOf);
$liab   = $asof->sumAccounts($map['LIABILITIES'] ?? [],   $asOf);
$equity = $asof->sumAccounts($map['EQUITY'] ?? [], $asOf);
$assets = round($assets,2);
$liab   = round($liab,2);
$equity = round($equity,2);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Balance Sheet as of <?=$asOf?></title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:20px}
h1{margin:0 0 12px 0}
.card{border:1px solid #eee;border-radius:8px;padding:16px;margin:10px 0}
.row{display:flex;gap:12px}
.col{flex:1}
.amount{font-weight:600;font-size:1.2em;text-align:right}
.small{color:#666;font-size:0.9em}
</style></head><body>
<h1>Balance Sheet <small class="small">as of <?=$asOf?></small></h1>
<form method="get"><label>As of <input type="date" name="as_of" value="<?=$asOf?>"></label> <button type="submit">Run</button></form>
<div class="row">
  <div class="col card">
    <div>Assets</div>
    <div class="amount"><?=number_format($assets, 2)?></div>
  </div>
  <div class="col card">
    <div>Liabilities</div>
    <div class="amount"><?=number_format($liab, 2)?></div>
  </div>
  <div class="col card">
    <div>Equity</div>
    <div class="amount"><?=number_format($equity, 2)?></div>
  </div>
</div>
<div class="card">
<strong class="small">Check</strong><br>
Assets (<?=number_format($assets,2)?>) = Liabilities (<?=number_format($liab,2)?>) + Equity (<?=number_format($equity,2)?>)
</div>
</body></html>
