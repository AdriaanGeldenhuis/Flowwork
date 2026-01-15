<?php
// /crm/compliance.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check if user has admin rights
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$userRole = $stmt->fetchColumn();

if (!in_array($userRole, ['admin', 'owner'])) {
    header('Location: /crm/');
    exit;
}

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch compliance types
$stmt = $DB->prepare("
    SELECT 
        ct.*,
        COUNT(cd.id) as doc_count,
        SUM(CASE WHEN cd.status = 'expired' THEN 1 ELSE 0 END) as expired_count,
        SUM(CASE WHEN cd.status = 'expiring' THEN 1 ELSE 0 END) as expiring_count
    FROM crm_compliance_types ct
    LEFT JOIN crm_compliance_docs cd ON cd.type_id = ct.id AND cd.company_id = ct.company_id
    WHERE ct.company_id = ?
    GROUP BY ct.id
    ORDER BY ct.name ASC
");
$stmt->execute([$companyId]);
$complianceTypes = $stmt->fetchAll();

// Fetch summary stats
$stmt = $DB->prepare("
    SELECT 
        COUNT(*) as total_docs,
        SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid_count,
        SUM(CASE WHEN status = 'expiring' THEN 1 ELSE 0 END) as expiring_count,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count,
        SUM(CASE WHEN status = 'missing' THEN 1 ELSE 0 END) as missing_count
    FROM crm_compliance_docs
    WHERE company_id = ?
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Admin – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-crm">
    <div class="fw-crm__container">
        
        <!-- Header -->
        <header class="fw-crm__header">
            <div class="fw-crm__brand">
                <div class="fw-crm__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-crm__brand-text">
                    <div class="fw-crm__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-crm__app-name">CRM – Compliance Admin</div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
                <a href="/" class="fw-crm__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                        <a href="/crm/" class="fw-crm__kebab-item">Back to CRM</a>
                        <a href="/crm/settings.php" class="fw-crm__kebab-item">CRM Settings</a>
                        <a href="/admin/" class="fw-crm__kebab-item">Admin Panel</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Compliance Document Types</h1>
                <p class="fw-crm__page-subtitle">
                    Define required document types for suppliers (COC, Tax Clearance, BEE, etc.)
                </p>
            </div>

            <!-- Stats Cards -->
            <div class="fw-crm__stats-grid">
                <div class="fw-crm__stat-card">
                    <div class="fw-crm__stat-value"><?= $stats['total_docs'] ?? 0 ?></div>
                    <div class="fw-crm__stat-label">Total Documents</div>
                </div>
                <div class="fw-crm__stat-card fw-crm__stat-card--valid">
                    <div class="fw-crm__stat-value"><?= $stats['valid_count'] ?? 0 ?></div>
                    <div class="fw-crm__stat-label">Valid</div>
                </div>
                <div class="fw-crm__stat-card fw-crm__stat-card--expiring">
                    <div class="fw-crm__stat-value"><?= $stats['expiring_count'] ?? 0 ?></div>
                    <div class="fw-crm__stat-label">Expiring Soon</div>
                </div>
                <div class="fw-crm__stat-card fw-crm__stat-card--expired">
                    <div class="fw-crm__stat-value"><?= $stats['expired_count'] ?? 0 ?></div>
                    <div class="fw-crm__stat-label">Expired</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="fw-crm__toolbar">
                <button class="fw-crm__btn fw-crm__btn--primary" onclick="openComplianceTypeModal()">
                    + Add Document Type
                </button>
            </div>

            <!-- Compliance Types List -->
            <div class="fw-crm__compliance-types-list">
                <?php if (count($complianceTypes) > 0): ?>
                    <?php foreach ($complianceTypes as $type): ?>
                    <div class="fw-crm__compliance-type-card" data-type-id="<?= $type['id'] ?>">
                        <div class="fw-crm__compliance-type-header">
                            <div class="fw-crm__compliance-type-info">
                                <h3 class="fw-crm__compliance-type-name"><?= htmlspecialchars($type['name']) ?></h3>
                                <div class="fw-crm__compliance-type-meta">
                                    Code: <strong><?= htmlspecialchars($type['code']) ?></strong>
                                    <?php if ($type['default_expiry_months']): ?>
                                        • Default expiry: <strong><?= $type['default_expiry_months'] ?> months</strong>
                                    <?php endif; ?>
                                    <?php if ($type['required']): ?>
                                        • <span class="fw-crm__badge fw-crm__badge--danger">Required</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="fw-crm__compliance-type-stats">
                                <div class="fw-crm__stat-mini">
                                    <div class="fw-crm__stat-mini-value"><?= $type['doc_count'] ?></div>
                                    <div class="fw-crm__stat-mini-label">Documents</div>
                                </div>
                                <?php if ($type['expiring_count'] > 0): ?>
                                <div class="fw-crm__stat-mini fw-crm__stat-mini--warning">
                                    <div class="fw-crm__stat-mini-value"><?= $type['expiring_count'] ?></div>
                                    <div class="fw-crm__stat-mini-label">Expiring</div>
                                </div>
                                <?php endif; ?>
                                <?php if ($type['expired_count'] > 0): ?>
                                <div class="fw-crm__stat-mini fw-crm__stat-mini--danger">
                                    <div class="fw-crm__stat-mini-value"><?= $type['expired_count'] ?></div>
                                    <div class="fw-crm__stat-mini-label">Expired</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fw-crm__card-actions">
                            <button class="fw-crm__btn fw-crm__btn--small fw-crm__btn--secondary" onclick="editComplianceType(<?= $type['id'] ?>)">
                                Edit
                            </button>
                            <button class="fw-crm__btn fw-crm__btn--small fw-crm__btn--danger" onclick="deleteComplianceType(<?= $type['id'] ?>)">
                                Delete
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="fw-crm__empty-state">
                        No compliance document types defined yet.<br>
                        <small>Add common types like COC, Tax Clearance, BEE Certificate, Insurance, etc.</small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Policy Settings -->
            <div class="fw-crm__section">
                <h2 class="fw-crm__section-title">Compliance Policy</h2>
                <div class="fw-crm__info-card">
                    <form id="policyForm">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__checkbox-wrapper">
                                <input 
                                    type="checkbox" 
                                    name="block_expired_suppliers" 
                                    id="blockExpiredSuppliers"
                                    class="fw-crm__checkbox"
                                    value="1"
                                >
                                <span>Block RFQs to suppliers with expired compliance documents</span>
                            </label>
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__checkbox-wrapper">
                                <input 
                                    type="checkbox" 
                                    name="notify_expiring" 
                                    id="notifyExpiring"
                                    class="fw-crm__checkbox"
                                    value="1"
                                    checked
                                >
                                <span>Send email reminders for expiring documents</span>
                            </label>
                        </div>
                        <div class="fw-crm__form-row">
                            <div class="fw-crm__form-group">
                                <label class="fw-crm__label">Reminder Schedule (days before expiry)</label>
                                <input 
                                    type="text" 
                                    name="reminder_days" 
                                    id="reminderDays"
                                    class="fw-crm__input"
                                    placeholder="30,14,7"
                                    value="30,14,7"
                                >
                                <small class="fw-crm__help-text">Comma-separated values, e.g., 30,14,7</small>
                            </div>
                        </div>
                        <div class="fw-crm__form-actions">
                            <button type="submit" class="fw-crm__btn fw-crm__btn--primary">
                                Save Policy Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <!-- Compliance Type Modal -->
    <div class="fw-crm__modal-overlay" id="complianceTypeModal">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h2 class="fw-crm__modal-title" id="complianceTypeModalTitle">Add Document Type</h2>
                <button class="fw-crm__modal-close" onclick="CRMModal.close('complianceTypeModal')">
                    <svg viewBox="0 0 24 24" fill="none">
                        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <form id="complianceTypeForm">
                <input type="hidden" name="id" id="complianceTypeId" value="">
                <div class="fw-crm__modal-body">
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">
                            Code <span class="fw-crm__required">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="code" 
                            id="complianceTypeCode" 
                            class="fw-crm__input"
                            required
                            placeholder="e.g., COC, TAX, BEE"
                            maxlength="32"
                        >
                        <small class="fw-crm__help-text">Unique identifier (uppercase, no spaces)</small>
                    </div>
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">
                            Name <span class="fw-crm__required">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="name" 
                            id="complianceTypeName" 
                            class="fw-crm__input"
                            required
                            placeholder="e.g., Certificate of Compliance"
                        >
                    </div>
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Default Expiry (months)</label>
                        <input 
                            type="number" 
                            name="default_expiry_months" 
                            id="complianceTypeExpiry" 
                            class="fw-crm__input"
                            min="1"
                            max="120"
                            placeholder="12"
                        >
                        <small class="fw-crm__help-text">Leave blank if documents don't expire</small>
                    </div>
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__checkbox-wrapper">
                            <input 
                                type="checkbox" 
                                name="required" 
                                id="complianceTypeRequired"
                                class="fw-crm__checkbox"
                                value="1"
                            >
                            <span>Mark as required for all suppliers</span>
                        </label>
                    </div>
                </div>
                <div class="fw-crm__modal-footer">
                    <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRMModal.close('complianceTypeModal')">Cancel</button>
                    <button type="submit" class="fw-crm__btn fw-crm__btn--primary">Save Type</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    // Compliance type handlers
    function openComplianceTypeModal() {
        document.getElementById('complianceTypeForm').reset();
        document.getElementById('complianceTypeId').value = '';
        document.getElementById('complianceTypeModalTitle').textContent = 'Add Document Type';
        CRMModal.open('complianceTypeModal');
    }

    function editComplianceType(id) {
        fetch('/crm/ajax/compliance_type_get.php?id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    document.getElementById('complianceTypeId').value = data.type.id;
                    document.getElementById('complianceTypeCode').value = data.type.code;
                    document.getElementById('complianceTypeName').value = data.type.name;
                    document.getElementById('complianceTypeExpiry').value = data.type.default_expiry_months || '';
                    document.getElementById('complianceTypeRequired').checked = data.type.required == 1;
                    document.getElementById('complianceTypeModalTitle').textContent = 'Edit Document Type';
                    CRMModal.open('complianceTypeModal');
                } else {
                    alert('Failed to load document type');
                }
            });
    }

    function deleteComplianceType(id) {
        if (!confirm('Delete this document type? All associated documents will be unlinked.')) return;
        
        fetch('/crm/ajax/compliance_type_delete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id})
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'Failed to delete document type');
            }
        });
    }

    document.getElementById('complianceTypeForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        try {
            const res = await fetch('/crm/ajax/compliance_type_save.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'Failed to save document type');
            }
        } catch (err) {
            alert('Network error');
        }
    });

    // Policy form handler
    document.getElementById('policyForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        try {
            const res = await fetch('/crm/ajax/compliance_policy_save.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.ok) {
                alert('Policy settings saved successfully');
            } else {
                alert(data.error || 'Failed to save policy settings');
            }
        } catch (err) {
            alert('Network error');
        }
    });

    // Load policy settings on page load
    fetch('/crm/ajax/compliance_policy_get.php')
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.policy) {
                document.getElementById('blockExpiredSuppliers').checked = data.policy.block_expired_suppliers == 1;
                document.getElementById('notifyExpiring').checked = data.policy.notify_expiring == 1;
                if (data.policy.reminder_days) {
                    document.getElementById('reminderDays').value = data.policy.reminder_days;
                }
            }
        });
    </script>
</body>
</html>