<?php
// /crm/account_view.php - FINAL WORKING VERSION
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-FINAL-FIX');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$accountId = (int)($_GET['id'] ?? 0);

if (!$accountId) {
    header('Location: /crm/');
    exit;
}

// Fetch account
$stmt = $DB->prepare("
    SELECT 
        a.*,
        i.name as industry_name,
        r.name as region_name,
        u.first_name as creator_first_name,
        u.last_name as creator_last_name
    FROM crm_accounts a
    LEFT JOIN crm_industries i ON i.id = a.industry_id
    LEFT JOIN crm_regions r ON r.id = a.region_id
    LEFT JOIN users u ON u.id = a.created_by
    WHERE a.id = ? AND a.company_id = ?
");
$stmt->execute([$accountId, $companyId]);
$account = $stmt->fetch();

if (!$account) {
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

// Count totals
$stmt = $DB->prepare("SELECT COUNT(*) FROM crm_contacts WHERE account_id = ? AND company_id = ?");
$stmt->execute([$accountId, $companyId]);
$contactsCount = (int)$stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(*) FROM crm_addresses WHERE account_id = ? AND company_id = ?");
$stmt->execute([$accountId, $companyId]);
$addressesCount = (int)$stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(*) FROM crm_compliance_docs WHERE account_id = ? AND company_id = ?");
$stmt->execute([$accountId, $companyId]);
$complianceCount = (int)$stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(*) FROM crm_interactions WHERE account_id = ? AND company_id = ?");
$stmt->execute([$accountId, $companyId]);
$interactionsCount = (int)$stmt->fetchColumn();

// Fetch contacts
$stmt = $DB->prepare("
    SELECT * FROM crm_contacts 
    WHERE account_id = ? AND company_id = ?
    ORDER BY is_primary DESC, first_name ASC
");
$stmt->execute([$accountId, $companyId]);
$contacts = $stmt->fetchAll();

// Fetch addresses
$stmt = $DB->prepare("
    SELECT * FROM crm_addresses 
    WHERE account_id = ? AND company_id = ?
    ORDER BY type ASC
");
$stmt->execute([$accountId, $companyId]);
$addresses = $stmt->fetchAll();

// Fetch tags
$stmt = $DB->prepare("
    SELECT t.* FROM crm_tags t
    JOIN crm_account_tags at ON at.tag_id = t.id
    WHERE at.account_id = ?
");
$stmt->execute([$accountId]);
$tags = $stmt->fetchAll();

// Fetch interactions
$stmt = $DB->prepare("
    SELECT 
        i.*,
        c.first_name as contact_first_name,
        c.last_name as contact_last_name,
        u.first_name as creator_first_name,
        u.last_name as creator_last_name
    FROM crm_interactions i
    LEFT JOIN crm_contacts c ON c.id = i.contact_id
    LEFT JOIN users u ON u.id = i.created_by
    WHERE i.account_id = ? AND i.company_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10
");
$stmt->execute([$accountId, $companyId]);
$interactions = $stmt->fetchAll();

// Fetch compliance docs
$stmt = $DB->prepare("
    SELECT 
        d.*,
        t.name as type_name,
        t.code as type_code
    FROM crm_compliance_docs d
    JOIN crm_compliance_types t ON t.id = d.type_id
    WHERE d.account_id = ? AND d.company_id = ?
    ORDER BY d.expiry_date ASC
");
$stmt->execute([$accountId, $companyId]);
$complianceDocs = $stmt->fetchAll();

// Fetch available compliance types for dropdown
$stmt = $DB->prepare("SELECT * FROM crm_compliance_types WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$availableComplianceTypes = $stmt->fetchAll();

// Check compliance badge setting
$complianceBadgeEnabled = true;
try {
    $badgeStmt = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'crm_compliance_badge_enable'");
    $badgeStmt->execute([$companyId]);
    $badgeVal = $badgeStmt->fetchColumn();
    if ($badgeVal !== false) {
        $complianceBadgeEnabled = ($badgeVal == '1');
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($account['name']) ?> – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="fw-crm" data-theme="dark" data-account-id="<?= $accountId ?>">
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
                    <div class="fw-crm__app-name">CRM</div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
                <a href="/crm/?tab=<?= $account['type'] === 'supplier' ? 'suppliers' : 'customers' ?>" class="fw-crm__back-btn" title="Back to list">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
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
                        <a href="/crm/account_edit.php?id=<?= $accountId ?>" class="fw-crm__kebab-item">Edit Account</a>
                        <a href="/quotes/new.php?customer_id=<?= $accountId ?>" class="fw-crm__kebab-item">New Quote</a>
                        <hr style="margin:4px 0; border:none; border-top:1px solid var(--fw-border-base);">
                        <a href="/crm/?tab=<?= $account['type'] === 'supplier' ? 'suppliers' : 'customers' ?>" class="fw-crm__kebab-item">Back to List</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <!-- Account Header -->
            <div class="fw-crm__account-header">
                <div class="fw-crm__account-header-left">
                    <div class="fw-crm__account-avatar fw-crm__account-avatar--<?= $account['type'] ?>">
                        <?= strtoupper(substr($account['name'], 0, 2)) ?>
                    </div>
                    <div>
                        <h1 class="fw-crm__account-header-title"><?= htmlspecialchars($account['name']) ?></h1>
                        <div class="fw-crm__account-header-meta">
                            <span class="fw-crm__badge fw-crm__badge--<?= $account['type'] ?>">
                                <?= strtoupper($account['type']) ?>
                            </span>
                            <span class="fw-crm__badge fw-crm__badge--<?= $account['status'] ?>">
                                <?= ucfirst($account['status']) ?>
                            </span>
                            <?php if ($complianceBadgeEnabled): ?>
                                <span class="fw-crm__badge" id="complianceBadge">Checking...</span>
                            <?php endif; ?>
                            <?php if ($account['preferred']): ?>
                                <span class="fw-crm__badge fw-crm__badge--preferred">⭐ Preferred</span>
                            <?php endif; ?>
                        </div>
                        <div class="fw-crm__account-header-tags">
                            <?php foreach ($tags as $tag): ?>
                                <span class="fw-crm__tag" style="background:<?= htmlspecialchars($tag['color']) ?>">
                                    <?= htmlspecialchars($tag['name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="fw-crm__account-header-actions">
                    <a href="/crm/account_edit.php?id=<?= $accountId ?>" class="fw-crm__btn fw-crm__btn--secondary">
                        Edit
                    </a>
                    <a href="/quotes/new.php?customer_id=<?= $accountId ?>" class="fw-crm__btn fw-crm__btn--primary">
                        New Quote
                    </a>
                </div>
            </div>

            <!-- Tabs -->
            <div class="fw-crm__view-tabs">
                <button class="fw-crm__view-tab fw-crm__view-tab--active" data-tab="overview">Overview</button>
                <button class="fw-crm__view-tab" data-tab="contacts">Contacts (<?= $contactsCount ?>)</button>
                <button class="fw-crm__view-tab" data-tab="addresses">Addresses (<?= $addressesCount ?>)</button>
                <button class="fw-crm__view-tab" data-tab="compliance">Compliance (<?= $complianceCount ?>)</button>
                <button class="fw-crm__view-tab" data-tab="interactions">Interactions (<?= $interactionsCount ?>)</button>
                <button class="fw-crm__view-tab" data-tab="linked">Linked Items</button>
                <button class="fw-crm__view-tab" data-tab="timeline">Timeline</button>
            </div>

            <!-- Tab Content -->
            <div class="fw-crm__view-content">
                
                <!-- OVERVIEW TAB -->
                <div class="fw-crm__tab-panel fw-crm__tab-panel--active" data-panel="overview">
                    <div class="crm-playground">
                        <div class="crm-playground-header">
                            <h2>📊 Account Analytics</h2>
                            <div class="crm-playground-controls">
                                <button class="fw-crm__btn fw-crm__btn--secondary" id="accountRefresh">🔄 Refresh Data</button>
                            </div>
                        </div>
                        <div class="crm-playground-board" id="accountChartsBoard"></div>
                    </div>

                    <div style="margin-top:32px;">
                        <div class="fw-crm__grid-2col">
                            <div class="fw-crm__info-card">
                                <h3 class="fw-crm__info-card-title">Basic Information</h3>
                                <dl class="fw-crm__info-list">
                                    <?php if ($account['legal_name']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Legal Name</dt>
                                        <dd><?= htmlspecialchars($account['legal_name']) ?></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['reg_no']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Registration No</dt>
                                        <dd><?= htmlspecialchars($account['reg_no']) ?></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['vat_no']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>VAT Number</dt>
                                        <dd><?= htmlspecialchars($account['vat_no']) ?></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['email']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Email</dt>
                                        <dd><a href="mailto:<?= htmlspecialchars($account['email']) ?>"><?= htmlspecialchars($account['email']) ?></a></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['phone']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Phone</dt>
                                        <dd><a href="tel:<?= htmlspecialchars($account['phone']) ?>"><?= htmlspecialchars($account['phone']) ?></a></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['website']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Website</dt>
                                        <dd><a href="<?= htmlspecialchars($account['website']) ?>" target="_blank"><?= htmlspecialchars($account['website']) ?></a></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['industry_name']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Industry</dt>
                                        <dd><?= htmlspecialchars($account['industry_name']) ?></dd>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($account['region_name']): ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Region</dt>
                                        <dd><?= htmlspecialchars($account['region_name']) ?></dd>
                                    </div>
                                    <?php endif; ?>
                                    <div class="fw-crm__info-item">
                                        <dt>Created</dt>
                                        <dd><?= date('d M Y', strtotime($account['created_at'])) ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="fw-crm__info-card">
                                <h3 class="fw-crm__info-card-title">Quick Stats</h3>
                                <div class="fw-crm__kpi-grid">
                                    <div class="fw-crm__kpi">
                                        <div class="fw-crm__kpi-value"><?= $contactsCount ?></div>
                                        <div class="fw-crm__kpi-label">Contacts</div>
                                    </div>
                                    <div class="fw-crm__kpi">
                                        <div class="fw-crm__kpi-value"><?= $addressesCount ?></div>
                                        <div class="fw-crm__kpi-label">Addresses</div>
                                    </div>
                                    <div class="fw-crm__kpi">
                                        <div class="fw-crm__kpi-value"><?= $complianceCount ?></div>
                                        <div class="fw-crm__kpi-label">Docs</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($account['notes']): ?>
                        <div class="fw-crm__info-card">
                            <h3 class="fw-crm__info-card-title">Notes</h3>
                            <p class="fw-crm__notes"><?= nl2br(htmlspecialchars($account['notes'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CONTACTS TAB -->
                <div class="fw-crm__tab-panel" data-panel="contacts">
                    <div class="fw-crm__toolbar">
                        <button class="fw-crm__btn fw-crm__btn--primary" onclick="CRM.openModal('contactModal')">
                            + Add Contact
                        </button>
                    </div>
                    
                    <?php if (count($contacts) > 0): ?>
                        <div class="fw-crm__contacts-grid">
                            <?php foreach ($contacts as $contact): ?>
                            <div class="fw-crm__contact-card">
                                <div class="fw-crm__contact-avatar">
                                    <?= strtoupper(substr($contact['first_name'] ?? '?', 0, 1) . substr($contact['last_name'] ?? '?', 0, 1)) ?>
                                </div>
                                <div class="fw-crm__contact-info">
                                    <div class="fw-crm__contact-name">
                                        <?= htmlspecialchars(trim($contact['first_name'] . ' ' . $contact['last_name'])) ?>
                                        <?php if ($contact['is_primary']): ?>
                                            <span class="fw-crm__badge fw-crm__badge--primary">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($contact['role_title']): ?>
                                        <div class="fw-crm__contact-role"><?= htmlspecialchars($contact['role_title']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($contact['email']): ?>
                                        <div class="fw-crm__contact-detail">
                                            <a href="mailto:<?= htmlspecialchars($contact['email']) ?>"><?= htmlspecialchars($contact['email']) ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($contact['phone']): ?>
                                        <div class="fw-crm__contact-detail">
                                            <a href="tel:<?= htmlspecialchars($contact['phone']) ?>"><?= htmlspecialchars($contact['phone']) ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <div style="margin-top:8px; display:flex; gap:8px;">
                                        <button onclick="CRM.editContact(<?= $contact['id'] ?>)" class="fw-crm__btn fw-crm__btn--secondary" style="font-size:12px; height:32px; padding:0 12px;">
                                            Edit
                                        </button>
                                        <?php if (!$contact['is_primary']): ?>
                                        <button onclick="CRM.setPrimary(<?= $contact['id'] ?>)" class="fw-crm__btn fw-crm__btn--secondary" style="font-size:12px; height:32px; padding:0 12px;">
                                            Set Primary
                                        </button>
                                        <?php endif; ?>
                                        <button onclick="CRM.deleteContact(<?= $contact['id'] ?>)" class="fw-crm__btn fw-crm__btn--danger" style="font-size:12px; height:32px; padding:0 12px;">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">No contacts yet</div>
                    <?php endif; ?>
                </div>

                <!-- ADDRESSES TAB -->
                <div class="fw-crm__tab-panel" data-panel="addresses">
                    <div class="fw-crm__toolbar">
                        <button class="fw-crm__btn fw-crm__btn--primary" onclick="CRM.openModal('addressModal')">
                            + Add Address
                        </button>
                    </div>
                    
                    <?php if (count($addresses) > 0): ?>
                        <div class="fw-crm__addresses-grid">
                            <?php foreach ($addresses as $address): ?>
                            <div class="fw-crm__address-card">
                                <div class="fw-crm__address-type">
                                    <?= ucfirst(str_replace('_', ' ', $address['type'])) ?>
                                </div>
                                <div class="fw-crm__address-text">
                                    <?= htmlspecialchars($address['line1']) ?><br>
                                    <?php if ($address['line2']): ?>
                                        <?= htmlspecialchars($address['line2']) ?><br>
                                    <?php endif; ?>
                                    <?php if ($address['city']): ?>
                                        <?= htmlspecialchars($address['city']) ?>, <?= htmlspecialchars($address['region']) ?><br>
                                    <?php endif; ?>
                                    <?php if ($address['postal_code']): ?>
                                        <?= htmlspecialchars($address['postal_code']) ?><br>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($address['country']) ?>
                                </div>
                                <div style="margin-top:12px; display:flex; gap:8px;">
                                    <button onclick="CRM.editAddress(<?= $address['id'] ?>)" class="fw-crm__btn fw-crm__btn--secondary" style="font-size:12px; height:32px; padding:0 12px;">
                                        Edit
                                    </button>
                                    <button onclick="CRM.deleteAddress(<?= $address['id'] ?>)" class="fw-crm__btn fw-crm__btn--danger" style="font-size:12px; height:32px; padding:0 12px;">
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">No addresses yet</div>
                    <?php endif; ?>
                </div>

                <!-- COMPLIANCE TAB -->
                <div class="fw-crm__tab-panel" data-panel="compliance">
                    <div class="fw-crm__toolbar">
                        <button class="fw-crm__btn fw-crm__btn--primary" onclick="CRM.openModal('complianceModal')">
                            + Upload Document
                        </button>
                    </div>
                    
                    <?php if (count($complianceDocs) > 0): ?>
                        <div class="fw-crm__compliance-list">
                            <?php foreach ($complianceDocs as $doc): ?>
                            <div class="fw-crm__compliance-item fw-crm__compliance-item--<?= $doc['status'] ?>">
                                <div class="fw-crm__compliance-icon">
                                    <?php
                                    $statusIcons = ['valid' => '✓', 'expiring' => '⚠️', 'expired' => '✗', 'missing' => '?'];
                                    echo $statusIcons[$doc['status']] ?? '•';
                                    ?>
                                </div>
                                <div class="fw-crm__compliance-info">
                                    <div class="fw-crm__compliance-name"><?= htmlspecialchars($doc['type_name']) ?></div>
                                    <div class="fw-crm__compliance-meta">
                                        <?php if ($doc['reference_no']): ?>
                                            Ref: <?= htmlspecialchars($doc['reference_no']) ?>
                                        <?php endif; ?>
                                        <?php if ($doc['expiry_date']): ?>
                                            • Expires: <?= date('d M Y', strtotime($doc['expiry_date'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="fw-crm__compliance-status">
                                    <span class="fw-crm__badge fw-crm__badge--<?= $doc['status'] ?>">
                                        <?= ucfirst($doc['status']) ?>
                                    </span>
                                </div>
                                <div style="margin-left:auto; display:flex; gap:8px;">
                                    <?php if ($doc['file_path']): ?>
                                    <a href="/crm/ajax/compliance_doc_download.php?id=<?= $doc['id'] ?>" class="fw-crm__btn fw-crm__btn--secondary" style="font-size:12px; height:32px; padding:0 12px;">
                                        Download
                                    </a>
                                    <?php endif; ?>
                                    <button onclick="CRM.editComplianceDoc(<?= $doc['id'] ?>)" class="fw-crm__btn fw-crm__btn--secondary" style="font-size:12px; height:32px; padding:0 12px;">
                                        Edit
                                    </button>
                                    <button onclick="CRM.deleteComplianceDoc(<?= $doc['id'] ?>)" class="fw-crm__btn fw-crm__btn--danger" style="font-size:12px; height:32px; padding:0 12px;">
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">No compliance documents yet</div>
                    <?php endif; ?>
                </div>

                <!-- INTERACTIONS TAB -->
                <div class="fw-crm__tab-panel" data-panel="interactions">
                    <div class="fw-crm__toolbar">
                        <button class="fw-crm__btn fw-crm__btn--primary" onclick="CRM.openModal('interactionModal')">
                            + Add Interaction
                        </button>
                    </div>
                    
                    <?php if (count($interactions) > 0): ?>
                        <div class="fw-crm__timeline">
                            <?php foreach ($interactions as $interaction): ?>
                            <div class="fw-crm__timeline-item">
                                <div class="fw-crm__timeline-icon">
                                    <?php
                                    $icons = ['email' => '📧', 'call' => '📞', 'visit' => '🏢', 'note' => '📝', 'issue' => '⚠️'];
                                    echo $icons[$interaction['type']] ?? '•';
                                    ?>
                                </div>
                                <div class="fw-crm__timeline-content">
                                    <div class="fw-crm__timeline-title"><?= htmlspecialchars($interaction['subject'] ?? ucfirst($interaction['type'])) ?></div>
                                    <div class="fw-crm__timeline-meta">
                                        <?= date('d M Y H:i', strtotime($interaction['created_at'])) ?>
                                        <?php if ($interaction['creator_first_name']): ?>
                                            • by <?= htmlspecialchars($interaction['creator_first_name'] . ' ' . $interaction['creator_last_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($interaction['body']): ?>
                                        <div class="fw-crm__timeline-body"><?= nl2br(htmlspecialchars($interaction['body'])) ?></div>
                                    <?php endif; ?>
                                    <div style="margin-top:8px;">
                                        <button onclick="CRM.deleteInteraction(<?= $interaction['id'] ?>)" class="fw-crm__btn fw-crm__btn--danger" style="font-size:12px; height:32px; padding:0 12px;">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">No interactions yet</div>
                    <?php endif; ?>
                </div>

                <!-- LINKED ITEMS TAB -->
                <div class="fw-crm__tab-panel" data-panel="linked">
                    <div class="fw-crm__empty-state">
                        Linked projects, quotes, invoices will appear here.<br>
                        <small>Feature coming soon</small>
                    </div>
                </div>

                <!-- TIMELINE TAB -->
                <div class="fw-crm__tab-panel" data-panel="timeline">
                    <div class="fw-crm__empty-state">
                        Complete timeline of all account activity.<br>
                        <small>Feature coming soon</small>
                    </div>
                </div>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Dark</span>
        </footer>

    </div>

    <!-- MODALS -->
    
    <!-- MODAL: ADD/EDIT CONTACT -->
    <div class="fw-crm__modal-overlay" id="contactModal" aria-hidden="true">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h3 class="fw-crm__modal-title" id="contactModalTitle">Add Contact</h3>
                <button type="button" class="fw-crm__modal-close" onclick="CRM.closeModal('contactModal')">&times;</button>
            </div>
            <div class="fw-crm__modal-body">
                <form id="contactForm">
                    <input type="hidden" name="contact_id" id="contact_id" value="">
                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                    
                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">First Name <span class="fw-crm__required">*</span></label>
                            <input type="text" name="first_name" class="fw-crm__input" required>
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Last Name <span class="fw-crm__required">*</span></label>
                            <input type="text" name="last_name" class="fw-crm__input" required>
                        </div>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Role/Title</label>
                        <input type="text" name="role_title" class="fw-crm__input">
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Email</label>
                            <input type="email" name="email" class="fw-crm__input">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Phone</label>
                            <input type="text" name="phone" class="fw-crm__input">
                        </div>
                    </div>

                    <div class="fw-crm__checkbox-wrapper">
                        <input type="checkbox" name="is_primary" value="1" class="fw-crm__checkbox" id="is_primary">
                        <label for="is_primary">Set as primary contact</label>
                    </div>
                </form>
            </div>
            <div class="fw-crm__modal-footer">
                <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRM.closeModal('contactModal')">Cancel</button>
                <button type="submit" form="contactForm" class="fw-crm__btn fw-crm__btn--primary">Save Contact</button>
            </div>
        </div>
    </div>

    <!-- MODAL: ADD/EDIT ADDRESS -->
    <div class="fw-crm__modal-overlay" id="addressModal" aria-hidden="true">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h3 class="fw-crm__modal-title" id="addressModalTitle">Add Address</h3>
                <button type="button" class="fw-crm__modal-close" onclick="CRM.closeModal('addressModal')">&times;</button>
            </div>
            <div class="fw-crm__modal-body">
                <form id="addressForm">
                    <input type="hidden" name="address_id" id="address_id" value="">
                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                    
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Address Type <span class="fw-crm__required">*</span></label>
                        <select name="type" class="fw-crm__select" required>
                            <option value="billing">Billing Address</option>
                            <option value="shipping">Shipping Address</option>
                            <option value="site">Site Address</option>
                            <option value="head_office">Head Office</option>
                        </select>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Address Line 1 <span class="fw-crm__required">*</span></label>
                        <input type="text" name="line1" class="fw-crm__input" required>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Address Line 2</label>
                        <input type="text" name="line2" class="fw-crm__input">
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">City</label>
                            <input type="text" name="city" class="fw-crm__input">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Province</label>
                            <input type="text" name="region" class="fw-crm__input">
                        </div>
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Postal Code</label>
                            <input type="text" name="postal_code" class="fw-crm__input">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Country</label>
                            <input type="text" name="country" class="fw-crm__input" value="ZA">
                        </div>
                    </div>
                </form>
            </div>
            <div class="fw-crm__modal-footer">
                <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRM.closeModal('addressModal')">Cancel</button>
                <button type="submit" form="addressForm" class="fw-crm__btn fw-crm__btn--primary">Save Address</button>
            </div>
        </div>
    </div>

    <!-- MODAL: ADD/EDIT COMPLIANCE DOC -->
    <div class="fw-crm__modal-overlay" id="complianceModal" aria-hidden="true">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h3 class="fw-crm__modal-title" id="complianceModalTitle">Upload Compliance Document</h3>
                <button type="button" class="fw-crm__modal-close" onclick="CRM.closeModal('complianceModal')">&times;</button>
            </div>
            <div class="fw-crm__modal-body">
                <form id="complianceForm" enctype="multipart/form-data">
                    <input type="hidden" name="doc_id" id="doc_id" value="">
                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                    
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Document Type <span class="fw-crm__required">*</span></label>
                        <select name="type_id" class="fw-crm__select" required>
                            <option value="">Select type...</option>
                            <?php foreach ($availableComplianceTypes as $type): ?>
                                <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Reference Number</label>
                        <input type="text" name="reference_no" class="fw-crm__input">
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Expiry Date</label>
                        <input type="date" name="expiry_date" class="fw-crm__input">
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Upload File</label>
                        <input type="file" name="file" class="fw-crm__input">
                        <small class="fw-crm__help-text">Max 5MB. PDF, JPG, PNG only.</small>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Notes</label>
                        <textarea name="notes" class="fw-crm__textarea" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="fw-crm__modal-footer">
                <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRM.closeModal('complianceModal')">Cancel</button>
                <button type="submit" form="complianceForm" class="fw-crm__btn fw-crm__btn--primary">Save Document</button>
            </div>
        </div>
    </div>

    <!-- MODAL: ADD INTERACTION -->
    <div class="fw-crm__modal-overlay" id="interactionModal" aria-hidden="true">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h3 class="fw-crm__modal-title">Log Interaction</h3>
                <button type="button" class="fw-crm__modal-close" onclick="CRM.closeModal('interactionModal')">&times;</button>
            </div>
            <div class="fw-crm__modal-body">
                <form id="interactionForm">
                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                    
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Type <span class="fw-crm__required">*</span></label>
                        <select name="type" class="fw-crm__select" required>
                            <option value="email">Email</option>
                            <option value="call">Phone Call</option>
                            <option value="visit">Site Visit</option>
                            <option value="note">Note</option>
                            <option value="issue">Issue</option>
                        </select>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Contact</label>
                        <select name="contact_id" class="fw-crm__select">
                            <option value="">No specific contact</option>
                            <?php foreach ($contacts as $contact): ?>
                                <option value="<?= $contact['id'] ?>"><?= htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Subject</label>
                        <input type="text" name="subject" class="fw-crm__input">
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Notes <span class="fw-crm__required">*</span></label>
                        <textarea name="body" class="fw-crm__textarea" rows="5" required></textarea>
                    </div>
                </form>
            </div>
            <div class="fw-crm__modal-footer">
                <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRM.closeModal('interactionModal')">Cancel</button>
                <button type="submit" form="interactionForm" class="fw-crm__btn fw-crm__btn--primary">Save Interaction</button>
            </div>
        </div>
    </div>

    <!-- DATA FOR JS -->
    <script>
        window.CRM_DATA = {
            accountId: <?= $accountId ?>,
            contactsCount: <?= $contactsCount ?>,
            addressesCount: <?= $addressesCount ?>,
            complianceCount: <?= $complianceCount ?>,
            interactionsCount: <?= $interactionsCount ?>
        };
    </script>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/crm/assets/account_view.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>