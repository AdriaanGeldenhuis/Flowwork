<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/http.php';
require_method('GET');
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/AccountsMap.php';

// Simple gaps report for AR invoices using doc_sequences prefixes
$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

define('ASSET_VERSION', FIN_ASSET_VERSION);

header('Content-Type: text/html; charset=utf-8');

// Fetch sequence settings for current period
$now = new DateTimeImmutable('now');
$periodKey = $now->format('Ym');

$stmt = $DB->prepare("SELECT prefix, pad, last_number FROM doc_sequences WHERE company_id = :cid AND doc_type = 'AR-INVOICE' AND period_key = :pk");
$stmt->execute([':cid'=>$companyId, ':pk'=>$periodKey]);
$seq = $stmt->fetch(PDO::FETCH_ASSOC);
$prefix = $seq['prefix'] ?? str_replace(['{YYYY}','{YY}','{MM}'], [$now->format('Y'),$now->format('y'),$now->format('m')], 'INV-{YYYY}-{MM}-');
$pad = (int)($seq['pad'] ?? 4);

// Pull all invoice numbers for the period with this prefix
$q = $DB->prepare("SELECT id, invoice_number FROM invoices WHERE company_id = :cid AND invoice_number LIKE :pfx");
$q->execute([':cid'=>$companyId, ':pfx'=>$prefix.'%']);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

// Extract numeric suffixes
$used = [];
$dupes = [];
$seen = [];
foreach ($rows as $r) {
    $no = $r['invoice_number'] ?? '';
    if (strpos($no, $prefix) !== 0) continue;
    $suffix = substr($no, strlen($prefix));
    if (ctype_digit($suffix)) {
        $n = (int)$suffix;
        $used[$n] = true;
        if (isset($seen[$n])) { $dupes[] = [$seen[$n], $r['id'], $no]; }
        $seen[$n] = $r['id'];
    }
}

// Determine gaps from 1..max
$max = $seq ? (int)$seq['last_number'] : (count($used) ? max(array_keys($used)) : 0);
$gaps = [];
for ($i=1; $i <= $max; $i++) {
    if (!isset($used[$i])) $gaps[] = $i;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Sequence gaps</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
  <style>
    code{background:#f3f3f3;padding:2px 4px;border-radius:4px}
    .bad{color:#b00020}
    .ok{color:#0a7d00}
    table{border-collapse:collapse}
    td,th{padding:6px 10px;border-bottom:1px solid #eee}
  </style>
</head>
<body class="fw-finance">
  <div class="fw-finance__container">
  <?php $finTitle = 'Sequence Gaps'; $finBack = '/finances/reports.php'; include __DIR__ . '/../partials/header.php'; ?>
  <main class="fw-finance__main">
  <div class="fw-finance__paper">
  <h1>AR Invoice sequence check</h1>
  <p>Prefix: <code><?=htmlspecialchars($prefix)?></code> Pad: <code><?=$pad?></code> Last issued: <code><?=$max?></code></p>
  <?php if ($gaps): ?>
    <h3 class="bad">Gaps (<?=count($gaps)?>)</h3>
    <p><?=implode(', ', $gaps)?></p>
  <?php else: ?>
    <p class="ok">No gaps detected up to last issued number.</p>
  <?php endif; ?>

  <?php if ($dupes): ?>
    <h3 class="bad">Duplicates (<?=count($dupes)?>)</h3>
    <table><tr><th>Suffix</th><th>Invoice IDs</th><th>Number</th></tr>
    <?php foreach ($dupes as $d): ?>
      <tr><td><?=$d[0]?></td><td><?=$d[1]?></td><td><?=htmlspecialchars($d[2])?></td></tr>
    <?php endforeach; ?>
    </table>
  <?php endif; ?>
  </div><!-- /fw-finance__paper -->
  </main>
  <footer class="fw-finance__footer">
      <span>Sequence Gaps v<?= ASSET_VERSION ?></span>
      <span id="themeIndicator">Theme: Light</span>
  </footer>
  </div><!-- /fw-finance__container -->
  <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
