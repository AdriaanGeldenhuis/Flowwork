<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Check admin access
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Access denied - Admin only');
}

// Fetch company
$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_company') {
    try {
        $name = trim($_POST['name'] ?? '');
        $businessType = trim($_POST['business_type'] ?? '');
        $vatNumber = trim($_POST['vat_number'] ?? '');
        $regNumber = trim($_POST['reg_number'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $addressLine1 = trim($_POST['address_line1'] ?? '');
        $addressLine2 = trim($_POST['address_line2'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $postal = trim($_POST['postal'] ?? '');
        $country = trim($_POST['country'] ?? 'South Africa');
        
        if (empty($name)) {
            throw new Exception('Company name is required');
        }
        
        $stmt = $DB->prepare("
            UPDATE companies 
            SET name = ?, 
                business_type = ?,
                vat_number = ?,
                reg_number = ?,
                phone = ?,
                email = ?,
                website = ?,
                address_line1 = ?,
                address_line2 = ?,
                city = ?,
                region = ?,
                postal = ?,
                country = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $name, $businessType, $vatNumber, $regNumber, $phone, $email, $website,
            $addressLine1, $addressLine2, $city, $region, $postal, $country,
            $companyId
        ]);
        
        // Update session
        $_SESSION['company_name'] = $name;
        
        $success = 'Company profile updated successfully';
        
        // Refresh company data
        $stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Profile – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Company Profile</h1>
                    <p class="fw-admin__page-subtitle">Manage company information and branding</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="fw-admin__alert fw-admin__alert--success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="fw-admin__alert fw-admin__alert--error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="fw-admin__form">
                <input type="hidden" name="action" value="update_company">
                
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Basic Information</h2>
                    
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">
                                Company Name <span class="fw-admin__required">*</span>
                            </label>
                            <input type="text" name="name" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['name']) ?>" required>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Business Type</label>
                            <select name="business_type" class="fw-admin__select">
                                <option value="construction" <?= $company['business_type'] === 'construction' ? 'selected' : '' ?>>Construction</option>
                                <option value="postal" <?= $company['business_type'] === 'postal' ? 'selected' : '' ?>>Postal/Courier</option>
                                <option value="hairdresser" <?= $company['business_type'] === 'hairdresser' ? 'selected' : '' ?>>Hairdresser/Salon</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">VAT Number</label>
                            <input type="text" name="vat_number" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['vat_number'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Registration Number</label>
                            <input type="text" name="reg_number" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['reg_number'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Contact Information</h2>
                    
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Phone</label>
                            <input type="tel" name="phone" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Email</label>
                            <input type="email" name="email" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['email'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Website</label>
                            <input type="url" name="website" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['website'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Address</h2>
                    
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Address Line 1</label>
                            <input type="text" name="address_line1" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['address_line1'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Address Line 2</label>
                            <input type="text" name="address_line2" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['address_line2'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">City</label>
                            <input type="text" name="city" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['city'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Province/Region</label>
                            <input type="text" name="region" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['region'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Postal Code</label>
                            <input type="text" name="postal" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['postal'] ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Country</label>
                            <input type="text" name="country" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['country'] ?? 'South Africa') ?>">
                        </div>
                    </div>
                </div>

                <div class="fw-admin__form-actions">
                    <button type="submit" class="fw-admin__btn fw-admin__btn--primary">
                        Save Changes
                    </button>
                </div>
            </form>

        </div>
    </main>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
</body>
</html>