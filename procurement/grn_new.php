<?php
// /procurement/grn_new.php – Create a Goods Received Note from a PO
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-GRN-NEW');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Get selected PO ID from query
$selectedPoId = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch available POs for selection (not cancelled)
$stmt = $DB->prepare(
    "SELECT id, po_number
     FROM purchase_orders
     WHERE company_id = ? AND status != 'cancelled'
     ORDER BY id DESC"
);
$stmt->execute([$companyId]);
$pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Data variables to populate when a PO is selected
$poLines = [];
$poInfo  = [];

if ($selectedPoId > 0) {
    // Ensure PO belongs to company
    $stmt = $DB->prepare("SELECT * FROM purchase_orders WHERE id = ? AND company_id = ?");
    $stmt->execute([$selectedPoId, $companyId]);
    $poInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($poInfo) {
        // Fetch PO lines and qty received to compute remainder
        $stmt = $DB->prepare("SELECT * FROM purchase_order_lines WHERE po_id = ? ORDER BY id");
        $stmt->execute([$selectedPoId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Received qty per line
        $stmt = $DB->prepare(
            "SELECT gl.po_line_id, SUM(gl.qty_received) AS qty_received
             FROM grn_lines gl
             JOIN goods_received_notes grn ON grn.id = gl.grn_id
             WHERE grn.po_id = ? AND grn.status != 'cancelled'
             GROUP BY gl.po_line_id"
        );
        $stmt->execute([$selectedPoId]);
        $recv = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recv[(int)$r['po_line_id']] = (float)$r['qty_received'];
        }
        foreach ($lines as $ln) {
            $ordered = (float)$ln['qty'];
            $received = $recv[$ln['id']] ?? 0.0;
            $remaining = max(0, $ordered - $received);
            // Only include lines with remaining quantity
            $poLines[] = [
                'id' => (int)$ln['id'],
                'description' => $ln['description'],
                'qty_ordered' => $ordered,
                'qty_received' => $received,
                'qty_remaining' => $remaining,
                'unit' => $ln['unit'],
                'unit_price' => (float)$ln['unit_price'],
                'tax_rate' => (float)$ln['tax_rate']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Goods Received Note – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
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
        table.grn-lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        table.grn-lines th, table.grn-lines td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
        }
        table.grn-lines th {
            background: var(--fw-bg-secondary);
        }
        table.grn-lines input {
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
                    <div class="fw-finance__company-name"><?php echo htmlspecialchars($companyName); ?></div>
                    <div class="fw-finance__app-name">New Goods Received Note</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?php echo htmlspecialchars($firstName); ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/procurement/po_list.php" class="fw-finance__back-btn" title="Back to PO List">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <?php if ($selectedPoId <= 0): ?>
                <form method="get" class="fw-finance__form">
                    <label>
                        Select Purchase Order
                        <select name="po_id" required>
                            <option value="">Select PO</option>
                            <?php foreach ($pos as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['po_number']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Load PO</button>
                </form>
            <?php else: ?>
                <h2>Goods Received Note for PO: <?php echo htmlspecialchars($poInfo['po_number']); ?></h2>
                <form id="grnForm" class="fw-finance__form" onsubmit="return false;">
                    <input type="hidden" id="poId" value="<?php echo (int)$selectedPoId; ?>">
                    <label>
                        GRN Number
                        <input type="text" id="grnNumber" placeholder="GRN-0001" required>
                    </label>
                    <label>
                        Notes
                        <textarea id="notes" rows="2"></textarea>
                    </label>
                    <table class="grn-lines" id="linesTable">
                        <thead>
                            <tr><th>Description</th><th>Qty Ordered</th><th>Qty Remaining</th><th>Qty Received</th><th>Unit</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($poLines as $ln): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ln['description']); ?></td>
                                <td><?php echo number_format($ln['qty_ordered'], 3); ?></td>
                                <td><?php echo number_format($ln['qty_remaining'], 3); ?></td>
                                <td>
                                    <input type="number" class="qtyReceived" data-po-line-id="<?php echo (int)$ln['id']; ?>" data-unit-price="<?php echo $ln['unit_price']; ?>" data-tax-rate="<?php echo $ln['tax_rate']; ?>" min="0" max="<?php echo $ln['qty_remaining']; ?>" step="0.001" value="<?php echo $ln['qty_remaining']; ?>">
                                </td>
                                <td><?php echo htmlspecialchars($ln['unit']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save GRN</button>
                    <div id="formMessage"></div>
                </form>
            <?php endif; ?>
        </div>
        <footer class="fw-finance__footer">
            <span>Procurement New GRN v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
<?php if ($selectedPoId > 0): ?>
<script src="/finances/assets/finance.js?v=<?php echo ASSET_VERSION; ?>"></script>
<script>
document.getElementById('grnForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var poId = document.getElementById('poId').value;
    var grnNumber = document.getElementById('grnNumber').value.trim();
    var notes = document.getElementById('notes').value.trim();
    if (!grnNumber) {
        showMessage('formMessage', 'Please enter a GRN number', 'error');
        return;
    }
    var qtyInputs = document.querySelectorAll('.qtyReceived');
    var lines = [];
    var subtotal = 0;
    var taxTotal = 0;
    qtyInputs.forEach(function(inp) {
        var qty = parseFloat(inp.value) || 0;
        if (qty <= 0) return;
        var poLineId = parseInt(inp.getAttribute('data-po-line-id'));
        var price    = parseFloat(inp.getAttribute('data-unit-price'));
        var taxRate  = parseFloat(inp.getAttribute('data-tax-rate'));
        lines.push({
            po_line_id: poLineId,
            qty_received: qty,
            unit_price: price,
            tax_rate: taxRate
        });
        var net = qty * price;
        var vat = (taxRate > 0) ? net * (taxRate / 100) : 0;
        subtotal += net;
        taxTotal += vat;
    });
    if (lines.length === 0) {
        showMessage('formMessage', 'Please enter at least one quantity to receive', 'error');
        return;
    }
    var total = subtotal + taxTotal;
    var payload = {
        header: {
            po_id: poId,
            grn_number: grnNumber,
            subtotal: subtotal,
            tax: taxTotal,
            total: total,
            notes: notes
        },
        lines: lines
    };
    try {
        var res = await fetch('/procurement/ajax/grn.create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var result = await res.json();
        if (result.ok) {
            showMessage('formMessage', 'Goods Received Note created successfully', 'success');
            setTimeout(function() {
                window.location.href = '/procurement/grn_view.php?id=' + result.grn_id;
            }, 1500);
        } else {
            showMessage('formMessage', result.error || 'Failed to create GRN', 'error');
        }
    } catch (err) {
        showMessage('formMessage', 'An error occurred while creating the GRN', 'error');
    }
});
</script>
<?php endif; ?>
</body>
</html>