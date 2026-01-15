<?php
// /timesheets/index.php – Manager timesheets approvals page
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-TS-INDEX');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$role      = $_SESSION['role'] ?? 'member';

// Only allow admin or bookkeeper or hr to view approvals page
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo 'You do not have permission to access this page.';
    exit;
}

// Fetch company and user info
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
$companyName = $company ? $company['name'] : 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userName = $user ? $user['first_name'] : 'User';

// Fetch pending timesheet entries
$stmt = $DB->prepare(
    "SELECT e.*, emp.first_name, emp.last_name, p.name AS project_name, bi.name AS item_name
     FROM timesheet_entries e
     JOIN employees emp ON e.employee_id = emp.id
     LEFT JOIN projects p ON e.project_id = p.project_id
     LEFT JOIN board_items bi ON e.item_id = bi.id
     WHERE e.company_id = ? AND e.status = 'submitted'
     ORDER BY e.ts_date, emp.last_name, emp.first_name"
);
$stmt->execute([$companyId]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timesheet Approvals – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
    <style>
        .ts-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ts-table th,
        .ts-table td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
            font-size: 0.9rem;
        }
        .ts-table th {
            background: var(--fw-bg-secondary);
        }
        .approve-actions {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
<main class="fw-finance">
    <div class="fw-finance__container">
        <header class="fw-finance__header">
            <div class="fw-finance__brand">
                <div class="fw-finance__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-finance__brand-text">
                    <div class="fw-finance__company-name"><?php echo htmlspecialchars($companyName); ?></div>
                    <div class="fw-finance__app-name">Timesheets Approvals</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?php echo htmlspecialchars($userName); ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/" class="fw-finance__back-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <h2>Pending Timesheet Entries</h2>
            <table class="ts-table">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Project</th>
                        <th>Task</th>
                        <th style="width:8%">Reg</th>
                        <th style="width:8%">OT</th>
                        <th style="width:8%">Sun</th>
                        <th style="width:8%">PH</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="9" style="text-align:center; padding:1rem;">No pending entries.</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $e): ?>
                            <tr data-entry-id="<?php echo (int)$e['id']; ?>">
                                <td><input type="checkbox" class="ts-approve-checkbox" value="<?php echo (int)$e['id']; ?>"></td>
                                <td><?php echo htmlspecialchars($e['ts_date']); ?></td>
                                <td><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></td>
                                <td><?php echo $e['project_name'] ? htmlspecialchars($e['project_name']) : '-'; ?></td>
                                <td><?php echo $e['item_name'] ? htmlspecialchars($e['item_name']) : '-'; ?></td>
                                <td><?php echo number_format((float)$e['regular_hours'], 2); ?></td>
                                <td><?php echo number_format((float)$e['ot_hours'], 2); ?></td>
                                <td><?php echo number_format((float)$e['sunday_hours'], 2); ?></td>
                                <td><?php echo number_format((float)$e['public_holiday_hours'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="approve-actions">
                <button id="approveSelected" class="fw-finance__btn fw-finance__btn--primary">Approve Selected</button>
            </div>
        </div>
        <footer class="fw-finance__footer">
            <span>Timesheets Approvals v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
<script src="/timesheets/js/ts.js?v=<?php echo ASSET_VERSION; ?>"></script>
<script>
    // Select all toggle
    document.getElementById('selectAll').addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('.ts-approve-checkbox').forEach(cb => cb.checked = checked);
    });
    TimesheetPage.initIndex();
</script>
</body>
</html>