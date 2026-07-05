<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/AsOf.php';
require_once __DIR__ . '/../lib/http.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$asOf = AsOf::normalizeDate($_GET['as_of'] ?? null);
$asof = new AsOf($DB, $companyId);
$tb = $asof->trialBalance($asOf);

define('ASSET_VERSION', FIN_ASSET_VERSION);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Trial Balance as of <?=$asOf?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
<style>
table{border-collapse:collapse;width:100%}
th,td{padding:6px 10px;border-bottom:1px solid #eee;text-align:right}
th:nth-child(1),td:nth-child(1){text-align:left}
th:nth-child(2),td:nth-child(2){text-align:left}
tfoot td{font-weight:bold}
h1{margin:0 0 12px 0}
.filters{margin:0 0 16px 0}
input{padding:6px 8px}
</style></head><body class="fw-finance">
<div class="fw-finance__container">
<?php $finTitle = 'Trial Balance'; $finBack = '/finances/reports.php'; include __DIR__ . '/../partials/header.php'; ?>
<main class="fw-finance__main">
<div class="fw-finance__paper">
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
</div><!-- /fw-finance__paper -->
</main>
<footer class="fw-finance__footer">
    <span>Trial Balance v<?= ASSET_VERSION ?></span>
    <span id="themeIndicator">Theme: Light</span>
</footer>
</div><!-- /fw-finance__container -->
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body></html>
