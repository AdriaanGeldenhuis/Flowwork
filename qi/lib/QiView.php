<?php
/**
 * QiView — what the document page (qi/view.php) shows: the paper, a summary
 * (total, paid, balance, due), the customer, payments, credits, the payment
 * schedule, the timeline and emails — and exactly which actions the
 * document's current state allows for this user. The page renders buttons
 * from that list; qi/api/action.php enforces the same rules again.
 */

require_once __DIR__ . '/QiDocument.php';
require_once __DIR__ . '/QiEditor.php';
require_once __DIR__ . '/QiActivity.php';
require_once __DIR__ . '/QiWriter.php';
require_once __DIR__ . '/QiYoco.php';
require_once __DIR__ . '/QiRecurring.php';
require_once __DIR__ . '/DocHtml.php';
require_once __DIR__ . '/QiTheme.php';
require_once __DIR__ . '/../services/QiMail.php';

final class QiView
{
    private const METHODS = ['eft' => 'EFT', 'card' => 'Card', 'cash' => 'Cash', 'cheque' => 'Cheque', 'yoco' => 'Yoco (card)', 'other' => 'Other'];

    public static function payload(PDO $db, int $companyId, int $userId, string $role, string $type, int $id): ?array
    {
        $writer = $role !== 'viewer';
        $admin = in_array($role, ['admin', 'bookkeeper'], true);
        if ($type === 'recurring') return self::recurring($db, $companyId, $writer, $id);
        $row = QiWriter::lockRow($db, $companyId, $type, $id);
        if (!$row) return null;
        $m = QiDocument::load($db, $companyId, $type, $id);
        if (!$m) return null;
        $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $m['company']['raw'] = $company;
        $design = QiTheme::load($db, $companyId, $company);
        $t = $m['totals'];
        $paid = array_sum(array_column($m['payments'] ?? [], 'amount'));
        $credited = array_sum(array_column($m['credits'] ?? [], 'amount'));
        $lock = QiWriter::lockState($db, $companyId, $type, $row);

        $out = [
            'type' => $type,
            'id' => $id,
            'number' => $m['number'],
            'status' => $m['status'],
            'raw_status' => (string)$row['status'],
            'html' => DocHtml::render($m, $design, ['editable' => false]),
            'symbol' => $m['symbol'],
            'currency' => $m['currency'],
            'summary' => [
                'total' => (float)$t['total'],
                'paid' => round($paid, 2),
                'credited' => round($credited, 2),
                'balance' => $type === 'invoice' ? (float)($t['balance'] ?? 0) : null,
                'written_off' => (float)($row['write_off_amount'] ?? 0),
                'issue_date' => $m['issue_date'],
                'due_date' => $m['due_date'] ?? null,
                'expiry_date' => $m['expiry_date'] ?? null,
                'reference' => $m['reference'],
                'exchange_rate' => $m['currency'] !== Currencies::BASE ? $m['exchange_rate'] : null,
            ],
            'customer' => QiEditor::customerCard($db, $companyId, (int)$row['customer_id']),
            'project' => !empty($row['project_id']) && $m['project'] ? ['id' => (int)$row['project_id'], 'name' => $m['project'], 'url' => '/projects/project.php?id=' . (int)$row['project_id']] : null,
            'links' => ['public' => $m['links']['public'] ?? null, 'pay' => $m['links']['pay'] ?? null,
                        'pdf' => '/qi/ajax/download_pdf.php?type=' . $type . '&id=' . $id, 'edit' => '/qi/edit.php?type=' . $type . '&id=' . $id],
            'milestones' => $m['milestones'] ?? [],
            'payments' => [],
            'credits' => [],
            'credit_notes' => [],
            'related' => [],
            'activity' => QiActivity::list($db, $companyId, $type, $id),
            'emails' => QiMail::history($db, $companyId, $type, $id),
            'lock' => $lock,
            'deleted' => !empty($row['deleted_at']),
            'archived' => !empty($row['archived_at']),
            'can_write' => $writer,
            'is_admin' => $admin,
            'can_email' => $writer && QiMail::accounts($db, $companyId, $userId) !== [],
            'yoco' => $type === 'invoice' && QiYoco::configured($db, $companyId),
        ];
        if ($type === 'quote') {
            $out['accepted'] = $m['accepted'] ?? null;
            if (!empty($m['invoice'])) $out['related'][] = ['type' => 'invoice', 'id' => $m['invoice']['id'], 'number' => $m['invoice']['number'], 'label' => 'Invoice'];
            $out['viewed_at'] = $row['viewed_at'] ?? null;
            $out['sent_at'] = $row['sent_at'] ?? null;
        }
        if ($type === 'invoice') {
            $st = $db->prepare("SELECT p.id, p.payment_date, p.method, p.reference, p.notes, pa.amount, p.refunded_at
                                  FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.company_id = ?
                                 WHERE pa.invoice_id = ? ORDER BY p.payment_date, p.id");
            $st->execute([$companyId, $id]);
            $out['payments'] = array_map(fn($r) => [
                'id' => (int)$r['id'], 'date' => $r['payment_date'], 'method' => $r['method'] === 'refund' ? 'Refund' : (self::METHODS[$r['method']] ?? ucfirst((string)$r['method'])),
                'reference' => (string)($r['reference'] ?? ''), 'amount' => (float)$r['amount'], 'refund' => $r['method'] === 'refund' || (float)$r['amount'] < 0,
                'refunded' => !empty($r['refunded_at']),
            ], $st->fetchAll(PDO::FETCH_ASSOC));
            $out['credits'] = $m['credits'] ?? [];
            $st = $db->prepare("SELECT id, credit_note_number, status, total, issue_date FROM credit_notes WHERE invoice_id = ? AND company_id = ? ORDER BY id");
            $st->execute([$id, $companyId]);
            $out['credit_notes'] = array_map(fn($r) => ['id' => (int)$r['id'], 'number' => $r['credit_note_number'], 'total' => (float)$r['total'],
                'status' => QiDocument::status('credit_note', $r), 'date' => $r['issue_date']], $st->fetchAll(PDO::FETCH_ASSOC));
            if (!empty($m['quote'])) $out['related'][] = ['type' => 'quote', 'id' => $m['quote']['id'], 'number' => $m['quote']['number'], 'label' => 'From quote'];
            if (!empty($row['recurring_invoice_id'])) {
                $st = $db->prepare("SELECT template_name FROM recurring_invoices WHERE id = ? AND company_id = ?");
                $st->execute([(int)$row['recurring_invoice_id'], $companyId]);
                if (($name = $st->fetchColumn()) !== false) $out['related'][] = ['type' => 'recurring', 'id' => (int)$row['recurring_invoice_id'], 'number' => $name, 'label' => 'Recurring'];
            }
            $out['viewed_at'] = $row['viewed_at'] ?? null;
            $out['sent_at'] = $row['sent_at'] ?? null;
        }
        if ($type === 'credit_note') {
            if (!empty($m['invoice'])) $out['related'][] = ['type' => 'invoice', 'id' => $m['invoice']['id'], 'number' => $m['invoice']['number'], 'label' => 'Credits invoice'];
            $st = $db->prepare("SELECT a.amount, a.invoice_id, i.invoice_number FROM credit_note_allocations a JOIN invoices i ON i.id = a.invoice_id AND i.company_id = a.company_id
                                 WHERE a.credit_note_id = ? AND a.company_id = ? ORDER BY a.id");
            $st->execute([$id, $companyId]);
            $out['allocations'] = array_map(fn($r) => ['invoice_id' => (int)$r['invoice_id'], 'number' => $r['invoice_number'], 'amount' => (float)$r['amount']], $st->fetchAll(PDO::FETCH_ASSOC));
            $out['summary']['applied'] = array_sum(array_column($out['allocations'], 'amount'));
            $out['summary']['unapplied'] = round((float)$t['total'] - $out['summary']['applied'], 2);
            $out['reason'] = (string)($row['reason'] ?? '');
            $out['apply_to'] = $row['status'] === 'approved' ? QiEditor::creditableInvoices($db, $companyId, (int)$row['customer_id']) : [];
            $out['apply_to'] = array_values(array_filter(array_map(function ($i) use ($db, $companyId) {
                $st = $db->prepare("SELECT balance_due FROM invoices WHERE id = ? AND company_id = ?");
                $st->execute([$i['id'], $companyId]);
                $i['balance'] = (float)$st->fetchColumn();
                return $i;
            }, $out['apply_to']), fn($i) => $i['balance'] > 0.004 && $i['currency'] === ($row['currency'] ?: 'ZAR')));
        }
        $out['actions'] = self::actions($type, $row, $out, $writer, $admin);
        return $out;
    }

    /**
     * Allowed actions, primary first. Each: key, label, icon, tone (primary|danger|''), group (main|more).
     */
    public static function actions(string $type, array $row, array $v, bool $writer, bool $admin): array
    {
        $a = [];
        $add = function (string $key, string $label, string $icon, string $group = 'more', string $tone = '') use (&$a) {
            $a[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'group' => $group, 'tone' => $tone];
        };
        $s = (string)$row['status'];
        $edit = $writer && $v['lock']['level'] !== 'full';
        if ($type === 'quote') {
            $expired = !empty($row['expiry_date']) && $row['expiry_date'] < date('Y-m-d');
            if ($writer && in_array($s, ['draft', 'sent', 'viewed'], true) && !$expired) $add('send', $s === 'draft' ? 'Send quote' : 'Send again', 'send', 'main', $s === 'draft' ? 'primary' : '');
            if ($writer && in_array($s, ['draft', 'sent', 'viewed', 'expired', 'accepted'], true)) $add('convert', 'Turn into invoice', 'iq-convert', 'main', in_array($s, ['sent', 'viewed', 'accepted'], true) ? 'primary' : '');
            if ($edit) $add('edit', $s === 'declined' || $expired ? 'Revise' : 'Edit', 'pen', 'main');
            $add('pdf', 'PDF', 'download', 'main');
            if ($writer && in_array($s, ['draft', 'sent', 'viewed', 'expired', 'declined'], true)) $add('accept', 'Mark as accepted', 'iq-accept');
            if ($writer && in_array($s, ['draft', 'sent', 'viewed', 'expired', 'accepted'], true) && empty($v['related'])) $add('decline', 'Mark as declined', 'iq-decline');
            if ($writer && $s === 'draft') $add('mark_sent', 'Mark as sent', 'check');
            if ($writer) $add('link', 'Copy customer link', 'link');
            if (!empty($row['expiry_date'])) $add('calendar', 'Show in calendar', 'calendar');
            if ($writer) $add('duplicate', 'Duplicate', 'copy');
            if ($writer && $s !== 'converted' && empty($v['related'])) $add('delete', 'Delete', 'trash', 'more', 'danger');
        } elseif ($type === 'invoice') {
            $open = in_array($s, ['sent', 'viewed', 'overdue', 'part-paid'], true);
            $deleted = !empty($row['deleted_at']);
            if ($deleted) {
                if ($admin) $add('restore', 'Restore', 'undo', 'main', 'primary');
                $add('pdf', 'PDF', 'download', 'main');
                return $a;
            }
            if ($writer && $s === 'draft') $add('send', 'Send invoice', 'send', 'main', 'primary');
            if ($writer && ($open || $s === 'draft') && (float)$row['balance_due'] > 0.004) $add('payment', 'Record payment', 'iq-pay', 'main', $s === 'draft' ? '' : 'primary');
            if ($writer && $open) $add('send', 'Send again', 'send', 'main');
            if ($edit) $add('edit', $v['lock']['level'] === 'wording' ? 'Edit wording' : 'Edit', 'pen', 'main');
            $add('pdf', 'PDF', 'download', 'main');
            if ($writer && $open && !empty($row['due_date']) && $row['due_date'] < date('Y-m-d')) $add('remind', 'Send reminder', 'bell', 'main');
            if ($writer && $s === 'draft') $add('issue', 'Issue without emailing', 'check');
            if ($writer && $open && $v['yoco']) $add('pay_link', 'Card payment link', 'iq-pay');
            if ($writer && in_array($s, ['sent', 'viewed', 'overdue', 'part-paid', 'paid'], true)) $add('credit', 'Create credit note', 'iq-credit');
            if ($writer && in_array($s, ['sent', 'viewed', 'overdue'], true) && empty($v['credit_notes'])) $add('credit_full', 'Cancel with a credit note', 'iq-credit');
            if ($admin && in_array($s, ['paid', 'part-paid'], true)) $add('refund', 'Refund', 'iq-refund');
            if ($writer && $open && !empty($v['payments'])) $add('unapply', 'Remove payments', 'undo');
            if ($writer && in_array($s, ['sent', 'viewed', 'overdue'], true) && empty($v['payments']) && empty($v['credits'])) $add('reopen', 'Back to draft', 'undo');
            if ($writer && $s === 'uncollectible') $add('reopen', 'Reopen', 'undo', 'main');
            if ($writer && $s === 'cancelled') $add('reopen', 'Reopen as draft', 'undo', 'main');
            if ($admin && $open) $add('write_off', 'Write off', 'minus');
            if ($writer && $open) $add('uncollectible', 'Mark uncollectible', 'ban');
            if ($writer && $open && empty($v['payments']) && empty($v['credits'])) $add('void', 'Void', 'iq-void', 'more', 'danger');
            if ($writer && empty($row['archived_at']) && !$open && $s !== 'draft') $add('archive', 'Archive', 'archive');
            if ($writer && !empty($row['archived_at'])) $add('unarchive', 'Restore from archive', 'archive', 'main');
            if ($writer) $add('link', 'Copy customer link', 'link');
            if (!empty($row['due_date']) && $s !== 'draft') $add('calendar', 'Show in calendar', 'calendar');
            if ($writer) $add('recurring', 'Make it recurring', 'iq-recurring');
            if ($writer) $add('duplicate', 'Duplicate', 'copy');
            if ($writer && $s === 'draft') $add('delete', 'Delete', 'trash', 'more', 'danger');
            elseif ($admin && empty($v['payments']) && empty($v['credits'])) $add('delete', 'Delete', 'trash', 'more', 'danger');
        } else {
            if ($writer && $s === 'draft') $add('approve', 'Approve', 'check', 'main', 'primary');
            $unapplied = (float)($v['summary']['unapplied'] ?? 0);
            if ($writer && $s === 'approved' && $unapplied > 0.004) $add('apply', 'Apply to an invoice', 'iq-credit', 'main', 'primary');
            if ($writer && in_array($s, ['approved', 'applied'], true)) $add('send', 'Email to customer', 'send', 'main');
            if ($edit) $add('edit', 'Edit', 'pen', 'main');
            $add('pdf', 'PDF', 'download', 'main');
            if ($writer && !empty($v['allocations'])) $add('unapply', 'Remove from invoice', 'undo');
            if ($writer && in_array($s, ['draft', 'approved'], true) && empty($v['allocations'])) $add('cancel', 'Cancel credit note', 'iq-void', 'more', 'danger');
            if ($writer && $s === 'draft') $add('delete', 'Delete', 'trash', 'more', 'danger');
        }
        return $a;
    }

    /** A recurring template: schedule, what it bills, upcoming dates and what it generated. */
    private static function recurring(PDO $db, int $companyId, bool $writer, int $id): ?array
    {
        $row = QiWriter::lockRow($db, $companyId, 'recurring', $id);
        if (!$row) return null;
        $doc = QiEditor::fromRow($db, $companyId, 'recurring', $row, false);
        $d = QiWriter::normalize($db, $companyId, 'recurring', $doc, $row, false);
        $m = QiDocument::fromDraft($db, $companyId, $d, null, (new SequenceAllocator($db))->peek($companyId, 'invoice'));
        $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $m['company']['raw'] = $company;
        $design = QiTheme::load($db, $companyId, $company);
        $upcoming = [];
        $end = !empty($row['end_date']) && $row['end_date'] > '1000-01-01' ? (string)$row['end_date'] : null;
        if (!empty($row['active'])) {
            $next = (string)$row['next_run_date'];
            $anchor = $row['anchor_day'] !== null ? (int)$row['anchor_day'] : (int)date('j', strtotime($next));
            for ($i = 0; $i < 6 && ($end === null || $next <= $end); $i++) {
                $upcoming[] = $next;
                $next = QiRecurring::nextDate($next, (string)$row['frequency'], (int)$row['interval_count'], $anchor);
            }
        }
        $st = $db->prepare("SELECT id, invoice_number, issue_date, total, balance_due, status, deleted_at, due_date, currency FROM invoices
                             WHERE company_id = ? AND recurring_invoice_id = ? ORDER BY id DESC LIMIT 50");
        $st->execute([$companyId, $id]);
        $generated = array_map(fn($r) => ['id' => (int)$r['id'], 'number' => $r['invoice_number'], 'date' => $r['issue_date'], 'total' => (float)$r['total'],
                                          'balance' => (float)$r['balance_due'], 'status' => QiDocument::status('invoice', $r)], $st->fetchAll(PDO::FETCH_ASSOC));
        $unit = QiRecurring::UNITS[$row['frequency']] ?? 'month';
        $n = max(1, (int)$row['interval_count']);
        $a = [];
        if ($writer) {
            if (!empty($row['active'])) $a[] = ['key' => 'run', 'label' => 'Generate the next invoice now', 'icon' => 'zap', 'group' => 'main', 'tone' => 'primary'];
            $a[] = ['key' => 'edit', 'label' => 'Edit', 'icon' => 'pen', 'group' => 'main', 'tone' => ''];
            $a[] = !empty($row['active'])
                ? ['key' => 'pause', 'label' => 'Pause', 'icon' => 'clock', 'group' => 'main', 'tone' => '']
                : ['key' => 'resume', 'label' => 'Resume', 'icon' => 'play', 'group' => 'main', 'tone' => 'primary'];
            $a[] = ['key' => 'duplicate', 'label' => 'Duplicate', 'icon' => 'copy', 'group' => 'more', 'tone' => ''];
            $a[] = ['key' => 'delete', 'label' => 'Delete', 'icon' => 'trash', 'group' => 'more', 'tone' => 'danger'];
        }
        return [
            'type' => 'recurring',
            'id' => $id,
            'number' => (string)$row['template_name'],
            'status' => !empty($row['active']) ? ['key' => 'active', 'label' => 'Active', 'tone' => 'success'] : ['key' => 'paused', 'label' => $end !== null && $end < date('Y-m-d') ? 'Ended' : 'Paused', 'tone' => 'muted'],
            'raw_status' => !empty($row['active']) ? 'active' : 'paused',
            'html' => DocHtml::render($m, $design, ['editable' => false, 'stamp' => false]),
            'symbol' => $m['symbol'],
            'currency' => $m['currency'],
            'summary' => [
                'total' => (float)$m['totals']['total'], 'every' => ($n === 1 ? 'Every ' . $unit : 'Every ' . $n . ' ' . $unit . 's'),
                'next' => !empty($row['active']) ? (string)$row['next_run_date'] : null, 'end' => $end, 'auto_send' => (bool)$row['auto_send'],
                'terms' => $row['payment_terms_days'] !== null ? (int)$row['payment_terms_days'] : null, 'count' => count($generated),
                'billed' => round(array_sum(array_map(fn($g) => $g['status']['key'] === 'deleted' ? 0 : $g['total'], $generated)), 2),
            ],
            'customer' => QiEditor::customerCard($db, $companyId, (int)$row['customer_id']),
            'upcoming' => $upcoming,
            'generated' => $generated,
            'activity' => QiActivity::list($db, $companyId, 'recurring', $id),
            'emails' => [],
            'links' => ['edit' => '/qi/edit.php?type=recurring&id=' . $id, 'pdf' => null, 'public' => null, 'pay' => null],
            'lock' => ['level' => 'none', 'reason' => '', 'repost' => false],
            'can_write' => $writer,
            'actions' => $a,
            'related' => [], 'payments' => [], 'credits' => [], 'credit_notes' => [], 'milestones' => [],
        ];
    }
}
