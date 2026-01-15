<?php
// /finances/ap/aging.php – Accounts payable aging report
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-AGING');

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AP Aging Report – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-finance__table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .fw-finance__table th,
        .fw-finance__table td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
            text-align: left;
        }
        .fw-finance__table th {
            background: var(--fw-bg-secondary);
            font-weight: bold;
        }
        .fw-finance__table td.amount {
            text-align: right;
        }
    </style>
</head>
<body>
<main class="fw-finance">
    <div class="fw-finance__container">
        <header class="fw-finance__header">
            <div class="fw-finance__brand">
                <div class="fw-finance__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-finance__brand-text">
                    <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-finance__app-name">AP Aging Report</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/ap/bills_list.php" class="fw-finance__back-btn" title="Back to Bills">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <div id="agingContainer">
                <div class="fw-finance__loading">Generating aging report...</div>
            </div>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP Aging v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
async function loadAging() {
    const container = document.getElementById('agingContainer');
    try {
        const res = await fetch('/finances/ap/api/ap_aging.php');
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Failed to generate report');
        const rows = json.data || [];
        if (rows.length === 0) {
            container.innerHTML = '<div class="fw-finance__empty-state">No outstanding balances.</div>';
            return;
        }
        let html = '<table class="fw-finance__table">';
        html += '<thead><tr><th>Supplier</th><th class="amount">Current</th><th class="amount">1–30</th><th class="amount">31–60</th><th class="amount">61–90</th><th class="amount">90+</th><th class="amount">Total</th></tr></thead><tbody>';
        rows.forEach(r => {
            html += '<tr>' +
                '<td>' + (r.supplier_name || '') + '</td>' +
                '<td class="amount">' + parseFloat(r.current).toFixed(2) + '</td>' +
                '<td class="amount">' + parseFloat(r.days_1_30).toFixed(2) + '</td>' +
                '<td class="amount">' + parseFloat(r.days_31_60).toFixed(2) + '</td>' +
                '<td class="amount">' + parseFloat(r.days_61_90).toFixed(2) + '</td>' +
                '<td class="amount">' + parseFloat(r.days_90_plus).toFixed(2) + '</td>' +
                '<td class="amount">' + parseFloat(r.total).toFixed(2) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="fw-finance__error">' + err.message + '</div>';
    }
}
document.addEventListener('DOMContentLoaded', loadAging);
</script>
</body>
</html>