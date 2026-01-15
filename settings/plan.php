<?php
// /settings/plan.php
// Plan usage and upgrade page

require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_gate.php';

// Get current plan info
$stmt = $db->prepare("SELECT c.*, p.name as plan_name, p.code as plan_code, p.max_users, p.max_companies, 
                             p.price_monthly_cents, s.status as subscription_status, s.current_period_end
                      FROM companies c
                      JOIN plans p ON c.plan_id = p.id
                      LEFT JOIN subscriptions s ON s.company_id = c.id
                      WHERE c.id = ?
                      ORDER BY s.created_at DESC
                      LIMIT 1");
$stmt->execute([$_SESSION['company_id']]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// Count active users (excluding bookkeepers who don't count as seats)
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND status = 'active' AND is_seat = 1");
$stmt->execute([$_SESSION['company_id']]);
$activeUsers = $stmt->fetchColumn();

// Count companies (for multi-company support)
$stmt = $db->prepare("SELECT COUNT(DISTINCT company_id) FROM user_companies uc
                      JOIN users u ON uc.user_id = u.id
                      WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$companyCount = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan & Billing - Werkhub</title>
    <link rel="stylesheet" href="/auth/style.css?v=2025-09-30-1">
    <style>
        .fw-billing {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .billing-header {
            margin-bottom: 2rem;
        }
        .billing-header h1 {
            font-size: 2rem;
            margin: 0 0 0.5rem 0;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        .status-badge.past-due {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        .status-badge.canceled {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        .usage-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .usage-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .usage-card h3 {
            margin: 0 0 1rem 0;
            font-size: 1.125rem;
        }
        .usage-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }
        .usage-bar-fill {
            height: 100%;
            background: #3b82f6;
            transition: width 0.3s;
        }
        .usage-bar-fill.warning {
            background: #f59e0b;
        }
        .usage-bar-fill.danger {
            background: #ef4444;
        }
        .upgrade-section {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="fw-billing">
    <div class="billing-header">
        <h1>Plan & Billing</h1>
        <p>Current Plan: <strong><?= htmlspecialchars($company['plan_name']) ?></strong> 
           <span class="status-badge <?= $company['subscription_status'] ?>">
               <?= ucfirst($company['subscription_status']) ?>
           </span>
        </p>
    </div>
    
    <div class="usage-cards">
        <div class="usage-card">
            <h3>User Seats</h3>
            <p><?= $activeUsers ?> of <?= $company['max_users'] ?> users</p>
            <div class="usage-bar">
                <?php
                $userPercent = ($activeUsers / $company['max_users']) * 100;
                $barClass = $userPercent >= 90 ? 'danger' : ($userPercent >= 75 ? 'warning' : '');
                ?>
                <div class="usage-bar-fill <?= $barClass ?>" style="width: <?= min($userPercent, 100) ?>%"></div>
            </div>
            <?php if ($activeUsers >= $company['max_users']): ?>
                <p style="color: #ef4444; font-size: 0.875rem; margin-top: 0.5rem;">
                    You've reached your user limit. Upgrade to add more users.
                </p>
            <?php endif; ?>
        </div>
        
        <div class="usage-card">
            <h3>Companies</h3>
            <p><?= $companyCount ?> of <?= $company['max_companies'] ?> companies</p>
            <div class="usage-bar">
                <?php
                $companyPercent = ($companyCount / $company['max_companies']) * 100;
                $barClass = $companyPercent >= 90 ? 'danger' : ($companyPercent >= 75 ? 'warning' : '');
                ?>
                <div class="usage-bar-fill <?= $barClass ?>" style="width: <?= min($companyPercent, 100) ?>%"></div>
            </div>
        </div>
        
        <div class="usage-card">
            <h3>Subscription</h3>
            <p>R<?= number_format($company['price_monthly_cents'] / 100, 2) ?>/month</p>
            <?php if ($company['current_period_end']): ?>
                <p style="font-size: 0.875rem; color: #6b7280; margin-top: 0.5rem;">
                    Next billing: <?= date('d M Y', strtotime($company['current_period_end'])) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($company['subscription_status'] !== 'active'): ?>
        <div class="upgrade-section" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2);">
            <h2>Subscription Inactive</h2>
            <p>Your subscription is currently inactive. Reactivate to continue using Werkhub.</p>
            <a href="/billing/reactivate.php" class="btn-primary" style="display: inline-block; margin-top: 1rem; text-decoration: none;">
                Reactivate Subscription
            </a>
        </div>
    <?php else: ?>
        <div class="upgrade-section">
            <h2>Need More?</h2>
            <p>Upgrade your plan to unlock more users, companies, and features.</p>
            <button class="btn-primary" style="margin-top: 1rem;" disabled>
                Upgrade Plan (Coming Soon)
            </button>
        </div>
    <?php endif; ?>
    
    <p style="text-align: center; margin-top: 2rem;">
        <a href="/index.php">← Back to Dashboard</a>
    </p>
</div>
</body>
</html>