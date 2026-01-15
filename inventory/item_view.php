<?php
// inventory/item_view.php
// View and edit a single inventory item, and manage its stock movements.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../finances/permissions.php';

// Allow admin, bookkeeper and member roles to view/edit items
requireRoles(['admin', 'bookkeeper', 'member']);

// Validate item ID
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$itemId) {
    header('Location: /inventory/index.php');
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Fetch item data
$stmt = $DB->prepare(
    "SELECT id, sku, name, uom, is_stocked
       FROM inventory_items
      WHERE id = ? AND company_id = ?"
);
$stmt->execute([$itemId, $companyId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    // Item not found or belongs to another company
    header('Location: /inventory/index.php');
    exit;
}

// Fetch movement history
$stmt = $DB->prepare(
    "SELECT id, movement_date, qty, unit_cost, ref_type, ref_id
       FROM inventory_movements
      WHERE company_id = ? AND item_id = ?
   ORDER BY movement_date DESC, id DESC"
);
$stmt->execute([$companyId, $itemId]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title>Inventory Item – <?= htmlspecialchars($item['name']) ?></title>
    <link rel="stylesheet" href="/inventory/inventory.css?v=20251007">
</head>
<body>
    <div class="fw-inv">
        <h1 class="fw-inv__title">Inventory Item: <?= htmlspecialchars($item['name']) ?></h1>
        <form id="editItemForm" class="inv-form">
            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
            <div class="inv-field">
                <label for="sku">SKU<span style="color:red">*</span></label><br>
                <input type="text" id="sku" name="sku" value="<?= htmlspecialchars($item['sku']) ?>" required>
            </div>
            <div class="inv-field">
                <label for="name">Name<span style="color:red">*</span></label><br>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($item['name']) ?>" required>
            </div>
            <div class="inv-field">
                <label for="uom">Unit of Measure</label><br>
                <input type="text" id="uom" name="uom" value="<?= htmlspecialchars($item['uom'] ?: 'ea') ?>">
            </div>
            <div class="inv-field">
                <label>
                    <input type="checkbox" id="is_stocked" name="is_stocked" value="1" <?= $item['is_stocked'] ? 'checked' : '' ?>>
                    Stocked Item
                </label>
            </div>
            <div class="inv-actions">
                <button type="submit" class="inv-btn inv-btn--primary">Save Changes</button>
                <button type="button" id="backBtn" class="inv-btn">Back to List</button>
            </div>
        </form>

        <h2 class="fw-inv__title" style="margin-top:32px;">Movements</h2>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Qty</th>
                    <th>Unit Cost</th>
                    <th>Total</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $mv): ?>
                <tr>
                    <td><?= htmlspecialchars($mv['movement_date']) ?></td>
                    <td><?= number_format((float)$mv['qty'], 3, '.', '') ?></td>
                    <td><?= number_format((float)$mv['unit_cost'], 4, '.', '') ?></td>
                    <td><?= number_format((float)$mv['qty'] * (float)$mv['unit_cost'], 2, '.', '') ?></td>
                    <td><?= htmlspecialchars($mv['ref_type'] ?: '') ?><?= $mv['ref_id'] ? ' #'.htmlspecialchars($mv['ref_id']) : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="fw-inv__title" style="margin-top:32px;">Record Movement</h2>
        <form id="movementForm" class="inv-form">
            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
            <div class="inv-field">
                <label for="moveDate">Date<span style="color:red">*</span></label><br>
                <input type="date" id="moveDate" name="date" required>
            </div>
            <div class="inv-field">
                <label for="moveType">Type</label><br>
                <select id="moveType" name="type">
                    <option value="receive">Receive</option>
                    <option value="issue">Issue</option>
                </select>
            </div>
            <div class="inv-field">
                <label for="moveQty">Quantity<span style="color:red">*</span></label><br>
                <input type="number" id="moveQty" name="qty" step="0.001" min="0" value="0" required>
            </div>
            <div class="inv-field" id="unitCostField">
                <label for="moveUnitCost">Unit Cost<span style="color:red">*</span></label><br>
                <input type="number" id="moveUnitCost" name="unit_cost" step="0.0001" min="0" value="0">
            </div>
            <div class="inv-actions">
                <button type="submit" class="inv-btn inv-btn--primary">Add Movement</button>
            </div>
        </form>
    </div>
    <script>
    // Handle item update
    document.getElementById('editItemForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = e.target;
        const params = new URLSearchParams(new FormData(form));
        fetch('/inventory/ajax/item.update.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf"]').getAttribute('content')
            },
            body: params
        }).then(resp => resp.json()).then(data => {
            if (data && data.success) {
                alert('Item updated successfully');
                // Reload to reflect changes (e.g. name in header)
                window.location.reload();
            } else {
                alert(data.message || 'Failed to update item');
            }
        }).catch(err => {
            console.error(err);
            alert('Unexpected error updating item');
        });
    });
    // Back to list
    document.getElementById('backBtn').addEventListener('click', function () {
        window.location.href = '/inventory/index.php';
    });
    // Toggle unit cost visibility based on movement type
    const moveTypeSelect = document.getElementById('moveType');
    const unitCostField = document.getElementById('unitCostField');
    moveTypeSelect.addEventListener('change', function () {
        if (this.value === 'receive') {
            unitCostField.style.display = '';
            document.getElementById('moveUnitCost').required = true;
        } else {
            unitCostField.style.display = 'none';
            document.getElementById('moveUnitCost').required = false;
        }
    });
    // Initialize visibility on page load
    moveTypeSelect.dispatchEvent(new Event('change'));
    // Handle new movement
    document.getElementById('movementForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = e.target;
        const paramsObj = {};
        form.querySelectorAll('input, select').forEach(function (el) {
            paramsObj[el.name] = el.value;
        });
        // Quantity: ensure positive value; send negative for issue
        let qtyVal = parseFloat(paramsObj.qty);
        if (isNaN(qtyVal) || qtyVal <= 0) {
            alert('Quantity must be greater than 0');
            return;
        }
        if (paramsObj.type === 'issue') {
            qtyVal = -Math.abs(qtyVal);
        }
        const bodyParams = new URLSearchParams();
        bodyParams.append('item_id', paramsObj.item_id);
        bodyParams.append('date', paramsObj.date);
        bodyParams.append('qty', qtyVal);
        if (paramsObj.type === 'receive') {
            const uc = parseFloat(paramsObj.unit_cost);
            if (isNaN(uc) || uc <= 0) {
                alert('Unit cost must be greater than 0 for receipts');
                return;
            }
            bodyParams.append('unit_cost', paramsObj.unit_cost);
        }
        fetch('/inventory/ajax/movement.create.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf"]').getAttribute('content')
            },
            body: bodyParams
        }).then(resp => resp.json()).then(data => {
            if (data && data.success) {
                alert('Movement recorded');
                window.location.reload();
            } else {
                alert(data.message || 'Failed to record movement');
            }
        }).catch(err => {
            console.error(err);
            alert('Unexpected error recording movement');
        });
    });
    </script>
</body>
</html>