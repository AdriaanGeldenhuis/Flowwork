<?php
// /finances/ap/bills_list.php – Supplier bills listing page
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-LIST');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user and company for greeting
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
    <title>Supplier Bills – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-finance__app-name">Supplier Bills</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/" class="fw-finance__back-btn" title="Back to Finance">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <a href="/finances/ap/bill_new.php" class="fw-finance__btn fw-finance__btn--primary" title="New Bill" style="margin-left:0.5rem;">
                    + New Bill
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <div class="fw-finance__toolbar">
                <input type="search" class="fw-finance__search" placeholder="Search bills..." id="searchInput">
                <select class="fw-finance__filter" id="statusFilter">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="review">Review</option>
                    <option value="approved">Approved</option>
                    <option value="posted">Posted</option>
                    <option value="paid">Paid</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>
            <div id="billList">
                <div class="fw-finance__loading">Loading bills...</div>
            </div>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP Bills v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
async function loadBills() {
    var status = document.getElementById('statusFilter').value;
    var url = '/finances/ap/api/bill_list.php';
    if (status) {
        url += '?status=' + encodeURIComponent(status);
    }
    var res = await fetch(url);
    var result = await res.json();
    var container = document.getElementById('billList');
    if (!result.ok) {
        container.innerHTML = '<div class="fw-finance__error">Failed to load bills</div>';
        return;
    }
    var bills = result.data;
    if (!bills || bills.length === 0) {
        container.innerHTML = '<div class="fw-finance__empty-state">No bills found.</div>';
        return;
    }
    var html = '<table class="fw-finance__table">';
    html += '<thead><tr><th>Invoice #</th><th>Supplier</th><th>Issue Date</th><th>Due Date</th><th>Status</th><th class="amount">Total</th><th class="amount">Paid</th><th class="amount">Credited</th><th class="amount">Balance</th><th>Action</th></tr></thead><tbody>';
    bills.forEach(function(b) {
        html += '<tr>';
        html += '<td>' + (b.vendor_invoice_number || '') + '</td>';
        html += '<td>' + (b.supplier_name || '') + '</td>';
        html += '<td>' + (b.issue_date || '') + '</td>';
        html += '<td>' + (b.due_date || '') + '</td>';
        html += '<td>' + (b.status || '') + '</td>';
        html += '<td class="amount">' + parseFloat(b.total).toFixed(2) + '</td>';
        html += '<td class="amount">' + parseFloat(b.paid).toFixed(2) + '</td>';
        html += '<td class="amount">' + parseFloat(b.credited).toFixed(2) + '</td>';
        html += '<td class="amount">' + parseFloat(b.balance).toFixed(2) + '</td>';
        html += '<td><a href="/finances/ap/bill_view.php?id=' + b.id + '">View</a></td>';
        html += '</tr>';
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}
document.getElementById('statusFilter').addEventListener('change', loadBills);
document.getElementById('searchInput').addEventListener('input', debounce(function() {
    var query = this.value.toLowerCase();
    var rows = document.querySelectorAll('#billList tbody tr');
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
}, 300));
document.addEventListener('DOMContentLoaded', loadBills);
</script>
</body>
</html>