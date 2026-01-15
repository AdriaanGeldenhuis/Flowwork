<?php
// /timesheets/my.php – Employee timesheet entry page
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-TS');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Fetch employee record for this user
$stmt = $DB->prepare("SELECT id FROM employees WHERE user_id = ? AND company_id = ? AND termination_date IS NULL");
$stmt->execute([$userId, $companyId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    echo 'You are not registered as an active employee.';
    exit;
}

$employeeId = (int)$employee['id'];

// Fetch user and company info
$stmt = $DB->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$userName = $userData ? $userData['first_name'] : 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyData = $stmt->fetch(PDO::FETCH_ASSOC);
$companyName = $companyData ? $companyData['name'] : 'Company';

// Fetch projects list
$stmt = $DB->prepare("SELECT project_id, name FROM projects WHERE company_id = ? AND status NOT IN ('archived', 'cancelled') ORDER BY name");
$stmt->execute([$companyId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing timesheet entries for this employee
$stmt = $DB->prepare(
    "SELECT e.*, p.name AS project_name, bi.name AS item_name
     FROM timesheet_entries e
     LEFT JOIN projects p ON e.project_id = p.project_id
     LEFT JOIN board_items bi ON e.item_id = bi.id
     WHERE e.company_id = ? AND e.employee_id = ?
     ORDER BY e.ts_date DESC, e.created_at DESC
     LIMIT 50"
);
$stmt->execute([$companyId, $employeeId]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timesheets – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
    <style>
        .ts-form {
            margin-bottom: 2rem;
        }
        .ts-form .row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }
        .ts-form label {
            display: flex;
            flex-direction: column;
            font-size: 0.875rem;
            flex: 1;
            min-width: 120px;
        }
        .ts-form input,
        .ts-form select {
            padding: 0.5rem;
            font-size: 1rem;
            border: 1px solid var(--fw-border);
            border-radius: 4px;
        }
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
                    <div class="fw-finance__app-name">My Timesheets</div>
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
            <h2>New Timesheet Entry</h2>
            <form id="tsForm" class="ts-form">
                <div class="row">
                    <label>
                        Date
                        <input type="date" id="tsDate" value="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                    <label>
                        Project
                        <select id="projectId">
                            <option value="">None</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo (int)$p['project_id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Task / Board Item
                        <select id="boardItemId" disabled>
                            <option value="">None</option>
                        </select>
                    </label>
                </div>
                <div class="row">
                    <label>
                        Regular Hours
                        <input type="number" id="regularHours" min="0" step="0.01" placeholder="0.00">
                    </label>
                    <label>
                        Overtime Hours
                        <input type="number" id="otHours" min="0" step="0.01" placeholder="0.00">
                    </label>
                    <label>
                        Sunday Hours
                        <input type="number" id="sundayHours" min="0" step="0.01" placeholder="0.00">
                    </label>
                    <label>
                        Public Holiday Hours
                        <input type="number" id="publicHours" min="0" step="0.01" placeholder="0.00">
                    </label>
                </div>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save Entry</button>
            </form>

            <h2>My Timesheet Entries</h2>
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Project</th>
                        <th>Task</th>
                        <th style="width:10%">Reg</th>
                        <th style="width:10%">OT</th>
                        <th style="width:10%">Sun</th>
                        <th style="width:10%">PH</th>
                        <th>Status</th>
                        <th>Approved At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="9" style="text-align:center; padding:1rem;">No entries yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $e): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($e['ts_date']); ?></td>
                                <td><?php echo $e['project_name'] ? htmlspecialchars($e['project_name']) : '-'; ?></td>
                                <td><?php echo $e['item_name'] ? htmlspecialchars($e['item_name']) : '-'; ?></td>
                                <td><?php echo number_format((float)$e['regular_hours'], 2); ?></td>
                                <td><?php echo number_format((float)$e['ot_hours'], 2); ?></td>
                                <td><?php echo number_format((float)$e['sunday_hours'], 2); ?></td>
                                <td><?php echo number_format((float)$e['public_holiday_hours'], 2); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($e['status'])); ?></td>
                                <td><?php echo $e['approved_at'] ? htmlspecialchars($e['approved_at']) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <footer class="fw-finance__footer">
            <span>Timesheets Module v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
<script src="/timesheets/js/ts.js?v=<?php echo ASSET_VERSION; ?>"></script>
<script>
    TimesheetPage.initMy();
</script>
</body>
</html>