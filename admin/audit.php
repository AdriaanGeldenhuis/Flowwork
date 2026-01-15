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

// Filters
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$filterAction = isset($_GET['action']) ? trim($_GET['action']) : null;
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
$filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;

// Build query
$sql = "
    SELECT al.*, u.first_name, u.last_name, u.email
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.company_id = ?
";
$params = [$companyId];

if ($filterUser) {
    $sql .= " AND al.user_id = ?";
    $params[] = $filterUser;
}

if ($filterAction) {
    $sql .= " AND al.action LIKE ?";
    $params[] = "%$filterAction%";
}

if ($filterDateFrom) {
    $sql .= " AND DATE(al.timestamp) >= ?";
    $params[] = $filterDateFrom;
}

if ($filterDateTo) {
    $sql .= " AND DATE(al.timestamp) <= ?";
    $params[] = $filterDateTo;
}

$sql .= " ORDER BY al.timestamp DESC LIMIT 500";

$stmt = $DB->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch all users for filter
$stmt = $DB->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? ORDER BY first_name");
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Audit Log</h1>
                    <p class="fw-admin__page-subtitle">Track all actions and changes across the system</p>
                </div>
                <button class="fw-admin__btn fw-admin__btn--secondary" onclick="exportAuditLog()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Export CSV
                </button>
            </header>

            <!-- Filters -->
            <div class="fw-admin__card" style="margin-bottom: var(--fw-spacing-lg);">
                <form method="GET" class="fw-admin__form">
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">User</label>
                            <select name="user_id" class="fw-admin__select">
                                <option value="">All Users</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Action</label>
                            <input type="text" name="action" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($filterAction ?? '') ?>" 
                                   placeholder="e.g., user_created">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">From Date</label>
                            <input type="date" name="date_from" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($filterDateFrom ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">To Date</label>
                            <input type="date" name="date_to" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($filterDateTo ?? '') ?>">
                        </div>

                        <div class="fw-admin__form-group" style="grid-column: 1 / -1;">
                            <button type="submit" class="fw-admin__btn fw-admin__btn--primary">
                                Apply Filters
                            </button>
                            <a href="/admin/audit.php" class="fw-admin__btn fw-admin__btn--secondary">
                                Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Audit Log Table -->
            <div class="fw-admin__card">
                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="fw-admin__empty-state">No audit logs found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <?= date('M j, Y', strtotime($log['timestamp'])) ?><br>
                                    <small style="color: var(--fw-text-muted);"><?= date('g:i:s A', strtotime($log['timestamp'])) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?><br>
                                    <small style="color: var(--fw-text-muted);"><?= htmlspecialchars($log['email']) ?></small>
                                </td>
                                <td>
                                    <code style="font-size: 12px; padding: 2px 6px; background: var(--fw-hover-bg); border-radius: 4px;">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </code>
                                </td>
                                <td style="max-width: 400px; word-break: break-word;">
                                    <?= htmlspecialchars($log['details'] ?? '—') ?>
                                </td>
                                <td>
                                    <code style="font-size: 11px; color: var(--fw-text-muted);">
                                        <?= htmlspecialchars($log['ip'] ?? '—') ?>
                                    </code>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (count($logs) === 500): ?>
                <div style="padding: var(--fw-spacing-md); text-align: center; color: var(--fw-text-muted); font-size: 12px; border-top: 1px solid var(--fw-border);">
                    Showing first 500 results. Use filters to narrow down.
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
<script>
function exportAuditLog() {
    const params = new URLSearchParams(window.location.search);
    params.set('action', 'export_audit');
    window.location.href = '/admin/api.php?' + params.toString();
}
</script>
</body>
</html>