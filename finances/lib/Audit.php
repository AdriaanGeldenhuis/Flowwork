<?php
// finances/lib/Audit.php
//
// Central audit-trail writer for the finance module. Writes to the shared
// audit_log table using its actual columns:
//   (company_id, user_id, action, entity_type, entity_id, details, ip, timestamp)
//
// Context (PDO, company, user) can be passed explicitly so that non-web
// callers (webhooks, cron, CLI tools) audit correctly; when omitted it falls
// back to the session and the global $DB connection.
//
// A failed audit write never breaks the business flow, but it is ALWAYS
// reported to the error log — a silently dead audit trail is itself a
// compliance defect (see FINANCE-SARS-FINDINGS.md).

class Audit
{
    /** @var PDO|null explicit connection for non-web contexts */
    private static ?PDO $pdo = null;

    /** Set an explicit PDO (webhooks/cron/CLI). Optional for web requests. */
    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Write an audit event.
     *
     * @param string      $action     e.g. 'journal_posted', 'vat_period_filed'
     * @param array       $details    structured payload, stored as JSON
     * @param string|null $entityType e.g. 'journal', 'invoice', 'vat_period'
     * @param int|null    $entityId
     * @param int|null    $companyId  defaults to the session company
     * @param int|null    $userId     defaults to the session user
     */
    public static function log(
        string $action,
        array $details = [],
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $companyId = null,
        ?int $userId = null
    ): void {
        try {
            if ($companyId === null || $userId === null) {
                if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
                    @session_start();
                }
                $companyId = $companyId ?? ($_SESSION['company_id'] ?? null);
                $userId    = $userId ?? ($_SESSION['user_id'] ?? null);
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                "INSERT INTO audit_log
                    (company_id, user_id, action, entity_type, entity_id, details, ip, timestamp)
                 VALUES (:cid, :uid, :action, :etype, :eid, :details, :ip, NOW())"
            );
            $stmt->execute([
                ':cid'     => $companyId,
                ':uid'     => $userId,
                ':action'  => $action,
                ':etype'   => $entityType,
                ':eid'     => $entityId,
                ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip'      => $ip,
            ]);
        } catch (\Throwable $e) {
            // Never break the user flow — but never fail silently either.
            error_log('AUDIT WRITE FAILED (' . $action . '): ' . $e->getMessage());
        }
    }

    private static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof PDO) {
            self::$pdo = $GLOBALS['DB'];
            return self::$pdo;
        }
        require_once dirname(__DIR__, 2) . '/db.php';
        if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof PDO) {
            self::$pdo = $GLOBALS['DB'];
            return self::$pdo;
        }
        throw new RuntimeException('PDO handle not found for audit logging');
    }
}
