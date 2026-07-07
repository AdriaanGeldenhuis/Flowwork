<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ap/payment_new.php – Record a supplier payment
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['bookkeeper','admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

require_once __DIR__ . '/../lib/Csrf.php';
$csrfToken = Csrf::token();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// Preselect supplier or bill if provided
$preSupplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$preBillId     = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch suppliers
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch bank accounts (columns are name / account_no — the previous query
// referenced non-existent account_name / account_number and fatalled,
// breaking the Record Supplier Payment page entirely)
$stmt = $DB->prepare("SELECT id, name AS account_name, account_no AS account_number
                        FROM gl_bank_accounts WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$companyId]);
$bankAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Supplier Payment – <?= htmlspecialchars($companyName) ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-finance__form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }
        .fw-finance__form label {
            display: flex;
            flex-direction: column;
            font-size: 0.875rem;
        }
        table.allocations {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        table.allocations th,
        table.allocations td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
            text-align: left;
        }
        table.allocations th {
            background: var(--fw-bg-secondary);
        }
        table.allocations td.amount-input {
            text-align: right;
        }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'Record Supplier Payment';
        $finBack = '/finances/ap/';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
            <form id="paymentForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Supplier
                    <select id="supplierSelect" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= (int)$sup['id'] ?>" <?php if ($preSupplierId && $preSupplierId == $sup['id']) echo 'selected'; ?>><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Payment Date
                    <input type="date" id="paymentDate" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>
                    Payment Method
                    <select id="paymentMethod" required>
                        <option value="eft">EFT</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                        <option value="card">Card</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>
                    Bank Account
                    <select id="bankAccount">
                        <option value="">Select Bank</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= (int)$ba['id'] ?>">
                                <?= htmlspecialchars($ba['account_name']) ?><?= $ba['account_number'] ? ' (' . htmlspecialchars($ba['account_number']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Reference
                    <input type="text" id="reference" placeholder="Bank or internal reference">
                </label>
                <label>
                    Notes
                    <textarea id="notes" rows="2"></textarea>
                </label>
                <!-- Allocations table -->
                <div id="allocContainer">
                    <div class="fw-finance__loading">Select a supplier to load outstanding bills...</div>
                </div>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save Payment</button>
                <div id="formMessage"></div>
            </form>
        </main>
        <footer class="fw-finance__footer">
            <span>Record Supplier Payment v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
// Preselected variables from PHP
const preSupplierId = <?= (int)$preSupplierId ?>;
const preBillId     = <?= (int)$preBillId ?>;

// Load outstanding bills for selected supplier
async function loadBillsForSupplier(supId) {
    const container = document.getElementById('allocContainer');
    if (!supId) {
        container.innerHTML = '<div class="fw-finance__info">Please select a supplier to view outstanding bills.</div>';
        return;
    }
    container.innerHTML = '<div class="fw-finance__loading">Loading bills...</div>';
    try {
        const res = await fetch('/finances/ap/api/bill_list.php');
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Failed to load bills');
        const allBills = json.data || [];
        // Filter by supplier and unpaid balance > 0
        const bills = allBills.filter(b => Number(b.supplier_id) === Number(supId) && parseFloat(b.balance) > 0.0001);
        if (bills.length === 0) {
            container.innerHTML = '<div class="fw-finance__empty-state">No outstanding bills for this supplier.</div>';
            return;
        }
        let html = '<table class="allocations">';
        html += '<thead><tr><th>Invoice #</th><th>Issue Date</th><th>Due Date</th><th style="text-align:right">Balance</th><th style="width:15%">Pay Amount</th></tr></thead><tbody>';
        bills.forEach(b => {
            const bal = parseFloat(b.balance);
            const defaultAlloc = (preBillId && Number(preBillId) === Number(b.id)) ? bal.toFixed(2) : '';
            html += '<tr>' +
                '<td>' + (b.vendor_invoice_number || '') + '</td>' +
                '<td>' + (b.issue_date || '') + '</td>' +
                '<td>' + (b.due_date || '') + '</td>' +
                '<td style="text-align:right">' + bal.toFixed(2) + '</td>' +
                '<td class="amount-input"><input type="number" class="allocAmt" data-bill-id="' + b.id + '" step="0.01" min="0" max="' + bal.toFixed(2) + '" value="' + defaultAlloc + '"></td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="fw-finance__error">Error loading bills: ' + err.message + '</div>';
    }
}

document.getElementById('supplierSelect').addEventListener('change', function() {
    const supId = this.value;
    loadBillsForSupplier(supId);
});
// Initial load if pre-selected
if (preSupplierId) {
    loadBillsForSupplier(preSupplierId);
}

// Submit payment form
document.getElementById('paymentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const supId    = document.getElementById('supplierSelect').value;
    const payDate  = document.getElementById('paymentDate').value;
    const method   = document.getElementById('paymentMethod').value;
    const bankAcc  = document.getElementById('bankAccount').value || null;
    const ref      = document.getElementById('reference').value.trim();
    const notes    = document.getElementById('notes').value.trim();
    if (!supId || !payDate) {
        showMessage('formMessage', 'Please select supplier and payment date', 'error');
        return;
    }
    // Gather allocations
    const inputs = document.querySelectorAll('.allocAmt');
    const allocations = [];
    inputs.forEach(inp => {
        const amt = parseFloat(inp.value);
        if (amt > 0) {
            allocations.push({ bill_id: parseInt(inp.getAttribute('data-bill-id')), amount: amt });
        }
    });
    if (allocations.length === 0) {
        showMessage('formMessage', 'Please enter at least one payment amount', 'error');
        return;
    }
    const payload = {
        supplier_id: parseInt(supId),
        payment_date: payDate,
        bank_account_id: bankAcc ? parseInt(bankAcc) : null,
        method: method,
        reference: ref || null,
        notes: notes || null,
        allocations: allocations,
        // One key per submit attempt series: double-clicks/retries replay
        // instead of paying the bill twice.
        idempotency_key: window.__apPayIdemKey || (window.__apPayIdemKey =
            (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(36).slice(2)))
    };
    const submitBtn = document.querySelector('#paymentForm button[type="submit"], #savePaymentBtn');
    if (submitBtn) submitBtn.disabled = true;
    try {
        var hdrs = { 'Content-Type': 'application/json' };
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) hdrs['X-CSRF-Token'] = csrfMeta.content;
        const res = await fetch('/finances/ap/api/payment_create.php', {
            method: 'POST',
            headers: hdrs,
            body: JSON.stringify(payload)
        });
        const result = await res.json();
        if (!result.ok) {
            showMessage('formMessage', result.error || 'Failed to save payment', 'error');
            return;
        }
        showMessage('formMessage', 'Payment recorded successfully', 'success');
        setTimeout(() => {
            window.location.href = '/finances/ap/payment_list.php';
        }, 1500);
    } catch (err) {
        showMessage('formMessage', err.message, 'error');
    }
});
</script>
</body>
</html>