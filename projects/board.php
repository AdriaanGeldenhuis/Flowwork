<?php
/**
 * Board View - Main Project Board Interface
 * Complete board management with groups, items, and dynamic columns
 */

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get board ID
$boardId = (int)($_GET['board_id'] ?? 0);
if (!$boardId) {
    header('Location: /projects/index.php');
    exit;
}

// Session variables
$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];
$USER_ROLE = $_SESSION['role'] ?? 'viewer';

// ===== LOAD BOARD =====
$stmt = $DB->prepare("
    SELECT pb.*, p.name AS project_name, p.project_id
    FROM project_boards pb
    JOIN projects p ON pb.project_id = p.project_id
    WHERE pb.board_id = ? AND pb.company_id = ?
");
$stmt->execute([$boardId, $COMPANY_ID]);
$board = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$board) {
    die('Board not found');
}

// ===== CHECK PERMISSIONS =====
$stmt = $DB->prepare("
    SELECT role FROM project_members
    WHERE project_id = ? AND user_id = ? AND company_id = ?
");
$stmt->execute([$board['project_id'], $USER_ID, $COMPANY_ID]);
$memberRole = $stmt->fetchColumn();

if (!$memberRole && $USER_ROLE !== 'admin') {
    die('Access denied');
}

// ===== LOAD COLUMNS =====
// All columns are loaded and rendered; hidden ones (visible = 0) are collapsed
// via the #fw-col-visibility stylesheet below so the client can show/hide
// columns without a reload (see js/modules/column-visibility.js).
$stmt = $DB->prepare("
    SELECT * FROM board_columns
    WHERE board_id = ? AND company_id = ?
    ORDER BY position
");
$stmt->execute([$boardId, $COMPANY_ID]);
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create default columns if none exist
if (empty($columns)) {
    $defaultColumns = [
        ['name' => 'Status', 'type' => 'status', 'width' => 150, 'config' => null],
        ['name' => 'Assignee', 'type' => 'people', 'width' => 180, 'config' => null],
        ['name' => 'Due Date', 'type' => 'date', 'width' => 150, 'config' => null],
        ['name' => 'Priority', 'type' => 'priority', 'width' => 150, 'config' => null],
        ['name' => 'Quantity', 'type' => 'number', 'width' => 120, 'config' => json_encode(['agg' => 'sum', 'precision' => 0])],
        ['name' => 'Price/Unit', 'type' => 'number', 'width' => 120, 'config' => json_encode(['agg' => 'avg', 'precision' => 2])],
        ['name' => 'Total', 'type' => 'formula', 'width' => 120, 'config' => json_encode(['formula' => '{Quantity} * {Price/Unit}', 'agg' => 'sum', 'precision' => 2])],
        ['name' => 'Notes', 'type' => 'text', 'width' => 200, 'config' => null],
    ];

    foreach ($defaultColumns as $idx => $colDef) {
        $stmt = $DB->prepare("
            INSERT INTO board_columns (board_id, company_id, name, type, width, position, visible, config, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([
            $boardId, $COMPANY_ID, $colDef['name'], $colDef['type'], $colDef['width'], $idx, $colDef['config']
        ]);
    }

    // Reload columns
    $stmt = $DB->prepare("
        SELECT * FROM board_columns
        WHERE board_id = ? AND company_id = ?
        ORDER BY position
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Hidden-column ids for the visibility stylesheet
$HIDDEN_COLUMN_IDS = array_values(array_map(
    fn($c) => (int)$c['column_id'],
    array_filter($columns, fn($c) => !(int)$c['visible'])
));

// ===== LOAD GROUPS =====
$stmt = $DB->prepare("
    SELECT * FROM board_groups 
    WHERE board_id = ? 
    ORDER BY position
");
$stmt->execute([$boardId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create default group if none exist
if (empty($groups)) {
    $stmt = $DB->prepare("
        INSERT INTO board_groups (board_id, name, color, position, collapsed, created_at)
        VALUES (?, 'Tasks', '#8b5cf6', 0, 0, NOW())
    ");
    $stmt->execute([$boardId]);

    $stmt = $DB->prepare("
        SELECT * FROM board_groups 
        WHERE board_id = ? 
        ORDER BY position
    ");
    $stmt->execute([$boardId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== LOAD ITEMS =====
// Server-render up to ITEM_RENDER_CAP rows; larger boards page the rest in
// through api/board.load.php (BoardApp.loadMoreItems). The cap must be a
// multiple of the load-more page size (100).
define('ITEM_RENDER_CAP', 500);

$stmt = $DB->prepare("
    SELECT COUNT(*) FROM board_items
    WHERE board_id = ? AND company_id = ? AND archived = 0
");
$stmt->execute([$boardId, $COMPANY_ID]);
$TOTAL_ITEMS = (int)$stmt->fetchColumn();

$stmt = $DB->prepare("
    SELECT bi.*, u.first_name, u.last_name, bg.name AS group_name
    FROM board_items bi
    LEFT JOIN users u ON bi.assigned_to = u.id
    LEFT JOIN board_groups bg ON bi.group_id = bg.id
    WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
    ORDER BY bi.group_id, bi.position, bi.id
    LIMIT " . ITEM_RENDER_CAP . "
");
$stmt->execute([$boardId, $COMPANY_ID]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ITEMS_TRUNCATED = $TOTAL_ITEMS > count($items);

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

// ===== FORMULA VALUES =====
// Formula results are persisted to board_item_values on every cell write
// (api/cell/update.php) and by formula/calculate.php, so page loads normally
// just read them out of $valuesMap. Values not yet persisted (legacy data)
// are computed here through the sandboxed engine in api/_formula.php —
// the old inline @eval loop is gone.
require_once __DIR__ . '/api/_formula.php';

$colNameMap = [];
foreach ($columns as $c) {
    $colNameMap[$c['name']] = $c['column_id'];
}

foreach ($columns as $c) {
    if ($c['type'] !== 'formula') continue;

    $cfg = $c['config'] ? json_decode($c['config'], true) : [];
    $formulaStr = $cfg['formula'] ?? '';
    $precision = isset($cfg['precision']) ? (int)$cfg['precision'] : 2;

    foreach ($items as $it) {
        $iid = $it['id'];

        // Persisted result available — nothing to compute
        if (isset($valuesMap[$iid][$c['column_id']]) && $valuesMap[$iid][$c['column_id']] !== '') {
            continue;
        }

        $ctx = [];
        if (isset($valuesMap[$iid])) {
            foreach ($valuesMap[$iid] as $cid => $val) {
                $ctx[$cid] = is_numeric($val) ? (float)$val : 0.0;
            }
        }

        if (!isset($valuesMap[$iid])) $valuesMap[$iid] = [];
        $valuesMap[$iid][$c['column_id']] = _fw_compute_formula($formulaStr, $ctx, $colNameMap, $precision);
    }
}

// Column types drive which auxiliary datasets are worth loading at all
$COLUMN_TYPES = array_column($columns, 'type');

// ===== LOAD ATTACHMENTS (only when a files column exists) =====
$attachmentsMap = [];

if (!empty($itemIds) && in_array('files', $COLUMN_TYPES, true)) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $DB->prepare("
        SELECT item_id, id, file_name, file_path, file_size
        FROM board_item_attachments
        WHERE item_id IN ($placeholders)
    ");
    $stmt->execute($itemIds);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $attRow) {
        $id = (int)$attRow['item_id'];
        if (!isset($attachmentsMap[$id])) $attachmentsMap[$id] = [];
        $attachmentsMap[$id][] = [
            'id' => (int)$attRow['id'],
            'file_name' => $attRow['file_name'],
            'file_path' => $attRow['file_path'],
            'file_size' => (int)$attRow['file_size'],
        ];
    }
}

// ===== LOAD COMMENT COUNTS =====
$commentCountsMap = [];
if (!empty($itemIds)) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $DB->prepare("
        SELECT item_id, COUNT(*) AS n
        FROM board_item_comments
        WHERE item_id IN ($placeholders)
        GROUP BY item_id
    ");
    $stmt->execute($itemIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $commentCountsMap[(int)$row['item_id']] = (int)$row['n'];
    }
}

// ===== BOARD TOTALS OVERRIDE (SQL over ALL items when the render is capped) =====
// The in-PHP totals loop only sees the rendered subset; on truncated boards
// compute number/formula aggregates in SQL so BOARD TOTALS stays correct.
$boardTotalsOverride = [];
if ($ITEMS_TRUNCATED) {
    $aggCols = array_values(array_filter($columns, fn($c) => in_array($c['type'], ['number', 'formula'], true)));
    if ($aggCols) {
        $colIds = array_map(fn($c) => (int)$c['column_id'], $aggCols);
        $ph = implode(',', array_fill(0, count($colIds), '?'));
        $stmt = $DB->prepare("
            SELECT v.column_id,
                   SUM(v.value + 0) AS agg_sum,
                   AVG(v.value + 0) AS agg_avg,
                   MIN(v.value + 0) AS agg_min,
                   MAX(v.value + 0) AS agg_max,
                   COUNT(*) AS agg_count
            FROM board_item_values v
            JOIN board_items bi ON v.item_id = bi.id
            WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
              AND v.column_id IN ($ph)
            GROUP BY v.column_id
        ");
        $stmt->execute(array_merge([$boardId, $COMPANY_ID], $colIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $aggRow) {
            $boardTotalsOverride[(int)$aggRow['column_id']] = $aggRow;
        }
    }
}

// ===== LAST AUDIT ID (cursor for near-real-time polling) =====
$stmt = $DB->prepare("SELECT COALESCE(MAX(id), 0) FROM board_audit_log WHERE board_id = ?");
$stmt->execute([$boardId]);
$LAST_AUDIT_ID = (int)$stmt->fetchColumn();

// ===== LOAD USERS (email is not needed by any board renderer) =====
$stmt = $DB->prepare("
    SELECT id, first_name, last_name
    FROM users
    WHERE company_id = ? AND status = 'active'
    ORDER BY first_name
");
$stmt->execute([$COMPANY_ID]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== LOAD SUPPLIERS (only when a supplier column exists) =====
$suppliers = [];
if (in_array('supplier', $COLUMN_TYPES, true)) {
    $stmt = $DB->prepare("
        SELECT id, name, phone, email, preferred
        FROM crm_accounts
        WHERE company_id = ? AND type = 'supplier' AND status = 'active'
        ORDER BY preferred DESC, name ASC
    ");
    $stmt->execute([$COMPANY_ID]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== STATUS CONFIG =====
$statusConfig = [
    'todo' => ['label' => 'To Do', 'color' => '#64748b'],
    'working' => ['label' => 'Working', 'color' => '#fdab3d'],
    'stuck' => ['label' => 'Stuck', 'color' => '#e2445c'],
    'done' => ['label' => 'Done', 'color' => '#00c875'],
];

// ===== COMPANY NAME =====
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$COMPANY_ID]);
$companyName = $stmt->fetchColumn() ?: 'Company';

// Asset cache busting: derive the version from the newest JS/CSS mtime so a
// deploy invalidates caches automatically (no more hand-bumped constants).
function fw_asset_version(): string {
    static $ver = null;
    if ($ver !== null) return $ver;
    $latest = 0;
    foreach (array_merge(
        glob(__DIR__ . '/assets/*.css') ?: [],
        glob(__DIR__ . '/js/*.js') ?: [],
        glob(__DIR__ . '/js/modules/*.js') ?: [],
        glob(__DIR__ . '/js/ui/*.js') ?: []
    ) as $f) {
        $mtime = @filemtime($f);
        if ($mtime && $mtime > $latest) $latest = $mtime;
    }
    return $ver = (string)($latest ?: time());
}
define('ASSET_VERSION', fw_asset_version());

// Board CSS is split into layered files; linked individually (in cascade order)
// instead of via @import so each gets the cache-busting version.
$BOARD_CSS_FILES = [
    'base.css', 'theme.css', 'layout.css', 'table.css', 'components.css',
    'modals.css', 'views.css', 'mobile.css',
    'board-3d.css', // 3D skin — must stay after the files it overlays
    'board.css',    // board-specific overrides — always last
];

// Initial view: the URL param wins, then the board's stored default.
// (The client may still override with the user's last-used view.)
$ALLOWED_VIEWS = ['table', 'kanban', 'calendar', 'gantt'];
$DEFAULT_VIEW = in_array($board['default_view'] ?? '', $ALLOWED_VIEWS, true) ? $board['default_view'] : 'table';
$INITIAL_VIEW = in_array($_GET['view'] ?? '', $ALLOWED_VIEWS, true) ? $_GET['view'] : $DEFAULT_VIEW;

// Theme rendered server-side from the cookie so the page paints in the right
// theme immediately (theme.css defaults .fw-proj to dark; without this, light
// users got a dark flash until header.js ran)
$THEME = ($_COOKIE['fw_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title><?= htmlspecialchars($board['title']) ?> – Flowwork</title>
    
<?php foreach ($BOARD_CSS_FILES as $cssFile): ?>
    <link rel="stylesheet" href="/projects/assets/<?= $cssFile ?>?v=<?= ASSET_VERSION ?>">
<?php endforeach; ?>
    <style id="fw-col-visibility">
<?php foreach ($HIDDEN_COLUMN_IDS as $hiddenId): ?>
        .fw-board-table [data-column-id="<?= $hiddenId ?>"] { display: none; }
<?php endforeach; ?>
    </style>
</head>
<body class="fw-board-body" data-board-id="<?= $boardId ?>">

<div class="fw-proj" data-theme="<?= $THEME ?>">
    
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
                <div class="fw-board-header__company"><?= htmlspecialchars($companyName) ?></div>
                <div class="fw-board-header__app">PROJECTS</div>
            </div>
        </div>

        <div class="fw-board-header__center">
            <h1 class="fw-board-title-display" style="margin:0;font-size:inherit;font-weight:inherit;">
                <?= htmlspecialchars($board['title']) ?>
            </h1>
        </div>

        <div class="fw-board-header__controls">
            <a href="/projects/index.php" class="fw-board-header__btn" title="Back to Projects">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>

            <a href="/index.php" class="fw-board-header__btn" title="Home">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
            </a>

            <button class="fw-board-header__btn" id="themeToggle" title="Toggle Theme" type="button">
                <svg class="fw-theme-icon fw-theme-icon--light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <svg class="fw-theme-icon fw-theme-icon--dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>

            <button class="fw-board-header__btn" 
                    onclick="BoardGuests.showModal()" 
                    title="Guest Access" 
                    type="button">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="8.5" cy="7" r="4"/>
                    <path d="M20 8v6M23 11h-6"/>
                </svg>
            </button>

            <div class="fw-board-header__menu-wrapper">
                <button class="fw-board-header__btn" id="boardMenuToggle" aria-expanded="false" title="Menu" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                        <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                        <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                    </svg>
                </button>
                <nav class="fw-board-header__menu" id="boardMenu" aria-hidden="true">
                    <button onclick="BoardApp.showBoardMembers()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="8.5" cy="7" r="4"/>
                            <path d="M20 8v6M23 11h-6"/>
                        </svg>
                        Manage Members
                    </button>
                    <button onclick="BoardApp.showActivityFeed()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        Activity Feed
                    </button>
                    <button onclick="BoardApp.showColumnVisibility()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        Column Visibility
                    </button>
                    <button onclick="BoardApp.exportBoard()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Export Board
                    </button>
                    <button onclick="BoardApp.showImportModal()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Import Items
                    </button>
                    <button onclick="BoardApp.duplicateBoard()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        Duplicate Board
                    </button>
                    <button onclick="BoardApp.showBoardSettings()" class="fw-board-header__menu-item" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m5.08 5.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m5.08-5.08l4.24-4.24"/>
                        </svg>
                        Board Settings
                    </button>
                    <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.1); margin: 8px 0;">
                    <button onclick="BoardApp.archiveBoard()" class="fw-board-header__menu-item fw-board-header__menu-item--danger" type="button">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                        Archive Board
                    </button>
                </nav>
            </div>
        </div>
    </header>

    <!-- ===== TOOLBAR ===== -->
    <div class="fw-board-toolbar">
        <div class="fw-board-toolbar__left">
            <button class="fw-view-btn<?= $INITIAL_VIEW === 'table' ? ' fw-view-btn--active' : '' ?>" data-view="table" aria-label="Table view" aria-pressed="<?= $INITIAL_VIEW === 'table' ? 'true' : 'false' ?>" onclick="BoardApp.switchView('table')">
                <svg width="16" height="16" fill="currentColor">
                    <rect width="16" height="3" rx="1"/>
                    <rect y="6" width="16" height="3" rx="1"/>
                    <rect y="12" width="16" height="3" rx="1"/>
                </svg>
                Table
            </button>
            <button class="fw-view-btn<?= $INITIAL_VIEW === 'kanban' ? ' fw-view-btn--active' : '' ?>" data-view="kanban" aria-label="Kanban view" aria-pressed="<?= $INITIAL_VIEW === 'kanban' ? 'true' : 'false' ?>" onclick="BoardApp.switchView('kanban')">
                <svg width="16" height="16" fill="currentColor">
                    <rect width="4" height="16" rx="1"/>
                    <rect x="6" width="4" height="16" rx="1"/>
                    <rect x="12" width="4" height="16" rx="1"/>
                </svg>
                Kanban
            </button>
            <button class="fw-view-btn<?= $INITIAL_VIEW === 'calendar' ? ' fw-view-btn--active' : '' ?>" data-view="calendar" aria-label="Calendar view" aria-pressed="<?= $INITIAL_VIEW === 'calendar' ? 'true' : 'false' ?>" onclick="BoardApp.switchView('calendar')">
                <svg width="16" height="16" fill="currentColor">
                    <rect x="1" y="3" width="14" height="12" rx="1" stroke="currentColor" fill="none"/>
                    <path d="M1 6h14M5 1v4M11 1v4"/>
                </svg>
                Calendar
            </button>
            <button class="fw-view-btn<?= $INITIAL_VIEW === 'gantt' ? ' fw-view-btn--active' : '' ?>" data-view="gantt" aria-label="Gantt view" aria-pressed="<?= $INITIAL_VIEW === 'gantt' ? 'true' : 'false' ?>" onclick="BoardApp.switchView('gantt')">
                <svg width="16" height="16" fill="currentColor">
                    <rect y="2" width="8" height="2" rx="1"/>
                    <rect x="4" y="6" width="10" height="2" rx="1"/>
                    <rect x="2" y="10" width="6" height="2" rx="1"/>
                </svg>
                Gantt
            </button>
        </div>

        <div class="fw-board-toolbar__right">
            <div class="fw-search-wrapper">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="fw-search-icon">
                    <circle cx="7" cy="7" r="6"/>
                    <path d="M11 11l4 4"/>
                </svg>
                <input id="boardSearchInput"
                       type="text"
                       class="fw-search-input"
                       placeholder="Search items..."
                       aria-label="Search items"
                       oninput="BoardApp.onSearchInput(this.value)" />
                <button id="boardSearchClear"
                        type="button"
                        class="fw-search-clear"
                        aria-label="Clear search"
                        style="display:none;"
                        onclick="BoardApp.clearSearch()">&times;</button>
            </div>
            <button id="filterChip" class="fw-chip" onclick="BoardApp.showFilterModal()">
                <svg width="14" height="14" fill="currentColor">
                    <path d="M0 1h14M2 5h10M4 9h6"/>
                </svg>
                Filters
            </button>
            <button class="fw-btn fw-btn--secondary" onclick="BoardApp.showViewsModal()">
                <svg width="14" height="14" fill="currentColor">
                    <path d="M2 2h10v2H2zM2 6h10v2H2zM2 10h10v2H2z"/>
                </svg>
                Views
            </button>
        </div>
    </div>

    <?php if ($ITEMS_TRUNCATED): ?>
    <!-- ===== TRUNCATION BANNER ===== -->
    <div class="fw-truncation-banner" id="truncationBanner" role="status"
         style="display:flex;align-items:center;justify-content:center;gap:12px;padding:8px 16px;background:rgba(245,158,11,0.12);border-bottom:1px solid rgba(245,158,11,0.3);font-size:13px;font-weight:600;">
        <span>Showing <span id="loadedItemCount"><?= count($items) ?></span> of <?= $TOTAL_ITEMS ?> items.</span>
        <button type="button" class="fw-btn fw-btn--secondary" id="loadMoreItemsBtn" onclick="BoardApp.loadMoreItems()" style="padding:4px 14px;">Load more</button>
    </div>
    <?php endif; ?>

    <!-- ===== BULK ACTION BAR ===== -->
    <div class="fw-bulk-action-bar" id="bulkActionBar">
        <span class="fw-bulk-count" id="bulkCount">0 selected</span>
        
        <button class="fw-bulk-btn" onclick="BoardApp.bulkUpdateStatus()">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            Update Status
        </button>
        
        <button class="fw-bulk-btn" onclick="BoardApp.bulkAssign()">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
            </svg>
            Assign
        </button>
        
        <button class="fw-bulk-btn" onclick="BoardApp.bulkMove()">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                <path d="M10 9h4V6h3l-5-5-5 5h3v3zm-1 1H6V7l-5 5 5 5v-3h3v-4zm14 2l-5-5v3h-3v4h3v3l5-5zm-9 3h-4v3H7l5 5 5-5h-3v-3z"/>
            </svg>
            Move
        </button>
        
        <button class="fw-bulk-btn fw-bulk-btn--danger" onclick="BoardApp.bulkDelete()">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
            </svg>
            Delete
        </button>
        
        <button class="fw-bulk-btn" onclick="BoardApp.clearBulkSelection()">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
            Clear
        </button>
    </div>

    <!-- ===== BOARD CONTAINER ===== -->
    <main class="fw-board-container" id="boardContainer" aria-label="Board items">
        <?php foreach ($groups as $group): ?>
            <?php $groupItems = array_filter($items, fn($item) => $item['group_id'] == $group['id']); ?>
            
            <div class="fw-group" 
                 id="group-<?= $group['id'] ?>" 
                 data-group-id="<?= $group['id'] ?>" 
                 data-collapsed="<?= $group['collapsed'] ? 'true' : 'false' ?>">
                
                <!-- Group Header -->
                <div class="fw-group-header" style="border-left-color: <?= htmlspecialchars($group['color'] ?: '#8b5cf6') ?>;">
                    <button type="button" class="fw-group-toggle" aria-label="Toggle group" onclick="BoardApp.toggleGroup(<?= $group['id'] ?>)">
                        <svg width="12" height="12" fill="currentColor">
                            <path d="M3 6l3 3 3-3"/>
                        </svg>
                    </button>

                    <input type="text" 
                           class="fw-group-name" 
                           value="<?= htmlspecialchars($group['name']) ?>" 
                           style="color: <?= htmlspecialchars($group['color'] ?: '#8b5cf6') ?>;" 
                           onblur="BoardApp.updateGroupName(<?= $group['id'] ?>, this.value)" />

                    <span class="fw-group-count"><?= count($groupItems) ?></span>

                    <button type="button" class="fw-icon-btn" aria-label="Group actions" onclick="BoardApp.showGroupMenu(<?= $group['id'] ?>, event)">
                        <svg width="16" height="16" fill="currentColor">
                            <circle cx="8" cy="3" r="1.5"/>
                            <circle cx="8" cy="8" r="1.5"/>
                            <circle cx="8" cy="13" r="1.5"/>
                        </svg>
                    </button>
                </div>

                <!-- Group Content -->
                <div class="fw-group-content">
                    <div class="fw-table-wrapper">
                        <table class="fw-board-table">
                            <colgroup>
    					<col style="width: 40px;">
    					<col style="width: min(25vw, 300px); min-width: 120px;">
    					<?php foreach ($columns as $col): ?>
       			 		  <col data-column-id="<?= (int)$col['column_id'] ?>" style="width: <?= (int)$col['width'] ?>px;">
    					<?php endforeach; ?>
    					<col style="width: 50px;">
				</colgroup>
                            <thead>
                                <tr>
                                    <th class="fw-col-checkbox" scope="col">
                                        <input type="checkbox" class="fw-checkbox" aria-label="Select all in <?= htmlspecialchars($group['name']) ?>" onchange="BoardApp.toggleGroupSelection(<?= $group['id'] ?>, this.checked)" />
                                    </th>
                                    
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
                                                       onblur="BoardApp.updateColumnName(<?= $col['column_id'] ?>, this.value)" />
                                                <button type="button" class="fw-icon-btn fw-col-menu-btn" aria-label="Column options" onclick="BoardApp.showColumnMenu(<?= $col['column_id'] ?>, event)">
                                                    <svg width="14" height="14" fill="currentColor">
                                                        <circle cx="7" cy="3" r="1.2"/>
                                                        <circle cx="7" cy="7" r="1.2"/>
                                                        <circle cx="7" cy="11" r="1.2"/>
                                                    </svg>
                                                </button>
                                            </div>
                                            <div class="fw-col-resize" data-column-id="<?= $col['column_id'] ?>"></div>
                                        </th>
                                    <?php endforeach; ?>

                                    <th class="fw-col-add">
                                        <button type="button" class="fw-col-add-btn" aria-label="Add column" onclick="BoardApp.showAddColumnModal()">+</button>
                                    </th>
                                </tr>
                            </thead>

                            <?php
                            // Collapsed groups skip row rendering entirely (data-lazy);
                            // groups.js hydrates them from BOARD_DATA on first expand.
                            $isLazy = !empty($group['collapsed']) && !empty($groupItems);
                            ?>
                            <tbody<?= $isLazy ? ' data-lazy="1"' : '' ?>>
                                <?php if (empty($groupItems)): ?>
                                    <tr>
                                        <td colspan="<?= 3 + count($columns) ?>" class="fw-empty-state">
                                            <div class="fw-empty-icon">📋</div>
                                            <div class="fw-empty-title">No items yet</div>
                                            <div class="fw-empty-text">Click "+ Add item" below to get started</div>
                                        </td>
                                    </tr>
                                <?php elseif (!$isLazy): ?>
                                    <?php foreach ($groupItems as $item): ?>
                                        <tr class="fw-item-row" 
                                            data-item-id="<?= $item['id'] ?>" 
                                            data-group-id="<?= $group['id'] ?>" 
                                            draggable="true">
                                            
                                            <td class="fw-col-checkbox">
                                                <input type="checkbox"
                                                       class="fw-checkbox fw-item-checkbox"
                                                       data-item-id="<?= $item['id'] ?>"
                                                       aria-label="Select <?= htmlspecialchars($item['title']) ?>"
                                                       onchange="BoardApp.toggleItemSelection(<?= $item['id'] ?>, this.checked)" />
                                            </td>
                                            
                                            <td class="fw-col-item">
                                                <input type="text"
                                                       class="fw-item-title"
                                                       value="<?= htmlspecialchars($item['title']) ?>"
                                                       aria-label="Item title"
                                                       onblur="BoardApp.updateItemTitle(<?= $item['id'] ?>, this.value)" />
                                                <?php $cCount = $commentCountsMap[(int)$item['id']] ?? 0; ?>
                                                <button type="button"
                                                        class="fw-item-comments-btn"
                                                        data-item-id="<?= $item['id'] ?>"
                                                        aria-label="Comments (<?= $cCount ?>)"
                                                        <?= $cCount === 0 ? 'style="display:none;"' : '' ?>
                                                        onclick="BoardApp.showComments(<?= $item['id'] ?>)">💬 <span class="fw-item-comments-count"><?= $cCount ?></span></button>
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
                                                    data-value="<?= htmlspecialchars($value ?? '') ?>"
                                                    data-label="<?= htmlspecialchars($col['name']) ?>"
                                                    tabindex="0"
                                                    onclick="BoardApp.editCell(<?= $item['id'] ?>, <?= $col['column_id'] ?>, '<?= $col['type'] ?>', event)">

                                                    <?php include __DIR__ . '/includes/cell-renderer.php'; ?>

                                                </td>
                                            <?php endforeach; ?>

                                            <td class="fw-col-menu">
                                                <button type="button" class="fw-icon-btn" aria-label="Item actions" onclick="BoardApp.showItemMenu(<?= $item['id'] ?>, event)">
                                                    <svg width="14" height="14" fill="currentColor">
                                                        <circle cx="7" cy="3" r="1.2"/>
                                                        <circle cx="7" cy="7" r="1.2"/>
                                                        <circle cx="7" cy="11" r="1.2"/>
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <!-- GROUP AGGREGATION ROW -->
                                <tr class="fw-agg-row fw-group-agg-row" data-group-id="<?= $group['id'] ?>">
                                    <td class="fw-col-checkbox"></td>
                                    <td class="fw-col-item">
                                        <span class="fw-agg-label">
                                            <svg width="14" height="14" fill="currentColor" style="opacity: 0.6;">
                                                <path d="M2 4h10M2 7h10M2 10h10" stroke="currentColor" stroke-width="1.5"/>
                                            </svg>
                                            Summary
                                        </span>
                                    </td>
                                    
                                    <?php foreach ($columns as $col): ?>
                                        <td class="fw-agg-cell" 
                                            data-type="<?= $col['type'] ?>" 
                                            data-column-id="<?= $col['column_id'] ?>"
                                            data-group-id="<?= $group['id'] ?>">
                                            <?php
                                            if (in_array($col['type'], ['number', 'formula'])) {
                                                $config = $col['config'] ? json_decode($col['config'], true) : [];
                                                $aggType = $config['agg'] ?? 'sum';
                                                
                                                $values = [];
                                                foreach ($groupItems as $item) {
                                                    if (isset($valuesMap[$item['id']][$col['column_id']])) {
                                                        $val = $valuesMap[$item['id']][$col['column_id']];
                                                        if (is_numeric($val)) {
                                                            $values[] = (float)$val;
                                                        }
                                                    }
                                                }
                                                
                                                $result = 0;
                                                if (!empty($values)) {
                                                    switch ($aggType) {
                                                        case 'sum': $result = array_sum($values); break;
                                                        case 'avg': $result = array_sum($values) / count($values); break;
                                                        case 'min': $result = min($values); break;
                                                        case 'max': $result = max($values); break;
                                                        case 'count': $result = count($values); break;
                                                    }
                                                }
                                                
                                                $precision = $config['precision'] ?? 2;
                                                $formatted = number_format($result, $precision, '.', ',');

                                                $affix = isset($config['affix']) ? (string)$config['affix'] : '';
                                                $affixPos = (isset($config['affixPosition']) && $config['affixPosition'] === 'suffix') ? 'suffix' : 'prefix';
                                                if ($affix !== '') {
                                                    $affixHtml = htmlspecialchars($affix);
                                                    $formatted = $affixPos === 'prefix' ? $affixHtml . $formatted : $formatted . $affixHtml;
                                                }

                                                echo '<span class="fw-agg-value">';
                                                echo '<span class="fw-agg-type">' . strtoupper($aggType) . '</span>';
                                                echo $formatted;
                                                echo '</span>';
                                            } else {
                                                echo '<span class="fw-agg-empty">—</span>';
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <td class="fw-col-menu"></td>
                                </tr>

                                <!-- QUICK ADD ROW -->
                                <tr class="fw-add-row">
                                    <td colspan="<?= 3 + count($columns) ?>">
                                        <input type="text"
                                               class="fw-quick-add-input"
                                               placeholder="+ Add item"
                                               aria-label="Add item to <?= htmlspecialchars($group['name']) ?>"
                                               data-group-id="<?= $group['id'] ?>"
                                               onkeydown="if(event.key==='Enter') BoardApp.quickAddItem(this, <?= $group['id'] ?>)" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                                                            
                </div>
            </div>
        <?php endforeach; ?>

        <!-- ✅ BOARD TOTALS -->
        <div class="fw-group fw-board-totals-group" data-group-id="totals">
            <div class="fw-group-header" style="border-left-color: #8b5cf6;">
                <button class="fw-group-toggle" disabled style="opacity: 0.3; cursor: not-allowed;">
                    <svg width="12" height="12" fill="currentColor">
                        <path d="M3 6l3 3 3-3"/>
                    </svg>
                </button>

                <input type="text" 
                       class="fw-group-name" 
                       value="BOARD TOTALS" 
                       readonly
                       style="color: #8b5cf6; cursor: default; max-width: none;" />

                <span class="fw-group-count"><?= count($items) ?></span>

                <button class="fw-icon-btn" onclick="BoardApp.showAggregationSettings()">
                    <svg width="16" height="16" fill="currentColor">
                        <circle cx="8" cy="3" r="1.5"/>
                        <circle cx="8" cy="8" r="1.5"/>
                        <circle cx="8" cy="13" r="1.5"/>
                    </svg>
                </button>
            </div>

            <div class="fw-group-content">
                <div class="fw-table-wrapper">
                    <table class="fw-board-table">
                        <colgroup>
                            <col style="width: 50px;">
                            <col style="min-width: 200px; max-width: 25vw;">
                            <?php foreach ($columns as $col): ?>
                                <col data-column-id="<?= (int)$col['column_id'] ?>" style="width: <?= (int)$col['width'] ?>px;">
                            <?php endforeach; ?>
                            <col style="width: 50px;">
                        </colgroup>
                        
                        <thead>
                            <tr>
                                <th class="fw-col-checkbox">
                                    <input type="checkbox" class="fw-checkbox" disabled style="opacity: 0.3;" />
                                </th>
                                
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
                                            <button class="fw-icon-btn" style="visibility: hidden;">
                                                <svg width="14" height="14" fill="currentColor">
                                                    <circle cx="7" cy="3" r="1.2"/>
                                                    <circle cx="7" cy="7" r="1.2"/>
                                                    <circle cx="7" cy="11" r="1.2"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>

                                <th class="fw-col-add"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr class="fw-agg-row fw-board-agg-row">
                                <td class="fw-col-checkbox"></td>
                                
                                <td class="fw-col-item">
                                    <span class="fw-agg-label">
                                        <svg width="14" height="14" fill="currentColor" style="opacity: 0.6;">
                                            <path d="M2 4h10M2 7h10M2 10h10" stroke="currentColor" stroke-width="1.5"/>
                                        </svg>
                                        Summary
                                    </span>
                                </td>
                                
                                <?php foreach ($columns as $col): ?>
                                    <td class="fw-agg-cell fw-board-agg-cell" 
                                        data-type="<?= $col['type'] ?>" 
                                        data-column-id="<?= $col['column_id'] ?>">
                                        <?php
                                        if (in_array($col['type'], ['number', 'formula'])) {
                                            $config = $col['config'] ? json_decode($col['config'], true) : [];
                                            $aggType = $config['agg'] ?? 'sum';

                                            if (isset($boardTotalsOverride[(int)$col['column_id']])) {
                                                // Truncated board: totals were computed in SQL over ALL items
                                                $aggRow = $boardTotalsOverride[(int)$col['column_id']];
                                                $result = (float)($aggRow['agg_' . $aggType] ?? $aggRow['agg_sum']);
                                            } else {
                                                $values = [];
                                                foreach ($items as $item) {
                                                    if (isset($valuesMap[$item['id']][$col['column_id']])) {
                                                        $val = $valuesMap[$item['id']][$col['column_id']];
                                                        if (is_numeric($val)) {
                                                            $values[] = (float)$val;
                                                        }
                                                    }
                                                }

                                                $result = 0;
                                                if (!empty($values)) {
                                                    switch ($aggType) {
                                                        case 'sum': $result = array_sum($values); break;
                                                        case 'avg': $result = array_sum($values) / count($values); break;
                                                        case 'min': $result = min($values); break;
                                                        case 'max': $result = max($values); break;
                                                        case 'count': $result = count($values); break;
                                                    }
                                                }
                                            }

                                            $precision = $config['precision'] ?? 2;
                                            $formatted = number_format($result, $precision, '.', ',');

                                            $affix = isset($config['affix']) ? (string)$config['affix'] : '';
                                            $affixPos = (isset($config['affixPosition']) && $config['affixPosition'] === 'suffix') ? 'suffix' : 'prefix';
                                            if ($affix !== '') {
                                                $affixHtml = htmlspecialchars($affix);
                                                $sep = '<span style="display:inline-block;width:0.25em;"></span>';
                                                $formatted = $affixPos === 'prefix' ? $affixHtml . $sep . $formatted : $formatted . $sep . $affixHtml;
                                            }

                                            echo '<span class="fw-agg-value fw-board-agg-value">';
                                            echo '<span class="fw-agg-type">' . strtoupper($aggType) . '</span>';
                                            echo '<strong>' . $formatted . '</strong>';
                                            echo '</span>';
                                        } else {
                                            echo '<span class="fw-agg-empty">—</span>';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                
                                <td class="fw-col-menu"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ADD GROUP -->
        <div class="fw-add-group-section">
            <button class="fw-btn fw-btn--primary" onclick="BoardApp.showAddGroupModal()">
                <svg width="16" height="16" fill="currentColor">
                    <path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="2.5"/>
                </svg>
                Add Group
            </button>
        </div>
    </main>

    <!-- VIEWS -->
    <div id="fw-kanban-view" class="fw-kanban-view" style="display:none;"></div>
    <div id="fw-calendar-view" class="fw-calendar-view" style="display:none;"></div>
    <div id="fw-gantt-view" class="fw-gantt-view" style="display:none;"></div>

    <!-- ✅ GLOBAL SCROLL BAR (FIXED BOTTOM) -->
    <div class="fw-scroll-sync-bar">
        <div class="fw-scroll-sync-bar__label">SCROLL</div>
        <div class="fw-scroll-sync-bar__track" id="globalScrollTrack">
            <div class="fw-scroll-sync-bar__thumb" id="globalScrollThumb"></div>
        </div>
        <div class="fw-scroll-sync-bar__info" id="scrollInfo">0%</div>
    </div>
</div>

<!-- ===== GUEST ACCESS MODAL ===== -->
<!-- fw-static-modal: server-rendered — generic overlay cleanup must never remove it -->
<div id="modalGuests" class="fw-cell-picker-overlay fw-static-modal" aria-hidden="true">
    <div class="fw-cell-picker" style="max-width: 900px; width: 90%;">
        <div class="fw-picker-header">
            <span>👥 Guest Access</span>
            <button class="fw-picker-close" onclick="BoardGuests.close()" type="button">&times;</button>
        </div>
        <div class="fw-picker-body" style="background: var(--fw-panel-bg);">
            
            <!-- Invite Form -->
            <div style="padding: 20px; border-bottom: 1px solid var(--fw-border); background: var(--fw-panel-bg);">
                <div style="display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: end;">
                    <div>
                        <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--fw-text-primary);">
                            Email Address
                        </label>
                        <input type="email" 
                               id="guestEmail" 
                               class="fw-input" 
                               placeholder="guest@example.com"
                               style="width: 100%; background: var(--fw-input-bg); color: var(--fw-text-primary); border: 1px solid var(--fw-border);">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--fw-text-primary);">
                            Expires In
                        </label>
                        <select id="guestExpiry" 
                                class="fw-input" 
                                style="width: 150px; background: var(--fw-input-bg); color: var(--fw-text-primary); border: 1px solid var(--fw-border);">
                            <option value="7">7 days</option>
                            <option value="30" selected>30 days</option>
                            <option value="90">90 days</option>
                            <option value="365">1 year</option>
                        </select>
                    </div>
                    <button onclick="BoardGuests.invite()" class="fw-btn fw-btn--primary" type="button">
                        📧 Send Invite
                    </button>
                </div>
                <small style="color: var(--fw-text-tertiary); font-size: 12px; margin-top: 12px; display: block;">
                    Guest will receive an email with a confirmation link. They'll have <strong>read-only</strong> access to this board.
                </small>
            </div>
            
            <!-- Guests List -->
            <div id="guestsList" style="padding: 20px; background: var(--fw-panel-bg); min-height: 200px; color: var(--fw-text-primary);">
                <div style="text-align: center; padding: 40px; color: var(--fw-text-tertiary);">
                    Loading guests...
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- ✅ CRITICAL: Apply theme immediately to prevent FOUC -->
<script>
(function() {
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }
    
    function setCookie(name, value, days = 365) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
    }
    
    // Apply theme immediately
    const savedTheme = getCookie('fw_theme') || 'light';
    const root = document.querySelector('.fw-proj');
    
    if (root) {
        root.setAttribute('data-theme', savedTheme);
        console.log('✅ Board: Theme applied from cookie:', savedTheme);
    }
})();
</script>

<!-- ✅ CRITICAL: Initialize 3-dot menu BEFORE module loader -->
<script>
(function() {
    'use strict';
    
    let menuInitialized = false;
    
    function initBoardMenuImmediate() {
        if (menuInitialized) {
            console.log('⚠️ Menu already initialized, skipping');
            return true;
        }
        
        const menuToggle = document.getElementById('boardMenuToggle');
        const menu = document.getElementById('boardMenu');
        
        if (!menuToggle || !menu) {
            console.warn('⚠️ Menu elements not found, retrying...');
            return false;
        }
        
        // ✅ FORCE menu to start closed
        menu.style.display = 'none';
        menu.setAttribute('aria-hidden', 'true');
        menuToggle.setAttribute('aria-expanded', 'false');
        
        console.log('✅ Menu forced to closed state');
        
        // Toggle menu
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const isHidden = menu.getAttribute('aria-hidden') === 'true';
            
            if (isHidden) {
                // Open menu
                menu.style.display = 'block';
                menu.setAttribute('aria-hidden', 'false');
                menuToggle.setAttribute('aria-expanded', 'true');
                console.log('🎛️ Menu opened');
            } else {
                // Close menu
                menu.style.display = 'none';
                menu.setAttribute('aria-hidden', 'true');
                menuToggle.setAttribute('aria-expanded', 'false');
                console.log('🎛️ Menu closed');
            }
        });
        
        // Close on outside click
        document.addEventListener('click', function(e) {
            const isOpen = menu.getAttribute('aria-hidden') === 'false';
            if (isOpen && !menu.contains(e.target) && !menuToggle.contains(e.target)) {
                menu.style.display = 'none';
                menu.setAttribute('aria-hidden', 'true');
                menuToggle.setAttribute('aria-expanded', 'false');
                console.log('🎛️ Menu closed (outside click)');
            }
        });
        
        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
                menu.style.display = 'none';
                menu.setAttribute('aria-hidden', 'true');
                menuToggle.setAttribute('aria-expanded', 'false');
                menuToggle.focus();
                console.log('🎛️ Menu closed (Escape key)');
            }
        });
        
        menuToggle.dataset.menuInitialized = 'true';
        menuInitialized = true;
        console.log('✅ 3-dot menu initialized immediately');
        return true;
    }
    
    // Try multiple times to ensure it works
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBoardMenuImmediate);
    } else {
        initBoardMenuImmediate();
    }
    
    // Fallback
    setTimeout(function() {
        if (!menuInitialized) {
            console.log('⏰ Delayed menu initialization attempt');
            initBoardMenuImmediate();
        }
    }, 500);
})();
</script>

<!-- ✅ Ensure modals start closed -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modals = document.querySelectorAll('.fw-cell-picker-overlay');
    modals.forEach(modal => {
        modal.setAttribute('aria-hidden', 'true');
    });
    console.log('✅ All modals initialized as closed');
});
</script>

<script>
window.BOARD_DATA = {
    boardId: <?= $boardId ?>,
    projectId: <?= $board['project_id'] ?>,
    defaultView: <?= json_encode($DEFAULT_VIEW) ?>,
    items: <?= json_encode($items) ?>,
    groups: <?= json_encode($groups) ?>,
    columns: <?= json_encode($columns) ?>,
    statusConfig: <?= json_encode($statusConfig) ?>,
    users: <?= json_encode($users) ?>,
    suppliers: <?= json_encode($suppliers) ?>,
    valuesMap: <?= json_encode($valuesMap) ?>,
    attachments: <?= json_encode($attachmentsMap) ?>,
    commentCounts: <?= json_encode($commentCountsMap ?: new stdClass()) ?>,
    lastAuditId: <?= $LAST_AUDIT_ID ?>,
    totalItems: <?= $TOTAL_ITEMS ?>,
    csrfToken: '<?= $_SESSION['csrf_token'] ?>',
    currentUserId: <?= (int)$USER_ID ?>
};
</script>

<?php
$jsFiles = [
    "main-board.js",
    "modules/api.js",
    "modules/ui.js",
    "modules/groups.js",
    "modules/items.js",
    "modules/columns.js",
    "modules/cells.js",
    "modules/formulas.js",
    "modules/kanban.js",
    "modules/calendar.js",
    "modules/gantt-full.js",
    "modules/realtime.js",
    "modules/bulk.js",
    "modules/shortcuts.js",
    "modules/filters.js",
    "modules/views.js",
    "modules/activity.js",
    "modules/comments.js",
    "modules/export.js",
    "modules/dragdrop.js",
    "modules/touch-dnd.js",
    "modules/column-dragdrop.js",
    "modules/group-dragdrop.js",
    "modules/column-visibility.js",
    "modules/guests.js",
    "ui/header.js",
    "modules/subitems.js",
    "modules/item-panel.js",
    "ui/scroll-sync.js"
];
$filesJson = json_encode($jsFiles, JSON_UNESCAPED_SLASHES);
$filesAttr = htmlspecialchars($filesJson, ENT_QUOTES, 'UTF-8');
?>
<script src="/projects/js/main.js?v=<?= ASSET_VERSION ?>"
        data-base="/projects/js"
        data-version="<?= ASSET_VERSION ?>"
        data-timeout="15000"
        data-files='<?= $filesAttr ?>'>
</script>

</body>
</html>