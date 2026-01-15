<?php
// /shopping/list.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-SHOP-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$listId = intval($_GET['id'] ?? 0);

if (!$listId) {
    header('Location: /shopping/');
    exit;
}

// Fetch list
$stmt = $DB->prepare("
    SELECT l.*, u.first_name, u.last_name
    FROM shopping_lists l
    JOIN users u ON l.owner_id = u.id
    WHERE l.id = ? AND l.company_id = ?
");
$stmt->execute([$listId, $companyId]);
$list = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$list) {
    die('List not found');
}

// Fetch items
$stmt = $DB->prepare("
    SELECT i.*, p.name as project_name
    FROM shopping_items i
    LEFT JOIN projects p ON i.project_id = p.project_id
    WHERE i.list_id = ? AND i.status != 'removed'
    ORDER BY i.order_index, i.id
");
$stmt->execute([$listId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch suppliers (CRM accounts of type supplier) for PO conversion dropdown
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if in buying mode
$stmt = $DB->prepare("
    SELECT id, active_store_id 
    FROM shopping_sessions 
    WHERE list_id = ? AND ended_at IS NULL
    ORDER BY started_at DESC
    LIMIT 1
");
$stmt->execute([$listId]);
$activeSession = $stmt->fetch(PDO::FETCH_ASSOC);
$isBuying = !empty($activeSession);

// Determine user role for permissions (admin/bookkeeper can convert to PO)
$userRole = $_SESSION['role'] ?? 'member';

// Calculate stats
$totalItems = count($items);
$boughtItems = count(array_filter($items, fn($i) => $i['status'] === 'bought'));
$pendingItems = $totalItems - $boughtItems;
$progressPct = $totalItems > 0 ? round(($boughtItems / $totalItems) * 100) : 0;

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($list['name']) ?> – Shopping AI</title>
    <link rel="stylesheet" href="/shopping/assets/shopping.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-shopping">
        <div class="fw-shopping__container">
            
            <!-- Header -->
            <header class="fw-shopping__header">
                <div class="fw-shopping__brand">
                    <div class="fw-shopping__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 2L7.17 4H4c-1.1 0-2 .9-2 2v13c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" stroke="currentColor" stroke-width="1.5" fill="none"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" fill="none"/>
                        </svg>
                    </div>
                    <div class="fw-shopping__brand-text">
                        <div class="fw-shopping__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-shopping__app-name">Shopping AI</div>
                    </div>
                </div>

                <div class="fw-shopping__greeting">
                    Hello, <span class="fw-shopping__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-shopping__controls">
                    <a href="/shopping/" class="fw-shopping__home-btn" title="Back to Lists">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </a>

                    <a href="/" class="fw-shopping__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </a>
                    
                    <button class="fw-shopping__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--light" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>

                    <div class="fw-shopping__menu-wrapper">
                        <button class="fw-shopping__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-shopping__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="#" class="fw-shopping__kebab-item" onclick="alert('Export coming soon'); return false;">📄 Export PDF</a>
                            <a href="#" class="fw-shopping__kebab-item" onclick="alert('Share coming soon'); return false;">🔗 Share Link</a>
                            <a href="#" class="fw-shopping__kebab-item" onclick="alert('Convert to RFQ coming soon'); return false;">📋 Convert to RFQ</a>
                            <a href="#" class="fw-shopping__kebab-item" onclick="if(confirm('Delete list?')) location.href='/shopping/ajax/list_delete.php?id=<?=$listId?>'">🗑️ Delete List</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- List Header -->
            <div class="fw-shopping__list-header">
                <div class="fw-shopping__list-header-top">
                    <div class="fw-shopping__list-title-group">
                        <h1 class="fw-shopping__list-title"><?= htmlspecialchars($list['name']) ?></h1>
                        <div class="fw-shopping__list-meta">
                            <span class="fw-shopping__badge fw-shopping__badge--<?= $list['purpose'] ?>">
                                <?= ucfirst($list['purpose']) ?>
                            </span>
                            <span class="fw-shopping__list-meta-item">
                                Owner: <?= htmlspecialchars($list['first_name'] . ' ' . $list['last_name']) ?>
                            </span>
                            <span class="fw-shopping__list-meta-item">
                                Created: <?= date('Y-m-d', strtotime($list['created_at'])) ?>
                            </span>
                            <?php if ($list['budget_cents'] > 0): ?>
                                <span class="fw-shopping__list-meta-item">
                                    Budget: R<?= number_format($list['budget_cents'] / 100, 2) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fw-shopping__list-actions">
                        <?php if (!$isBuying): ?>
                            <button class="fw-shopping__btn fw-shopping__btn--success" id="btnStartBuying">
                                🛒 Start Buying
                            </button>
                        <?php else: ?>
                            <button class="fw-shopping__btn fw-shopping__btn--danger" id="btnStopBuying">
                                ⏹️ Stop Buying
                            </button>
                        <?php endif; ?>
                        
                        <button class="fw-shopping__btn fw-shopping__btn--secondary" onclick="location.reload()">
                            🔄 Refresh
                        </button>
                        <?php if (in_array($userRole, ['admin','bookkeeper'])): ?>
                            <select id="poSupplierSelect" class="fw-shopping__form-select" style="margin-left:12px; margin-right:8px; min-width:180px;">
                                <option value="">— Select Supplier —</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="fw-shopping__btn fw-shopping__btn--primary" id="btnCreatePO">
                                📦 Create PO
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Buying Mode Banner -->
            <?php if ($isBuying): ?>
            <div class="fw-shopping__buying-mode">
                <div class="fw-shopping__buying-mode-header">
                    <h3 class="fw-shopping__buying-mode-title">
                        🛒 Buying Mode Active
                    </h3>
                </div>
                <div class="fw-shopping__buying-mode-progress">
                    <div class="fw-shopping__buying-mode-progress-fill" style="width: <?= $progressPct ?>%"></div>
                </div>
                <div class="fw-shopping__buying-mode-stats">
                    <span><?= $boughtItems ?> / <?= $totalItems ?> items bought</span>
                    <span><?= $progressPct ?>% complete</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main Layout -->
            <div class="fw-shopping__list-layout">
                
                <!-- Items Panel -->
                <div class="fw-shopping__items-panel">
                    <div class="fw-shopping__items-toolbar">
                        <h3>Items (<?= $totalItems ?>)</h3>
                        <button class="fw-shopping__btn fw-shopping__btn--primary" onclick="document.getElementById('addItemForm').style.display='block'">
                            + Add Item
                        </button>
                    </div>

                    <!-- Add Item Form -->
                    <form id="addItemForm" class="fw-shopping__add-item-form" style="display: none;">
                        <div class="fw-shopping__form-row">
                            <div class="fw-shopping__form-group">
                                <label class="fw-shopping__form-label">Item Name</label>
                                <input type="text" name="name" class="fw-shopping__form-input" placeholder="e.g. PVC Elbow 20mm" required>
                            </div>
                            <div class="fw-shopping__form-group">
                                <label class="fw-shopping__form-label">Qty</label>
                                <input type="number" name="qty" class="fw-shopping__form-input" value="1" step="0.01" min="0.01" required>
                            </div>
                            <div class="fw-shopping__form-group">
                                <label class="fw-shopping__form-label">Unit</label>
                                <select name="unit" class="fw-shopping__form-select">
                                    <option value="ea">ea</option>
                                    <option value="m">m</option>
                                    <option value="mm">mm</option>
                                    <option value="cm">cm</option>
                                    <option value="kg">kg</option>
                                    <option value="g">g</option>
                                    <option value="L">L</option>
                                    <option value="ml">ml</option>
                                </select>
                            </div>
                            <div class="fw-shopping__form-group">
                                <label class="fw-shopping__form-label">Priority</label>
                                <select name="priority" class="fw-shopping__form-select">
                                    <option value="low">Low</option>
                                    <option value="med" selected>Med</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="fw-shopping__form-group">
                                <button type="submit" class="fw-shopping__btn fw-shopping__btn--primary">Add</button>
                            </div>
                        </div>
                    </form>

                    <!-- Items Table -->
                    <table class="fw-shopping__items-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">✓</th>
                                <th>Item</th>
                                <th style="width: 100px;">Qty</th>
                                <th style="width: 40px;">!</th>
                                <th style="width: 120px;">Needed By</th>
                                <th style="width: 150px;">Project</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 24px; color: var(--fw-text-muted);">
                                        No items yet. Add one above!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $rowClass = $item['status'] === 'bought' ? 'fw-shopping__item-row--bought' : '';
                                    $checked = $item['status'] === 'bought' ? 'checked' : '';
                                    $priorityClass = 'fw-shopping__item-priority--' . $item['priority'];
                                    ?>
                                    <tr class="<?= $rowClass ?>" data-item-id="<?= $item['id'] ?>">
                                        <td>
                                            <input type="checkbox" 
                                                   class="fw-shopping__item-checkbox" 
                                                   <?= $checked ?> 
                                                   onchange="ShoppingListPage.toggleCheck(<?= $item['id'] ?>, this.checked)">
                                        </td>
                                        <td>
                                            <div class="fw-shopping__item-name"><?= htmlspecialchars($item['name_raw']) ?></div>
                                            <?php if (!empty($item['notes'])): ?>
                                                <div style="font-size: 12px; color: var(--fw-text-muted);">
                                                    <?= htmlspecialchars($item['notes']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-shopping__item-qty">
                                            <?= $item['qty'] ?> <?= $item['unit'] ?>
                                        </td>
                                        <td>
                                            <span class="fw-shopping__item-priority <?= $priorityClass ?>"></span>
                                        </td>
                                        <td>
                                            <?= $item['needed_by'] ? date('Y-m-d', strtotime($item['needed_by'])) : '-' ?>
                                        </td>
                                        <td>
                                            <?= $item['project_name'] ? htmlspecialchars($item['project_name']) : '-' ?>
                                        </td>
                                        <td>
                                            <div class="fw-shopping__item-actions">
                                                <button class="fw-shopping__item-btn" 
                                                        onclick="ShoppingListPage.findStores(<?= $item['id'] ?>)"
                                                        title="Find stores">
                                                    🔍
                                                </button>
                                                <button class="fw-shopping__item-btn" 
                                                        onclick="ShoppingListPage.editItem(<?= $item['id'] ?>)"
                                                        title="Edit">
                                                    ✏️
                                                </button>
                                                <button class="fw-shopping__item-btn" 
                                                        onclick="ShoppingListPage.deleteItem(<?= $item['id'] ?>)"
                                                        title="Delete">
                                                    🗑️
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- AI Panel -->
                <div class="fw-shopping__ai-panel">
                    <div class="fw-shopping__ai-panel-header">
                        <div class="fw-shopping__ai-icon">🤖</div>
                        <h3 class="fw-shopping__ai-panel-title">AI Store Finder</h3>
                        <button class="fw-shopping__ai-refresh" onclick="location.reload()" title="Refresh">🔄</button>
                    </div>

                    <div id="aiSuggestions">
                        <div class="fw-shopping__ai-empty">
                            Select an item and click 🔍 to find stores
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <footer class="fw-shopping__footer">
            <span>Shopping AI v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </main>

    <script src="/shopping/assets/shopping.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        // Initialize list page
        ShoppingListPage.init(<?= $listId ?>);
        document.addEventListener('DOMContentLoaded', function() {
            var btnPo = document.getElementById('btnCreatePO');
            if (btnPo) {
                btnPo.addEventListener('click', async function() {
                    var supSelect = document.getElementById('poSupplierSelect');
                    var supplierId = supSelect ? supSelect.value : '';
                    if (!supplierId) {
                        showToast('Please select a supplier', 'error');
                        return;
                    }
                    // Disable button and show spinner
                    var originalHtml = btnPo.innerHTML;
                    btnPo.disabled = true;
                    btnPo.innerHTML = '<span class="fw-shopping__spinner"></span> Creating...';
                    try {
                        await ShoppingListPage.createPo(supplierId);
                    } finally {
                        btnPo.disabled = false;
                        btnPo.innerHTML = originalHtml;
                    }
                });
            }
        });
    </script>
</body>
</html>