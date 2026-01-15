<?php
// /payroll/payslips.php
// Display payslips for a specific payroll run and allow regeneration.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$runId     = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;

if (!$runId) {
    header('Location: /payroll/runs.php');
    exit;
}

// Fetch run details
$stmt = $DB->prepare(
    "SELECT * FROM pay_runs WHERE id = ? AND company_id = ?"
);
$stmt->execute([$runId, $companyId]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    header('Location: /payroll/runs.php');
    exit;
}

// Fetch employees and payslip paths
$stmt = $DB->prepare(
    "SELECT pre.*, e.first_name, e.last_name, e.employee_no
     FROM pay_run_employees pre
     JOIN employees e ON e.id = pre.employee_id
     WHERE pre.run_id = ? AND pre.company_id = ?
     ORDER BY e.last_name, e.first_name"
);
$stmt->execute([$runId, $companyId]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if all payslips exist
$allGenerated = true;
foreach ($employees as $emp) {
    if (empty($emp['payslip_path'])) {
        $allGenerated = false;
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslips – <?= htmlspecialchars($run['name']) ?></title>
    <link rel="stylesheet" href="/payroll/assets/payroll.css?v=2025-01-21-PAYROLL-1">
    <style>
        .ps-message { margin: 16px 0; }
        .ps-message.success { color: green; }
        .ps-message.error { color: red; }
        .ps-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .ps-table th, .ps-table td { border: 1px solid #ccc; padding: 6px; }
        .ps-table th { background: #f5f5f5; text-align: left; }
    </style>
</head>
<body>
<main class="fw-payroll">
    <div class="fw-payroll__container">
        <header class="fw-payroll__header">
            <div class="fw-payroll__brand">
                <div class="fw-payroll__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                        <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                        <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                        <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
                <div class="fw-payroll__brand-text">
                    <div class="fw-payroll__company-name">
                        <?php
                        // Company name
                        $stmtC = $DB->prepare("SELECT name FROM companies WHERE id = ?");
                        $stmtC->execute([$companyId]);
                        $comp = $stmtC->fetch();
                        echo htmlspecialchars($comp['name'] ?? 'Company');
                        ?>
                    </div>
                    <div class="fw-payroll__app-name">Payroll</div>
                </div>
            </div>
            <div class="fw-payroll__greeting">
                Payslips for Run
            </div>
        </header>
        <div class="fw-payroll__main">
            <h1 class="fw-payroll__page-title">Payslips – <?= htmlspecialchars($run['name']) ?></h1>
            <p class="fw-payroll__page-subtitle">
                Period: <?= date('d M Y', strtotime($run['period_start'])) ?> – <?= date('d M Y', strtotime($run['period_end'])) ?>
                • Pay Date: <?= date('d M Y', strtotime($run['pay_date'])) ?>
            </p>

            <div id="psMessage" class="ps-message"></div>
            <div class="fw-payroll__action-bar">
                <button class="fw-payroll__btn fw-payroll__btn--primary" id="generateBtn" <?php if ($allGenerated) echo 'style="display:none"'; ?> onclick="generatePayslips()">
                    🔄 Generate Payslips
                </button>
                <?php if ($allGenerated): ?>
                    <div class="fw-payroll__alert fw-payroll__alert--success">All payslips generated.</div>
                <?php endif; ?>
            </div>

            <table class="ps-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Employee No.</th>
                        <th>Net Pay</th>
                        <th>Payslip</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                    <?php
                        $net = isset($emp['net_cents']) ? ((float)$emp['net_cents'] / 100) : 0.0;
                        $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                        $psPath = $emp['payslip_path'] ?? '';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($empName) ?></td>
                        <td><?= htmlspecialchars($emp['employee_no'] ?? '') ?></td>
                        <td>R <?= number_format($net, 2) ?></td>
                        <td>
                            <?php if ($psPath): ?>
                                <a href="<?= htmlspecialchars($psPath) ?>" target="_blank" class="fw-payroll__btn fw-payroll__btn--secondary">Download</a>
                            <?php else: ?>
                                <span style="color:#888">Not generated</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="fw-payroll__footer">
            <span>Payroll v2025-01-21-PAYROLL-1</span>
        </footer>
    </div>
</main>

<script>
function generatePayslips() {
    const msg = document.getElementById('psMessage');
    const btn = document.getElementById('generateBtn');
    msg.className = 'ps-message';
    msg.textContent = 'Generating payslips...';
    btn.disabled = true;
    fetch('/payroll/ajax/payslip_generate.php?run_id=<?= $runId ?>')
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                msg.className = 'ps-message success';
                msg.textContent = 'Payslips generated: ' + data.count;
                // Reload after short delay to refresh list
                setTimeout(() => { location.reload(); }, 2000);
            } else {
                msg.className = 'ps-message error';
                msg.textContent = data.error || 'Generation failed';
                btn.disabled = false;
            }
        })
        .catch(err => {
            msg.className = 'ps-message error';
            msg.textContent = 'Network error';
            btn.disabled = false;
        });
}
</script>
</body>
</html>