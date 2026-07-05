<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Tieout.php';
require_method('GET');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

$asOf = $_GET['as_of'] ?? date('Y-m-d');
$t = new Tieout($DB, $companyId);

$gl  = $t->glBalance('AP', $asOf);
$sub = $t->apSubledger($asOf);
$diff = round($gl - $sub, 2);

define('ASSET_VERSION', FIN_ASSET_VERSION);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>AP Tie-out as of <?=$asOf?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
<style>
.card{border:1px solid #eee;border-radius:8px;padding:16px;margin:10px 0}
.row{display:flex;gap:12px}
.col{flex:1}
.amount{font-weight:600;font-size:1.2em;text-align:right}
.bad{color:#b00020}
.good{color:#0a7d00}
.small{color:#666;font-size:0.9em}
</style></head><body class="fw-finance">
<div class="fw-finance__container">
<?php $finTitle = 'AP Tie-out'; $finBack = '/finances/reports.php'; include __DIR__ . '/../partials/header.php'; ?>
<main class="fw-finance__main">
<div class="fw-finance__paper">
<h1>Accounts Payable Tie-out <span class="small">as of <?=$asOf?></span></h1>
<form method="get">
  <label>As of <input type="date" name="as_of" value="<?=$asOf?>"></label>
  <button type="submit">Run</button>
</form>

<div class="row">
  <div class="col card"><div>GL AP Balance</div><div class="amount"><?=number_format($gl,2)?></div></div>
  <div class="col card"><div>Subledger AP</div><div class="amount"><?=number_format($sub,2)?></div></div>
  <div class="col card"><div>Difference</div>
       <div class="amount <?=abs($diff)<0.01?'good':'bad'?>"><?=number_format($diff,2)?></div></div>
</div>

<p class="small">
GL AP uses accounts mapped in <code>gl_report_map</code> group ‘AP’.<br>
Subledger AP = bills − payments − vendor credits to date.
</p>
</div><!-- /fw-finance__paper -->
</main>
<footer class="fw-finance__footer">
    <span>AP Tie-out v<?= ASSET_VERSION ?></span>
    <span id="themeIndicator">Theme: Light</span>
</footer>
</div><!-- /fw-finance__container -->
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body></html>
