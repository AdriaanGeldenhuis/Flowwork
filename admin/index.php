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

// Fetch company info
$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Fetch plan info
$stmt = $DB->prepare("SELECT * FROM plans WHERE id = ?");
$stmt->execute([$company['plan_id']]);
$plan = $stmt->fetch();

// Fetch usage stats
$stmt = $DB->prepare("SELECT COUNT(*) as user_count FROM users WHERE company_id = ? AND status = 'active' AND is_seat = 1");
$stmt->execute([$companyId]);
$userCount = $stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(DISTINCT project_id) as project_count FROM projects WHERE company_id = ?");
$stmt->execute([$companyId]);
$projectCount = $stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(*) as board_count FROM project_boards WHERE company_id = ?");
$stmt->execute([$companyId]);
$boardCount = $stmt->fetchColumn();

// Recent activity
$stmt = $DB->prepare("
    SELECT al.*, u.first_name, u.last_name 
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.company_id = ?
    ORDER BY al.timestamp DESC
    LIMIT 10
");
$stmt->execute([$companyId]);
$recentActivity = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard – <?= htmlspecialchars($company['name']) ?></title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Admin Dashboard</h1>
                    <p class="fw-admin__page-subtitle">Overview and quick actions</p>
                </div>
                <a href="/home.php" class="fw-admin__btn-secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    Back to Home
                </a>
            </header>

            <!-- Stats Grid -->
            <div class="fw-admin__stats-grid">
                <div class="fw-admin__stat-card">
                    <div class="fw-admin__stat-icon fw-admin__stat-icon--primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <div class="fw-admin__stat-content">
                        <div class="fw-admin__stat-value"><?= $userCount ?> / <?= $plan['max_users'] ?></div>
                        <div class="fw-admin__stat-label">Active Users</div>
                    </div>
                </div>

                <div class="fw-admin__stat-card">
                    <div class="fw-admin__stat-icon fw-admin__stat-icon--success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="3" y="14" width="7" height="7" rx="1"/>
                            <rect x="14" y="14" width="7" height="7" rx="1"/>
                        </svg>
                    </div>
                    <div class="fw-admin__stat-content">
                        <div class="fw-admin__stat-value"><?= $projectCount ?></div>
                        <div class="fw-admin__stat-label">Projects</div>
                    </div>
                </div>

                <div class="fw-admin__stat-card">
                    <div class="fw-admin__stat-icon fw-admin__stat-icon--info">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2"/>
                            <line x1="9" y1="9" x2="15" y2="9"/>
                            <line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                    </div>
                    <div class="fw-admin__stat-content">
                        <div class="fw-admin__stat-value"><?= $boardCount ?></div>
                        <div class="fw-admin__stat-label">Boards</div>
                    </div>
                </div>

                <div class="fw-admin__stat-card">
                    <div class="fw-admin__stat-icon fw-admin__stat-icon--warning">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6v6l4 2"/>
                        </svg>
                    </div>
                    <div class="fw-admin__stat-content">
                        <div class="fw-admin__stat-value"><?= ucfirst($plan['name']) ?></div>
                        <div class="fw-admin__stat-label">Current Plan</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="fw-admin__section">
                <h2 class="fw-admin__section-title">Quick Actions</h2>
                <div class="fw-admin__quick-actions">
                    <a href="/admin/users.php" class="fw-admin__quick-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="8.5" cy="7" r="4"/>
                            <line x1="20" y1="8" x2="20" y2="14"/>
                            <line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                        <span>Add User</span>
                    </a>
                    <a href="/admin/invites.php" class="fw-admin__quick-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                        <span>Send Invite</span>
                    </a>
                    <a href="/admin/company.php" class="fw-admin__quick-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        <span>Company Profile</span>
                    </a>
                    <a href="/admin/billing.php" class="fw-admin__quick-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                            <line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                        <span>Billing</span>
                    </a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="fw-admin__section">
                <h2 class="fw-admin__section-title">Recent Activity</h2>
                <div class="fw-admin__card">
                    <?php if (empty($recentActivity)): ?>
                        <p class="fw-admin__empty-state">No recent activity</p>
                    <?php else: ?>
                        <div class="fw-admin__activity-list">
                            <?php foreach ($recentActivity as $activity): ?>
                            <div class="fw-admin__activity-item">
                                <div class="fw-admin__activity-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <div class="fw-admin__activity-content">
                                    <div class="fw-admin__activity-text">
                                        <strong><?= htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']) ?></strong>
                                        <?= htmlspecialchars($activity['action']) ?>
                                    </div>
                                    <div class="fw-admin__activity-time">
                                        <?= date('M j, Y g:i A', strtotime($activity['timestamp'])) ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
</body>
</html>