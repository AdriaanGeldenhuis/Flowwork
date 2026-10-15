<?php
// /mail/api/crm.php — link conversations to CRM customers / suppliers.
//   GET  ?key=t123&q=acme      → {results:[...], suggestions:[...], links:[...]}
//   POST {key, crm_account_id, on:bool}
require_once __DIR__ . '/_bootstrap.php';

$crmAccount = function (int $id) use ($DB, $MAIL_COMPANY_ID): ?array {
    $st = $DB->prepare("SELECT id, name, type FROM crm_accounts WHERE id = ? AND company_id = ?");
    $st->execute([$id, $MAIL_COMPANY_ID]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$notDeleted = function (string $alias) use ($DB): string {
    static $has = null;
    if ($has === null) {
        $has = (bool)$DB->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND TABLE_NAME = 'crm_accounts' AND COLUMN_NAME = 'deleted_at'")->fetchColumn();
    }
    return $has ? " AND $alias.deleted_at IS NULL" : '';
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in = mail_input();
    $keys = mail_keys($in);
    $acc = $crmAccount((int)($in['crm_account_id'] ?? 0));
    if (!$acc) mail_error('CRM account not found', 404);
    $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $keys, 'visible');
    if (!$rows) mail_error('Conversation not found', 404);
    $on = mail_bool($in, 'on', true);
    $ids = array_map(fn($r) => (int)$r['email_id'], $rows);
    if ($on) {
        $exists = $DB->prepare("SELECT 1 FROM email_links WHERE email_id = ? AND linked_type = ? AND linked_id = ?");
        $ins = $DB->prepare("INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($ids as $id) {
            $exists->execute([$id, $acc['type'], $acc['id']]);
            if (!$exists->fetchColumn()) $ins->execute([$MAIL_COMPANY_ID, $id, $acc['type'], $acc['id'], $MAIL_USER_ID]);
        }
    } else {
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $DB->prepare("DELETE FROM email_links WHERE linked_type = ? AND linked_id = ? AND email_id IN ($in2)")
           ->execute(array_merge([$acc['type'], $acc['id']], $ids));
    }
    mail_json(['links' => MailRepo::linksFor($DB, $MAIL_COMPANY_ID, $ids)]);
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$results = [];
if ($q !== '') {
    $st = $DB->prepare("SELECT a.id, a.name, a.type, a.email FROM crm_accounts a
                         WHERE a.company_id = ? AND (a.name LIKE ? OR a.email LIKE ?)" . $notDeleted('a') . "
                      ORDER BY a.name LIMIT 12");
    $like = '%' . MailRepo::likeEscape($q) . '%';
    $st->execute([$MAIL_COMPANY_ID, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'email' => $r['email']];
    }
}

// Suggestions from the conversation's participants (exact contact match, then domain).
$suggestions = [];
$links = [];
$key = (string)($_GET['key'] ?? '');
if (MailRepo::parseKey($key)) {
    $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [$key], 'visible');
    $links = MailRepo::linksFor($DB, $MAIL_COMPANY_ID, array_map(fn($r) => (int)$r['email_id'], $rows));
    $mine = array_map(fn($a) => strtolower($a['email_address']), MailRepo::accounts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID));
    $addresses = [];
    foreach ($rows as $r) {
        foreach (array_merge(MimeParser::parseAddressList((string)$r['sender']),
                             MimeParser::parseAddressList((string)$r['to_recipients']),
                             MimeParser::parseAddressList((string)$r['cc_recipients'])) as $a) {
            if (!in_array($a['email'], $mine, true)) $addresses[$a['email']] = true;
        }
    }
    $seen = [];
    $exact = $DB->prepare("SELECT DISTINCT a.id, a.name, a.type FROM crm_contacts c
                             JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
                            WHERE c.company_id = ? AND LOWER(c.email) = ?" . $notDeleted('a') . "
                           UNION
                           SELECT a.id, a.name, a.type FROM crm_accounts a
                            WHERE a.company_id = ? AND LOWER(a.email) = ?" . $notDeleted('a'));
    $domain = $DB->prepare("SELECT DISTINCT a.id, a.name, a.type FROM crm_contacts c
                              JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
                             WHERE c.company_id = ? AND LOWER(c.email) LIKE ?" . $notDeleted('a') . " LIMIT 5");
    $public = ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com', 'live.com', 'mweb.co.za', 'telkomsa.net', 'webmail.co.za', 'vodamail.co.za'];
    foreach (array_slice(array_keys($addresses), 0, 20) as $addr) {
        $exact->execute([$MAIL_COMPANY_ID, $addr, $MAIL_COMPANY_ID, $addr]);
        foreach ($exact->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($seen[$r['id']])) continue;
            $seen[$r['id']] = true;
            $suggestions[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'reason' => 'Contact ' . $addr];
        }
        $dom = substr(strrchr($addr, '@') ?: '', 1);
        if ($dom === '' || in_array($dom, $public, true)) continue;
        $domain->execute([$MAIL_COMPANY_ID, '%@' . MailRepo::likeEscape($dom)]);
        foreach ($domain->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($seen[$r['id']])) continue;
            $seen[$r['id']] = true;
            $suggestions[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'reason' => 'Same domain @' . $dom];
        }
    }
}

mail_json(['results' => $results, 'suggestions' => array_slice($suggestions, 0, 6), 'links' => $links]);
