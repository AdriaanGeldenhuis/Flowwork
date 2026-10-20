<?php
// /admin/companies.php — Your companies: every company your account can
// open, your role in each, and adding another one (within your plan's limit).
// Switching uses /admin/ajax/switch_company.php (shared/company_switcher.js).
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/../includes/business_types.php';

$companies = fw_user_companies($ADMIN_USER_ID);
$limit = fw_user_plan_company_limit($ADMIN_USER_ID);
$atLimit = count($companies) >= $limit['max'];
$st = $DB->prepare("SELECT company_id, COUNT(*) FROM user_companies WHERE company_id IN (SELECT company_id FROM user_companies WHERE user_id = ?) GROUP BY company_id");
$st->execute([$ADMIN_USER_ID]);
$sizes = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
$st = $DB->prepare("SELECT company_id FROM users WHERE id = ?");
$st->execute([$ADMIN_USER_ID]);
$primaryId = (int)$st->fetchColumn();

$page = 'companies';
$pageTitle = 'Your companies';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Company <i>◆</i> <?= count($companies) ?> of <?= (int)$limit['max'] ?> on the <?= $e($limit['plan']) ?> plan</p>
        <h1>Your <em>companies</em></h1>
        <p>Run more than one business from one sign-in. Each company keeps its own people, settings, books and documents; switch between them here or from the menu at the top.</p>
    </div>
    <div class="fwa-head__actions">
        <button class="btn btn--primary" type="button" id="addCompany"<?= $atLimit ? ' disabled title="Your plan’s company limit is reached"' : '' ?>><svg class="i"><use href="#i-plus"/></svg>Add a company</button>
    </div>
</header>

<?php if ($atLimit): ?>
    <div class="fwa-banner"><svg class="i"><use href="#i-info"/></svg><div class="fwa-banner__body">Your <b><?= $e($limit['plan']) ?></b> plan includes <?= (int)$limit['max'] ?> compan<?= $limit['max'] === 1 ? 'y' : 'ies' ?>. <a href="/admin/billing.php">Move to a bigger plan</a> to add another.</div></div>
<?php endif; ?>

<div class="fwa-grid">
    <?php foreach ($companies as $co): $active = (int)$co['id'] === $ADMIN_COMPANY_ID; ?>
        <section class="fwa-card fwa-co<?= $active ? ' is-active' : '' ?>">
            <header class="fwa-card__head">
                <span class="fwa-ava" style="--h:<?= AdminKit::hue((string)$co['name']) ?>"><?= $e(AdminKit::initials((string)$co['name'])) ?></span>
                <div class="fwa-card__title"><h3><?= $e($co['name']) ?></h3><p><?= $e(fw_business_type_label($co['business_type'] ?? null)) ?> · <?= (int)($sizes[(int)$co['id']] ?? 1) ?> <?= ($sizes[(int)$co['id']] ?? 1) === 1 ? 'person' : 'people' ?></p></div>
            </header>
            <div class="fwa-card__body" style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ($active): ?><span class="fwa-pill" data-tone="accent">Open now</span><?php endif; ?>
                <span class="fwa-pill fwa-pill--plain"><?= $e(AdminKit::ROLES[$co['role']][0] ?? ucfirst((string)$co['role'])) ?></span>
                <?php if ((int)$co['id'] === $primaryId): ?><span class="fwa-pill" data-tone="gold" title="Your account and plan belong to this company">Your main company</span><?php endif; ?>
                <?php if (!(int)$co['subscription_active']): ?><span class="fwa-pill" data-tone="danger">Subscription inactive</span><?php endif; ?>
            </div>
            <footer class="fwa-card__foot">
                <?php if ($active): ?>
                    <a class="btn btn--sm" href="/admin/company.php"><svg class="i"><use href="#i-pen"/></svg>Edit profile</a>
                <?php elseif ((int)$co['subscription_active']): ?>
                    <button class="btn btn--sm btn--primary" type="button" data-fw-switch-company="<?= (int)$co['id'] ?>"><svg class="i"><use href="#i-refresh"/></svg>Switch to it</button>
                <?php else: ?>
                    <span class="fwa-muted" style="font-size:12.5px">Can’t be opened until its subscription is active.</span>
                <?php endif; ?>
            </footer>
        </section>
    <?php endforeach; ?>
</div>
<?php
$pageScripts = ['companies'];
$pageBoot = ['types' => fw_business_types(), 'atLimit' => $atLimit];
include __DIR__ . '/partials/foot.php';
