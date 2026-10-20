<?php
// /admin/api/billing.php — the company's plan and subscription.
//   POST {action:'change_plan', plan_id}  → moves to that plan when everything still fits
//                                           (paid seats in this company, companies on your account)
//   POST {action:'cancel'} | {action:'resume'}
require_once __DIR__ . '/_bootstrap.php';

admin_require_post();
$in = admin_input();
$action = admin_str($in, 'action', 20);
admin_rate_limit('admin-billing', 20, 600);

$latest = function () use ($DB, $ADMIN_COMPANY_ID) {
    $st = $DB->prepare("SELECT * FROM subscriptions WHERE company_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $st->execute([$ADMIN_COMPANY_ID]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

switch ($action) {
    case 'change_plan':
        $st = $DB->prepare("SELECT * FROM plans WHERE id = ?");
        $st->execute([admin_int($in, 'plan_id')]);
        $plan = $st->fetch(PDO::FETCH_ASSOC);
        if (!$plan) admin_error('That plan does not exist.', 404);
        $company = AdminKit::company($DB, $ADMIN_COMPANY_ID);
        if ((int)$company['plan_id'] === (int)$plan['id']) admin_error('You are already on the ' . $plan['name'] . ' plan.');
        $seats = AdminKit::seats($DB, $ADMIN_COMPANY_ID);
        if ($seats['used'] > (int)$plan['max_users']) {
            admin_error('The ' . $plan['name'] . ' plan has ' . (int)$plan['max_users'] . ' paid seat' . ((int)$plan['max_users'] === 1 ? '' : 's') . ', and ' . $seats['used'] . ' are in use. Suspend or remove people — or make outside accountants Bookkeepers, who use no seat — first.', 409);
        }
        $companies = fw_user_company_count($ADMIN_USER_ID);
        if ($companies > (int)$plan['max_companies']) {
            admin_error('The ' . $plan['name'] . ' plan allows ' . (int)$plan['max_companies'] . ' compan' . ((int)$plan['max_companies'] === 1 ? 'y' : 'ies') . ', and your account has ' . $companies . '.', 409);
        }
        $DB->beginTransaction();
        try {
            $DB->prepare("UPDATE companies SET plan_id = ?, max_users = ?, max_companies = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$plan['id'], $plan['max_users'], $plan['max_companies'], $ADMIN_COMPANY_ID]);
            $sub = $latest();
            if ($sub) $DB->prepare("UPDATE subscriptions SET plan_id = ?, updated_at = NOW() WHERE id = ?")->execute([$plan['id'], $sub['id']]);
            else $DB->prepare("INSERT INTO subscriptions (company_id, plan_id, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())")->execute([$ADMIN_COMPANY_ID, $plan['id']]);
            $DB->commit();
        } catch (Throwable $e) {
            if ($DB->inTransaction()) $DB->rollBack();
            throw $e;
        }
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'plan_changed', ['from' => $company['plan_name'] ?? '', 'to' => $plan['name'],
            'price' => AdminKit::money((int)($company['price_monthly_cents'] ?? 0)) . ' → ' . AdminKit::money((int)$plan['price_monthly_cents']) . ' a month'], 'company', $ADMIN_COMPANY_ID);
        admin_json(['plan' => $plan['name']]);

    case 'cancel':
    case 'resume':
        $sub = $latest();
        if (!$sub) admin_error('There is no subscription on record for this company.', 404);
        if ($action === 'cancel' && $sub['status'] === 'canceled') admin_error('The subscription is already cancelled.');
        if ($action === 'resume' && $sub['status'] !== 'canceled') admin_error('The subscription is not cancelled.');
        $DB->prepare("UPDATE subscriptions SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$action === 'cancel' ? 'canceled' : 'active', $sub['id']]);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, $action === 'cancel' ? 'subscription_canceled' : 'subscription_resumed',
            ['period ends' => $sub['current_period_end'] ? AdminKit::date($sub['current_period_end']) : '—'], 'company', $ADMIN_COMPANY_ID);
        admin_json();
}
admin_error('Unknown action');
