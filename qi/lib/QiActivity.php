<?php
/**
 * QiActivity — the timeline of a quote, invoice, credit note or recurring
 * invoice (created, edited, sent, viewed, accepted, paid, ...), shown on the
 * document page. Writes are best-effort: a timeline entry must never break
 * the business write it describes.
 */
final class QiActivity
{
    public const LABELS = [
        'created'       => 'Created',
        'edited'        => 'Edited',
        'reposted'      => 'Edited — ledger updated',
        'sent'          => 'Sent',
        'send_failed'   => 'Sending failed',
        'viewed'        => 'Viewed by customer',
        'accepted'      => 'Accepted',
        'declined'      => 'Declined',
        'converted'     => 'Turned into an invoice',
        'issued'        => 'Issued',
        'payment'       => 'Payment recorded',
        'refund'        => 'Refund recorded',
        'credit'        => 'Credit applied',
        'unapplied'     => 'Credit removed',
        'approved'      => 'Approved',
        'cancelled'     => 'Cancelled',
        'voided'        => 'Voided',
        'reverted'      => 'Reopened',
        'written_off'   => 'Written off',
        'uncollectible' => 'Marked uncollectible',
        'archived'      => 'Archived',
        'restored'      => 'Restored',
        'deleted'       => 'Deleted',
        'duplicated'    => 'Duplicated',
        'generated'     => 'Invoice generated',
        'paused'        => 'Paused',
        'resumed'       => 'Resumed',
        'pay_link'      => 'Payment link created',
        'reminder'      => 'Reminder sent',
    ];

    public static function log(PDO $db, int $companyId, string $docType, int $docId, string $action, ?string $detail = null, ?int $userId = null, ?string $actor = null): void
    {
        if ($docId <= 0) return;
        try {
            if ($actor === null && $userId) {
                $st = $db->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM users WHERE id = ?");
                $st->execute([$userId]);
                $actor = (string)($st->fetchColumn() ?: '') ?: null;
            }
            $db->prepare("INSERT INTO qi_activity (company_id, doc_type, doc_id, action, detail, user_id, actor) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $companyId,
                   substr($docType, 0, 16),
                   $docId,
                   substr($action, 0, 40),
                   $detail !== null ? mb_substr($detail, 0, 500) : null,
                   $userId ?: null,
                   $actor !== null ? mb_substr($actor, 0, 190) : null,
               ]);
        } catch (Throwable $e) {
            error_log('[qi] activity log: ' . $e->getMessage());
        }
    }

    /** Newest first. */
    public static function list(PDO $db, int $companyId, string $docType, int $docId, int $limit = 100): array
    {
        try {
            $st = $db->prepare("SELECT action, detail, actor, created_at FROM qi_activity
                                 WHERE company_id = ? AND doc_type = ? AND doc_id = ?
                                 ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
            $st->execute([$companyId, $docType, $docId]);
            return array_map(fn($r) => [
                'action' => (string)$r['action'],
                'label'  => self::LABELS[$r['action']] ?? ucfirst(str_replace('_', ' ', (string)$r['action'])),
                'detail' => (string)($r['detail'] ?? ''),
                'actor'  => (string)($r['actor'] ?? ''),
                'at'     => (string)$r['created_at'],
            ], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }
}
