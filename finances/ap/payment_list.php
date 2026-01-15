<?php
// /finances/ap/payment_list.php – List of supplier payments
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-PAYLIST');

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

// Fetch suppliers for filter
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Payments – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-finance__app-name">Supplier Payments</div>
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
                <a href="/finances/ap/payment_new.php" class="fw-finance__btn fw-finance__btn--primary" title="New Payment" style="margin-left:0.5rem;">
                    + New Payment
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <!-- Filter toolbar -->
            <div class="fw-finance__toolbar">
                <select id="supplierFilter" class="fw-finance__filter">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="paymentList">
                <div class="fw-finance__loading">Loading payments...</div>
            </div>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP Payments v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
async function loadPayments() {
    const supFilter = document.getElementById('supplierFilter').value;
    let url = '/finances/ap/api/payment_list.php';
    if (supFilter) {
        url += '?supplier_id=' + encodeURIComponent(supFilter);
    }
    const container = document.getElementById('paymentList');
    container.innerHTML = '<div class="fw-finance__loading">Loading payments...</div>';
    try {
        const res = await fetch(url);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Failed to load payments');
        const rows = json.data || [];
        if (rows.length === 0) {
            container.innerHTML = '<div class="fw-finance__empty-state">No payments found.</div>';
            return;
        }
        let html = '<table class="fw-finance__table">';
        html += '<thead><tr><th>Date</th><th>Supplier</th><th>Method</th><th>Reference</th><th class="amount">Amount</th></tr></thead><tbody>';
        rows.forEach(p => {
            html += '<tr>' +
                '<td>' + (p.payment_date || '') + '</td>' +
                '<td>' + (p.supplier_name || '') + '</td>' +
                '<td>' + (p.method || '') + '</td>' +
                '<td>' + (p.reference || '') + '</td>' +
                '<td class="amount">' + parseFloat(p.amount).toFixed(2) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="fw-finance__error">' + err.message + '</div>';
    }
}
document.getElementById('supplierFilter').addEventListener('change', loadPayments);
document.addEventListener('DOMContentLoaded', loadPayments);
</script>
</body>
</html>