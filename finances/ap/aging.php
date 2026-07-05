<?php
require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ap/aging.php – Accounts payable aging report
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'AP Aging Report';
        $finBack = '/finances/ap/bills_list.php';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
            <div id="agingContainer">
                <div class="fw-finance__loading">Generating aging report...</div>
            </div>
        </main>
        <footer class="fw-finance__footer">
            <span>AP Aging Report v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
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
                '<td>' + escapeHtml(r.supplier_name) + '</td>' +
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
        container.innerHTML = '<div class="fw-finance__error">' + escapeHtml(err.message) + '</div>';
    }
}
document.addEventListener('DOMContentLoaded', loadAging);
</script>
</body>
</html>