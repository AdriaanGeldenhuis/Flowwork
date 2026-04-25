<?php
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
