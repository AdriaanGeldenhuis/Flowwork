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

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_data') {
    try {
        $settings = [
            'data_retention_audit_days' => trim($_POST['data_retention_audit_days'] ?? '365'),
            'data_retention_receipts_days' => trim($_POST['data_retention_receipts_days'] ?? '2555'),
            'data_auto_cleanup' => isset($_POST['data_auto_cleanup']) ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'data_settings_updated', 'Updated data & backup settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Data settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'data_retention_audit_days' => getCRMSetting('data_retention_audit_days', '365'),
    'data_retention_receipts_days' => getCRMSetting('data_retention_receipts_days', '2555'),
    'data_auto_cleanup' => getCRMSetting('data_auto_cleanup', '0'),
];

// Calculate data usage
$stats = [];
$tables = [
    ['label' => 'Users', 'query' => "SELECT COUNT(*) FROM users WHERE company_id = ?"],
    ['label' => 'Projects', 'query' => "SELECT COUNT(*) FROM projects WHERE company_id = ?"],
    ['label' => 'Boards', 'query' => "SELECT COUNT(*) FROM project_boards WHERE company_id = ?"],
    ['label' => 'Audit Log Entries', 'query' => "SELECT COUNT(*) FROM audit_log WHERE company_id = ?"],
    ['label' => 'Invites', 'query' => "SELECT COUNT(*) FROM invites WHERE company_id = ?"],
];

foreach ($tables as $table) {
    try {
        $stmt = $DB->prepare($table['query']);
        $stmt->execute([$companyId]);
        $stats[] = ['label' => $table['label'], 'count' => $stmt->fetchColumn()];
    } catch (Exception $e) {
        $stats[] = ['label' => $table['label'], 'count' => '—'];
    }
}
?>
<?php
  $pageTitle = 'Data & Backups – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Data & Backups</h1>
                    <p class="fw-admin__page-subtitle">Export data, manage retention policies, and download backups</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="fw-admin__alert fw-admin__alert--success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="fw-admin__alert fw-admin__alert--error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Data Overview -->
            <div class="fw-admin__card">
                <h2 class="fw-admin__card-title">Data Overview</h2>
                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>Data Type</th>
                                <th>Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats as $stat): ?>
                            <tr>
                                <td style="font-weight: 600;"><?= htmlspecialchars($stat['label']) ?></td>
                                <td><?= number_format((int)$stat['count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Export Data -->
            <div class="fw-admin__card">
                <h2 class="fw-admin__card-title">Export Data</h2>
                <div class="fw-admin__action-list">
                    <button class="fw-admin__action-item" onclick="exportData('users')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                        </svg>
                        <div>
                            <div class="fw-admin__action-title">Export Users</div>
                            <div class="fw-admin__action-desc">Download all user data as CSV</div>
                        </div>
                    </button>

                    <button class="fw-admin__action-item" onclick="exportData('projects')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7"/>
                            <rect x="14" y="3" width="7" height="7"/>
                            <rect x="3" y="14" width="7" height="7"/>
                            <rect x="14" y="14" width="7" height="7"/>
                        </svg>
                        <div>
                            <div class="fw-admin__action-title">Export Projects</div>
                            <div class="fw-admin__action-desc">Download all project data as CSV</div>
                        </div>
                    </button>

                    <button class="fw-admin__action-item" onclick="exportData('audit')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                        <div>
                            <div class="fw-admin__action-title">Export Audit Log</div>
                            <div class="fw-admin__action-desc">Download full audit log as CSV (up to 10,000 records)</div>
                        </div>
                    </button>

                    <button class="fw-admin__action-item" onclick="exportData('all')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <div>
                            <div class="fw-admin__action-title">Export Summary</div>
                            <div class="fw-admin__action-desc">Download a summary of all company data</div>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Retention Policy -->
            <form method="POST">
                <input type="hidden" name="action" value="save_data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Data Retention Policy</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Audit Log Retention (days)</label>
                            <select name="data_retention_audit_days" class="fw-admin__select">
                                <option value="90" <?= $s['data_retention_audit_days'] === '90' ? 'selected' : '' ?>>90 days</option>
                                <option value="180" <?= $s['data_retention_audit_days'] === '180' ? 'selected' : '' ?>>180 days</option>
                                <option value="365" <?= $s['data_retention_audit_days'] === '365' ? 'selected' : '' ?>>1 year</option>
                                <option value="730" <?= $s['data_retention_audit_days'] === '730' ? 'selected' : '' ?>>2 years</option>
                                <option value="1825" <?= $s['data_retention_audit_days'] === '1825' ? 'selected' : '' ?>>5 years</option>
                                <option value="0" <?= $s['data_retention_audit_days'] === '0' ? 'selected' : '' ?>>Keep forever</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Receipt Retention (days)</label>
                            <select name="data_retention_receipts_days" class="fw-admin__select">
                                <option value="365" <?= $s['data_retention_receipts_days'] === '365' ? 'selected' : '' ?>>1 year</option>
                                <option value="730" <?= $s['data_retention_receipts_days'] === '730' ? 'selected' : '' ?>>2 years</option>
                                <option value="1825" <?= $s['data_retention_receipts_days'] === '1825' ? 'selected' : '' ?>>5 years</option>
                                <option value="2555" <?= $s['data_retention_receipts_days'] === '2555' ? 'selected' : '' ?>>7 years</option>
                                <option value="0" <?= $s['data_retention_receipts_days'] === '0' ? 'selected' : '' ?>>Keep forever</option>
                            </select>
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">SARS requires 5+ years for tax records</small>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="data_auto_cleanup" value="1" <?= $s['data_auto_cleanup'] === '1' ? 'checked' : '' ?>>
                                <span>Automatically clean up expired data based on retention policy</span>
                            </label>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Retention Settings</button>
                    </div>
                </div>
            </form>

            <!-- Danger Zone -->
            <div class="fw-admin__card" style="border-color: var(--fw-danger);">
                <h2 class="fw-admin__card-title" style="color: var(--fw-danger);">Danger Zone</h2>
                <div class="fw-admin__action-list">
                    <div class="fw-admin__alert fw-admin__alert--warning">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        These actions cannot be undone. Please export your data before proceeding.
                    </div>
                </div>
            </div>
<script>
function exportData(type) {
    window.location.href = '/admin/api.php?action=export_data&type=' + encodeURIComponent(type);
}
</script>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
