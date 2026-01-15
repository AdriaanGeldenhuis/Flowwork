<?php
// mail/lib/MailSyncService.php
require_once __DIR__ . '/RulesEngine.php';
class MailSyncService {
  public static function syncImapAccount(PDO $DB, array $account, int $companyId): int {
    if (!function_exists('imap_open')) return 0;
    $host = $account['imap_server']; $port = (int)$account['imap_port']; $enc = strtolower($account['imap_encryption'] ?? 'ssl');
    $mbx = sprintf("{%s:%d/imap/%s}INBOX", $host, $port, $enc==='ssl'?'ssl':($enc==='tls'?'tls':'novalidate-cert'));
    $in = @imap_open($mbx, $account['username'], \SecureVault::decrypt($account['password_encrypted'] ?? ''), 0, 1);
    if (!$in) return 0;
    $uids = imap_search($in, 'UNSEEN', SE_UID) ?: [];
    $synced = 0;
    foreach ($uids as $uid) {
      $header = imap_headerinfo($in, imap_msgno($in, $uid));
      $subject = imap_utf8($header->subject ?? '');
      $from = $header->fromaddress ?? '';
      $date = date('Y-m-d H:i:s', strtotime($header->date ?? 'now'));
      $body = imap_body($in, imap_msgno($in,$uid), FT_PEEK);
      // find or create thread by subject
      $t = $DB->prepare("SELECT thread_id FROM email_threads WHERE company_id=? AND subject=? LIMIT 1");
      $t->execute([$companyId,$subject]);
      $threadId = (int)($t->fetchColumn() ?: 0);
      if (!$threadId) {
        $DB->prepare("INSERT INTO email_threads (company_id,subject,last_message_at,first_incoming_at,status) VALUES (?,?,?, ?, 'open')")
           ->execute([$companyId,$subject,$date,$date]);
        $threadId = (int)$DB->lastInsertId();
      } else {
        $DB->prepare("UPDATE email_threads SET last_message_at=? WHERE thread_id=?")->execute([$date,$threadId]);
      }
      $stmt = $DB->prepare("INSERT IGNORE INTO emails (company_id,account_id,thread_id,imap_uid,folder,direction,sender,to_recipients,subject,body_html,body_text,sent_at,is_read,created_at)
                            VALUES (?,?,?,?,?,'incoming',?,?,?,?,?, ?,0,NOW())");
      $to = ''; $html = ''; $text = strip_tags($body);
      $stmt->execute([$companyId,$account['account_id'],$threadId,$uid,'INBOX',$from,$to,$subject,$html,$text,$date]);
      $emailId = (int)$DB->lastInsertId();
      if ($emailId) {
        RulesEngine::applyToMessage($DB, $emailId, $companyId);
        $synced++;
      }
    }
    imap_close($in);
    return $synced;
  }
}