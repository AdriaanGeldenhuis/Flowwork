<?php
require_once __DIR__ . '/business_types.php';

// Helpers for the multi-company "active company" mechanism.
//
// `users.company_id` is the user's PRIMARY/default company (set on
// registration). The currently-active company in this browser session is
// stored in $_SESSION['active_company_id'] and may differ — provided the
// user has a row in `user_companies` for it. auth_gate.php resolves the
// effective $_SESSION['company_id'] from these on every request.

function fw_user_companies(int $userId): array
{
    global $DB;

    $stmt = $DB->prepare(
        "SELECT c.id, c.name, c.business_type, c.subscription_active, uc.role
         FROM user_companies uc
         JOIN companies c ON c.id = uc.company_id
         WHERE uc.user_id = ?
         ORDER BY c.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function fw_user_can_access_company(int $userId, int $companyId): bool
{
    global $DB;

    $stmt = $DB->prepare(
        "SELECT 1
         FROM user_companies uc
         JOIN companies c ON c.id = uc.company_id
         WHERE uc.user_id = ? AND uc.company_id = ? AND c.subscription_active = 1
         LIMIT 1"
    );
    $stmt->execute([$userId, $companyId]);
    return (bool)$stmt->fetchColumn();
}

// Role for the user inside a specific company. Prefers the per-tenant
// role on user_companies (the multi-company source of truth) and falls
// back to users.role for legacy users that pre-date user_companies.
function fw_user_role_in_company(int $userId, int $companyId): ?string
{
    global $DB;

    $stmt = $DB->prepare(
        "SELECT role FROM user_companies WHERE user_id = ? AND company_id = ?"
    );
    $stmt->execute([$userId, $companyId]);
    $role = $stmt->fetchColumn();
    if ($role !== false) {
        return (string)$role;
    }

    $stmt = $DB->prepare(
        "SELECT role FROM users WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$userId, $companyId]);
    $role = $stmt->fetchColumn();
    return $role !== false ? (string)$role : null;
}

function fw_active_user_is_admin(): bool
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        return false;
    }
    $role = fw_user_role_in_company(
        (int)$_SESSION['user_id'],
        (int)$_SESSION['company_id']
    );
    return $role === 'admin';
}

// Drop-in replacement for the old "SELECT role FROM users ..." block at
// the top of every admin page. $format='json' emits a JSON 403 instead
// of a HTML one, for AJAX endpoints.
function fw_require_admin(string $format = 'html'): void
{
    if (fw_active_user_is_admin()) {
        return;
    }

    http_response_code(403);
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'Admin access required']);
    } else {
        echo 'Access denied - Admin only';
    }
    exit;
}

function fw_resolve_active_company_id(int $userId, int $defaultCompanyId): int
{
    $candidate = (int)($_SESSION['active_company_id'] ?? 0);

    if ($candidate > 0 && $candidate !== $defaultCompanyId
        && fw_user_can_access_company($userId, $candidate)) {
        return $candidate;
    }

    unset($_SESSION['active_company_id']);
    return $defaultCompanyId;
}

function fw_set_active_company(int $userId, int $companyId): bool
{
    if (!fw_user_can_access_company($userId, $companyId)) {
        return false;
    }
    $_SESSION['active_company_id'] = $companyId;
    return true;
}

function fw_user_company_count(int $userId): int
{
    global $DB;
    $stmt = $DB->prepare("SELECT COUNT(*) FROM user_companies WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

// Plan limit comes from the user's primary company (the one set on
// users.company_id at registration). We read max_companies from the
// linked plan so that updates to the plans table apply immediately,
// rather than the per-company snapshot that was copied at sign-up time.
function fw_user_plan_company_limit(int $userId): array
{
    global $DB;
    $stmt = $DB->prepare(
        "SELECT COALESCE(p.max_companies, c.max_companies) AS max_companies,
                p.name AS plan_name
         FROM users u
         JOIN companies c ON c.id = u.company_id
         LEFT JOIN plans p ON p.id = c.plan_id
         WHERE u.id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'max'  => (int)($row['max_companies'] ?? 1),
        'plan' => $row['plan_name'] ?? 'Solo',
    ];
}

function fw_create_company_for_user(int $userId, string $name, string $businessType): int
{
    global $DB;

    $name = trim($name);
    if ($name === '') {
        throw new Exception('Company name is required.');
    }
    if (!in_array($businessType, fw_business_type_keys(), true)) {
        $businessType = 'construction';
    }

    $limit = fw_user_plan_company_limit($userId);
    $count = fw_user_company_count($userId);
    if ($count >= $limit['max']) {
        throw new Exception(sprintf(
            'Your %s plan allows %d compan%s. Upgrade your plan to add more.',
            $limit['plan'],
            $limit['max'],
            $limit['max'] === 1 ? 'y' : 'ies'
        ));
    }

    // Inherit plan + seat limits from the user's primary company so the
    // new tenant matches the existing subscription. Prefer the live values
    // on the plans row over the per-company snapshot, so plan upgrades
    // (e.g. Business 3 -> 5 companies) apply without a backfill.
    $stmt = $DB->prepare(
        "SELECT c.plan_id,
                COALESCE(p.max_users, c.max_users) AS max_users,
                COALESCE(p.max_companies, c.max_companies) AS max_companies
         FROM companies c
         LEFT JOIN plans p ON p.id = c.plan_id
         WHERE c.id = (SELECT company_id FROM users WHERE id = ?)"
    );
    $stmt->execute([$userId]);
    $primary = $stmt->fetch();
    if (!$primary) {
        throw new Exception('Could not load your subscription plan.');
    }

    $DB->beginTransaction();
    try {
        $stmt = $DB->prepare(
            "INSERT INTO companies
                (name, business_type, plan_id, max_users, max_companies,
                 subscription_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())"
        );
        $stmt->execute([
            $name,
            $businessType,
            $primary['plan_id'],
            $primary['max_users'],
            $primary['max_companies'],
        ]);
        $newCompanyId = (int)$DB->lastInsertId();

        $stmt = $DB->prepare(
            "INSERT INTO user_companies (user_id, company_id, role, created_at)
             VALUES (?, ?, 'admin', NOW())"
        );
        $stmt->execute([$userId, $newCompanyId]);

        $DB->commit();
        return $newCompanyId;
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
}
