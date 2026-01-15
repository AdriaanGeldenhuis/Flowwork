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

// Fetch company with Yoco keys
$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Fetch API keys
$stmt = $DB->prepare("
    SELECT ak.*, u.first_name, u.last_name
    FROM api_keys ak
    LEFT JOIN users u ON u.id = ak.created_by
    WHERE ak.company_id = ?
    ORDER BY ak.created_at DESC
");
$stmt->execute([$companyId]);
$apiKeys = $stmt->fetchAll();

// Fetch webhooks
$stmt = $DB->prepare("
    SELECT w.*, u.first_name, u.last_name
    FROM webhooks w
    LEFT JOIN users u ON u.id = w.created_by
    WHERE w.company_id = ?
    ORDER BY w.created_at DESC
");
$stmt->execute([$companyId]);
$webhooks = $stmt->fetchAll();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_yoco') {
            $yocoMode = trim($_POST['yoco_mode'] ?? 'test');
            $yocoPubKey = trim($_POST['yoco_pub_key'] ?? '');
            $yocoSecKey = trim($_POST['yoco_sec_key'] ?? '');
            $yocoWebhookSecret = trim($_POST['yoco_webhook_secret'] ?? '');
            
            $stmt = $DB->prepare("
                UPDATE companies 
                SET yoco_mode = ?, 
                    yoco_pub_key = ?, 
                    yoco_sec_key = ?, 
                    yoco_webhook_secret = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$yocoMode, $yocoPubKey, $yocoSecKey, $yocoWebhookSecret, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'yoco_updated', 'Updated Yoco integration settings', NOW())
            ");
            $stmt->execute([$companyId, $userId]);
            
            $success = 'Yoco settings saved successfully';
            
            // Refresh company data
            $stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch();
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
    <title>Integrations – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Integrations</h1>
                    <p class="fw-admin__page-subtitle">Manage API keys, webhooks, and third-party services</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="fw-admin__alert fw-admin__alert--success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <?= htmlspecialchars($success) ?>
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

            <!-- Yoco Integration -->
            <div class="fw-admin__card">
                <h2 class="fw-admin__card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;display:inline-block;vertical-align:middle;margin-right:8px;">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                    Yoco Payment Gateway
                </h2>
                
                <form method="POST" class="fw-admin__form">
                    <input type="hidden" name="action" value="save_yoco">
                    
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Mode</label>
                            <select name="yoco_mode" class="fw-admin__select">
                                <option value="test" <?= ($company['yoco_mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test Mode</option>
                                <option value="live" <?= ($company['yoco_mode'] ?? 'test') === 'live' ? 'selected' : '' ?>>Live Mode</option>
                            </select>
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">
                                Use Test Mode for development. Switch to Live Mode when ready to accept real payments.
                            </small>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Publishable Key</label>
                            <input type="text" name="yoco_pub_key" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['yoco_pub_key'] ?? '') ?>"
                                   placeholder="pk_test_...">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">
                                Safe to share publicly (used in browser)
                            </small>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Secret Key</label>
                            <input type="password" name="yoco_sec_key" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['yoco_sec_key'] ?? '') ?>"
                                   placeholder="sk_test_...">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">
                                Keep this private (used on server)
                            </small>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Webhook Secret</label>
                            <input type="password" name="yoco_webhook_secret" class="fw-admin__input" 
                                   value="<?= htmlspecialchars($company['yoco_webhook_secret'] ?? '') ?>"
                                   placeholder="whsec_...">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">
                                Used to verify webhook signatures
                            </small>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <div style="padding: 12px; background: var(--fw-hover-bg); border-radius: var(--fw-radius-md); font-size: 13px;">
                                <strong>Webhook URL:</strong><br>
                                <code style="word-break: break-all;">https://<?= $_SERVER['HTTP_HOST'] ?>/webhooks/yoco.php</code><br>
                                <small style="color: var(--fw-text-muted);">Add this URL in your Yoco dashboard to receive payment notifications.</small>
                            </div>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">
                            Save Yoco Settings
                        </button>
                        <a href="https://developer.yoco.com/" target="_blank" class="fw-admin__btn fw-admin__btn--secondary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                            Yoco Docs
                        </a>
                    </div>
                </form>
            </div>

            <!-- API Keys -->
            <div class="fw-admin__card">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--fw-spacing-lg); border-bottom: 1px solid var(--fw-border);">
                    <h2 style="margin: 0; font-size: 16px; font-weight: 700;">API Keys</h2>
                    <button class="fw-admin__btn fw-admin__btn--primary" onclick="createAPIKey()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Create API Key
                    </button>
                </div>

                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Key</th>
                                <th>Scopes</th>
                                <th>Created By</th>
                                <th>Created</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($apiKeys)): ?>
                            <tr>
                                <td colspan="7" class="fw-admin__empty-state">No API keys created yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($apiKeys as $key): ?>
                            <tr>
                                <td><?= htmlspecialchars($key['name']) ?></td>
                                <td>
                                    <code style="font-size: 12px; color: var(--fw-text-muted);">
                                        <?= htmlspecialchars(substr($key['token_hash'], 0, 20)) ?>...
                                    </code>
                                </td>
                                <td>
                                    <?php 
                                    $scopes = json_decode($key['scopes'] ?? '[]', true);
                                    echo implode(', ', $scopes ?: ['read']);
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($key['first_name'] . ' ' . $key['last_name']) ?></td>
                                <td><?= date('M j, Y', strtotime($key['created_at'])) ?></td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= $key['revoked_at'] ? 'muted' : 'success' ?>">
                                        <?= $key['revoked_at'] ? 'Revoked' : 'Active' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!$key['revoked_at']): ?>
                                    <button class="fw-admin__btn-icon fw-admin__btn-icon--danger" 
                                            onclick="revokeAPIKey(<?= $key['id'] ?>)" 
                                            title="Revoke">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="18" y1="6" x2="6" y2="18"/>
                                            <line x1="6" y1="6" x2="18" y2="18"/>
                                        </svg>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Webhooks -->
            <div class="fw-admin__card">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--fw-spacing-lg); border-bottom: 1px solid var(--fw-border);">
                    <h2 style="margin: 0; font-size: 16px; font-weight: 700;">Webhooks</h2>
                    <button class="fw-admin__btn fw-admin__btn--primary" onclick="createWebhook()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add Webhook
                    </button>
                </div>

                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Events</th>
                                <th>Status</th>
                                <th>Last Delivery</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($webhooks)): ?>
                            <tr>
                                <td colspan="6" class="fw-admin__empty-state">No webhooks configured</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($webhooks as $webhook): 
                                $events = json_decode($webhook['events_json'] ?? '[]', true);
                            ?>
                            <tr>
                                <td>
                                    <code style="font-size: 12px; word-break: break-all;">
                                        <?= htmlspecialchars($webhook['url']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?>
                                </td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= $webhook['active'] ? 'success' : 'muted' ?>">
                                        <?= $webhook['active'] ? 'Active' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($webhook['last_delivery_at']): ?>
                                        <?= date('M j, g:i A', strtotime($webhook['last_delivery_at'])) ?>
                                        <br>
                                        <span class="fw-admin__badge fw-admin__badge--<?= $webhook['last_status'] === 200 ? 'success' : 'danger' ?>">
                                            <?= $webhook['last_status'] ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($webhook['first_name'] . ' ' . $webhook['last_name']) ?></td>
                                <td>
                                    <div class="fw-admin__table-actions">
                                        <button class="fw-admin__btn-icon" 
                                                onclick="testWebhook(<?= $webhook['id'] ?>)" 
                                                title="Send Test">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polygon points="5 3 19 12 5 21 5 3"/>
                                            </svg>
                                        </button>
                                        <button class="fw-admin__btn-icon fw-admin__btn-icon--danger" 
                                                onclick="deleteWebhook(<?= $webhook['id'] ?>)" 
                                                title="Delete">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            </svg>
                                        </button>
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

<script src="/admin/admin.js?v=2025-01-21-1"></script>
<script>
function createAPIKey() {
    const name = prompt('API Key Name:');
    if (!name) return;
    
    fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'create_api_key', name: name })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(`API Key Created!\n\nKey: ${data.api_key}\n\nSave this now - you won't see it again!`);
            location.reload();
        } else {
            alert(data.error || 'Failed to create API key');
        }
    });
}

async function revokeAPIKey(keyId) {
    if (!confirm('Revoke this API key? This cannot be undone.')) return;
    
    const res = await fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'revoke_api_key', key_id: keyId })
    });
    
    const data = await res.json();
    if (data.success) {
        alert('API key revoked');
        location.reload();
    } else {
        alert(data.error || 'Failed');
    }
}

function createWebhook() {
    const url = prompt('Webhook URL:');
    if (!url) return;
    
    fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'create_webhook', url: url })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Webhook created!');
            location.reload();
        } else {
            alert(data.error || 'Failed');
        }
    });
}

async function testWebhook(webhookId) {
    const res = await fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'test_webhook', webhook_id: webhookId })
    });
    
    const data = await res.json();
    alert(data.success ? 'Test ping sent!' : (data.error || 'Failed'));
}

async function deleteWebhook(webhookId) {
    if (!confirm('Delete this webhook?')) return;
    
    const res = await fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete_webhook', webhook_id: webhookId })
    });
    
    const data = await res.json();
    if (data.success) {
        alert('Webhook deleted');
        location.reload();
    } else {
        alert(data.error || 'Failed');
    }
}
</script>
</body>
</html>