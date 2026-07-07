<?php
class Audit {
    public static function log(string $action, array $data = []): void {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
            $companyId = $_SESSION['company_id'] ?? null;
            $userId    = $_SESSION['user_id'] ?? null;
            $ip        = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua        = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $payload = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $pdo = self::pdo();
            // The audit_log column set varies across this codebase's history:
            // most direct INSERTs use (details, ip, timestamp) — the finance
            // convention — auth flows use (details, ip, created_at), and this
            // class historically used (details_json, ip_address, user_agent).
            // Try each in turn (remembering the winner) so the entry lands on
            // whichever schema the deployment has, instead of silently writing
            // nothing when the first column set does not match. Safe mid-
            // transaction on MySQL: a failed statement does not poison the
            // enclosing transaction.
            static $workingVariant = null;
            $variants = [
                ["INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                  VALUES (:cid, :uid, :action, :details, :ip, NOW())",
                 [':cid' => $companyId, ':uid' => $userId, ':action' => $action, ':details' => $payload, ':ip' => $ip]],
                ["INSERT INTO audit_log (company_id, user_id, action, details, ip, created_at)
                  VALUES (:cid, :uid, :action, :details, :ip, NOW())",
                 [':cid' => $companyId, ':uid' => $userId, ':action' => $action, ':details' => $payload, ':ip' => $ip]],
                ["INSERT INTO audit_log (company_id, user_id, action, details_json, ip_address, user_agent)
                  VALUES (:cid, :uid, :action, :details, :ip, :ua)",
                 [':cid' => $companyId, ':uid' => $userId, ':action' => $action, ':details' => $payload, ':ip' => $ip, ':ua' => $ua]],
            ];
            $order = ($workingVariant === null)
                ? array_keys($variants)
                : array_unique(array_merge([$workingVariant], array_keys($variants)));
            $lastError = null;
            foreach ($order as $i) {
                [$sql, $params] = $variants[$i];
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $workingVariant = $i;
                    return;
                } catch (\Throwable $e) {
                    $lastError = $e;
                }
            }
            if ($lastError !== null) {
                error_log('Audit::log failed on all column variants: ' . $lastError->getMessage());
            }
        } catch (\Throwable $e) {
            // Never break user flow due to audit logging
        }
    }

    public static function logSql(string $sql, $params = null): void {
        // Only record mutating statements
        $s = ltrim($sql);
        if (!preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i', $s)) {
            return;
        }
        $paramArr = is_array($params) ? $params : [];
        self::log('sql', ['sql' => $sql, 'params' => $paramArr]);
    }

    private static function pdo(): \PDO {
        // Try common globals in this codebase
        if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof \PDO) {
            return $GLOBALS['DB'];
        }
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof \PDO) {
            return $GLOBALS['db'];
        }
        // Fallback: attempt to include db bootstrap if present
        $candidates = [__DIR__.'/db.php', __DIR__.'/pdo.php', __DIR__.'/bootstrap.php'];
        foreach ($candidates as $c) { if (file_exists($c)) { require_once $c; } }
        if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof \PDO) { return $GLOBALS['DB']; }
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof \PDO) { return $GLOBALS['db']; }
        throw new \RuntimeException('PDO handle not found for audit logging');
    }
}
