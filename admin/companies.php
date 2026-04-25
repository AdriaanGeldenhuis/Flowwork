<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../includes/companies.php';

fw_require_admin();

$userId          = (int)$_SESSION['user_id'];
$activeCompanyId = (int)$_SESSION['company_id'];
$companies       = fw_user_companies($userId);
$limit           = fw_user_plan_company_limit($userId);
$count           = count($companies);
$atLimit         = $count >= $limit['max'];

$pageTitle = 'Companies – Admin';
include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Companies</h1>
                    <p class="fw-admin__page-subtitle">Switch between, or add another, company tied to your account (<?= (int)$count ?> of <?= (int)$limit['max'] ?> on the <?= htmlspecialchars($limit['plan']) ?> plan).</p>
                </div>
                <?php if ($atLimit): ?>
                    <button type="button" class="fw-admin__btn fw-admin__btn--primary" disabled
                            title="Plan limit reached. Upgrade to add more companies.">
                        + Add Company
                    </button>
                <?php else: ?>
                    <a href="/admin/company_new.php" class="fw-admin__btn fw-admin__btn--primary">
                        + Add Company
                    </a>
                <?php endif; ?>
            </header>

            <?php if (!empty($_GET['created'])): ?>
                <div class="fw-admin__alert fw-admin__alert--success">
                    Company created and set as active.
                </div>
            <?php endif; ?>

            <div class="fw-admin__card">
                <div class="fw-admin__table-wrapper">
                <table class="fw-admin__table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($companies as $c): ?>
                            <?php $isActive = ((int)$c['id'] === $activeCompanyId); ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($c['name']) ?></strong>
                                    <?php if ($isActive): ?>
                                        <span class="fw-admin__badge fw-admin__badge--success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($c['role'])) ?></td>
                                <td><?= $c['subscription_active'] ? 'Subscribed' : 'Inactive' ?></td>
                                <td style="text-align:right;">
                                    <?php if ($isActive): ?>
                                        <a href="/admin/company.php" class="fw-admin__btn">Edit profile</a>
                                    <?php else: ?>
                                        <button type="button"
                                                class="fw-admin__btn fw-admin__btn--primary"
                                                data-fw-switch-company="<?= (int)$c['id'] ?>">
                                            Switch to
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
<script src="/shared/company_switcher.js?v=2026-04-25-2"></script>
