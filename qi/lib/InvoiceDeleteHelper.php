<?php
// /qi/lib/InvoiceDeleteHelper.php
// Shared helpers for invoice deletion / archival / status-change actions.
// Keeps the child-row cleanup in one place so every endpoint behaves consistently.

class InvoiceDeleteHelper
{
    public static function fetchInvoice(PDO $db, int $invoiceId, int $companyId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$invoiceId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function log(PDO $db, int $companyId, int $userId, string $action, array $details): void
    {
        $stmt = $db->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $userId,
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Remove every child row that references this invoice.
     * The invoices schema has no ON DELETE CASCADE, so the original delete
     * endpoint silently left orphans (and hit a 500 when it tried to cascade).
     */
    public static function purgeChildren(PDO $db, int $invoiceId, int $companyId): void
    {
        $children = [
            'DELETE FROM invoice_lines WHERE invoice_id = ?',
            'DELETE FROM payment_allocations WHERE invoice_id = ?',
            'DELETE FROM credit_note_allocations WHERE invoice_id = ?',
            'DELETE FROM payment_milestones WHERE entity_type = "invoice" AND entity_id = ?',
            'DELETE FROM receipt_match WHERE invoice_id = ?',
            'DELETE FROM receipt_policy_log WHERE invoice_id = ?',
        ];
        foreach ($children as $sql) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute([$invoiceId]);
            } catch (Throwable $e) {
                error_log('InvoiceDeleteHelper::purgeChildren ['.$sql.']: '.$e->getMessage());
            }
        }

        // receipt_file rows stay (actual receipt asset), just null the FK
        try {
            $stmt = $db->prepare('UPDATE receipt_file SET invoice_id = NULL WHERE invoice_id = ? AND company_id = ?');
            $stmt->execute([$invoiceId, $companyId]);
        } catch (Throwable $e) {
            error_log('InvoiceDeleteHelper receipt_file unlink: '.$e->getMessage());
        }
    }

    public static function removeCalendarEvent(PDO $db, int $invoiceId): void
    {
        try {
            $path = __DIR__ . '/../services/CalendarHook.php';
            if (!file_exists($path)) return;
            require_once $path;
            $hook = new CalendarHook($db);
            $hook->deleteEvent('invoice', $invoiceId);
        } catch (Throwable $e) {
            error_log('InvoiceDeleteHelper calendar unlink: '.$e->getMessage());
        }
    }

    /**
     * Reverse a posted journal entry for this invoice if one exists.
     * Best-effort: silently logs and continues on failure.
     */
    public static function reverseJournal(PDO $db, int $companyId, int $userId, int $invoiceId): void
    {
        try {
            $stmt = $db->prepare('SELECT journal_id FROM invoices WHERE id = ? AND company_id = ?');
            $stmt->execute([$invoiceId, $companyId]);
            $journalId = (int)$stmt->fetchColumn();
            if (!$journalId) return;

            $postingServicePath = __DIR__ . '/../../finances/lib/PostingService.php';
            if (!file_exists($postingServicePath)) return;
            require_once $postingServicePath;
            if (!class_exists('PostingService')) return;

            $posting = new PostingService($db, $companyId, $userId);
            if (method_exists($posting, 'reverseJournal')) {
                $posting->reverseJournal($journalId, 'invoice-reverse');
            }
        } catch (Throwable $e) {
            error_log('InvoiceDeleteHelper reverseJournal: '.$e->getMessage());
        }
    }
}
