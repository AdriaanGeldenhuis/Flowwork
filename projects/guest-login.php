<?php
require_once __DIR__ . '/../init.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    try {
        if (empty($email) || empty($password)) {
            throw new Exception('Please enter email and password');
        }
        
        // Find guest
        $stmt = $DB->prepare("
            SELECT bg.*, pb.title as board_title
            FROM board_guests bg
            JOIN project_boards pb ON pb.board_id = bg.board_id
            WHERE bg.email = ? AND bg.status = 'active'
        ");
        $stmt->execute([$email]);
        $guest = $stmt->fetch();
        
        if (!$guest) {
            throw new Exception('Invalid email or password');
        }
        
        // Check password
        if (!password_verify($password, $guest['password_hash'])) {
            throw new Exception('Invalid email or password');
        }
        
        // Check expiry
        if ($guest['expires_at'] && strtotime($guest['expires_at']) < time()) {
            throw new Exception('Your access has expired');
        }
        
        // Set session
        $_SESSION['guest_id'] = $guest['id'];
        $_SESSION['guest_email'] = $guest['email'];
        $_SESSION['guest_board_id'] = $guest['board_id'];
        $_SESSION['guest_token'] = $guest['token'];
        
        // Log access
        $stmt = $DB->prepare("
            UPDATE board_guests 
            SET last_access_at = NOW(), access_count = access_count + 1 
            WHERE id = ?
        ");
        $stmt->execute([$guest['id']]);
        
        // Redirect to board
        header("Location: /projects/guest-view.php?token={$guest['token']}");
        exit;
        
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
    <title>Guest Login – Flowwork</title>
    <link rel="stylesheet" href="/projects/assets/board.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        
        .guest-login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .guest-login-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 400px;
            width: 100%;
            padding: 40px;
        }
        
        .guest-login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .guest-login-logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        
        .guest-login-logo svg {
            width: 32px;
            height: 32px;
            fill: white;
        }
        
        .guest-login-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a1d29;
            margin: 0 0 8px 0;
        }
        
        .guest-login-subtitle {
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
            box-sizing: border-box;
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
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>

<div class="guest-login-page">
    <div class="guest-login-card">
        
        <div class="guest-login-header">
            <div class="guest-login-logo">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
            </div>
            <h1 class="guest-login-title">Guest Access</h1>
            <p class="guest-login-subtitle">Login to view your board</p>
        </div>
        
        <?php if ($error): ?>
            <div class="guest-alert">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="guest-form-group">
                <label class="guest-label">Email Address</label>
                <input type="email" 
                       name="email" 
                       class="guest-input" 
                       required 
                       autocomplete="email" 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            
            <div class="guest-form-group">
                <label class="guest-label">Password</label>
                <input type="password" 
                       name="password" 
                       class="guest-input" 
                       required 
                       autocomplete="current-password">
            </div>
            
            <button type="submit" class="guest-btn">
                🔓 Login
            </button>
        </form>
        
    </div>
</div>

</body>
</html>