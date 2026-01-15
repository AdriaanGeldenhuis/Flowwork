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

// Fetch users
$stmt = $DB->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM project_members pm WHERE pm.user_id = u.id) as project_count
    FROM users u
    WHERE u.company_id = ?
    ORDER BY u.role DESC, u.created_at DESC
");
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();

// Fetch plan limits
$stmt = $DB->prepare("
    SELECT p.max_users 
    FROM companies c
    JOIN plans p ON p.id = c.plan_id
    WHERE c.id = ?
");
$stmt->execute([$companyId]);
$maxUsers = $stmt->fetchColumn();

$activeSeats = count(array_filter($users, fn($u) => $u['is_seat'] == 1 && $u['status'] === 'active'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users & Roles – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Users & Roles</h1>
                    <p class="fw-admin__page-subtitle">Manage team members and permissions</p>
                </div>
                <button class="fw-admin__btn fw-admin__btn--primary" id="btnAddUser">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add User
                </button>
            </header>

            <!-- Usage Stats -->
            <div class="fw-admin__alert fw-admin__alert--info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                Using <strong><?= $activeSeats ?> of <?= $maxUsers ?></strong> user seats
            </div>

            <!-- Users Table -->
            <div class="fw-admin__card">
                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Seat</th>
                                <th>Projects</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-admin__user-name">
                                        <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= strtolower($u['role']) ?>">
                                        <?= ucfirst($u['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= $u['status'] === 'active' ? 'success' : 'muted' ?>">
                                        <?= ucfirst($u['status']) ?>
                                    </span>
                                </td>
                                <td><?= $u['is_seat'] ? 'Yes' : 'No' ?></td>
                                <td><?= $u['project_count'] ?></td>
                                <td><?= $u['last_login_at'] ? date('M j, Y', strtotime($u['last_login_at'])) : '—' ?></td>
                                <td>
                                    <div class="fw-admin__table-actions">
                                        <button class="fw-admin__btn-icon" 
                                                onclick="editUser(<?= $u['id'] ?>)" 
                                                title="Edit">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                        </button>
                                        <?php if ($u['id'] != $userId): ?>
                                        <button class="fw-admin__btn-icon fw-admin__btn-icon--danger" 
                                                onclick="deleteUser(<?= $u['id'] ?>)" 
                                                title="Remove">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            </svg>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Add/Edit User Modal -->
<div class="fw-admin__modal" id="modalUser" aria-hidden="true">
    <div class="fw-admin__modal-backdrop"></div>
    <div class="fw-admin__modal-content">
        <header class="fw-admin__modal-header">
            <h2 id="modalUserTitle">Add User</h2>
            <button class="fw-admin__modal-close" aria-label="Close">&times;</button>
        </header>
        <form id="formUser" class="fw-admin__form">
            <input type="hidden" name="user_id" id="userId">
            
            <div class="fw-admin__form-grid">
                <div class="fw-admin__form-group">
                    <label class="fw-admin__label">First Name <span class="fw-admin__required">*</span></label>
                    <input type="text" name="first_name" id="userFirstName" class="fw-admin__input" required>
                </div>

                <div class="fw-admin__form-group">
                    <label class="fw-admin__label">Last Name <span class="fw-admin__required">*</span></label>
                    <input type="text" name="last_name" id="userLastName" class="fw-admin__input" required>
                </div>

                <div class="fw-admin__form-group fw-admin__form-group--full">
                    <label class="fw-admin__label">Email <span class="fw-admin__required">*</span></label>
                    <input type="email" name="email" id="userEmail" class="fw-admin__input" required>
                </div>

                <div class="fw-admin__form-group">
                    <label class="fw-admin__label">Role <span class="fw-admin__required">*</span></label>
                    <select name="role" id="userRole" class="fw-admin__select" required>
                        <option value="admin">Admin</option>
                        <option value="member" selected>Member</option>
                        <option value="viewer">Viewer</option>
                        <option value="pos">POS Only</option>
                        <option value="bookkeeper">Bookkeeper</option>
                    </select>
                </div>

                <div class="fw-admin__form-group">
                    <label class="fw-admin__label">Status</label>
                    <select name="status" id="userStatus" class="fw-admin__select">
                        <option value="active" selected>Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>

                <div class="fw-admin__form-group fw-admin__form-group--full">
                    <label class="fw-admin__checkbox">
                        <input type="checkbox" name="is_seat" id="userIsSeat" value="1" checked>
                        <span>Counts as a seat (uncheck for Bookkeeper role)</span>
                    </label>
                </div>
            </div>

            <footer class="fw-admin__modal-footer">
                <button type="button" class="fw-admin__btn fw-admin__btn--secondary" id="btnCancelUser">Cancel</button>
                <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save User</button>
            </footer>
        </form>
    </div>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
<script>
// User management functions
function editUser(userId) {
    // TODO: Fetch user data and populate modal
    console.log('Edit user:', userId);
}

function deleteUser(userId) {
    if (!confirm('Are you sure you want to remove this user?')) return;
    // TODO: Implement delete
    console.log('Delete user:', userId);
}
</script>
</body>
</html>