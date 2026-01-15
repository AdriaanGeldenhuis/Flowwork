<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_rate-limit.php';

checkRateLimit('remove_member', 10, 60);

$memberId = (int)($_POST['member_id'] ?? 0);
if (!$memberId) respond_error('Member ID required');

$stmt = $DB->prepare("SELECT project_id, user_id FROM project_members WHERE member_id = ? AND company_id = ?");
$stmt->execute([$memberId, $COMPANY_ID]);
$member = $stmt->fetch();

if (!$member) respond_error('Member not found', 404);

// Admin or project manager only
if ($USER_ROLE !== 'admin') {
    require_project_role($member['project_id'], 'manager');
}

// Can't remove yourself if you're the last owner
$stmt = $DB->prepare("
    SELECT COUNT(*) FROM project_members 
    WHERE project_id = ? AND role = 'owner' AND company_id = ?
");
$stmt->execute([$member['project_id'], $COMPANY_ID]);
$ownerCount = (int)$stmt->fetchColumn();

$stmt = $DB->prepare("SELECT role FROM project_members WHERE member_id = ? AND company_id = ?");
$stmt->execute([$memberId, $COMPANY_ID]);
$memberRole = $stmt->fetchColumn();

if ($memberRole === 'owner' && $ownerCount <= 1 && $member['user_id'] == $USER_ID) {
    respond_error('Cannot remove the last owner from project', 400);
}

try {
    $stmt = $DB->prepare("DELETE FROM project_members WHERE member_id = ? AND company_id = ?");
    $stmt->execute([$memberId, $COMPANY_ID]);
    
    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO board_audit_log (company_id, user_id, action, details, ip_address, created_at)
        VALUES (?, ?, 'removed member', ?, ?, NOW())
    ");
    $stmt->execute([
        $COMPANY_ID, 
        $USER_ID, 
        json_encode(['member_id' => $memberId, 'removed_user_id' => $member['user_id']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    respond_ok(['removed' => true]);
    
} catch (Exception $e) {
    error_log("Remove member error: " . $e->getMessage());
    respond_error('Failed to remove member', 500);
}