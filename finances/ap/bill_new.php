<?php
// /finances/ap/bill_new.php – Create a new supplier bill
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-BILLNEW');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user and company
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

// Fetch expense accounts
$stmt = $DB->prepare("SELECT account_id, account_code, account_name FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type = 'expense' ORDER BY account_code");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Bill – <?= htmlspecialchars($companyName) ?></title>
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
        table.bill-lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        table.bill-lines th, table.bill-lines td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
        }
        table.bill-lines th {
            background: var(--fw-bg-secondary);
        }
        table.bill-lines input, table.bill-lines select {
            width: 100%;
            box-sizing: border-box;
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
                    <div class="fw-finance__app-name">New Supplier Bill</div>
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
            <form id="billForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Supplier
                    <select id="supplierId" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Invoice Number
                    <input type="text" id="invoiceNumber" required>
                </label>
                <label>
                    Issue Date
                    <input type="date" id="invoiceDate" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>
                    Due Date
                    <input type="date" id="dueDate">
                </label>
                <label>
                    Notes
                    <textarea id="notes" rows="2"></textarea>
                </label>
                <!-- Line items table -->
                <table class="bill-lines" id="linesTable">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="width:8%">Qty</th>
                            <th style="width:10%">Unit</th>
                            <th style="width:12%">Unit Price</th>
                            <th style="width:10%">Discount</th>
                            <th style="width:10%">Tax %</th>
                            <th style="width:18%">Account</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="linesBody">
                        <!-- Initial row -->
                    </tbody>
                </table>
                <button type="button" class="fw-finance__btn" id="addLineBtn">+ Add Line</button>
                <!-- Optional matching panel for 3‑way match -->
                <details id="matchSection" style="margin-top:1rem;">
                    <summary>Match PO/GRN (optional)</summary>
                    <div id="matchContainer" style="margin-top:0.5rem;">
                        <button type="button" id="loadMatchBtn" class="fw-finance__btn">Load PO/GRN Lines</button>
                        <table class="bill-lines" id="matchTable" style="margin-top:1rem;">
                            <thead>
                                <tr>
                                    <th style="width:25%">Bill Line</th>
                                    <th style="width:25%">PO Line</th>
                                    <th style="width:25%">GRN Line</th>
                                    <th style="width:15%">Qty</th>
                                    <th style="width:10%"></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        <button type="button" id="addMatchRowBtn" class="fw-finance__btn">+ Add Match</button>
                    </div>
                </details>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save Bill</button>
                <div id="formMessage"></div>
            </form>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP New Bill v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
// Accounts list for dropdown
var accounts = <?php echo json_encode(array_map(function($a) {
    return [
        'id' => $a['account_id'],
        'code' => $a['account_code'],
        'name' => $a['account_name']
    ];
}, $accounts)); ?>;

// ---------------------------
// 3-Way Matching helpers
// The match panel allows the user to link bill lines to PO and/or GRN lines.
// We'll load available PO/GRN lines for the selected supplier via three_way_get.php.
// matchData holds arrays of available lines after loading.
var matchData = { po_lines: [], grn_lines: [] };

// Fetch PO and GRN lines for the chosen supplier
async function loadMatchData() {
    var supplierId = document.getElementById('supplierId').value;
    if (!supplierId) {
        showMessage('formMessage', 'Please select a supplier before loading matches', 'error');
        return;
    }
    try {
        var res = await fetch('/finances/ap/api/three_way_get.php?supplier_id=' + supplierId);
        var data = await res.json();
        matchData.po_lines  = Array.isArray(data.po_lines) ? data.po_lines : [];
        matchData.grn_lines = Array.isArray(data.grn_lines) ? data.grn_lines : [];
        // Clear any existing match rows when reloading
        var mtBody = document.querySelector('#matchTable tbody');
        if (mtBody) mtBody.innerHTML = '';
        showMessage('formMessage', 'PO/GRN lines loaded', 'success');
    } catch (err) {
        showMessage('formMessage', 'Failed to load match data', 'error');
    }
}

// Build options for the bill line select (by index)
function buildBillLineOptions(selected) {
    var options = '<option value="">Select</option>';
    var rows = document.querySelectorAll('#linesBody tr');
    rows.forEach(function(row, idx) {
        var desc = row.querySelector('.desc').value.trim() || ('Line ' + (idx + 1));
        options += '<option value="' + idx + '"' + ((selected !== undefined && Number(selected) === idx) ? ' selected' : '') + '>' + (idx + 1) + ': ' + desc + '</option>';
    });
    return options;
}

// Build options for PO lines
function buildPoOptions(selected) {
    var options = '<option value="">None</option>';
    matchData.po_lines.forEach(function(p) {
        var text = p.po_number + ' - ' + p.description + ' (Avail ' + parseFloat(p.qty_available).toFixed(3) + ')';
        options += '<option value="' + p.id + '"' + ((selected && Number(selected) === Number(p.id)) ? ' selected' : '') + '>' + text + '</option>';
    });
    return options;
}

// Build options for GRN lines
function buildGrnOptions(selected) {
    var options = '<option value="">None</option>';
    matchData.grn_lines.forEach(function(g) {
        var desc = g.po_description || '';
        var text = g.grn_number + ' - ' + desc + ' (Avail ' + parseFloat(g.qty_available).toFixed(3) + ')';
        options += '<option value="' + g.id + '"' + ((selected && Number(selected) === Number(g.id)) ? ' selected' : '') + '>' + text + '</option>';
    });
    return options;
}

// Add a new match row to the match table
function addMatchRow(defaults) {
    defaults = defaults || {};
    var tbody = document.querySelector('#matchTable tbody');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.innerHTML = '' +
        '<td><select class="billLineSelect">' + buildBillLineOptions(defaults.bill_line_index) + '</select></td>' +
        '<td><select class="poLineSelect">' + buildPoOptions(defaults.po_line_id) + '</select></td>' +
        '<td><select class="grnLineSelect">' + buildGrnOptions(defaults.grn_line_id) + '</select></td>' +
        '<td><input type="number" class="matchQty" min="0" step="0.001" value="' + (defaults.qty || 0) + '"></td>' +
        '<td><button type="button" class="removeMatchRowBtn">×</button></td>';
    tbody.appendChild(tr);
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
    // Add initial bill line
    addLineRow();
    // Set up match panel event listeners
    var loadBtn = document.getElementById('loadMatchBtn');
    if (loadBtn) {
        loadBtn.addEventListener('click', loadMatchData);
    }
    var addBtn = document.getElementById('addMatchRowBtn');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            addMatchRow();
        });
    }
    var matchTable = document.getElementById('matchTable');
    if (matchTable) {
        matchTable.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('removeMatchRowBtn')) {
                e.target.closest('tr').remove();
            }
        });
    }
});

document.getElementById('billForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var supplierId   = document.getElementById('supplierId').value;
    var invoiceNo    = document.getElementById('invoiceNumber').value.trim();
    var invoiceDate  = document.getElementById('invoiceDate').value;
    var dueDate      = document.getElementById('dueDate').value;
    var notes        = document.getElementById('notes').value.trim();
    var rows         = document.querySelectorAll('#linesBody tr');
    if (!supplierId || !invoiceNo || !invoiceDate || rows.length === 0) {
        showMessage('formMessage', 'Please complete required fields and add at least one line', 'error');
        return;
    }
    var lines = [];
    rows.forEach(function(row) {
        var desc      = row.querySelector('.desc').value.trim();
        var qty       = parseFloat(row.querySelector('.qty').value) || 1;
        var unit      = row.querySelector('.unit').value.trim() || 'ea';
        var price     = parseFloat(row.querySelector('.price').value) || 0;
        var discount  = parseFloat(row.querySelector('.discount').value) || 0;
        var taxRate   = parseFloat(row.querySelector('.taxRate').value) || 0;
        var glAcc     = row.querySelector('.account').value;
        lines.push({
            description: desc,
            qty: qty,
            unit: unit,
            unit_price: price,
            discount: discount,
            tax_rate: taxRate,
            gl_account_id: glAcc
        });
    });
    // Compute totals
    var subtotal = 0;
    var taxTotal = 0;
    lines.forEach(function(l) {
        var net = (l.qty * l.unit_price) - l.discount;
        var vat = (l.tax_rate > 0) ? net * (l.tax_rate / 100) : 0;
        subtotal += net;
        taxTotal += vat;
    });
    var total = subtotal + taxTotal;
    var data = {
        header: {
            supplier_id: supplierId,
            invoice_number: invoiceNo,
            invoice_date: invoiceDate,
            due_date: dueDate,
            subtotal: subtotal,
            tax: taxTotal,
            total: total,
            notes: notes
        },
        lines: lines
    };
    var res = await fetch('/finances/ap/api/bill_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    var result = await res.json();
    if (result.ok) {
        // If user has added match rows, apply them via AJAX
        var matchRows = document.querySelectorAll('#matchTable tbody tr');
        if (matchRows.length > 0) {
            var matchesToSend = [];
            matchRows.forEach(function(row) {
                var billIdx = row.querySelector('.billLineSelect').value;
                var poId  = row.querySelector('.poLineSelect').value;
                var grnId = row.querySelector('.grnLineSelect').value;
                var qty   = parseFloat(row.querySelector('.matchQty').value) || 0;
                // Skip if no qty or no selection
                if (!billIdx && !poId && !grnId) return;
                matchesToSend.push({
                    bill_line_index: billIdx !== '' ? parseInt(billIdx) : null,
                    po_line_id: poId !== '' ? parseInt(poId) : null,
                    grn_line_id: grnId !== '' ? parseInt(grnId) : null,
                    qty: qty
                });
            });
            if (matchesToSend.length > 0) {
                try {
                    var mRes = await fetch('/finances/ap/ajax/bill.match.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ bill_id: result.bill_id, matches: matchesToSend })
                    });
                    var mResult = await mRes.json();
                    if (!mResult.ok) {
                        console.error('Match apply errors', mResult);
                    }
                } catch (err) {
                    console.error('Error applying matches', err);
                }
            }
        }
        showMessage('formMessage', 'Bill created successfully', 'success');
        // Redirect to view page after a moment
        setTimeout(function() {
            window.location.href = '/finances/ap/bill_view.php?id=' + result.bill_id;
        }, 1500);
    } else {
        showMessage('formMessage', result.error || 'Failed to create bill', 'error');
    }
});
</script>
</body>
</html>