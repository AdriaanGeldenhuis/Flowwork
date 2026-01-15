<?php
// /projects/guest-confirm.php
require_once __DIR__ . '/../init.php';

$token = trim($_GET['token'] ?? '');
$error = $success = '';
$guest = null;

if (!$token) {
    $error = 'Invalid confirmation link';
} else {
    try {
        $stmt = $DB->prepare("
            SELECT bg.*, pb.title as board_title, c.name as company_name
            FROM board_guests bg
            JOIN project_boards pb ON pb.board_id = bg.board_id
            JOIN companies c ON c.id = bg.company_id
            WHERE bg.token = ?
        ");
        $stmt->execute([$token]);
        $guest = $stmt->fetch();
        
        if (!$guest) {
            throw new Exception('Invalid or expired link');
        }
        
        if ($guest['expires_at'] && strtotime($guest['expires_at']) < time()) {
            throw new Exception('This invitation has expired');
        }
        
        // Check if password already set
        if ($guest['password_hash'] && $guest['status'] === 'active') {
            // Already confirmed - redirect to login
            header("Location: /projects/guest-login.php");
            exit;
        }
        
        // Handle password submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            if ($password !== $confirmPassword) {
                throw new Exception('Passwords do not match');
            }
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Update guest
            $stmt = $DB->prepare("
                UPDATE board_guests 
                SET password_hash = ?, 
                    password_set_at = NOW(), 
                    status = 'active', 
                    confirmed_at = NOW()
                WHERE token = ?
            ");
            $stmt->execute([$passwordHash, $token]);
            
            $success = "Password set successfully! You can now login.";
            
            // Auto-login: set session
            $_SESSION['guest_id'] = $guest['id'];
            $_SESSION['guest_email'] = $guest['email'];
            $_SESSION['guest_board_id'] = $guest['board_id'];
            $_SESSION['guest_token'] = $token;
            
            // Redirect to board
            header("Location: /projects/guest-view.php?token={$token}");
            exit;
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Password – Guest Access</title>
    <link rel="stylesheet" href="/projects/assets/board.css">
    <style>
        .guest-confirm-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .guest-confirm-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 450px;
            width: 100%;
            padding: 40px;
        }
        
        .guest-confirm-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .guest-confirm-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 32px;
        }
        
        .guest-confirm-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a1d29;
            margin: 0 0 8px 0;
        }
        
        .guest-confirm-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
        }
        
        .guest-form-group {
            margin-bottom: 20px;
        }
        
        .guest-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .guest-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .guest-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .guest-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .guest-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        
        .guest-alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .guest-alert--error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        
        .guest-alert--success {
            background: #d1fae5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        
        .guest-info-box {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .guest-info-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .guest-info-value {
            font-size: 15px;
            font-weight: 600;
            color: #1a1d29;
        }
    </style>
</head>
<body>

<div class="guest-confirm-page">
    <div class="guest-confirm-card">
        
        <?php if ($error): ?>
            <div class="guest-alert guest-alert--error">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
            <a href="/" class="guest-btn" style="text-decoration: none; display: block; text-align: center;">Go Home</a>
        
        <?php elseif ($success): ?>
            <div class="guest-alert guest-alert--success">
                ✅ <?= htmlspecialchars($success) ?>
            </div>
            <p style="text-align: center; color: #6b7280;">Redirecting to board...</p>
        
        <?php elseif ($guest): ?>
            <div class="guest-confirm-header">
                <div class="guest-confirm-icon">🔐</div>
                <h1 class="guest-confirm-title">Set Your Password</h1>
                <p class="guest-confirm-subtitle">Create a password to access the board</p>
            </div>
            
            <div class="guest-info-box">
                <div class="guest-info-label">Board</div>
                <div class="guest-info-value"><?= htmlspecialchars($guest['board_title']) ?></div>
            </div>
            
            <div class="guest-info-box">
                <div class="guest-info-label">Your Email</div>
                <div class="guest-info-value"><?= htmlspecialchars($guest['email']) ?></div>
            </div>
            
            <form method="POST">
                <div class="guest-form-group">
                    <label class="guest-label">Password (min 6 characters)</label>
                    <input type="password" name="password" class="guest-input" required minlength="6" autocomplete="new-password">
                </div>
                
                <div class="guest-form-group">
                    <label class="guest-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="guest-input" required minlength="6" autocomplete="new-password">
                </div>
                
                <button type="submit" class="guest-btn">
                    ✨ Set Password & Access Board
                </button>
            </form>
            
            <p style="text-align: center; margin-top: 24px; font-size: 12px; color: #6b7280;">
                You will only need to do this once.
            </p>
        <?php endif; ?>
        
    </div>
</div>

</body>
</html>