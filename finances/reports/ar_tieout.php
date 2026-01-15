<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Tieout.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$asOf = $_GET['as_of'] ?? date('Y-m-d');
$t = new Tieout($DB, $companyId);

$gl  = $t->glBalance('AR', $asOf);
$sub = $t->arSubledger($asOf);
$diff = round($gl - $sub, 2);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>AR Tie-out as of <?=$asOf?></title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:20px}
.card{border:1px solid #eee;border-radius:8px;padding:16px;margin:10px 0}
.row{display:flex;gap:12px}
.col{flex:1}
.amount{font-weight:600;font-size:1.2em;text-align:right}
.bad{color:#b00020}
.good{color:#0a7d00}
.small{color:#666;font-size:0.9em}
</style></head><body>
<h1>Accounts Receivable Tie-out <span class="small">as of <?=$asOf?></span></h1>
<form method="get">
  <label>As of <input type="date" name="as_of" value="<?=$asOf?>"></label>
  <button type="submit">Run</button>
</form>

<div class="row">
  <div class="col card"><div>GL AR Balance</div><div class="amount"><?=number_format($gl,2)?></div></div>
  <div class="col card"><div>Subledger AR</div><div class="amount"><?=number_format($sub,2)?></div></div>
  <div class="col card"><div>Difference</div>
       <div class="amount <?=abs($diff)<0.01?'good':'bad'?>"><?=number_format($diff,2)?></div></div>
</div>

<p class="small">
GL AR uses accounts mapped in <code>gl_report_map</code> group ‘AR’.<br>
Subledger AR = invoices − payments − credit notes to date.
</p>
</body></html>
