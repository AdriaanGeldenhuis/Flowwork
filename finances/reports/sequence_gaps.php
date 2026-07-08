<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/http.php';
require_method('GET');
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/../lib/AccountsMap.php';

// SARS sequential-numbering check, driven by the ACTUAL document numbers in the
// data. The previous version read a doc_sequences 'AR-INVOICE' row that nothing
// ever writes and matched an 'INV-YYYY-MM-' prefix the app never produces (real
// numbers are e.g. INV2026-0001), so it silently reported "No gaps" for every
// company. This parses each number into <prefix><digits>, groups by prefix, and
// reports internal gaps and duplicates within each sequence for the selected
// year (numbering resets yearly, so the year scopes the check).
$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

define('ASSET_VERSION', FIN_ASSET_VERSION);
header('Content-Type: text/html; charset=utf-8');

$now = new DateTimeImmutable('now');
$yearParam = (string)($_GET['year'] ?? '');
$year = preg_match('/^20\d{2}$/', $yearParam) ? (int)$yearParam : (int)$now->format('Y');

/** Split a document number into [prefix, intSuffix]; null when it has no trailing digits. */
function fw_split_seq(string $num): ?array {
    if (preg_match('/^(.*?)(\d+)$/', trim($num), $m)) {
        return [$m[1], (int)$m[2]];
    }
    return null;
}

// Each source: label, table, number column, date column, extra WHERE.
// All identifiers are hard-coded constants — no user input reaches the SQL.
$sources = [
    ['AR Invoices',  'invoices',     'invoice_number',     'issue_date', 'AND deleted_at IS NULL'],
    ['Credit Notes', 'credit_notes', 'credit_note_number', 'issue_date', ''],
];

$report = [];
foreach ($sources as $src) {
    [$label, $table, $col, $dateCol, $extra] = $src;
    $q = $DB->prepare("SELECT id, `$col` AS num FROM `$table`
                       WHERE company_id = ? AND YEAR(`$dateCol`) = ? $extra");
    $q->execute([$companyId, $year]);

    $groups = []; // prefix => [ seq => [id, ...] ]
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $parsed = fw_split_seq((string)($r['num'] ?? ''));
        if (!$parsed) { continue; }
        [$prefix, $n] = $parsed;
        $groups[$prefix][$n][] = $r['id'];
    }

    $groupResults = [];
    foreach ($groups as $prefix => $nums) {
        ksort($nums, SORT_NUMERIC);
        $keys = array_keys($nums);
        $min  = (int)min($keys);
        $max  = (int)max($keys);
        $gaps = [];
        for ($i = $min; $i <= $max; $i++) {
            if (!isset($nums[$i])) { $gaps[] = $i; }
        }
        $dupes = [];
        foreach ($nums as $n => $ids) {
            if (count($ids) > 1) { $dupes[] = ['seq' => $n, 'ids' => $ids]; }
        }
        $groupResults[] = [
            'prefix' => $prefix, 'min' => $min, 'max' => $max,
            'count' => count($keys), 'gaps' => $gaps, 'dupes' => $dupes,
        ];
    }
    usort($groupResults, fn($a, $b) => strcmp((string)$a['prefix'], (string)$b['prefix']));
    $report[] = ['label' => $label, 'groups' => $groupResults];
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
  <h1>Document sequence check — <?= (int)$year ?></h1>
  <form method="get" style="margin-bottom:12px">
    <label for="year">Year:</label>
    <select id="year" name="year" onchange="this.form.submit()">
      <?php for ($y = (int)$now->format('Y'); $y >= (int)$now->format('Y') - 7; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <noscript><button type="submit">View</button></noscript>
  </form>
  <p style="color:#6c757d;margin-bottom:1rem">Detects missing numbers (gaps) and duplicates within each document-number
     sequence for the year. Sequential numbering without gaps is a SARS requirement.</p>

  <?php foreach ($report as $section): ?>
    <h2><?= htmlspecialchars($section['label']) ?></h2>
    <?php if (!$section['groups']): ?>
      <p>No documents for <?= (int)$year ?>.</p>
    <?php else: foreach ($section['groups'] as $g): ?>
      <div style="margin-bottom:1.25rem">
        <p>Prefix <code><?= htmlspecialchars($g['prefix'] !== '' ? $g['prefix'] : '(none)') ?></code>
           — range <code><?= (int)$g['min'] ?></code>–<code><?= (int)$g['max'] ?></code>,
           <?= (int)$g['count'] ?> number(s) used.</p>
        <?php if ($g['gaps']): ?>
          <p class="bad">Gaps (<?= count($g['gaps']) ?>): <?= htmlspecialchars(implode(', ', $g['gaps'])) ?></p>
        <?php else: ?>
          <p class="ok">No gaps.</p>
        <?php endif; ?>
        <?php if ($g['dupes']): ?>
          <p class="bad">Duplicates (<?= count($g['dupes']) ?>):</p>
          <table><tr><th>Number</th><th>Document IDs</th></tr>
          <?php foreach ($g['dupes'] as $d): ?>
            <tr><td><?= (int)$d['seq'] ?></td><td><?= htmlspecialchars(implode(', ', $d['ids'])) ?></td></tr>
          <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  <?php endforeach; ?>
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
