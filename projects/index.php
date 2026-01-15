<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$COMPANY_ID]);
$companyName = $stmt->fetchColumn() ?: 'Company';

$pageTitle = 'All Projects';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> – Flowwork</title>
    <link rel="stylesheet" href="/projects/assets/projects.css?v=<?= time() ?>">
</head>
<body class="fw-crm fw-proj">    
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
        
        <h1 class="fw-crm__title">All Projects</h1>
        
        <div class="fw-crm__controls">
            <a href="/index.php" class="fw-crm__home-btn" title="Home">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
    
    <!-- Toolbar -->
    <div class="fw-board-toolbar">
        <div class="fw-board-toolbar__left">
            <div class="fw-search-wrapper">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="fw-search-icon">
                    <circle cx="7" cy="7" r="6"/>
                    <path d="M11 11l4 4"/>
                </svg>
                <input
                    id="projSearch"
                    type="text"
                    class="fw-search-input"
                    placeholder="Search projects..."
                    aria-label="Search projects"
                />
            </div>
        </div>
        
        <div class="fw-board-toolbar__right">
            <select id="projFilterStatus" class="fw-chip">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="on_hold">On Hold</option>
                <option value="cancelled">Cancelled</option>
            </select>
            
            <select id="projFilterManager" class="fw-chip">
                <option value="">All Managers</option>
            </select>
            
            <button id="btnGlobalSettings" class="fw-btn fw-btn--secondary">
                <svg width="16" height="16" fill="currentColor" style="margin-right: 8px;">
                    <circle cx="8" cy="8" r="3" stroke="currentColor" fill="none" stroke-width="2"/>
                    <path d="M8 1v2M8 13v2M15 8h-2M3 8H1M13.5 2.5l-1.4 1.4M3.9 12.1l-1.4 1.4M13.5 13.5l-1.4-1.4M3.9 3.9L2.5 2.5" stroke="currentColor" stroke-width="2"/>
                </svg>
                Settings
            </button>

            <button id="btnNewProject" class="fw-btn fw-btn--primary fw-btn--glossy">
                <svg width="16" height="16" fill="currentColor">
                    <path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
                New Project
            </button>
        </div>
    </div>
    
    <!-- Projects List -->
    <div class="fw-board-container">
        <div id="projectsList" class="fw-projects-grid">
            <div class="fw-proj__loading">Loading projects...</div>
        </div>
        
        <!-- Pagination -->
        <div id="projectsPagination" class="fw-proj__pagination"></div>
    </div>

</div>

<!-- New Project Modal -->
<div id="modalNewProject" class="fw-cell-picker-overlay">
    <div class="fw-cell-picker">
        <div class="fw-picker-header">
            <span>➕ Create New Project</span>
            <button class="fw-picker-close" onclick="ProjModal.close('modalNewProject')">&times;</button>
        </div>
        <form id="formNewProject" class="fw-picker-body">
            <div class="fw-form-group">
                <label class="fw-label">Project Name <span style="color: #ef4444;">*</span></label>
                <input type="text" name="name" class="fw-input" required autocomplete="off">
            </div>
            
            <div class="fw-form-row">
                <div class="fw-form-group">
                    <label class="fw-label">Start Date</label>
                    <input type="date" name="start_date" class="fw-input">
                </div>
                <div class="fw-form-group">
                    <label class="fw-label">End Date</label>
                    <input type="date" name="end_date" class="fw-input">
                </div>
            </div>
            
            <div class="fw-form-row">
                <div class="fw-form-group">
                    <label class="fw-label">Manager</label>
                    <select name="manager_user_id" id="selectManager" class="fw-input">
                        <option value="">Select manager...</option>
                    </select>
                </div>
                <div class="fw-form-group">
                    <label class="fw-label">Budget (R)</label>
                    <input type="number" name="budget" class="fw-input" min="0" step="100">
                </div>
            </div>
            
            <div class="fw-form-group">
                <label class="fw-label">Template</label>
                <select name="template" id="selectTemplate" class="fw-input">
                    <option value="">Blank</option>
                    <option value="construction_standard">Construction (Standard)</option>
                </select>
                <small style="color: var(--fw-text-tertiary); font-size: 12px; margin-top: 4px; display: block;">
                    Selecting a template will automatically create recommended boards and columns.
                </small>
            </div>
            
            <div class="fw-form-message" id="newProjectMessage" style="display:none;"></div>
        </form>
        <div class="fw-picker-actions">
            <button type="button" class="fw-btn fw-btn--secondary" onclick="ProjModal.close('modalNewProject')">Cancel</button>
            <button type="submit" form="formNewProject" class="fw-btn fw-btn--primary fw-btn--glossy">✨ Create Project</button>
        </div>
    </div>
</div>

<!-- Edit Project Modal -->
<div id="modalEditProject" class="fw-cell-picker-overlay">
    <div class="fw-cell-picker">
        <div class="fw-picker-header">
            <span>✏️ Edit Project</span>
            <button class="fw-picker-close" onclick="ProjModal.close('modalEditProject')">&times;</button>
        </div>
        <form id="formEditProject" class="fw-picker-body">
            <input type="hidden" name="project_id" id="editProjectId">
            
            <div class="fw-form-group">
                <label class="fw-label">Project Name <span style="color: #ef4444;">*</span></label>
                <input type="text" name="name" id="editProjectName" class="fw-input" required autocomplete="off">
            </div>
            
            <div class="fw-form-row">
                <div class="fw-form-group">
                    <label class="fw-label">Start Date</label>
                    <input type="date" name="start_date" id="editProjectStartDate" class="fw-input">
                </div>
                <div class="fw-form-group">
                    <label class="fw-label">End Date</label>
                    <input type="date" name="end_date" id="editProjectEndDate" class="fw-input">
                </div>
            </div>
            
            <div class="fw-form-row">
                <div class="fw-form-group">
                    <label class="fw-label">Manager</label>
                    <select name="manager_user_id" id="editProjectManager" class="fw-input">
                        <option value="">Select manager...</option>
                    </select>
                </div>
                <div class="fw-form-group">
                    <label class="fw-label">Budget (R)</label>
                    <input type="number" name="budget" id="editProjectBudget" class="fw-input" min="0" step="100">
                </div>
            </div>
            
            <div class="fw-form-message" id="editProjectMessage" style="display:none;"></div>
        </form>
        <div class="fw-picker-actions">
            <button type="button" class="fw-btn fw-btn--secondary" onclick="ProjModal.close('modalEditProject')">Cancel</button>
            <button type="submit" form="formEditProject" class="fw-btn fw-btn--primary">💾 Save Changes</button>
        </div>
    </div>
</div>

<!-- Initialize theme from COOKIE -->
<script>
(function() {
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }
    
    const savedTheme = getCookie('fw_theme') || 'light';
    const root = document.querySelector('.fw-proj');
    if (root) {
        root.setAttribute('data-theme', savedTheme);
        console.log('✅ Projects: Theme loaded from cookie:', savedTheme);
    }
})();
</script>

<script src="/projects/js/ui/proheader.js?v=<?= time() ?>"></script>
<script src="/projects/js/projects-index.js?v=<?= time() ?>"></script>

<!-- Global Settings Modal -->
<div id="modalGlobalSettings" class="fw-cell-picker-overlay">
    <div class="fw-cell-picker" style="max-width: 800px; width: 90%;">
        <div class="fw-picker-header">
            <span>⚙️ Projects Settings</span>
            <button class="fw-picker-close" onclick="ProjModal.close('modalGlobalSettings')">&times;</button>
        </div>
        <div class="fw-picker-body">
            
            <div class="fw-settings-section">
                <div class="fw-settings-section__header">
                    <span class="fw-settings-section__icon">📋</span>
                    <div>
                        <h3 class="fw-settings-section__title">Default Board Templates</h3>
                        <p class="fw-settings-section__desc">Manage available templates for new boards</p>
                    </div>
                </div>
                
                <div class="fw-global-settings-grid">
                    <div class="fw-setting-card">
                        <div class="fw-setting-card__icon">🎯</div>
                        <h4 class="fw-setting-card__title">Kanban</h4>
                        <p class="fw-setting-card__desc">To Do, In Progress, Done columns</p>
                        <div class="fw-toggle active"></div>
                    </div>
                    
                    <div class="fw-setting-card">
                        <div class="fw-setting-card__icon">💰</div>
                        <h4 class="fw-setting-card__title">Quote Tracker</h4>
                        <p class="fw-setting-card__desc">Client, Amount, Status, Date</p>
                        <div class="fw-toggle active"></div>
                    </div>
                    
                    <div class="fw-setting-card">
                        <div class="fw-setting-card__icon">🧾</div>
                        <h4 class="fw-setting-card__title">Invoice Manager</h4>
                        <p class="fw-setting-card__desc">Invoice #, Client, Amount, Paid</p>
                        <div class="fw-toggle active"></div>
                    </div>
                    
                    <div class="fw-setting-card">
                        <div class="fw-setting-card__icon">📦</div>
                        <h4 class="fw-setting-card__title">Procurement</h4>
                        <p class="fw-setting-card__desc">Supplier, PO #, Items, Status</p>
                        <div class="fw-toggle active"></div>
                    </div>
                </div>
            </div>
            
        </div>
        <div class="fw-picker-actions">
            <button type="button" class="fw-btn fw-btn--secondary" onclick="ProjModal.close('modalGlobalSettings')">Close</button>
            <button type="button" class="fw-btn fw-btn--primary">💾 Save Settings</button>
        </div>
    </div>
</div>

</body>
</html>