<?php
require_once __DIR__ . '/init.php';

$token = trim($_GET['token'] ?? '');
$error = $success = '';

if (empty($token)) {
    $error = 'Invalid invite link';
}

// Fetch invite
if (!$error) {
    $stmt = $DB->prepare("
        SELECT i.*, c.name as company_name 
        FROM invites i
        JOIN companies c ON c.id = i.company_id
        WHERE i.token = ? AND i.used = 0 AND i.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();
    
    if (!$invite) {
        $error = 'Invite not found or expired';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $passwordConfirm = trim($_POST['password_confirm'] ?? '');
        
        if (empty($firstName) || empty($lastName) || empty($password)) {
            throw new Exception('All fields are required');
        }
        
        if ($password !== $passwordConfirm) {
            throw new Exception('Passwords do not match');
        }
        
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters');
        }
        
        // Check if email already exists
        $stmt = $DB->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$invite['email']]);
        if ($stmt->fetch()) {
            throw new Exception('An account already exists with this email');
        }
        
        $DB->beginTransaction();
        
        // Create user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $isSeat = $invite['role'] === 'bookkeeper' ? 0 : 1;
        
        $stmt = $DB->prepare("
            INSERT INTO users (company_id, email, password_hash, first_name, last_name, role, is_seat, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            $invite['company_id'],
            $invite['email'],
            $passwordHash,
            $firstName,
            $lastName,
            $invite['role'],
            $isSeat
        ]);
        
        $newUserId = $DB->lastInsertId();
        
        // Mark invite as used
        $stmt = $DB->prepare("
            UPDATE invites 
            SET used = 1, used_by = ?, used_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newUserId, $invite['id']]);
        
        // Log audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'user_registered', ?, NOW())
        ");
        $stmt->execute([$invite['company_id'], $newUserId, "User accepted invite: {$invite['email']}"]);
        
        $DB->commit();
        
        $success = true;
        
    } catch (Exception $e) {
        if ($DB->inTransaction()) {
            $DB->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invite</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 420px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1a1d29;
        }
        .subtitle {
            color: #6b7280;
            margin-bottom: 32px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #1a1d29;
        }
        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        input:focus {
            outline: 2px solid #667eea;
            border-color: #667eea;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        button:hover {
            background: #5568d3;
            transform: translateY(-1px);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee;
            color: #c00;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #efe;
            color: #060;
            border: 1px solid #cfc;
        }
        .success-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 32px;
        }
        a {
            color: #667eea;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <div class="success-icon">✓</div>
            <h1 style="text-align: center;">Welcome aboard!</h1>
            <p class="subtitle" style="text-align: center;">Your account has been created successfully.</p>
            <a href="/login.php" style="display: block; text-align: center; padding: 12px; background: #667eea; color: #fff; border-radius: 8px; font-weight: 600;">
                Go to Login
            </a>
        <?php elseif ($error): ?>
            <h1>Oops!</h1>
            <p class="subtitle">Something went wrong</p>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <a href="/">← Back to home</a>
        <?php else: ?>
            <h1>Accept Invitation</h1>
            <p class="subtitle">Join <strong><?= htmlspecialchars($invite['company_name']) ?></strong> as <?= ucfirst($invite['role']) ?></p>
            
            <form method="POST">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" value="<?= htmlspecialchars($invite['email']) ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" minlength="8" required>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="password_confirm" minlength="8" required>
                </div>
                
                <button type="submit">Create Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>