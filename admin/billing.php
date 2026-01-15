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

// Fetch company with plan
$stmt = $DB->prepare("
    SELECT c.*, p.name as plan_name, p.code as plan_code, p.max_users, p.max_companies, p.price_monthly_cents
    FROM companies c
    JOIN plans p ON p.id = c.plan_id
    WHERE c.id = ?
");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Fetch subscription
$stmt = $DB->prepare("
    SELECT * FROM subscriptions 
    WHERE company_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->execute([$companyId]);
$subscription = $stmt->fetch();

// Fetch all plans
$stmt = $DB->query("SELECT * FROM plans ORDER BY price_monthly_cents ASC");
$plans = $stmt->fetchAll();

// Calculate usage
$stmt = $DB->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND status = 'active' AND is_seat = 1");
$stmt->execute([$companyId]);
$currentUsers = $stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(DISTINCT company_id) FROM user_companies WHERE user_id IN (SELECT id FROM users WHERE company_id = ?)");
$stmt->execute([$companyId]);
$currentCompanies = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Subscription & Billing</h1>
                    <p class="fw-admin__page-subtitle">Manage your plan and payment details</p>
                </div>
            </header>

            <!-- Current Plan -->
            <div class="fw-admin__card">
                <div class="fw-admin__billing-header">
                    <div>
                        <h2 class="fw-admin__card-title">Current Plan</h2>
                        <div class="fw-admin__plan-name">
                            <?= htmlspecialchars($company['plan_name']) ?>
                            <span class="fw-admin__badge fw-admin__badge--<?= $company['subscription_active'] ? 'success' : 'danger' ?>">
                                <?= $company['subscription_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                    </div>
                    <div class="fw-admin__plan-price">
                        R<?= number_format($company['price_monthly_cents'] / 100, 2) ?>
                        <span>/month</span>
                    </div>
                </div>

                <div class="fw-admin__plan-details">
                    <div class="fw-admin__plan-detail">
                        <div class="fw-admin__plan-detail-label">Users</div>
                        <div class="fw-admin__plan-detail-value">
                            <?= $currentUsers ?> / <?= $company['max_users'] ?>
                        </div>
                        <div class="fw-admin__progress-bar">
                            <div class="fw-admin__progress-fill" style="width: <?= min(100, ($currentUsers / $company['max_users']) * 100) ?>%"></div>
                        </div>
                    </div>

                    <div class="fw-admin__plan-detail">
                        <div class="fw-admin__plan-detail-label">Companies</div>
                        <div class="fw-admin__plan-detail-value">
                            <?= $currentCompanies ?> / <?= $company['max_companies'] ?>
                        </div>
                        <div class="fw-admin__progress-bar">
                            <div class="fw-admin__progress-fill" style="width: <?= min(100, ($currentCompanies / $company['max_companies']) * 100) ?>%"></div>
                        </div>
                    </div>
                </div>

                <?php if ($subscription): ?>
                <div class="fw-admin__subscription-info">
                    <div class="fw-admin__info-row">
                        <span class="fw-admin__info-label">Status:</span>
                        <span class="fw-admin__badge fw-admin__badge--<?= $subscription['status'] === 'active' ? 'success' : 'warning' ?>">
                            <?= ucfirst($subscription['status']) ?>
                        </span>
                    </div>
                    <?php if ($subscription['current_period_end']): ?>
                    <div class="fw-admin__info-row">
                        <span class="fw-admin__info-label">Next billing date:</span>
                        <span><?= date('F j, Y', strtotime($subscription['current_period_end'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($subscription['yoco_subscription_id']): ?>
                    <div class="fw-admin__info-row">
                        <span class="fw-admin__info-label">Subscription ID:</span>
                        <code><?= htmlspecialchars($subscription['yoco_subscription_id']) ?></code>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Available Plans -->
            <div class="fw-admin__section">
                <h2 class="fw-admin__section-title">Available Plans</h2>
                <div class="fw-admin__plans-grid">
                    <?php foreach ($plans as $plan): 
                        $isCurrent = $plan['id'] == $company['plan_id'];
                    ?>
                    <div class="fw-admin__plan-card <?= $isCurrent ? 'fw-admin__plan-card--current' : '' ?>">
                        <?php if ($isCurrent): ?>
                        <div class="fw-admin__plan-badge">Current Plan</div>
                        <?php endif; ?>
                        
                        <h3 class="fw-admin__plan-card-title"><?= htmlspecialchars($plan['name']) ?></h3>
                        
                        <div class="fw-admin__plan-card-price">
                            R<?= number_format($plan['price_monthly_cents'] / 100, 2) ?>
                            <span>/month</span>
                        </div>

                        <ul class="fw-admin__plan-features">
                            <li>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                <?= $plan['max_users'] ?> user<?= $plan['max_users'] > 1 ? 's' : '' ?>
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                <?= $plan['max_companies'] ?> compan<?= $plan['max_companies'] > 1 ? 'ies' : 'y' ?>
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                All features included
                            </li>
                        </ul>

                        <?php if (!$isCurrent): ?>
                        <button class="fw-admin__btn fw-admin__btn--primary fw-admin__btn--block" 
                                onclick="changePlan(<?= $plan['id'] ?>, '<?= htmlspecialchars($plan['name']) ?>')">
                            <?= $plan['price_monthly_cents'] > $company['price_monthly_cents'] ? 'Upgrade' : 'Downgrade' ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="fw-admin__card">
                <h2 class="fw-admin__card-title">Payment Method</h2>
                
                <?php if ($company['yoco_customer_id']): ?>
                <div class="fw-admin__payment-method">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                    <div>
                        <div class="fw-admin__payment-method-label">Card on file</div>
                        <div class="fw-admin__payment-method-id">Yoco Customer: <?= htmlspecialchars($company['yoco_customer_id']) ?></div>
                    </div>
                    <button class="fw-admin__btn fw-admin__btn--secondary" onclick="updatePaymentMethod()">
                        Update Card
                    </button>
                </div>
                <?php else: ?>
                <div class="fw-admin__empty-state">
                    <p>No payment method on file</p>
                    <button class="fw-admin__btn fw-admin__btn--primary" onclick="addPaymentMethod()">
                        Add Payment Method
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Billing Actions -->
            <div class="fw-admin__card">
                <h2 class="fw-admin__card-title">Actions</h2>
                <div class="fw-admin__action-list">
                    <button class="fw-admin__action-item" onclick="downloadInvoices()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <div>
                            <div class="fw-admin__action-title">Download Invoices</div>
                            <div class="fw-admin__action-desc">View and download past invoices</div>
                        </div>
                    </button>

                    <button class="fw-admin__action-item fw-admin__action-item--danger" onclick="cancelSubscription()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="15" y1="9" x2="9" y2="15"/>
                            <line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                        <div>
                            <div class="fw-admin__action-title">Cancel Subscription</div>
                            <div class="fw-admin__action-desc">Cancel your subscription (takes effect at period end)</div>
                        </div>
                    </button>
                </div>
            </div>

        </div>
    </main>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
<script>
function changePlan(planId, planName) {
    if (!confirm(`Are you sure you want to change to the ${planName} plan? Changes will be pro-rated.`)) return;
    
    fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'change_plan', plan_id: planId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Plan changed successfully!');
            location.reload();
        } else {
            alert(data.error || 'Failed to change plan');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error');
    });
}

function updatePaymentMethod() {
    alert('Redirect to Yoco payment update flow');
    // TODO: Implement Yoco payment update
}

function addPaymentMethod() {
    alert('Redirect to Yoco payment setup flow');
    // TODO: Implement Yoco payment setup
}

function downloadInvoices() {
    alert('Download invoices functionality');
    // TODO: Implement invoice download
}

function cancelSubscription() {
    if (!confirm('Are you sure? This will cancel your subscription at the end of the current billing period.')) return;
    
    fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'cancel_subscription' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Subscription cancellation scheduled');
            location.reload();
        } else {
            alert(data.error || 'Failed to cancel');
        }
    });
}
</script>
</body>
</html>