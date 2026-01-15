<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// ===== PREVENT ANY OUTPUT BEFORE JSON =====
ob_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$boardId = (int)($_POST['board_id'] ?? $_GET['board_id'] ?? 0);
$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Check board access
if ($boardId) {
    $stmt = $DB->prepare("
        SELECT pb.* FROM project_boards pb
        LEFT JOIN board_members bm ON bm.board_id = pb.board_id AND bm.user_id = ?
        WHERE pb.board_id = ? AND pb.company_id = ?
        AND (bm.role IN ('owner', 'editor') OR EXISTS(
            SELECT 1 FROM users WHERE id = ? AND role = 'admin'
        ))
    ");
    $stmt->execute([$userId, $boardId, $companyId, $userId]);
    $board = $stmt->fetch();
    
    if (!$board) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

try {
    switch ($action) {
        
        // ========== INVITE GUEST ==========
        case 'invite_guest':
            $email = trim($_POST['email'] ?? '');
            $expiryDays = (int)($_POST['expiry_days'] ?? 30);
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address');
            }
            
            // Check if already exists
            $stmt = $DB->prepare("
                SELECT id, status FROM board_guests 
                WHERE board_id = ? AND email = ?
            ");
            $stmt->execute([$boardId, $email]);
            $existing = $stmt->fetch();
            
            if ($existing && $existing['status'] === 'active') {
                throw new Exception('Guest already has active access');
            }
            
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
            
            if ($existing) {
                // Update existing
                $stmt = $DB->prepare("
                    UPDATE board_guests 
                    SET token = ?, status = 'pending', expires_at = ?, invited_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$token, $expiresAt, $existing['id']]);
                $guestId = $existing['id'];
            } else {
                // Create new
                $stmt = $DB->prepare("
                    INSERT INTO board_guests 
                    (board_id, company_id, email, token, status, invited_by, invited_at, expires_at)
                    VALUES (?, ?, ?, ?, 'pending', ?, NOW(), ?)
                ");
                $stmt->execute([$boardId, $companyId, $email, $token, $userId, $expiresAt]);
                $guestId = $DB->lastInsertId();
            }
            
            // Send email
            $confirmLink = "https://{$_SERVER['HTTP_HOST']}/projects/guest-confirm.php?token={$token}";
            
            $to = $email;
            $subject = "Board Access Invitation";
            $message = "
                <html>
                <body style='font-family: Arial, sans-serif;'>
                    <h2>You've been invited to view a board</h2>
                    <p>Click the link below to confirm and access the board:</p>
                    <p><a href='{$confirmLink}' style='background: #6366f1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>Confirm Access</a></p>
                    <p style='color: #6b7280; font-size: 12px;'>This link expires in {$expiryDays} days.</p>
                    <p style='color: #6b7280; font-size: 12px;'>If you didn't request this, you can safely ignore this email.</p>
                </body>
                </html>
            ";
            
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=utf-8\r\n";
            $headers .= "From: Flowwork <noreply@flowwork.io>\r\n";
            
            @mail($to, $subject, $message, $headers);
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'guest_id' => $guestId,
                'message' => 'Invitation sent'
            ]);
            exit;
            
        // ========== LIST GUESTS ==========
        case 'list_guests':
            $stmt = $DB->prepare("
                SELECT bg.*, u.first_name, u.last_name
                FROM board_guests bg
                LEFT JOIN users u ON u.id = bg.invited_by
                WHERE bg.board_id = ?
                ORDER BY bg.invited_at DESC
            ");
            $stmt->execute([$boardId]);
            $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_end_clean();
            echo json_encode(['success' => true, 'guests' => $guests]);
            exit;
            
        // ========== REVOKE GUEST ==========
        case 'revoke_guest':
            $guestId = (int)($_POST['guest_id'] ?? 0);
            
            $stmt = $DB->prepare("
                DELETE FROM board_guests 
                WHERE id = ? AND board_id = ?
            ");
            $stmt->execute([$guestId, $boardId]);
            
            ob_end_clean();
            echo json_encode(['success' => true]);
            exit;
            
        // ========== RESEND INVITE ==========
        case 'resend_invite':
            $guestId = (int)($_POST['guest_id'] ?? 0);
            
            $stmt = $DB->prepare("
                SELECT * FROM board_guests 
                WHERE id = ? AND board_id = ?
            ");
            $stmt->execute([$guestId, $boardId]);
            $guest = $stmt->fetch();
            
            if (!$guest) throw new Exception('Guest not found');
            
            // Generate new token
            $token = bin2hex(random_bytes(32));
            
            $stmt = $DB->prepare("
                UPDATE board_guests 
                SET token = ?, invited_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$token, $guestId]);
            
            // Send email
            $confirmLink = "https://{$_SERVER['HTTP_HOST']}/projects/guest-confirm.php?token={$token}";
            
            $to = $guest['email'];
            $subject = "Board Access Invitation (Resent)";
            $message = "
                <html>
                <body style='font-family: Arial, sans-serif;'>
                    <h2>You've been invited to view a board</h2>
                    <p>Click the link below to confirm and access the board:</p>
                    <p><a href='{$confirmLink}' style='background: #6366f1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>Confirm Access</a></p>
                </body>
                </html>
            ";
            
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=utf-8\r\n";
            $headers .= "From: Flowwork <noreply@flowwork.io>\r\n";
            
            @mail($to, $subject, $message, $headers);
            
            ob_end_clean();
            echo json_encode(['success' => true]);
            exit;
            
        default:
            throw new Exception('Unknown action');
    }
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}