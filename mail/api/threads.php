<?php
// GET /mail/api/threads.php?view=inbox&account_id=&label_id=&q=&filter=&offset=
require_once __DIR__ . '/_bootstrap.php';

$res = MailRepo::listThreads($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [
    'view'       => (string)($_GET['view'] ?? 'inbox'),
    'account_id' => (int)($_GET['account_id'] ?? 0),
    'label_id'   => (int)($_GET['label_id'] ?? 0),
    'q'          => mb_substr((string)($_GET['q'] ?? ''), 0, 300),
    'filter'     => (string)($_GET['filter'] ?? ''),
    'offset'     => (int)($_GET['offset'] ?? 0),
    'limit'      => (int)($_GET['limit'] ?? 50),
]);

// Reply reminders: conversations awaiting my answer past the target time.
$prefs = MailRepo::prefs($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID);
if ($prefs['sla_enabled'] && $res['threads']) {
    $tids = [];
    foreach ($res['threads'] as $t) if ($t['key'][0] === 't') $tids[] = (int)substr($t['key'], 1);
    if ($tids) {
        $in = implode(',', array_fill(0, count($tids), '?'));
        $st = $DB->prepare("SELECT thread_id,
                                   MAX(CASE WHEN direction = 'incoming' AND folder NOT IN ('Trash','Spam') THEN sent_at END) AS last_in,
                                   MAX(CASE WHEN direction = 'outgoing' AND folder = 'Sent' THEN sent_at END) AS last_out
                              FROM emails WHERE thread_id IN ($in) GROUP BY thread_id");
        $st->execute($tids);
        $due = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!$r['last_in'] || ($r['last_out'] && $r['last_out'] >= $r['last_in'])) continue;
            if (strtotime($r['last_in']) < time() - 14 * 86400) continue;
            $due[(int)$r['thread_id']] = date('c', strtotime($r['last_in']) + $prefs['sla_default_hours'] * 3600);
        }
        foreach ($res['threads'] as &$t) {
            $tid = $t['key'][0] === 't' ? (int)substr($t['key'], 1) : 0;
            $t['reply_due_at'] = $due[$tid] ?? null;
        }
        unset($t);
    }
}

mail_json($res);
