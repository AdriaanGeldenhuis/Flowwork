<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../includes/business_types.php';
require_once __DIR__ . '/../includes/companies.php';

fw_require_admin();

$userId = (int)$_SESSION['user_id'];
$error  = '';
$prefill = [
    'name'          => '',
    'business_type' => 'construction',
];

$limit = fw_user_plan_company_limit($userId);
$count = fw_user_company_count($userId);
$atLimit = $count >= $limit['max'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prefill['name']          = trim($_POST['name'] ?? '');
    $prefill['business_type'] = trim($_POST['business_type'] ?? 'construction');

    try {
        $newCompanyId = fw_create_company_for_user(
            $userId,
            $prefill['name'],
            $prefill['business_type']
        );

        fw_set_active_company($userId, $newCompanyId);

        try {
            $stmt = $DB->prepare(
                "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                 VALUES (?, ?, 'company_create', ?, ?, NOW())"
            );
            $stmt->execute([
                $newCompanyId,
                $userId,
                json_encode(['name' => $prefill['name']]),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (Exception $e) {
            error_log('company_create audit log error: ' . $e->getMessage());
        }

        redirect('/admin/companies.php?created=1');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Add Company – Admin';
include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Add Company</h1>
                    <p class="fw-admin__page-subtitle">Create another company under your account (<?= (int)$count ?> of <?= (int)$limit['max'] ?> used on the <?= htmlspecialchars($limit['plan']) ?> plan).</p>
                </div>
            </header>

            <?php if ($error): ?>
                <div class="fw-admin__alert fw-admin__alert--error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($atLimit && !$error): ?>
                <div class="fw-admin__alert fw-admin__alert--error">
                    You've reached the company limit on your current plan. <a href="/admin/billing.php">Upgrade your plan</a> to add more.
                </div>
            <?php endif; ?>

            <form method="POST" class="fw-admin__form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Basic Information</h2>

                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">
                                Company Name <span class="fw-admin__required">*</span>
                            </label>
                            <input type="text" name="name" class="fw-admin__input"
                                   value="<?= htmlspecialchars($prefill['name']) ?>"
                                   placeholder="e.g. Concreto Civils" required <?= $atLimit ? 'disabled' : '' ?>>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Business Type</label>
                            <select name="business_type" class="fw-admin__select" <?= $atLimit ? 'disabled' : '' ?>>
                                <?php foreach (fw_business_types() as $btKey => $btLabel): ?>
                                    <option value="<?= htmlspecialchars($btKey) ?>" <?= $prefill['business_type'] === $btKey ? 'selected' : '' ?>><?= htmlspecialchars($btLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="fw-admin__form-actions">
                    <a href="/admin/companies.php" class="fw-admin__btn">Cancel</a>
                    <button type="submit" class="fw-admin__btn fw-admin__btn--primary" <?= $atLimit ? 'disabled' : '' ?>>
                        Create Company
                    </button>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
