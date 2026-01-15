<?php
// inventory/index.php
// List inventory items for the current company with basic search and link to create/view

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
// Reuse finance permissions helper to restrict access to appropriate roles
require_once __DIR__ . '/../finances/permissions.php';

// Allow admin, bookkeeper and member roles to view/manage inventory
requireRoles(['admin', 'bookkeeper', 'member']);

$companyId = (int)$_SESSION['company_id'];

// Fetch all inventory items for this company along with on‑hand quantity
$stmt = $DB->prepare(
    "SELECT i.id, i.sku, i.name, i.uom, i.is_stocked,
            COALESCE(SUM(m.qty), 0) AS on_hand
       FROM inventory_items i
  LEFT JOIN inventory_movements m
         ON m.company_id = i.company_id AND m.item_id = i.id
      WHERE i.company_id = ?
   GROUP BY i.id, i.sku, i.name, i.uom, i.is_stocked
   ORDER BY i.name ASC"
);
$stmt->execute([$companyId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare CSRF token for AJAX requests
$csrfToken = $_SESSION['csrf_token'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title>Inventory – Flowwork</title>
    <link rel="stylesheet" href="/inventory/inventory.css?v=20251007">
</head>
<body>
    <div class="fw-inv">
        <h1 class="fw-inv__title">Inventory Items</h1>
        <div class="inv-toolbar">
            <input type="search" id="itemSearch" class="inv-search" placeholder="Search items..." autocomplete="off">
            <button id="btnNewItem" class="inv-btn inv-btn--primary">+ New Item</button>
        </div>
        <table id="itemsTable" class="inv-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Name</th>
                    <th>Unit</th>
                    <th>On Hand</th>
                    <th>Stocked</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr data-id="<?= (int)$item['id'] ?>">
                    <td><?= htmlspecialchars($item['sku']) ?></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['uom'] ?: 'ea') ?></td>
                    <td><?= number_format((float)$item['on_hand'], 3, '.', '') ?></td>
                    <td><?= $item['is_stocked'] ? 'Yes' : 'No' ?></td>
                    <td><a class="inv-view-link" href="/inventory/item_view.php?id=<?= (int)$item['id'] ?>">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script src="/inventory/js/inventory.js?v=20251007"></script>
</body>
</html>