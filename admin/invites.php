<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Check admin access
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Access denied - Admin only');
}

// Fetch invites
$stmt = $DB->prepare("
    SELECT i.*, 
           u.first_name as created_by_name, 
           u.last_name as created_by_surname,
           uu.first_name as used_by_name,
           uu.last_name as used_by_surname
    FROM invites i
    LEFT JOIN users u ON u.id = i.created_by
    LEFT JOIN users uu ON uu.id = i.used_by
    WHERE i.company_id = ?
    ORDER BY i.created_at DESC
");
$stmt->execute([$companyId]);
$invites = $stmt->fetchAll();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_invite') {
    try {
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? 'member');
        $expiryDays = (int)($_POST['expiry_days'] ?? 7);
        $message = trim($_POST['message'] ?? '');
        
        if (empty($email)) {
            throw new Exception('Email is required');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        // Check if user already exists
        $stmt = $DB->prepare("SELECT id FROM users WHERE email = ? AND company_id = ?");
        $stmt->execute([$email, $companyId]);
        if ($stmt->fetch()) {
            throw new Exception('User already exists with this email');
        }
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryDays days"));
        
        // Create invite
        $stmt = $DB->prepare("
            INSERT INTO invites (company_id, email, token, role, expires_at, created_by, created_at, custom_message)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$companyId, $email, $token, $role, $expiresAt, $userId, $message]);
        
        // Log audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'invite_sent', ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, "Invited $email as $role"]);
        
        // TODO: Send email with invite link
        $inviteLink = "https://" . $_SERVER['HTTP_HOST'] . "/accept-invite.php?token=" . $token;
        
        $success = "Invite sent to $email. Link: <a href='$inviteLink' target='_blank'>$inviteLink</a>";
        
        // Refresh invites
        $stmt = $DB->prepare("
            SELECT i.*, 
                   u.first_name as created_by_name, 
                   u.last_name as created_by_surname,
                   uu.first_name as used_by_name,
                   uu.last_name as used_by_surname
            FROM invites i
            LEFT JOIN users u ON u.id = i.created_by
            LEFT JOIN users uu ON uu.id = i.used_by
            WHERE i.company_id = ?
            ORDER BY i.created_at DESC
        ");
        $stmt->execute([$companyId]);
        $invites = $stmt->fetchAll();
        
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
    <title>Invites – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Invites & Access Links</h1>
                    <p class="fw-admin__page-subtitle">Send email invitations to new team members</p>
                </div>
                <button class="fw-admin__btn fw-admin__btn--primary" id="btnSendInvite">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    Send Invite
                </button>
            </header>

            <?php if ($success): ?>
                <div class="fw-admin__alert fw-admin__alert--success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <?= $success ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="fw-admin__alert fw-admin__alert--error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Invites Table -->
            <div class="fw-admin__card">
                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Sent By</th>
                                <th>Sent At</th>
                                <th>Expires</th>
                                <th>Used By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invites)): ?>
                            <tr>
                                <td colspan="8" class="fw-admin__empty-state">No invites sent yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($invites as $invite): 
                                $isExpired = strtotime($invite['expires_at']) < time();
                                $status = $invite['used'] ? 'accepted' : ($isExpired ? 'expired' : 'pending');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($invite['email']) ?></td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= strtolower($invite['role']) ?>">
                                        <?= ucfirst($invite['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= $status === 'accepted' ? 'success' : ($status === 'expired' ? 'muted' : 'warning') ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($invite['created_by_name'] . ' ' . $invite['created_by_surname']) ?></td>
                                <td><?= date('M j, Y g:i A', strtotime($invite['created_at'])) ?></td>
                                <td><?= date('M j, Y', strtotime($invite['expires_at'])) ?></td>
                                <td>
                                    <?php if ($invite['used']): ?>
                                        <?= htmlspecialchars($invite['used_by_name'] . ' ' . $invite['used_by_surname']) ?>
                                        <br><small><?= date('M j, Y', strtotime($invite['used_at'])) ?></small>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-admin__table-actions">
                                        <?php if (!$invite['used'] && !$isExpired): ?>
                                        <button class="fw-admin__btn-icon" 
                                                onclick="copyInviteLink('<?= htmlspecialchars($invite['token']) ?>')" 
                                                title="Copy Link">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                            </svg>
                                        </button>
                                        <button class="fw-admin__btn-icon fw-admin__btn-icon--danger" 
                                                onclick="revokeInvite(<?= $invite['id'] ?>)" 
                                                title="Revoke">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <line x1="18" y1="6" x2="6" y2="18"/>
                                                <line x1="6" y1="6" x2="18" y2="18"/>
                                            </svg>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Send Invite Modal -->
<div class="fw-admin__modal" id="modalInvite" aria-hidden="true">
    <div class="fw-admin__modal-backdrop"></div>
    <div class="fw-admin__modal-content">
        <header class="fw-admin__modal-header">
            <h2>Send Invite</h2>
            <button class="fw-admin__modal-close" aria-label="Close">&times;</button>
        </header>
        <form method="POST" class="fw-admin__form">
            <input type="hidden" name="action" value="send_invite">
            
            <div class="fw-admin__form-grid">
                <div class="fw-admin__form-group fw-admin__form-group--full">
                    <label class="fw-admin__label">Email Address <span class="fw-admin__required">*</span></label>
                    <input type="email" name="email" class="fw-admin__input" required>
                </div>

                <div class="fw-admin__form-group">
                    <label class="fw-admin__label">Role <span class="fw-admin__required">*</span></label>
                    <select name="role" class="fw-admin__select" required>
                        <option value="member" selected>Member</option>
                        <option value="viewer">Viewer</option>
                        <option value="pos">POS Only</option>
                        <option value="bookkeeper">Bookkeeper</option>
                    </select>
                </div>

                <div class="fw-admin__form-group">
                    <label class="fw-admin__label">Expires In</label>
                    <select name="expiry_days" class="fw-admin__select">
                        <option value="7" selected>7 days</option>
                        <option value="14">14 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>

                <div class="fw-admin__form-group fw-admin__form-group--full">
                    <label class="fw-admin__label">Custom Message (optional)</label>
                    <textarea name="message" class="fw-admin__textarea" rows="3" placeholder="Add a personal welcome message..."></textarea>
                </div>
            </div>

            <footer class="fw-admin__modal-footer">
                <button type="button" class="fw-admin__btn fw-admin__btn--secondary" id="btnCancelInvite">Cancel</button>
                <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Send Invite</button>
            </footer>
        </form>
    </div>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
<script>
// Open modal
document.getElementById('btnSendInvite')?.addEventListener('click', () => {
    const modal = document.getElementById('modalInvite');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
});

// Close modal
document.getElementById('btnCancelInvite')?.addEventListener('click', () => {
    const modal = document.getElementById('modalInvite');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
});

document.querySelector('#modalInvite .fw-admin__modal-close')?.addEventListener('click', () => {
    const modal = document.getElementById('modalInvite');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
});

document.querySelector('#modalInvite .fw-admin__modal-backdrop')?.addEventListener('click', () => {
    const modal = document.getElementById('modalInvite');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
});

function copyInviteLink(token) {
    const link = `${window.location.origin}/accept-invite.php?token=${token}`;
    navigator.clipboard.writeText(link).then(() => {
        alert('Invite link copied to clipboard!');
    });
}

async function revokeInvite(inviteId) {
    if (!confirm('Are you sure you want to revoke this invite?')) return;
    
    try {
        const res = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'revoke_invite', invite_id: inviteId })
        });
        
        const data = await res.json();
        
        if (data.success) {
            alert('Invite revoked');
            location.reload();
        } else {
            alert(data.error || 'Failed to revoke invite');
        }
    } catch (err) {
        console.error(err);
        alert('Network error');
    }
}
</script>
</body>
</html>