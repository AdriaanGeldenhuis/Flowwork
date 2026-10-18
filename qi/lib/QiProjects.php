<?php
// /qi/lib/QiProjects.php
// Projects on the quote and invoice forms, through the Projects API
// (projects/lib/ProjectLink.php): the project dropdown and the "new quote /
// invoice from a project" prefill. Everything is scoped to the company and to
// the projects the signed-in user may open.

require_once __DIR__ . '/../../projects/lib/autoload.php';
require_once __DIR__ . '/Currencies.php';

class QiProjects
{
    /** The user's role in the active company (auth_gate.php refreshes it). */
    private static function role(): string
    {
        return (string)($_SESSION['role'] ?? 'viewer');
    }

    /**
     * Projects for the form's dropdown: open projects the user can see, plus
     * $keepId (the document's own project) even when it has since been
     * completed or archived, so editing never drops it.
     * @return array<int, array{id:int, label:string, client_id:?int}>
     */
    public static function options(PDO $db, int $companyId, int $userId, int $keepId = 0): array
    {
        return ProjectLink::pickerProjects($db, $companyId, $userId, self::role(), $keepId > 0 ? [$keepId] : []);
    }

    /**
     * A new document opened from Projects (?project_id=N[&customer_id=M], linked
     * from a project's Money tab and the item panel). The project is used only
     * when it is this company's and the user can see it; the customer is
     * customer_id when given, else the project's client. currency is the
     * customer's preferred billing currency (as picking the customer would set).
     * @return array{project_id:int, customer_id:int, currency:?string}
     */
    public static function prefill(PDO $db, int $companyId, int $userId, $projectId, $customerId): array
    {
        $out = ['project_id' => 0, 'customer_id' => max(0, (int)$customerId), 'currency' => null];
        $projectId = (int)$projectId;
        if ($projectId > 0) {
            $visible = ProjectLink::visibleProjectIds($db, $companyId, $userId, self::role());
            if ($visible === null || isset($visible[$projectId])) {
                $st = $db->prepare("SELECT client_id FROM projects WHERE project_id = ? AND company_id = ?");
                $st->execute([$projectId, $companyId]);
                $clientId = $st->fetchColumn();
                if ($clientId !== false) {
                    $out['project_id'] = $projectId;
                    if (!$out['customer_id'] && $clientId) $out['customer_id'] = (int)$clientId;
                }
            }
        }
        if ($out['customer_id']) {
            $st = $db->prepare("SELECT currency FROM crm_accounts WHERE id = ? AND company_id = ?");
            $st->execute([$out['customer_id'], $companyId]);
            $currency = $st->fetchColumn();
            if ($currency === false) $out['customer_id'] = 0;
            elseif (Currencies::isValid($currency)) $out['currency'] = strtoupper((string)$currency);
        }
        return $out;
    }
}
