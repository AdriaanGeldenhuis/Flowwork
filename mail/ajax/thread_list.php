<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
header('Content-Type: application/json');
$companyId = $_SESSION['company_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$folder = $_GET['folder'] ?? 'INBOX';
$filter = $_GET['filter'] ?? ''; // unread|read|''
$tagId      = (int)($_GET['tag_id'] ?? 0);
$assignee   = (int)($_GET['assigned_user_id'] ?? 0);
$threadStatus = $_GET['thread_status'] ?? '';
$slaState   = $_GET['sla_state'] ?? '';

$sql = "SELECT t.thread_id,t.subject,t.last_message_at,t.sla_due_at,t.status AS thread_status,t.assigned_user_id,
               e.sender,e.is_read,e.is_starred,e.has_attachments,SUBSTRING(e.body_text,1,160) AS snippet
        FROM email_threads t
        JOIN emails e ON e.thread_id=t.thread_id AND e.sent_at=t.last_message_at
        JOIN email_accounts a ON a.account_id=e.account_id
        WHERE a.company_id=? AND a.user_id=? AND e.folder=?";
$params = [$companyId,$userId,$folder];
if ($filter === 'unread') $sql .= " AND e.is_read=0";
if ($filter === 'read')   $sql .= " AND e.is_read=1";
if ($tagId) { $sql .= " JOIN email_tag_map etm ON etm.email_id=e.email_id AND etm.tag_id=?"; $params[]=$tagId; }
if ($assignee) { $sql .= " AND t.assigned_user_id=?"; $params[]=$assignee; }
if ($threadStatus) { $sql .= " AND t.status=?"; $params[]=$threadStatus; }
if ($slaState==='due') { $sql .= " AND t.sla_due_at IS NOT NULL AND t.sla_due_at>NOW()"; }
if ($slaState==='breached') { $sql .= " AND t.sla_due_at IS NOT NULL AND t.sla_due_at<=NOW()"; }
$sql .= " ORDER BY t.last_message_at DESC LIMIT 200";
$stmt=$DB->prepare($sql); $stmt->execute($params);
echo json_encode(['ok'=>true,'threads'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);