<?php
// /finances/ap/three_way_match.php – 3-way match placeholder (PO vs GRN vs Bill)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-3WMATCH');


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

// Fetch suppliers for matching
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3-Way Match – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
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
                    <div class="fw-finance__app-name">3-Way Match</div>
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
            <!-- Supplier selection -->
            <form id="supplierForm" class="fw-finance__form" onsubmit="return false;" style="max-width:600px;">
                <label for="supplierSelect">Supplier:</label>
                <select id="supplierSelect" class="fw-finance__select" required>
                    <option value="">Select supplier…</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="loadLinesBtn" class="fw-finance__btn">Load Lines</button>
            </form>

            <!-- Hidden until lines are loaded -->
            <div id="matchSection" style="display:none; margin-top:2rem;">
                <!-- PO Lines Table -->
                <h2>Purchase Order Lines</h2>
                <div style="overflow-x:auto;">
                    <table id="poTable" class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>PO #</th>
                                <th>Description</th>
                                <th>Qty Ordered</th>
                                <th>Qty Matched</th>
                                <th>Qty Available</th>
                                <th>Unit Price</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <!-- GRN Lines Table -->
                <h2>Goods Received Note Lines</h2>
                <div style="overflow-x:auto;">
                    <table id="grnTable" class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>GRN #</th>
                                <th>PO Line ID</th>
                                <th>Description</th>
                                <th>Qty Received</th>
                                <th>Qty Matched</th>
                                <th>Qty Available</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <!-- Bill Lines Table -->
                <h2>Bill Lines</h2>
                <div style="overflow-x:auto;">
                    <table id="billTable" class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Invoice #</th>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Qty Matched</th>
                                <th>Qty Available</th>
                                <th>Unit Price</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <!-- Match creation form -->
                <h2>Create Match</h2>
                <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; max-width:100%;">
                    <div style="flex:1; min-width:180px;">
                        <label for="poLineSelect">PO Line:</label>
                        <select id="poLineSelect" class="fw-finance__select"></select>
                    </div>
                    <div style="flex:1; min-width:180px;">
                        <label for="grnLineSelect">GRN Line:</label>
                        <select id="grnLineSelect" class="fw-finance__select"></select>
                    </div>
                    <div style="flex:1; min-width:180px;">
                        <label for="billLineSelect">Bill Line:</label>
                        <select id="billLineSelect" class="fw-finance__select"></select>
                    </div>
                    <div style="flex:1; min-width:120px;">
                        <label for="qtyInput">Qty:</label>
                        <input type="number" id="qtyInput" class="fw-finance__input" step="0.001" min="0" placeholder="0" />
                    </div>
                    <div style="flex-basis:100%;">
                        <button type="button" id="addMatchBtn" class="fw-finance__btn">Add Match</button>
                    </div>
                </div>

                <!-- Pending matches table -->
                <h2>Pending Matches</h2>
                <div style="overflow-x:auto;">
                    <table id="matchesTable" class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>PO Line</th>
                                <th>GRN Line</th>
                                <th>Bill Line</th>
                                <th>Qty</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div style="margin-top:1rem;">
                    <button type="button" id="applyMatchesBtn" class="fw-finance__btn fw-finance__btn--primary">Apply Matches</button>
                </div>
            </div>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP 3‑Way Match v<?= ASSET_VERSION ?></span>
        </footer>

        <!-- Inline script handling dynamic matching -->
        <script>
        (function() {
            // Data arrays
            let poLines = [];
            let grnLines = [];
            let billLines = [];
            let pendingMatches = [];

            const supplierSelect = document.getElementById('supplierSelect');
            const loadBtn       = document.getElementById('loadLinesBtn');
            const matchSection  = document.getElementById('matchSection');
            const poTableBody   = document.getElementById('poTable').querySelector('tbody');
            const grnTableBody  = document.getElementById('grnTable').querySelector('tbody');
            const billTableBody = document.getElementById('billTable').querySelector('tbody');
            const poLineSelect  = document.getElementById('poLineSelect');
            const grnLineSelect = document.getElementById('grnLineSelect');
            const billLineSelect= document.getElementById('billLineSelect');
            const qtyInput      = document.getElementById('qtyInput');
            const addMatchBtn   = document.getElementById('addMatchBtn');
            const matchesTableBody = document.getElementById('matchesTable').querySelector('tbody');
            const applyBtn      = document.getElementById('applyMatchesBtn');

            function numberFmt(n) {
                return parseFloat(n).toLocaleString(undefined, {minimumFractionDigits: 3, maximumFractionDigits: 3});
            }

            loadBtn.addEventListener('click', function() {
                const supplierId = supplierSelect.value;
                if (!supplierId) {
                    alert('Please select a supplier first');
                    return;
                }
                // Reset arrays and UI
                pendingMatches = [];
                matchesTableBody.innerHTML = '';
                fetch(`/finances/ap/api/three_way_get.php?supplier_id=${supplierId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        poLines = data.po_lines || [];
                        grnLines = data.grn_lines || [];
                        billLines = data.bill_lines || [];
                        populateTables();
                        populateSelects();
                        matchSection.style.display = '';
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Error loading lines');
                    });
            });

            function populateTables() {
                // PO table
                poTableBody.innerHTML = '';
                poLines.forEach(line => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${line.id}</td>` +
                        `<td>${line.po_number}</td>` +
                        `<td>${line.description || ''}</td>` +
                        `<td>${numberFmt(line.qty_ordered)}</td>` +
                        `<td>${numberFmt(line.qty_matched)}</td>` +
                        `<td>${numberFmt(line.qty_available)}</td>` +
                        `<td>${line.unit_price.toFixed(2)}</td>`;
                    poTableBody.appendChild(tr);
                });
                // GRN table
                grnTableBody.innerHTML = '';
                grnLines.forEach(line => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${line.id}</td>` +
                        `<td>${line.grn_number}</td>` +
                        `<td>${line.po_line_id !== null ? line.po_line_id : ''}</td>` +
                        `<td>${line.po_description || ''}</td>` +
                        `<td>${numberFmt(line.qty_received)}</td>` +
                        `<td>${numberFmt(line.qty_matched)}</td>` +
                        `<td>${numberFmt(line.qty_available)}</td>`;
                    grnTableBody.appendChild(tr);
                });
                // Bill table
                billTableBody.innerHTML = '';
                billLines.forEach(line => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${line.id}</td>` +
                        `<td>${line.invoice_number || ''}</td>` +
                        `<td>${line.description || ''}</td>` +
                        `<td>${numberFmt(line.qty)}</td>` +
                        `<td>${numberFmt(line.qty_matched)}</td>` +
                        `<td>${numberFmt(line.qty_available)}</td>` +
                        `<td>${line.unit_price.toFixed(2)}</td>`;
                    billTableBody.appendChild(tr);
                });
            }

            function populateSelects() {
                // Populate PO line select
                poLineSelect.innerHTML = '<option value="">--none--</option>';
                poLines.forEach(line => {
                    if (line.qty_available > 0) {
                        const opt = document.createElement('option');
                        opt.value = line.id;
                        opt.textContent = `${line.po_number} – ${line.description} (avail: ${numberFmt(line.qty_available)})`;
                        opt.dataset.available = line.qty_available;
                        poLineSelect.appendChild(opt);
                    }
                });
                // Populate GRN line select
                grnLineSelect.innerHTML = '<option value="">--none--</option>';
                grnLines.forEach(line => {
                    if (line.qty_available > 0) {
                        const opt = document.createElement('option');
                        opt.value = line.id;
                        opt.textContent = `${line.grn_number} – ${line.po_description || ''} (avail: ${numberFmt(line.qty_available)})`;
                        opt.dataset.available = line.qty_available;
                        grnLineSelect.appendChild(opt);
                    }
                });
                // Populate Bill line select
                billLineSelect.innerHTML = '<option value="">--none--</option>';
                billLines.forEach(line => {
                    if (line.qty_available > 0) {
                        const opt = document.createElement('option');
                        opt.value = line.id;
                        opt.textContent = `${line.invoice_number || ''} – ${line.description} (avail: ${numberFmt(line.qty_available)})`;
                        opt.dataset.available = line.qty_available;
                        billLineSelect.appendChild(opt);
                    }
                });
            }

            addMatchBtn.addEventListener('click', function() {
                const poId  = poLineSelect.value ? parseInt(poLineSelect.value) : null;
                const grnId = grnLineSelect.value ? parseInt(grnLineSelect.value) : null;
                const billId= billLineSelect.value ? parseInt(billLineSelect.value) : null;
                const qty   = parseFloat(qtyInput.value);
                if (!poId && !grnId && !billId) {
                    alert('Please select at least one of PO, GRN or Bill line');
                    return;
                }
                if (!qty || qty <= 0) {
                    alert('Please enter a valid quantity');
                    return;
                }
                // Validate available quantities
                function getLineAndReduce(arr, id, field) {
                    if (!id) return true;
                    const line = arr.find(l => l.id === id);
                    if (!line) return true;
                    if (line.qty_available < qty) {
                        alert('Quantity exceeds available on selected line');
                        return false;
                    }
                    // reduce available quantity
                    line.qty_available = parseFloat((line.qty_available - qty).toFixed(3));
                    return true;
                }
                if (!getLineAndReduce(poLines, poId) || !getLineAndReduce(grnLines, grnId) || !getLineAndReduce(billLines, billId)) {
                    return;
                }
                // Add to pending list
                pendingMatches.push({ po_line_id: poId, grn_line_id: grnId, bill_line_id: billId, qty: qty });
                // Refresh UI
                populateTables();
                populateSelects();
                qtyInput.value = '';
                // Append to matches table
                const tr = document.createElement('tr');
                const poText  = poId ? (poLines.find(l => l.id === poId).po_number + ' – ' + (poLines.find(l => l.id === poId).description || '')) : '';
                const grnText = grnId ? (grnLines.find(l => l.id === grnId).grn_number + ' – ' + (grnLines.find(l => l.id === grnId).po_description || '')) : '';
                const billText= billId ? (billLines.find(l => l.id === billId).invoice_number + ' – ' + (billLines.find(l => l.id === billId).description || '')) : '';
                tr.innerHTML = `<td>${poText}</td><td>${grnText}</td><td>${billText}</td><td>${numberFmt(qty)}</td><td><button type="button" class="removeMatchBtn">Remove</button></td>`;
                // store index on row
                const index = pendingMatches.length - 1;
                tr.dataset.index = index;
                matchesTableBody.appendChild(tr);
            });

            // Delegate remove buttons
            matchesTableBody.addEventListener('click', function(ev) {
                if (ev.target && ev.target.classList.contains('removeMatchBtn')) {
                    const row = ev.target.closest('tr');
                    const index = parseInt(row.dataset.index);
                    const match = pendingMatches[index];
                    // restore available quantities
                    function restoreQty(arr, id) {
                        if (!id) return;
                        const line = arr.find(l => l.id === id);
                        if (line) {
                            line.qty_available = parseFloat((line.qty_available + match.qty).toFixed(3));
                        }
                    }
                    restoreQty(poLines, match.po_line_id);
                    restoreQty(grnLines, match.grn_line_id);
                    restoreQty(billLines, match.bill_line_id);
                    // Remove from list
                    pendingMatches.splice(index, 1);
                    // Remove row
                    row.remove();
                    // refresh index attributes
                    Array.from(matchesTableBody.children).forEach((r, idx) => {
                        r.dataset.index = idx;
                    });
                    // refresh selects and tables
                    populateTables();
                    populateSelects();
                }
            });

            applyBtn.addEventListener('click', function() {
                if (pendingMatches.length === 0) {
                    alert('No matches to apply');
                    return;
                }
                // Disable button to prevent duplicate clicks
                applyBtn.disabled = true;
                fetch('/finances/ap/api/three_way_apply.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ matches: pendingMatches })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.error) {
                        alert(res.error);
                    } else {
                        alert('Matches applied: ' + res.inserted);
                        // reset
                        pendingMatches = [];
                        matchesTableBody.innerHTML = '';
                        // reload lines to update matched quantities
                        loadBtn.click();
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error applying matches');
                })
                .finally(() => {
                    applyBtn.disabled = false;
                });
            });
        })();
        </script>
    </div>
</main>
</body>
</html>