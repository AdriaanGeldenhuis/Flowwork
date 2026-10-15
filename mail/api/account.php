<?php
// /mail/api/account.php — mail accounts of the current user.
//   GET  ?id=…                    one account (no password)
//   POST {op:"save", …fields}     create / update
//   POST {op:"test", …fields}     test IMAP + SMTP (works before saving; blank password = stored one)
//   POST {op:"delete", account_id}
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $a = MailRepo::account($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, (int)($_GET['id'] ?? 0));
    if (!$a) mail_error('Account not found', 404);
    mail_json(['account' => MailRepo::publicAccount($a)]);
}

mail_require_post();
$in = mail_input();
$op = mail_str($in, 'op', 10);

$enc = fn($v, $def) => in_array($v, ['ssl', 'tls', 'none'], true) ? $v : $def;
$fields = function (array $in) use ($enc): array {
    $port = fn($v, $def) => ($p = (int)$v) >= 1 && $p <= 65535 ? $p : $def;
    return [
        'account_name'    => mail_str($in, 'account_name', 120),
        'display_name'    => MimeBuilder::clean(mail_str($in, 'display_name', 190)),
        'email_address'   => strtolower(mail_str($in, 'email_address', 190)),
        'imap_server'     => strtolower(mail_str($in, 'imap_server', 255)),
        'imap_port'       => $port($in['imap_port'] ?? 993, 993),
        'imap_encryption' => $enc($in['imap_encryption'] ?? 'ssl', 'ssl'),
        'smtp_server'     => strtolower(mail_str($in, 'smtp_server', 255)),
        'smtp_port'       => $port($in['smtp_port'] ?? 587, 587),
        'smtp_encryption' => $enc($in['smtp_encryption'] ?? 'tls', 'tls'),
        'username'        => mail_str($in, 'username', 190),
        'tls_verify'      => mail_bool($in, 'tls_verify', true) ? 1 : 0,
        'save_sent_copy'  => mail_bool($in, 'save_sent_copy', true) ? 1 : 0,
        'is_active'       => mail_bool($in, 'is_active', true) ? 1 : 0,
        'default_signature_id' => (int)($in['default_signature_id'] ?? 0) ?: null,
    ];
};

if ($op === 'delete') {
    $a = MailRepo::account($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, (int)($in['account_id'] ?? 0));
    if (!$a) mail_error('Account not found', 404);
    $id = (int)$a['account_id'];
    @set_time_limit(120);
    // Remove mail in chunks (files + labels + links), then the account's state.
    $st = $DB->prepare("SELECT email_id FROM emails WHERE account_id = ? LIMIT 500");
    do {
        $st->execute([$id]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $DB->beginTransaction();
            MailStore::deleteEmails($DB, $ids);
            $DB->commit();
        }
    } while ($ids);
    $DB->beginTransaction();
    $DB->prepare("DELETE FROM email_threads WHERE account_id = ?")->execute([$id]);
    $DB->prepare("DELETE FROM email_folder_state WHERE account_id = ?")->execute([$id]);
    $DB->prepare("DELETE FROM email_imap_ops WHERE account_id = ?")->execute([$id]);
    $DB->prepare("DELETE FROM email_accounts WHERE account_id = ? AND company_id = ? AND user_id = ?")->execute([$id, $MAIL_COMPANY_ID, $MAIL_USER_ID]);
    $DB->commit();
    mail_audit($DB, 'mail_account_deleted', ['account_id' => $id, 'email' => $a['email_address']]);
    mail_json();
}

if ($op === 'test') {
    mail_rate_limit('account_test', 12, 300);
    $f = $fields($in);
    $existing = !empty($in['account_id']) ? MailRepo::account($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, (int)$in['account_id']) : null;
    $pass = (string)($in['password'] ?? '');
    if ($pass === '' && $existing) $pass = SecureVault::decrypt($existing['password_encrypted'] ?? '');
    $user = $f['username'] !== '' ? $f['username'] : $f['email_address'];

    $result = ['imap' => null, 'smtp' => null];
    if ($f['imap_server'] !== '') {
        if ($p = mail_host_problem($f['imap_server'])) {
            $result['imap'] = ['ok' => false, 'error' => $p];
        } else {
            try {
                $c = new ImapClient($f['imap_server'], $f['imap_port'], $f['imap_encryption'], $f['tls_verify'] === 1, 20);
                $c->connect();
                $c->login($user, $pass);
                $folders = $c->listFolders();
                $c->logout();
                $result['imap'] = ['ok' => true, 'folders' => count($folders)];
            } catch (Throwable $e) {
                $result['imap'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
    }
    if ($f['smtp_server'] !== '') {
        if ($p = mail_host_problem($f['smtp_server'])) {
            $result['smtp'] = ['ok' => false, 'error' => $p];
        } else {
            $result['smtp'] = SmtpSender::test(['host' => $f['smtp_server'], 'port' => $f['smtp_port'], 'encryption' => $f['smtp_encryption'],
                                                'tls_verify' => $f['tls_verify'] === 1, 'username' => $user, 'password' => $pass,
                                                'public_only' => true]);
        }
    }
    if ($result['imap'] === null && $result['smtp'] === null) mail_error('Enter an incoming (IMAP) or outgoing (SMTP) server first');
    mail_json($result);
}

if ($op === 'save') {
    $f = $fields($in);
    if ($f['account_name'] === '') $f['account_name'] = $f['email_address'];
    if (!MimeBuilder::validEmail($f['email_address'])) mail_error('Enter a valid email address');
    if ($f['imap_server'] === '' && $f['smtp_server'] === '') mail_error('Enter at least an incoming (IMAP) or outgoing (SMTP) server');
    foreach (['imap_server', 'smtp_server'] as $h) {
        if ($f[$h] !== '' && ($p = mail_host_problem($f[$h]))) mail_error($p);
    }
    if ($f['default_signature_id']) {
        $st = $DB->prepare("SELECT 1 FROM email_signatures WHERE signature_id = ? AND company_id = ? AND (user_id = ? OR user_id IS NULL)");
        $st->execute([$f['default_signature_id'], $MAIL_COMPANY_ID, $MAIL_USER_ID]);
        if (!$st->fetchColumn()) $f['default_signature_id'] = null;
    }
    $pass = (string)($in['password'] ?? '');
    $id = (int)($in['account_id'] ?? 0);

    if ($id) {
        $a = MailRepo::account($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $id);
        if (!$a) mail_error('Account not found', 404);
        $serverChanged = $a['imap_server'] !== $f['imap_server'] || (int)$a['imap_port'] !== $f['imap_port']
                      || strtolower((string)$a['username']) !== strtolower($f['username']);
        $sql = "UPDATE email_accounts SET account_name = ?, display_name = ?, email_address = ?, imap_server = ?, imap_port = ?,
                       imap_encryption = ?, smtp_server = ?, smtp_port = ?, smtp_encryption = ?, username = ?, tls_verify = ?,
                       save_sent_copy = ?, is_active = ?, default_signature_id = ?, last_error = NULL"
             . ($pass !== '' ? ", password_encrypted = ?" : "")
             . ($serverChanged ? ", folder_map_json = NULL" : "")
             . " WHERE account_id = ? AND company_id = ? AND user_id = ?";
        $params = [$f['account_name'], $f['display_name'] ?: null, $f['email_address'], $f['imap_server'], $f['imap_port'],
                   $f['imap_encryption'], $f['smtp_server'], $f['smtp_port'], $f['smtp_encryption'], $f['username'],
                   $f['tls_verify'], $f['save_sent_copy'], $f['is_active'], $f['default_signature_id']];
        if ($pass !== '') $params[] = SecureVault::encrypt($pass);
        array_push($params, $id, $MAIL_COMPANY_ID, $MAIL_USER_ID);
        $DB->prepare($sql)->execute($params);
        if ($serverChanged) {
            // A different mailbox: forget the old sync cursors and pending server ops.
            $DB->prepare("DELETE FROM email_folder_state WHERE account_id = ?")->execute([$id]);
            $DB->prepare("DELETE FROM email_imap_ops WHERE account_id = ?")->execute([$id]);
            $DB->prepare("UPDATE emails SET imap_uid = NULL, imap_folder = NULL WHERE account_id = ?")->execute([$id]);
        }
        mail_audit($DB, 'mail_account_updated', ['account_id' => $id]);
    } else {
        if ($pass === '') mail_error('Enter the account password (or app password)');
        $st = $DB->prepare("SELECT COUNT(*) FROM email_accounts WHERE company_id = ? AND user_id = ?");
        $st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
        if ((int)$st->fetchColumn() >= 10) mail_error('You can connect up to 10 accounts');
        $DB->prepare("INSERT INTO email_accounts (company_id, user_id, account_name, display_name, email_address, imap_server, imap_port,
                             imap_encryption, smtp_server, smtp_port, smtp_encryption, username, password_encrypted, tls_verify,
                             save_sent_copy, is_active, default_signature_id, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID, $f['account_name'], $f['display_name'] ?: null, $f['email_address'],
                      $f['imap_server'], $f['imap_port'], $f['imap_encryption'], $f['smtp_server'], $f['smtp_port'],
                      $f['smtp_encryption'], $f['username'], SecureVault::encrypt($pass), $f['tls_verify'], $f['save_sent_copy'],
                      $f['is_active'], $f['default_signature_id']]);
        $id = (int)$DB->lastInsertId();
        mail_audit($DB, 'mail_account_added', ['account_id' => $id, 'email' => $f['email_address']]);
    }
    $a = MailRepo::account($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $id);
    mail_json(['account' => MailRepo::publicAccount($a)]);
}
mail_error('Unknown operation');
