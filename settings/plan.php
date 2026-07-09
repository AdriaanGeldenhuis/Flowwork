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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        /* Plan & Billing — Dimension 3D (indigo), scoped to this standalone page */
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            background-color: #eef1f6;
            background-image:
                radial-gradient(1400px 700px at 18% -8%, rgba(99,102,241,0.10), transparent 60%),
                radial-gradient(1200px 600px at 85% 5%, rgba(6,182,212,0.06), transparent 55%),
                radial-gradient(900px 500px at 55% 110%, rgba(139,92,246,0.05), transparent 60%);
            min-height: 100vh;
        }
        .fw-billing {
            padding: 2.5rem 2rem;
            max-width: 1100px;
            margin: 0 auto;
            color: #1a1d29;
        }
        .billing-header { margin-bottom: 2rem; }
        .billing-header h1 {
            font-size: 2.25rem;
            font-weight: 900;
            margin: 0 0 0.5rem 0;
            background: linear-gradient(135deg, #818cf8, #6366f1 55%, #3730a3);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 2px 12px rgba(99,102,241,0.25));
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            color: #fff;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.4), inset 0 -2px 3px rgba(0,0,0,0.2), 0 2px 6px rgba(0,0,0,0.15);
        }
        .status-badge.active { background: linear-gradient(165deg, #34d399, #10b981 60%, #059669); }
        .status-badge.past-due { background: linear-gradient(165deg, #fcd34d, #f59e0b 60%, #d97706); color: #3a2400; }
        .status-badge.canceled { background: linear-gradient(165deg, #f87171, #ef4444 60%, #b91c1c); }
        .usage-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .usage-card {
            background: linear-gradient(168deg, #ffffff 0%, #fcfcff 45%, #eef0fb 100%);
            border: 1px solid rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow:
                0 1px 2px rgba(30,27,75,0.05), 0 6px 14px -4px rgba(30,27,75,0.09),
                0 18px 36px -14px rgba(30,27,75,0.16), 0 30px 60px -26px rgba(99,102,241,0.20),
                inset 0 1px 0 rgba(255,255,255,1);
            transition: transform 0.45s cubic-bezier(0.22,1,0.36,1), box-shadow 0.45s cubic-bezier(0.22,1,0.36,1);
        }
        .usage-card:hover {
            transform: translateY(-4px);
            box-shadow:
                0 2px 4px rgba(30,27,75,0.06), 0 12px 24px -6px rgba(30,27,75,0.13),
                0 32px 56px -16px rgba(30,27,75,0.22), 0 48px 90px -28px rgba(99,102,241,0.36),
                0 0 0 1px rgba(99,102,241,0.22), inset 0 1px 0 rgba(255,255,255,1);
        }
        .usage-card h3 { margin: 0 0 1rem 0; font-size: 1.125rem; font-weight: 800; }
        .usage-bar {
            height: 10px;
            background: #f2f3fb;
            border-radius: 9999px;
            overflow: hidden;
            margin: 0.5rem 0;
            box-shadow: inset 0 2px 4px rgba(30,27,75,0.10), inset 0 -1px 0 rgba(255,255,255,0.9);
        }
        .usage-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #6366f1);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.35);
            transition: width 0.3s;
        }
        .usage-bar-fill.warning { background: linear-gradient(90deg, #fbbf24, #f59e0b); }
        .usage-bar-fill.danger { background: linear-gradient(90deg, #f87171, #ef4444); }
        .upgrade-section {
            background:
                linear-gradient(135deg, rgba(99,102,241,0.10), transparent 60%),
                linear-gradient(168deg, #ffffff 0%, #fcfcff 45%, #eef0fb 100%);
            border: 1px solid rgba(99,102,241,0.35);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            box-shadow:
                0 1px 2px rgba(30,27,75,0.05), 0 12px 28px -12px rgba(30,27,75,0.16),
                0 30px 60px -26px rgba(99,102,241,0.22), inset 0 1px 0 rgba(255,255,255,1);
        }
        .upgrade-section h2 { font-weight: 800; margin-top: 0; }
        .fw-billing .btn-primary {
            background: linear-gradient(165deg, #a5b4fc 0%, #6366f1 55%, #4338ca 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.7rem 1.4rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.4), inset 0 -2px 3px rgba(30,27,75,0.3),
                0 3px 0 #3730a3, 0 6px 14px -2px rgba(99,102,241,0.35), 0 14px 28px -8px rgba(99,102,241,0.25);
            transition: transform 0.2s cubic-bezier(0.4,0,0.2,1), box-shadow 0.2s cubic-bezier(0.4,0,0.2,1);
        }
        .fw-billing .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.45), inset 0 -2px 3px rgba(30,27,75,0.3),
                0 5px 0 #3730a3, 0 10px 22px -2px rgba(99,102,241,0.4), 0 22px 44px -10px rgba(99,102,241,0.3);
        }
        .fw-billing .btn-primary:disabled { opacity: 0.55; cursor: not-allowed; }
        .fw-billing a { color: #4f46e5; font-weight: 600; }
        @media (prefers-reduced-motion: reduce) {
            .usage-card, .fw-billing .btn-primary { transition: none !important; }
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