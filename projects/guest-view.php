<?php
/**
 * Guest View - Read-Only Board Access
 */
require_once __DIR__ . '/../init.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    header('Location: /projects/guest-login.php');
    exit;
}

// Fetch guest + board
$stmt = $DB->prepare("
    SELECT bg.*, pb.*, p.name as project_name, c.name as company_name
    FROM board_guests bg
    JOIN project_boards pb ON pb.board_id = bg.board_id
    JOIN companies c ON c.id = bg.company_id
    LEFT JOIN projects p ON p.project_id = pb.project_id
    WHERE bg.token = ? AND bg.status = 'active'
");
$stmt->execute([$token]);
$guest = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$guest) {
    header('Location: /projects/guest-login.php');
    exit;
}

// Check session
if (!isset($_SESSION['guest_id']) || $_SESSION['guest_id'] != $guest['id']) {
    header('Location: /projects/guest-login.php');
    exit;
}

// Check expiry
if ($guest['expires_at'] && strtotime($guest['expires_at']) < time()) {
    die('Access has expired');
}

// Log access
$stmt = $DB->prepare("
    UPDATE board_guests 
    SET last_access_at = NOW(), access_count = access_count + 1 
    WHERE token = ?
");
$stmt->execute([$token]);

$boardId = $guest['board_id'];
$companyId = $guest['company_id'];

// ===== LOAD COLUMNS =====
$stmt = $DB->prepare("
    SELECT * FROM board_columns
    WHERE board_id = ? AND company_id = ? AND visible = 1
    ORDER BY position
");
$stmt->execute([$boardId, $companyId]);
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== LOAD GROUPS =====
$stmt = $DB->prepare("
    SELECT * FROM board_groups 
    WHERE board_id = ? 
    ORDER BY position
");
$stmt->execute([$boardId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== LOAD ITEMS =====
$stmt = $DB->prepare("
    SELECT bi.*, u.first_name, u.last_name, bg.name AS group_name
    FROM board_items bi
    LEFT JOIN users u ON bi.assigned_to = u.id
    LEFT JOIN board_groups bg ON bi.group_id = bg.id
    WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
    ORDER BY bg.position, bi.position
    LIMIT 500
");
$stmt->execute([$boardId, $companyId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== LOAD ITEM VALUES =====
$itemIds = array_column($items, 'id');
$valuesMap = [];

if (!empty($itemIds)) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $DB->prepare("
        SELECT item_id, column_id, value
        FROM board_item_values
        WHERE item_id IN ($placeholders)
    ");
    $stmt->execute($itemIds);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $valuesMap[$row['item_id']][$row['column_id']] = $row['value'];
    }
}

// ===== STATUS CONFIG =====
$statusConfig = [
    'todo' => ['label' => 'To Do', 'color' => '#64748b'],
    'working' => ['label' => 'Working', 'color' => '#fdab3d'],
    'stuck' => ['label' => 'Stuck', 'color' => '#e2445c'],
    'done' => ['label' => 'Done', 'color' => '#00c875'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($guest['title']) ?> – Guest View</title>
    <link rel="stylesheet" href="/projects/assets/board.css?v=<?= time() ?>">
    <style>
        /* Make everything read-only */
        .fw-board-body--guest input,
        .fw-board-body--guest textarea,
        .fw-board-body--guest select,
        .fw-board-body--guest button:not(.guest-logout-btn) {
            pointer-events: none !important;
            opacity: 0.7;
        }
        
        .fw-board-body--guest .fw-cell {
            cursor: default !important;
        }
        
        .guest-logout-btn {
            pointer-events: auto !important;
            opacity: 1 !important;
            cursor: pointer !important;
        }
        
        /* Force scroll bars */
        .fw-table-wrapper {
            overflow-x: auto !important;
            overflow-y: visible !important;
        }
        
        /* Custom scrollbar styling */
        .fw-table-wrapper::-webkit-scrollbar {
            height: 12px;
        }
        
        .fw-table-wrapper::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
        }
        
        .fw-table-wrapper::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        
        .fw-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        /* Firefox scrollbar */
        .fw-table-wrapper {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.2) rgba(255, 255, 255, 0.05);
        }
    </style>
</head>
<body class="fw-board-body fw-board-body--guest" data-board-id="<?= $boardId ?>">

<div class="fw-proj" data-theme="dark">
    
    <!-- ===== HEADER ===== -->
    <header class="fw-board-header">
        <div class="fw-board-header__brand">
            <div class="fw-board-header__logo">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="3" width="7" height="7" rx="1" fill="currentColor"/>
                    <rect x="14" y="3" width="7" height="7" rx="1" fill="currentColor"/>
                    <rect x="3" y="14" width="7" height="7" rx="1" fill="currentColor"/>
                    <rect x="14" y="14" width="7" height="7" rx="1" fill="currentColor"/>
                </svg>
            </div>
            <div class="fw-board-header__text">
                <div class="fw-board-header__company"><?= htmlspecialchars($guest['company_name'] ?? 'Flowwork') ?></div>
                <div class="fw-board-header__app">GUEST VIEW</div>
            </div>
        </div>

        <div class="fw-board-header__center">
            <div class="fw-board-title-display">
                🔒 <?= htmlspecialchars($guest['title']) ?>
            </div>
        </div>

        <div class="fw-board-header__controls">
            <span style="padding: 8px 16px; background: rgba(255,255,255,0.1); border-radius: 6px; font-size: 12px; font-weight: 600;">
                👁️ Read-Only Access
            </span>
            
            <form action="/projects/guest-logout.php" method="POST" style="display: inline; margin: 0;">
                <button type="submit" class="fw-board-header__btn guest-logout-btn" title="Logout">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </button>
            </form>
        </div>
    </header>
    
    <!-- ===== BOARD CONTAINER ===== -->
    <div class="fw-board-container" id="boardContainer">
        <?php foreach ($groups as $group): ?>
            <?php $groupItems = array_filter($items, fn($item) => $item['group_id'] == $group['id']); ?>
            
            <div class="fw-group" id="group-<?= $group['id'] ?>" data-group-id="<?= $group['id'] ?>">
                
                <!-- Group Header -->
                <div class="fw-group-header" style="border-left-color: <?= htmlspecialchars($group['color'] ?: '#8b5cf6') ?>;">
                    <button class="fw-group-toggle" disabled style="opacity: 0.3; cursor: not-allowed;">
                        <svg width="12" height="12" fill="currentColor">
                            <path d="M3 6l3 3 3-3"/>
                        </svg>
                    </button>

                    <input type="text" 
                           class="fw-group-name" 
                           value="<?= htmlspecialchars($group['name']) ?>" 
                           readonly
                           style="color: <?= htmlspecialchars($group['color'] ?: '#8b5cf6') ?>;" />

                    <span class="fw-group-count"><?= count($groupItems) ?></span>
                </div>

                <!-- Group Content -->
                <div class="fw-group-content">
                    <div class="fw-table-wrapper">
                        <table class="fw-board-table">
                            <colgroup>
                                <col style="width: min(25vw, 300px); min-width: 120px;">
                                <?php foreach ($columns as $col): ?>
                                    <col style="width: <?= (int)$col['width'] ?>px;">
                                <?php endforeach; ?>
                            </colgroup>
                            
                            <thead>
                                <tr>
                                    <th class="fw-col-item">
                                        <div class="fw-col-header">
                                            <input type="text" class="fw-col-name-input" value="ITEM" readonly />
                                        </div>
                                    </th>

                                    <?php foreach ($columns as $col): ?>
                                        <th data-column-id="<?= $col['column_id'] ?>" 
                                            data-type="<?= htmlspecialchars($col['type']) ?>">
                                            <div class="fw-col-header">
                                                <input type="text" 
                                                       class="fw-col-name-input" 
                                                       value="<?= htmlspecialchars($col['name']) ?>" 
                                                       readonly />
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($groupItems)): ?>
                                    <tr>
                                        <td colspan="<?= 1 + count($columns) ?>" class="fw-empty-state">
                                            <div class="fw-empty-icon">📋</div>
                                            <div class="fw-empty-title">No items</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($groupItems as $item): ?>
                                        <tr class="fw-item-row" data-item-id="<?= $item['id'] ?>">
                                            
                                            <td class="fw-col-item">
                                                <input type="text" 
                                                       class="fw-item-title" 
                                                       value="<?= htmlspecialchars($item['title']) ?>" 
                                                       readonly />
                                            </td>

                                            <?php foreach ($columns as $col): ?>
                                                <?php
                                                $value = isset($valuesMap[$item['id']][$col['column_id']]) 
                                                    ? $valuesMap[$item['id']][$col['column_id']] 
                                                    : null;

                                                if ($col['type'] === 'people' && !$value && $item['assigned_to']) 
                                                    $value = $item['assigned_to'];
                                                if ($col['type'] === 'date' && !$value && $item['due_date']) 
                                                    $value = $item['due_date'];
                                                if ($col['type'] === 'priority' && !$value && $item['priority']) 
                                                    $value = $item['priority'];
                                                if ($col['type'] === 'status' && !$value && $item['status_label']) 
                                                    $value = $item['status_label'];
                                                ?>

                                                <td class="fw-cell" 
                                                    data-type="<?= $col['type'] ?>" 
                                                    data-item-id="<?= $item['id'] ?>" 
                                                    data-column-id="<?= $col['column_id'] ?>" 
                                                    data-value="<?= htmlspecialchars($value ?? '') ?>">

                                                    <?php include __DIR__ . '/includes/cell-renderer.php'; ?>

                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ✅ GLOBAL SCROLL BAR (FIXED BOTTOM) -->
    <div class="fw-scroll-sync-bar">
        <div class="fw-scroll-sync-bar__label">SCROLL</div>
        <div class="fw-scroll-sync-bar__track" id="globalScrollTrack">
            <div class="fw-scroll-sync-bar__thumb" id="globalScrollThumb"></div>
        </div>
        <div class="fw-scroll-sync-bar__info" id="scrollInfo">0%</div>
    </div>
    
</div>


<script>
window.BOARD_DATA = {
    readOnly: true,
    items: <?= json_encode($items ?? []) ?>,
    groups: <?= json_encode($groups ?? []) ?>,
    columns: <?= json_encode($columns ?? []) ?>,
    statusConfig: <?= json_encode($statusConfig) ?>
};

// ===== PERFECT 1:1 SCROLL SYNC =====
(function() {
    'use strict';
    
    const tables = document.querySelectorAll('.fw-table-wrapper');
    const scrollBar = document.querySelector('.fw-scroll-sync-bar');
    const track = document.getElementById('globalScrollTrack');
    const thumb = document.getElementById('globalScrollThumb');
    const info = document.getElementById('scrollInfo');
    
    if (!tables.length || !track || !thumb || !scrollBar) {
        console.warn('Scroll sync elements not found');
        return;
    }
    
    const firstTable = tables[0];
    let isThumbDragging = false;
    let dragStartX = 0;
    let dragStartScrollLeft = 0;
    
    // ===== UPDATE SCROLLBAR POSITION =====
    function updateScrollbar() {
        const scrollWidth = firstTable.scrollWidth;
        const clientWidth = firstTable.clientWidth;
        const scrollLeft = firstTable.scrollLeft;
        
        // Hide if no scroll needed
        if (scrollWidth <= clientWidth) {
            scrollBar.style.display = 'none';
            return;
        }
        
        scrollBar.style.display = 'flex';
        
        // Calculate thumb size and position
        const thumbWidth = (clientWidth / scrollWidth) * 100;
        const thumbLeft = (scrollLeft / scrollWidth) * 100;
        
        thumb.style.width = thumbWidth + '%';
        thumb.style.left = thumbLeft + '%';
        
        // Update percentage
        const scrollPercent = Math.round((scrollLeft / (scrollWidth - clientWidth)) * 100);
        info.textContent = scrollPercent + '%';
    }
    
    // ===== SYNC ALL TABLES ON SCROLL =====
    firstTable.addEventListener('scroll', () => {
        if (!isThumbDragging) {
            updateScrollbar();
            
            // Sync other tables
            tables.forEach(t => {
                if (t !== firstTable) {
                    t.scrollLeft = firstTable.scrollLeft;
                }
            });
        }
    });
    
    // ===== WINDOW RESIZE =====
    window.addEventListener('resize', updateScrollbar);
    
    // ===== THUMB DRAG START =====
    thumb.addEventListener('mousedown', (e) => {
        isThumbDragging = true;
        dragStartX = e.clientX;
        dragStartScrollLeft = firstTable.scrollLeft;
        
        document.body.style.cursor = 'grabbing';
        document.body.style.userSelect = 'none';
        
        e.preventDefault();
    });
    
    // ===== THUMB DRAG MOVE (1:1 PIXEL TRACKING) =====
    document.addEventListener('mousemove', (e) => {
        if (!isThumbDragging) return;
        
        const deltaX = e.clientX - dragStartX;
        const trackWidth = track.offsetWidth;
        const scrollWidth = firstTable.scrollWidth;
        const clientWidth = firstTable.clientWidth;
        const maxScroll = scrollWidth - clientWidth;
        
        // Calculate scroll ratio: how many pixels to scroll per pixel of thumb movement
        const scrollRatio = maxScroll / (trackWidth - thumb.offsetWidth);
        
        // Apply 1:1 movement
        const newScrollLeft = Math.max(0, Math.min(maxScroll, dragStartScrollLeft + (deltaX * scrollRatio)));
        
        // Update all tables immediately
        tables.forEach(t => {
            t.scrollLeft = newScrollLeft;
        });
        
        updateScrollbar();
    });
    
    // ===== THUMB DRAG END =====
    document.addEventListener('mouseup', () => {
        if (isThumbDragging) {
            isThumbDragging = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        }
    });
    
    // ===== TRACK CLICK (JUMP TO POSITION) =====
    track.addEventListener('mousedown', (e) => {
        if (e.target === thumb) return; // Don't handle if clicking thumb
        
        const trackRect = track.getBoundingClientRect();
        const clickX = e.clientX - trackRect.left;
        const thumbWidth = thumb.offsetWidth;
        
        // Calculate target position (center thumb on click)
        const targetThumbLeft = Math.max(0, Math.min(trackRect.width - thumbWidth, clickX - thumbWidth / 2));
        const percent = targetThumbLeft / (trackRect.width - thumbWidth);
        
        const scrollWidth = firstTable.scrollWidth;
        const clientWidth = firstTable.clientWidth;
        const targetScroll = percent * (scrollWidth - clientWidth);
        
        // Smooth scroll to position
        tables.forEach(t => {
            t.scrollTo({
                left: targetScroll,
                behavior: 'smooth'
            });
        });
    });
    
    // ===== INITIAL UPDATE =====
    setTimeout(() => {
        updateScrollbar();
        console.log('✅ Guest view scroll sync initialized (1:1 tracking)');
    }, 100);
    
})();
</script>

</body>
</html>