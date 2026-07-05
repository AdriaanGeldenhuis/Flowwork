<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ap/vendor_credit_new.php – Create a vendor credit note
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['bookkeeper','admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

require_once __DIR__ . '/../lib/Csrf.php';
$csrfToken = Csrf::token();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// Preselect supplier or bill
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

// Fetch GL expense accounts (and inventory/asset) for lines
$stmt = $DB->prepare("SELECT account_id, account_code, account_name FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type IN ('expense','asset','inventory') ORDER BY account_code");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Vendor Credit – <?= htmlspecialchars($companyName) ?></title>
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
        table.credit-lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        table.credit-lines th,
        table.credit-lines td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
        }
        table.credit-lines th {
            background: var(--fw-bg-secondary);
        }
        table.credit-lines input,
        table.credit-lines select {
            width: 100%;
            box-sizing: border-box;
        }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'New Vendor Credit';
        $finBack = '/finances/ap/bills_list.php';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
            <form id="creditForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Supplier
                    <select id="supplierId" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?php if ($preSupplierId && $preSupplierId == $s['id']) echo 'selected'; ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Credit Number
                    <input type="text" id="creditNumber" required>
                </label>
                <label>
                    Issue Date
                    <input type="date" id="issueDate" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>
                    Original Bill Reference
                    <select id="refBillId">
                        <option value="">Select original bill (SARS requirement)</option>
                    </select>
                    <small style="color:var(--fw-text-secondary);">SARS: Credit notes must reference the original tax invoice</small>
                </label>
                <label>
                    Reason Code <small style="color:var(--fw-text-secondary);">(SARS requirement)</small>
                    <select id="reasonCode" required>
                        <option value="">Select reason</option>
                        <option value="return">Goods Returned</option>
                        <option value="discount">Discount / Rebate</option>
                        <option value="correction">Invoice Correction</option>
                        <option value="damaged">Damaged / Defective Goods</option>
                        <option value="cancellation">Order Cancellation</option>
                        <option value="vat_adjustment">VAT Adjustment</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>
                    Notes
                    <textarea id="notes" rows="2"></textarea>
                </label>
                <!-- Lines table -->
                <table class="credit-lines" id="linesTable">
                    <thead>
                        <tr><th>Description</th><th style="width:8%">Qty</th><th style="width:10%">Unit</th><th style="width:12%">Unit Price</th><th style="width:10%">Discount</th><th style="width:10%">Tax %</th><th style="width:18%">Account</th><th></th></tr>
                    </thead>
                    <tbody id="linesBody"></tbody>
                </table>
                <button type="button" class="fw-finance__btn" id="addLineBtn">+ Add Line</button>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save Credit</button>
                <div id="formMessage"></div>
            </form>
        </main>
        <footer class="fw-finance__footer">
            <span>New Vendor Credit v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
// Accounts array for select options
var accounts = <?php echo json_encode(array_map(function($a) { return [
    'id'   => $a['account_id'],
    'code' => $a['account_code'],
    'name' => $a['account_name'],
]; }, $accounts)); ?>;

function buildAccountSelect(selectedId) {
    var html = '<select class="account">';
    html += '<option value="">Select</option>';
    accounts.forEach(function(a) {
        var sel = (selectedId && Number(selectedId) === Number(a.id)) ? ' selected' : '';
        html += '<option value="' + a.id + '"' + sel + '>' + a.code + ' - ' + a.name + '</option>';
    });
    html += '</select>';
    return html;
}

function addLineRow(desc, qty, unit, price, discount, taxRate, accountId) {
    var tbody = document.getElementById('linesBody');
    var tr = document.createElement('tr');
    tr.innerHTML = '' +
        '<td><input type="text" class="desc" value="' + (desc || '') + '" required></td>' +
        '<td><input type="number" class="qty" min="0" step="0.01" value="' + (qty || 1) + '"></td>' +
        '<td><input type="text" class="unit" value="' + (unit || 'ea') + '"></td>' +
        '<td><input type="number" class="price" min="0" step="0.01" value="' + (price || 0) + '"></td>' +
        '<td><input type="number" class="discount" min="0" step="0.01" value="' + (discount || 0) + '"></td>' +
        '<td><input type="number" class="taxRate" min="0" step="0.01" value="' + (taxRate !== undefined ? taxRate : 15) + '"></td>' +
        '<td>' + buildAccountSelect(accountId) + '</td>' +
        '<td><button type="button" class="removeLineBtn">×</button></td>';
    tbody.appendChild(tr);
}

// Load supplier bills for original bill reference (SARS)
document.getElementById('supplierId').addEventListener('change', async function() {
    var supId = this.value;
    var select = document.getElementById('refBillId');
    select.innerHTML = '<option value="">Select original bill (SARS requirement)</option>';
    if (!supId) return;
    try {
        var res = await fetch('/finances/ap/api/bill_list.php');
        var json = await res.json();
        if (json.ok) {
            var bills = (json.data || []).filter(function(b) { return Number(b.supplier_id) === Number(supId); });
            bills.forEach(function(b) {
                var opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = (b.vendor_invoice_number || 'Bill #' + b.id) + ' - R' + parseFloat(b.total).toFixed(2) + ' (' + b.issue_date + ')';
                select.appendChild(opt);
            });
        }
    } catch (e) { /* ignore */ }
});

document.getElementById('addLineBtn').addEventListener('click', function() {
    addLineRow();
});

document.getElementById('linesTable').addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('removeLineBtn')) {
        e.target.closest('tr').remove();
    }
});
// Add initial row
document.addEventListener('DOMContentLoaded', function() {
    addLineRow();
});

document.getElementById('creditForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var supplierId   = document.getElementById('supplierId').value;
    var creditNumber = document.getElementById('creditNumber').value.trim();
    var issueDate    = document.getElementById('issueDate').value;
    var notes        = document.getElementById('notes').value.trim();
    var rows         = document.querySelectorAll('#linesBody tr');
    if (!supplierId || !creditNumber || !issueDate || rows.length === 0) {
        showMessage('formMessage', 'Please complete required fields and add at least one line', 'error');
        return;
    }
    var lines = [];
    rows.forEach(function(row) {
        var desc     = row.querySelector('.desc').value.trim();
        var qty      = parseFloat(row.querySelector('.qty').value) || 1;
        var unit     = row.querySelector('.unit').value.trim() || 'ea';
        var price    = parseFloat(row.querySelector('.price').value) || 0;
        var discount = parseFloat(row.querySelector('.discount').value) || 0;
        var taxRate  = parseFloat(row.querySelector('.taxRate').value) || 0;
        var accId    = row.querySelector('.account').value;
        lines.push({
            description: desc,
            qty: qty,
            unit: unit,
            unit_price: price,
            discount: discount,
            tax_rate: taxRate,
            gl_account_id: accId
        });
    });
    // Compute totals for display but not used here
    var subtotal = 0;
    var taxTotal = 0;
    lines.forEach(function(l) {
        var net = (l.qty * l.unit_price) - l.discount;
        var vat = (l.tax_rate > 0) ? net * (l.tax_rate / 100) : 0;
        subtotal += net;
        taxTotal += vat;
    });
    // SARS: Prepend original bill reference to notes if selected
    var refBillId = document.getElementById('refBillId').value;
    var refBillText = '';
    if (refBillId) {
        var refOpt = document.getElementById('refBillId').selectedOptions[0];
        refBillText = 'Ref Invoice: ' + refOpt.textContent;
    }
    var fullNotes = refBillText ? (refBillText + (notes ? '\n' + notes : '')) : notes;

    var reasonCode = document.getElementById('reasonCode').value;
    var data = {
        header: {
            supplier_id: parseInt(supplierId),
            credit_number: creditNumber,
            issue_date: issueDate,
            reason_code: reasonCode,
            notes: fullNotes
        },
        lines: lines
    };
    try {
        var hdrs = { 'Content-Type': 'application/json' };
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) hdrs['X-CSRF-Token'] = csrfMeta.content;
        const res = await fetch('/finances/ap/api/vendor_credit_create.php', {
            method: 'POST',
            headers: hdrs,
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (!result.ok) {
            showMessage('formMessage', result.error || 'Failed to save credit', 'error');
            return;
        }
        showMessage('formMessage', 'Vendor credit created successfully', 'success');
        setTimeout(function() {
            window.location.href = '/finances/ap/vendor_credit_view.php?id=' + result.credit_id;
        }, 1500);
    } catch (err) {
        showMessage('formMessage', err.message, 'error');
    }
});
</script>
</body>
</html>