<?php
// GET /mail/api/contacts.php?q=ann — recipient suggestions:
// people you correspond with, CRM contacts and colleagues.
require_once __DIR__ . '/_bootstrap.php';

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if ($q === '') mail_json(['contacts' => []]);
$prefix = MailRepo::likeEscape($q) . '%';
$any = '%' . MailRepo::likeEscape($q) . '%';

$out = [];
$add = function (string $email, string $name, string $source, string $org = '') use (&$out) {
    $email = strtolower(trim($email));
    if ($email === '' || isset($out[$email]) || !MimeBuilder::validEmail($email)) return;
    $out[$email] = ['email' => $email, 'name' => trim($name), 'source' => $source, 'org' => $org];
};

$st = $DB->prepare("SELECT email, name FROM email_contacts
                     WHERE user_id = ? AND (email LIKE ? OR name LIKE ?)
                  ORDER BY use_count DESC, last_used_at DESC LIMIT 8");
$st->execute([$MAIL_USER_ID, $prefix, $any]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['email'], (string)$r['name'], 'recent');

try {
    $st = $DB->prepare("SELECT c.email, TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS name, a.name AS org
                          FROM crm_contacts c LEFT JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
                         WHERE c.company_id = ? AND c.email IS NOT NULL AND c.email <> ''
                           AND (c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?
                                OR CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) LIKE ? OR a.name LIKE ?)
                         LIMIT 8");
    $st->execute([$MAIL_COMPANY_ID, $prefix, $prefix, $prefix, $any, $any]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['email'], (string)$r['name'], 'crm', (string)$r['org']);
} catch (Throwable $e) { /* CRM optional */ }

try {
    $st = $DB->prepare("SELECT email, TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) AS name FROM users
                         WHERE company_id = ? AND status = 'active'
                           AND (email LIKE ? OR first_name LIKE ? OR last_name LIKE ?) LIMIT 5");
    $st->execute([$MAIL_COMPANY_ID, $prefix, $prefix, $prefix]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['email'], (string)$r['name'], 'team');
} catch (Throwable $e) { /* ignore */ }

mail_json(['contacts' => array_slice(array_values($out), 0, 10)]);
