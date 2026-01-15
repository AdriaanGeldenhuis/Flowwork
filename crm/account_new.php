<?php
// /crm/account_new.php - COMPLETE WITH BACK BUTTON
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-4');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Get type from URL
$type = $_GET['type'] ?? 'supplier';
if (!in_array($type, ['supplier', 'customer'])) {
    $type = 'supplier';
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

// Fetch industries
$industries = $DB->query("SELECT * FROM crm_industries ORDER BY name ASC")->fetchAll();

// Fetch regions
$regions = $DB->query("SELECT * FROM crm_regions ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New <?= ucfirst($type) ?> – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-crm__app-name">CRM – New <?= ucfirst($type) ?></div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
                <a href="/crm/?tab=<?= $type ?>s" class="fw-crm__back-btn" title="Back to list">
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
                        <a href="/crm/?tab=<?= $type ?>s" class="fw-crm__kebab-item">Back to List</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Create New <?= ucfirst($type) ?></h1>
                <p class="fw-crm__page-subtitle">
                    Add a new <?= $type ?> to your CRM
                </p>
            </div>

            <!-- Create Form -->
            <form id="createAccountForm" class="fw-crm__form" onsubmit="submitCreateForm(event)">
                <input type="hidden" name="type" value="<?= $type ?>">

                <!-- Basic Info Card -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Basic Information</h2>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Account Name *</label>
                        <input type="text" name="name" class="fw-crm__input" required placeholder="e.g. ABC Suppliers">
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Legal Name</label>
                        <input type="text" name="legal_name" class="fw-crm__input" placeholder="e.g. ABC Suppliers Pty Ltd">
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Registration Number</label>
                            <input type="text" name="reg_no" class="fw-crm__input" placeholder="e.g. 2021/123456/07">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">VAT Number</label>
                            <input type="text" name="vat_no" class="fw-crm__input" placeholder="e.g. 4123456789">
                        </div>
                    </div>
                </div>

                <!-- Contact Info Card -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Contact Information</h2>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Email</label>
                            <input type="email" name="email" class="fw-crm__input" placeholder="e.g. info@abc.co.za">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Phone</label>
                            <input type="tel" name="phone" class="fw-crm__input" placeholder="e.g. 0123456789">
                        </div>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Website</label>
                        <input type="url" name="website" class="fw-crm__input" placeholder="e.g. https://abc.co.za">
                    </div>
                </div>

                <!-- Business Details Card -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Business Details</h2>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Industry</label>
                            <select name="industry_id" class="fw-crm__input">
                                <option value="">Select industry...</option>
                                <?php foreach ($industries as $industry): ?>
                                    <option value="<?= $industry['id'] ?>"><?= htmlspecialchars($industry['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Region</label>
                            <select name="region_id" class="fw-crm__input">
                                <option value="">Select region...</option>
                                <?php foreach ($regions as $region): ?>
                                    <option value="<?= $region['id'] ?>"><?= htmlspecialchars($region['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Status</label>
                            <select name="status" class="fw-crm__input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="prospect">Prospect</option>
                            </select>
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__checkbox-wrapper">
                                <input type="checkbox" name="preferred" class="fw-crm__checkbox">
                                <span>Mark as Preferred <?= ucfirst($type) ?></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Primary Contact Card (Optional) -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Primary Contact (Optional)</h2>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">First Name</label>
                            <input type="text" name="contact_first_name" class="fw-crm__input" placeholder="e.g. John">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Last Name</label>
                            <input type="text" name="contact_last_name" class="fw-crm__input" placeholder="e.g. Doe">
                        </div>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Role/Title</label>
                        <input type="text" name="contact_role_title" class="fw-crm__input" placeholder="e.g. Sales Manager">
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Email</label>
                            <input type="email" name="contact_email" class="fw-crm__input" placeholder="e.g. john@abc.co.za">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Phone</label>
                            <input type="tel" name="contact_phone" class="fw-crm__input" placeholder="e.g. 0821234567">
                        </div>
                    </div>
                </div>

                <!-- Primary Address Card (Optional) -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Primary Address (Optional)</h2>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Address Type</label>
                        <select name="address_type" class="fw-crm__input">
                            <option value="head_office">Head Office</option>
                            <option value="billing">Billing Address</option>
                            <option value="shipping">Shipping Address</option>
                            <option value="site">Site Address</option>
                        </select>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Address Line 1</label>
                        <input type="text" name="address_line1" class="fw-crm__input" placeholder="e.g. 123 Main Road">
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Address Line 2</label>
                        <input type="text" name="address_line2" class="fw-crm__input" placeholder="e.g. Building A">
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">City</label>
                            <input type="text" name="address_city" class="fw-crm__input" placeholder="e.g. Johannesburg">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Province</label>
                            <input type="text" name="address_region" class="fw-crm__input" placeholder="e.g. Gauteng">
                        </div>
                    </div>

                    <div class="fw-crm__form-row">
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Postal Code</label>
                            <input type="text" name="address_postal_code" class="fw-crm__input" placeholder="e.g. 2001">
                        </div>
                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Country</label>
                            <input type="text" name="address_country" class="fw-crm__input" value="ZA" placeholder="ZA">
                        </div>
                    </div>
                </div>

                <!-- Notes Card -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Notes</h2>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Internal Notes</label>
                        <textarea name="notes" class="fw-crm__textarea" rows="5" placeholder="Any additional information..."></textarea>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="fw-crm__form-actions">
                    <a href="/crm/?tab=<?= $type ?>s" class="fw-crm__btn fw-crm__btn--secondary">
                        Cancel
                    </a>
                    <button type="submit" class="fw-crm__btn fw-crm__btn--primary" id="submitBtn">
                        Create <?= ucfirst($type) ?>
                    </button>
                </div>

                <div id="formError" class="fw-crm__form-error" style="display:none;"></div>
            </form>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    async function submitCreateForm(e) {
        e.preventDefault();
        
        const form = e.target;
        const btn = document.getElementById('submitBtn');
        const errorDiv = document.getElementById('formError');
        const formData = new FormData(form);

        errorDiv.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Creating...';

        try {
            const res = await fetch('/crm/ajax/account_save.php', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();

            if (data.ok) {
                window.location.href = '/crm/account_view.php?id=' + data.account_id;
            } else {
                errorDiv.textContent = data.error || 'Failed to create account';
                errorDiv.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Create <?= ucfirst($type) ?>';
            }
        } catch (err) {
            errorDiv.textContent = 'Network error. Please try again.';
            errorDiv.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Create <?= ucfirst($type) ?>';
            console.error(err);
        }
    }
    </script>
</body>
</html>