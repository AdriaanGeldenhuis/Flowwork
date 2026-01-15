<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    header('Location: /projects/index.php');
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$COMPANY_ID]);
$companyName = $stmt->fetchColumn() ?: 'Company';

$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'boards', 'timeline', 'files', 'settings'];
if (!in_array($activeTab, $allowedTabs)) {
    $activeTab = 'overview';
}

$pageTitle = 'Project Details';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> – Flowwork</title>
    <link rel="stylesheet" href="/projects/assets/projects.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="fw-crm fw-proj">

<div class="fw-proj__inner">
    
    <!-- Header -->
    <header class="fw-crm__header">
        <div class="fw-crm__brand">
            <div class="fw-crm__logo-tile">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="fw-crm__brand-text">
                <div class="fw-crm__company-name"><?= htmlspecialchars($companyName) ?></div>
                <div class="fw-crm__app-name">PROJECTS</div>
            </div>
        </div>
        
        <h1 class="fw-crm__title">Project Details</h1>
        
        <div class="fw-crm__controls">
            <a href="/projects/index.php" class="fw-crm__home-btn" title="Back to Projects">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            
            <button class="fw-crm__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                <svg class="fw-crm__theme-icon fw-crm__theme-icon--light" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                    <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <svg class="fw-crm__theme-icon fw-crm__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                    <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            
            <div class="fw-crm__menu-wrapper">
                <button class="fw-crm__kebab-toggle" id="kebabToggle" aria-label="Menu">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                        <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                        <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                    </svg>
                </button>
                <nav class="fw-crm__kebab-menu" id="kebabMenu" aria-hidden="true">
                    <a href="/index.php" class="fw-crm__kebab-item">Dashboard</a>
                    <a href="/crm/index.php" class="fw-crm__kebab-item">CRM</a>
                    <a href="/settings.php" class="fw-crm__kebab-item">Settings</a>
                </nav>
            </div>
        </div>
    </header>
    
    <!-- Tabs -->
    <div class="fw-board-toolbar">
        <div class="fw-board-toolbar__left">
            <button class="fw-view-btn <?= $activeTab === 'overview' ? 'fw-view-btn--active' : '' ?>" onclick="window.location.href='/projects/view.php?project_id=<?= $projectId ?>'">
                <svg width="16" height="16" fill="currentColor">
                    <rect width="16" height="3" rx="1"/>
                    <rect y="6" width="16" height="3" rx="1"/>
                    <rect y="12" width="16" height="3" rx="1"/>
                </svg>
                Overview
            </button>
            <button class="fw-view-btn <?= $activeTab === 'boards' ? 'fw-view-btn--active' : '' ?>" onclick="window.location.href='/projects/view.php?project_id=<?= $projectId ?>&tab=boards'">
                <svg width="16" height="16" fill="currentColor">
                    <rect width="4" height="16" rx="1"/>
                    <rect x="6" width="4" height="16" rx="1"/>
                    <rect x="12" width="4" height="16" rx="1"/>
                </svg>
                Boards
            </button>
            <button class="fw-view-btn <?= $activeTab === 'timeline' ? 'fw-view-btn--active' : '' ?>" onclick="window.location.href='/projects/view.php?project_id=<?= $projectId ?>&tab=timeline'">
                <svg width="16" height="16" fill="currentColor">
                    <rect y="2" width="8" height="2" rx="1"/>
                    <rect x="4" y="6" width="10" height="2" rx="1"/>
                    <rect x="2" y="10" width="6" height="2" rx="1"/>
                </svg>
                Timeline
            </button>
            <button class="fw-view-btn <?= $activeTab === 'files' ? 'fw-view-btn--active' : '' ?>" onclick="window.location.href='/projects/view.php?project_id=<?= $projectId ?>&tab=files'">
                <svg width="16" height="16" fill="currentColor">
                    <path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9l-7-7z" stroke="currentColor" fill="none"/>
                    <path d="M13 2v7h7" stroke="currentColor" fill="none"/>
                </svg>
                Files
            </button>
            <button class="fw-view-btn <?= $activeTab === 'settings' ? 'fw-view-btn--active' : '' ?>" onclick="window.location.href='/projects/view.php?project_id=<?= $projectId ?>&tab=settings'">
                <svg width="16" height="16" fill="currentColor">
                    <circle cx="8" cy="8" r="3" stroke="currentColor" fill="none" stroke-width="2"/>
                    <path d="M8 1v2M8 13v2M15 8h-2M3 8H1M13.5 2.5l-1.4 1.4M3.9 12.1l-1.4 1.4M13.5 13.5l-1.4-1.4M3.9 3.9L2.5 2.5" stroke="currentColor" stroke-width="2"/>
                </svg>
                Settings
            </button>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="fw-board-container">
        <?php if ($activeTab === 'overview'): ?>
            <!-- OVERVIEW TAB WITH CHARTS -->
            <div class="proj-playground">
                <div class="proj-playground-header">
                    <h2>🎮 Interactive Analytics Playground</h2>
                    <div class="proj-playground-controls">
                        <button id="playgroundLock" class="fw-btn fw-btn--secondary">🔓 Unlock</button>
                        <button id="playgroundReset" class="fw-btn fw-btn--secondary">🔄 Reset Layout</button>
                        <button id="playgroundExport" class="fw-btn fw-btn--secondary">📤 Export Data</button>
                    </div>
                </div>
                <div class="proj-playground-board" id="playgroundBoard">
                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: rgba(255,255,255,0.5);">
                        Loading project data...
                    </div>
                </div>
            </div>
            
        <?php elseif ($activeTab === 'boards'): ?>
            <div id="boardsListView">
                <div class="fw-proj__loading">Loading boards...</div>
            </div>
            
        <?php elseif ($activeTab === 'timeline'): ?>
            <div class="fw-empty-state">
                <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;">📅</div>
                <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">Timeline View</div>
                <div style="font-size: 14px; opacity: 0.7;">Coming soon – Gantt chart integration</div>
            </div>
            
        <?php elseif ($activeTab === 'files'): ?>
            <div id="filesListView">
                <div class="fw-proj__loading">Loading files...</div>
            </div>
            
        <?php elseif ($activeTab === 'settings'): ?>
            <div id="projectSettingsView">
                <div class="fw-proj__loading">Loading settings...</div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modals -->
<div id="modalNewBoard" class="fw-cell-picker-overlay">
    <div class="fw-cell-picker" style="max-width: 700px; width: 90%;">
        <div class="fw-picker-header">
            <span>Create Board</span>
            <button class="fw-picker-close" onclick="ProjModal.close('modalNewBoard')">&times;</button>
        </div>
        <div class="fw-picker-body">
            <form id="formNewBoard">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                
                <div class="fw-form-group">
                    <label class="fw-label">Board Title <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="title" class="fw-input" required>
                </div>
                
                <div class="fw-form-group">
                    <label class="fw-label">Choose Template</label>
                    <div class="fw-template-grid">
                        <label class="fw-template-card">
                            <input type="radio" name="template" value="blank" checked>
                            <div class="fw-template-card__inner">
                                <div class="fw-template-icon">📋</div>
                                <div class="fw-template-title">Blank Board</div>
                                <div class="fw-template-desc">Start from scratch</div>
                            </div>
                        </label>
                        
                        <label class="fw-template-card">
                            <input type="radio" name="template" value="kanban">
                            <div class="fw-template-card__inner">
                                <div class="fw-template-icon">🎯</div>
                                <div class="fw-template-title">Kanban Board</div>
                                <div class="fw-template-desc">To Do, In Progress, Done</div>
                            </div>
                        </label>
                        
                        <label class="fw-template-card">
                            <input type="radio" name="template" value="quote">
                            <div class="fw-template-card__inner">
                                <div class="fw-template-icon">💰</div>
                                <div class="fw-template-title">Quote Tracker</div>
                                <div class="fw-template-desc">Track quotes & proposals</div>
                            </div>
                        </label>
                        
                        <label class="fw-template-card">
                            <input type="radio" name="template" value="invoice">
                            <div class="fw-template-card__inner">
                                <div class="fw-template-icon">🧾</div>
                                <div class="fw-template-title">Invoice Manager</div>
                                <div class="fw-template-desc">Manage invoices & payments</div>
                            </div>
                        </label>
                        
                        <label class="fw-template-card">
                            <input type="radio" name="template" value="procurement">
                            <div class="fw-template-card__inner">
                                <div class="fw-template-icon">📦</div>
                                <div class="fw-template-title">Procurement</div>
                                <div class="fw-template-desc">Purchase orders & suppliers</div>
                            </div>
                        </label>
                        
                        <label class="fw-template-card">
                            <input type="radio" name="template" value="construction">
                            <div class="fw-template-card__inner">
                                <div class="fw-template-icon">🏗️</div>
                                <div class="fw-template-title">Construction</div>
                                <div class="fw-template-desc">Tasks, materials, labour</div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="fw-form-group">
                    <label class="fw-label">Default View</label>
                    <select name="default_view" class="fw-input">
                        <option value="table">Table</option>
                        <option value="kanban">Kanban</option>
                        <option value="calendar">Calendar</option>
                        <option value="gantt">Gantt</option>
                    </select>
                </div>
                
                <div class="fw-form-message" id="newBoardMessage" style="display:none;"></div>
            </form>
        </div>
        <div class="fw-picker-actions">
            <button type="button" class="fw-btn fw-btn--secondary" onclick="ProjModal.close('modalNewBoard')">Cancel</button>
            <button type="submit" form="formNewBoard" class="fw-btn fw-btn--primary">Create Board</button>
        </div>
    </div>
</div>

<!-- Rename Board Modal -->
<div id="modalRenameBoard" class="fw-cell-picker-overlay">
    <div class="fw-cell-picker" style="max-width: 500px; width: 90%;">
        <div class="fw-picker-header">
            <span>✏️ Rename Board</span>
            <button class="fw-picker-close" onclick="ProjModal.close('modalRenameBoard')">&times;</button>
        </div>
        <form id="formRenameBoard" class="fw-picker-body">
            <input type="hidden" name="board_id" id="renameBoardId">
            
            <div class="fw-form-group">
                <label class="fw-label">Board Title <span style="color: #ef4444;">*</span></label>
                <input type="text" name="title" id="renameBoardTitle" class="fw-input" required autocomplete="off">
            </div>
            
            <div class="fw-form-message" id="renameBoardMessage" style="display:none;"></div>
        </form>
        <div class="fw-picker-actions">
            <button type="button" class="fw-btn fw-btn--secondary" onclick="ProjModal.close('modalRenameBoard')">Cancel</button>
            <button type="submit" form="formRenameBoard" class="fw-btn fw-btn--primary">💾 Save</button>
        </div>
    </div>
</div>

<script>
    const PROJECT_ID = <?= $projectId ?>;
    const ACTIVE_TAB = '<?= $activeTab ?>';
</script>
<script src="/projects/js/ui/proheader.js?v=<?= time() ?>"></script>
<script src="/projects/js/projects-view.js?v=<?= time() ?>"></script>

</body>
</html>