<?php
// /crm/ajax/_helpers.php
// Shared helpers for CRM AJAX endpoints and admin pages: JSON responses,
// safe error exposure, and the tenant role model (viewer < member < admin < owner).
// Include after init.php + auth_gate.php.

const CRM_ACCOUNT_STATUSES = ['active', 'inactive', 'prospect', 'banned'];

function crm_json_ok(array $data = [])
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['ok' => true] + $data);
    exit;
}

function crm_json_fail(string $message, int $httpCode = 400)
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code($httpCode);
    }
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/**
 * Message that is safe to show the client. Endpoints throw plain
 * `new Exception(...)` for intentional validation messages — those pass
 * through. Anything else (PDOException, TypeError, ...) is an internal
 * error: log it and return a generic message instead.
 */
function crm_public_error(Throwable $e): string
{
    if (get_class($e) === Exception::class) {
        return $e->getMessage();
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'ajax');
    error_log('CRM ' . $script . ' error: ' . $e->getMessage());
    return 'Server error';
}

/**
 * The project an opportunity was converted into: [project_id, project_number,
 * name], or null (never converted, or the project has since been deleted).
 * crm_opportunities has no project column, so opportunity_convert.php keeps
 * the link in audit_log (action 'convert', details {"project_id": N}).
 */
function crm_opp_project(PDO $DB, int $companyId, int $oppId): ?array
{
    $stmt = $DB->prepare("SELECT details FROM audit_log
                          WHERE company_id = ? AND action = 'convert' AND entity_type = 'crm_opportunity' AND entity_id = ?
                          ORDER BY id DESC LIMIT 10");
    $stmt->execute([$companyId, $oppId]);
    $find = $DB->prepare("SELECT project_id, project_number, name FROM projects WHERE project_id = ? AND company_id = ?");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $details) {
        $projectId = (int)(json_decode((string)$details, true)['project_id'] ?? 0);
        if ($projectId <= 0) continue;
        $find->execute([$projectId, $companyId]);
        if ($row = $find->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    return null;
}

function crm_role_rank(?string $role): int
{
    $ranks = ['viewer' => 0, 'member' => 1, 'admin' => 2, 'owner' => 3];
    $key = strtolower((string)$role);
    return isset($ranks[$key]) ? $ranks[$key] : 0;
}

/**
 * Stop the request unless the session role is at least $minRole.
 * $format 'json' emits a 403 JSON body; 'html' redirects to the CRM home.
 * Relies on auth_gate.php having refreshed $_SESSION['role'] for the
 * active company.
 */
function crm_require_min_role(string $minRole, string $format = 'json')
{
    $role = $_SESSION['role'] ?? 'viewer';
    if (crm_role_rank($role) >= crm_role_rank($minRole)) {
        return;
    }
    if ($format === 'html') {
        header('Location: /crm/');
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(403);
    }
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}
