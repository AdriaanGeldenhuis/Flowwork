<?php
// inventory/item_new.php
// Screen for creating a new inventory item.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../finances/permissions.php';

// Allow admin, bookkeeper and member roles to create items
requireRoles(['admin', 'bookkeeper', 'member']);

// CSRF token for requests
$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title>New Inventory Item – Flowwork</title>
    <link rel="stylesheet" href="/inventory/inventory.css?v=20251007">
</head>
<body>
    <div class="fw-inv">
        <h1 class="fw-inv__title">New Inventory Item</h1>
        <form id="newItemForm" class="inv-form">
            <div class="inv-field">
                <label for="sku">SKU<span style="color:red">*</span></label><br>
                <input type="text" id="sku" name="sku" required>
            </div>
            <div class="inv-field">
                <label for="name">Name<span style="color:red">*</span></label><br>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="inv-field">
                <label for="uom">Unit of Measure</label><br>
                <input type="text" id="uom" name="uom" value="ea">
            </div>
            <div class="inv-field">
                <label>
                    <input type="checkbox" id="is_stocked" name="is_stocked" value="1" checked>
                    Stocked Item
                </label>
            </div>
            <div class="inv-actions">
                <button type="submit" class="inv-btn inv-btn--primary">Create Item</button>
                <button type="button" id="cancelBtn" class="inv-btn">Cancel</button>
            </div>
        </form>
    </div>
    <script>
    // Handle form submission for creating a new inventory item
    document.getElementById('newItemForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = e.target;
        const params = new URLSearchParams(new FormData(form));
        fetch('/inventory/ajax/item.create.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf"]').getAttribute('content')
            },
            body: params
        }).then(resp => resp.json()).then(data => {
            if (data && data.success) {
                // Redirect to view page for the new item
                window.location.href = '/inventory/item_view.php?id=' + data.item_id;
            } else {
                alert(data.message || 'Failed to create item');
            }
        }).catch(err => {
            console.error(err);
            alert('Unexpected error creating item');
        });
    });
    // Cancel button returns to inventory list
    document.getElementById('cancelBtn').addEventListener('click', function() {
        window.location.href = '/inventory/index.php';
    });
    </script>
</body>
</html>