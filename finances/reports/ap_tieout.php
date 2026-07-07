<?php
// /finances/reports/ap_tieout.php
// Accounts Payable tie-out: compares the AP control account balance in the
// general ledger against the AP subledger (posted bills − payments − vendor
// credits) as at a date. A non-zero difference flags a control that has
// drifted from its subledger — a core SARS/audit reconciliation.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/../lib/Tieout.php';
require_once __DIR__ . '/../ajax/report_helpers.php';

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { http_response_code(403); echo 'No company'; exit; }

// Validate as_of; default to today.
$asOf = $_GET['as_of'] ?? date('Y-m-d');
$dt = DateTime::createFromFormat('Y-m-d', $asOf);
if (!$dt || $dt->format('Y-m-d') !== $asOf) {
    $asOf = date('Y-m-d');
}

$meta = getReportMeta($DB, $companyId, $userId);
$companyName = $meta['company_name'] ?: 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

$tie  = new Tieout($DB, $companyId);
$gl   = $tie->glBalance('AP', $asOf);
$sub  = $tie->apSubledger($asOf);
$diff = round($gl - $sub, 2);
$inBalance = abs($diff) < 0.01;

function zar($v) { return 'R ' . number_format((float)$v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AP Tie-out – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/finances/assets/report_print.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php $finTitle = 'AP Tie-out'; $finBack = '/finances/reports.php'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/../partials/header.php'; ?>
        <main class="fw-finance__main">
            <form class="fw-report-toolbar fw-print-hide" method="get">
                <div class="fw-finance__filter-group">
                    <label class="fw-finance__label" for="as_of">As at Date</label>
                    <input type="date" class="fw-finance__filter" id="as_of" name="as_of" value="<?= htmlspecialchars($asOf) ?>">
                </div>
                <div class="fw-report-actions">
                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Run Report</button>
                    <button type="button" class="fw-finance__btn fw-finance__btn--secondary" onclick="window.print()">Print</button>
                </div>
            </form>

            <div class="fw-finance__report-content">
                <div class="fw-finance__report-header">
                    <h1 class="fw-finance__report-title"><?= htmlspecialchars($companyName) ?></h1>
                    <?php
                    $details = [];
                    if (!empty($meta['reg_number'])) $details[] = 'Reg No: ' . htmlspecialchars($meta['reg_number']);
                    if (!empty($meta['vat_number'])) $details[] = 'VAT No: ' . htmlspecialchars($meta['vat_number']);
                    if (!empty($meta['tax_number'])) $details[] = 'Tax No: ' . htmlspecialchars($meta['tax_number']);
                    if ($details): ?>
                        <p class="fw-finance__report-company-details"><?= implode(' &nbsp;|&nbsp; ', $details) ?></p>
                    <?php endif; ?>
                    <h2 class="fw-finance__report-subtitle">Accounts Payable Tie-out</h2>
                    <p class="fw-finance__report-date">As at <?= htmlspecialchars(date('j M Y', strtotime($asOf))) ?></p>
                    <p class="fw-finance__report-prepared">Prepared by: <?= htmlspecialchars($meta['prepared_by']) ?> &nbsp;|&nbsp; Generated: <?= htmlspecialchars(date('Y-m-d H:i')) ?></p>
                </div>

                <table class="fw-finance__report-table">
                    <tbody>
                        <tr>
                            <td>GL AP control balance</td>
                            <td class="fw-finance__report-table-number"><?= zar($gl) ?></td>
                        </tr>
                        <tr>
                            <td>AP subledger (bills − payments − vendor credits)</td>
                            <td class="fw-finance__report-table-number"><?= zar($sub) ?></td>
                        </tr>
                        <tr class="fw-finance__report-table-total">
                            <td><strong>Difference (GL − subledger)</strong></td>
                            <td class="fw-finance__report-table-number"><strong><?= zar($diff) ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ($inBalance): ?>
                    <div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">AP control ties out to the subledger.</div>
                <?php else: ?>
                    <div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">AP is OUT by <?= zar(abs($diff)) ?> — the GL control and subledger do not agree.</div>
                <?php endif; ?>

                <p class="fw-finance__report-prepared" style="margin-top: 1rem;">
                    GL AP uses the mapped AP control account (company settings, falling back to the seeded chart).
                    Subledger AP counts only documents that reached the GL: posted bills, supplier payments and
                    vendor credits with a posted journal.
                </p>
            </div>
        </main>
        <footer class="fw-finance__footer">
            <span>AP Tie-out v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
