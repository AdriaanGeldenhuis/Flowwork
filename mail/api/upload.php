<?php
// POST /mail/api/upload.php (multipart)  draft_id, files[]   → attach to a draft
// POST /mail/api/upload.php (JSON)       {op:"remove", attachment_id}
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

const MAIL_MAX_FILE = 20 * 1024 * 1024;
const MAIL_BLOCKED_EXT = ['exe', 'bat', 'cmd', 'com', 'scr', 'pif', 'msi', 'msp', 'vbs', 'vbe', 'js', 'jse', 'wsf',
                          'wsh', 'ps1', 'psm1', 'jar', 'cpl', 'hta', 'lnk', 'reg', 'dll', 'sys', 'php', 'phtml', 'phar'];

$in = mail_input();
$draftOk = function (int $id) use ($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID): array {
    $row = MailRepo::email($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $id);
    if (!$row || $row['folder'] !== 'Drafts') mail_error('Save the draft before attaching files', 409);
    return $row;
};

if (($in['op'] ?? '') === 'remove') {
    $st = $DB->prepare("SELECT ea.attachment_id, ea.email_id, ea.file_path FROM email_attachments ea
                          JOIN emails e ON e.email_id = ea.email_id
                          JOIN email_accounts a ON a.account_id = e.account_id
                         WHERE ea.attachment_id = ? AND a.company_id = ? AND a.user_id = ?");
    $st->execute([(int)($in['attachment_id'] ?? 0), $MAIL_COMPANY_ID, $MAIL_USER_ID]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) mail_error('Attachment not found', 404);
    $draftOk((int)$att['email_id']);
    $DB->prepare("DELETE FROM email_attachments WHERE attachment_id = ?")->execute([(int)$att['attachment_id']]);
    $p = MailPaths::resolveStored((string)$att['file_path']);
    if ($p !== null && str_contains($p, DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR)) @unlink($p);
    mail_json();
}

mail_rate_limit('upload', 120, 600);
$draft = $draftOk((int)($_POST['draft_id'] ?? 0));
$files = $_FILES['files'] ?? null;
if (!$files || !is_array($files['name'])) mail_error('No file received');

$st = $DB->prepare("SELECT COALESCE(SUM(file_size), 0) FROM email_attachments WHERE email_id = ?");
$st->execute([(int)$draft['email_id']]);
$total = (int)$st->fetchColumn();

$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
$added = [];
$errors = [];
foreach ($files['name'] as $i => $origName) {
    $err = (int)$files['error'][$i];
    $name = MimeParser::safeFilename((string)$origName);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $errors[] = "$name is too large"; continue; }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) { $errors[] = "$name could not be uploaded"; continue; }
    $size = (int)$files['size'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, MAIL_BLOCKED_EXT, true)) { $errors[] = "$name is blocked for security reasons (.$ext)"; continue; }
    if ($size > MAIL_MAX_FILE) { $errors[] = "$name is larger than 20 MB"; continue; }
    if ($total + $size > MailOutbox::MAX_TOTAL_ATTACHMENTS) { $errors[] = "$name would take the message over 25 MB"; continue; }
    $content = (string)file_get_contents($files['tmp_name'][$i]);
    $mime = $finfo ? (finfo_buffer($finfo, $content) ?: 'application/octet-stream') : 'application/octet-stream';
    if (in_array($mime, ['application/x-dosexec', 'application/x-msdownload', 'application/x-executable'], true)) {
        $errors[] = "$name looks like a program and is blocked"; continue;
    }
    $id = MailStore::storeAttachment($DB, $MAIL_COMPANY_ID, (int)$draft['account_id'], (int)$draft['email_id'], $name, $mime, $content);
    $total += $size;
    $added[] = ['attachment_id' => $id, 'name' => $name, 'size' => $size, 'mime' => $mime, 'url' => '/mail/api/attachment.php?id=' . $id];
}
$DB->prepare("UPDATE emails SET has_attachments = 1 WHERE email_id = ? AND ? > 0")->execute([(int)$draft['email_id'], count($added)]);

mail_json(['added' => $added, 'errors' => $errors]);
