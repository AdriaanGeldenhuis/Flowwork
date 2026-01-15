<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../lib/AsOf.php';
require_once __DIR__ . '/../lib/http.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$asOf = AsOf::normalizeDate($_GET['as_of'] ?? null);
$asof = new AsOf($DB, $companyId);
$tb = $asof->trialBalance($asOf);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Trial Balance as of <?=$asOf?></title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:20px}
table{border-collapse:collapse;width:100%}
th,td{padding:6px 10px;border-bottom:1px solid #eee;text-align:right}
th:nth-child(1),td:nth-child(1){text-align:left}
th:nth-child(2),td:nth-child(2){text-align:left}
tfoot td{font-weight:bold}
h1{margin:0 0 12px 0}
.filters{margin:0 0 16px 0}
input{padding:6px 8px}
</style></head><body>
<h1>Trial Balance <small style="font-size:0.6em;color:#666">as of <?=$asOf?></small></h1>
<div class="filters">
  <form method="get">
    <label>As of:
      <input type="date" name="as_of" value="<?=$asOf?>" />
    </label>
    <button type="submit">Run</button>
  </form>
</div>
<table>
  <thead><tr><th>Code</th><th>Account</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
  <tbody>
  <?php foreach ($tb['rows'] as $r): ?>
    <tr>
      <td><?=htmlspecialchars($r['code'])?></td>
      <td><?=htmlspecialchars($r['name'])?></td>
      <td><?=number_format($r['debit'],2)?></td>
      <td><?=number_format($r['credit'],2)?></td>
      <td><?=number_format($r['balance'],2)?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="2">Totals</td><td><?=number_format($tb['total_debit'],2)?></td><td><?=number_format($tb['total_credit'],2)?></td><td></td></tr>
  </tfoot>
</table>
</body></html>
