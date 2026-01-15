<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_rate-limit.php';

checkRateLimit('update_member_role', 20, 60);

$memberId = (int)($_POST['member_id'] ?? 0);
$newRole = $_POST['role'] ?? null;
$canEdit = isset($_POST['can_edit']) ? (int)$_POST['can_edit'] : null;

if (!$memberId) respond_error('Member ID required');

// Get member info
$stmt = $DB->prepare("SELECT project_id, user_id FROM project_members WHERE member_id = ? AND company_id = ?");
$stmt->execute([$memberId, $COMPANY_ID]);
$member = $stmt->fetch();

if (!$member) respond_error('Member not found', 404);

// Admin or project manager only
if ($USER_ROLE !== 'admin') {
    require_project_role($member['project_id'], 'manager');
}

$allowedRoles = ['owner', 'manager', 'member', 'viewer'];
if ($newRole && !in_array($newRole, $allowedRoles)) {
    respond_error('Invalid role');
}

try {
    $updates = [];
    $values = [];
    
    if ($newRole) {
        $updates[] = "role = ?";
        $values[] = $newRole;
        
        // Auto-set can_edit based on role
        if (!isset($canEdit)) {
            $canEdit = in_array($newRole, ['owner', 'manager', 'member']) ? 1 : 0;
        }
    }
    
    if ($canEdit !== null) {
        $updates[] = "can_edit = ?";
        $values[] = $canEdit;
    }
    
    if (empty($updates)) respond_error('No changes specified');
    
    $values[] = $memberId;
    $values[] = $COMPANY_ID;
    
    $stmt = $DB->prepare("UPDATE project_members SET " . implode(', ', $updates) . " WHERE member_id = ? AND company_id = ?");
    $stmt->execute($values);
    
    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO board_audit_log (company_id, user_id, action, details, ip_address, created_at)
        VALUES (?, ?, 'updated member role', ?, ?, NOW())
    ");
    $stmt->execute([
        $COMPANY_ID, 
        $USER_ID, 
        json_encode(['member_id' => $memberId, 'new_role' => $newRole, 'can_edit' => $canEdit]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    respond_ok(['updated' => true]);
    
} catch (Exception $e) {
    error_log("Update member role error: " . $e->getMessage());
    respond_error('Failed to update member role', 500);
}