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

// Fetch all boards with member counts
$stmt = $DB->prepare("
    SELECT
        pb.board_id,
        pb.title,
        pb.board_type,
        pb.created_at,
        p.name as project_name,
        p.project_id,
        (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = pb.board_id) as member_count,
        (SELECT COUNT(*) FROM board_items bi WHERE bi.board_id = pb.board_id) as item_count,
        (SELECT CONCAT(u.first_name, ' ', u.last_name) FROM board_members bm2
         JOIN users u ON u.id = bm2.user_id
         WHERE bm2.board_id = pb.board_id AND bm2.role = 'owner' LIMIT 1) as owner_name
    FROM project_boards pb
    LEFT JOIN projects p ON p.project_id = pb.project_id
    WHERE pb.company_id = ?
    ORDER BY pb.created_at DESC
");
$stmt->execute([$companyId]);
$boards = $stmt->fetchAll();
?>
<?php
  $pageTitle = 'Boards & Permissions – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Boards & Permissions</h1>
                    <p class="fw-admin__page-subtitle">Manage board access and visibility across all projects</p>
                </div>
            </header>

            <!-- Boards Table -->
            <div class="fw-admin__card">
                <div class="fw-admin__table-wrapper">
                    <table class="fw-admin__table">
                        <thead>
                            <tr>
                                <th>Board Name</th>
                                <th>Project</th>
                                <th>Type</th>
                                <th>Owner</th>
                                <th>Members</th>
                                <th>Items</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($boards)): ?>
                            <tr>
                                <td colspan="8" class="fw-admin__empty-state">No boards found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($boards as $board): ?>
                            <tr>
                                <td>
                                    <a href="/projects/board.php?id=<?= $board['board_id'] ?>" target="_blank" style="color: var(--fw-primary); text-decoration: none;">
                                        <?= htmlspecialchars($board['title']) ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="/projects/project.php?id=<?= $board['project_id'] ?? '' ?>" target="_blank" style="color: inherit; text-decoration: none;">
                                        <?= htmlspecialchars($board['project_name'] ?? '') ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="fw-admin__badge fw-admin__badge--<?= strtolower($board['board_type']) ?>">
                                        <?= ucfirst($board['board_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($board['owner_name'] ?? '—') ?></td>
                                <td><?= $board['member_count'] ?></td>
                                <td><?= $board['item_count'] ?></td>
                                <td><?= date('M j, Y', strtotime($board['created_at'])) ?></td>
                                <td>
                                    <div class="fw-admin__table-actions">
                                        <button class="fw-admin__btn-icon"
                                                data-board-id="<?= $board['board_id'] ?>"
                                                data-board-title="<?= htmlspecialchars($board['title']) ?>"
                                                onclick="manageBoardAccess(this.dataset.boardId, this.dataset.boardTitle)"
                                                title="Manage Access">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                                <circle cx="9" cy="7" r="4"/>
                                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                            </svg>
                                        </button>
                                        <button class="fw-admin__btn-icon"
                                                onclick="exportBoard(<?= $board['board_id'] ?>)"
                                                title="Export CSV">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                <polyline points="7 10 12 15 17 10"/>
                                                <line x1="12" y1="15" x2="12" y2="3"/>
                                            </svg>
                                        </button>
                                        <button class="fw-admin__btn-icon fw-admin__btn-icon--danger"
                                                onclick="archiveBoard(<?= $board['board_id'] ?>)"
                                                title="Archive">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="21 8 21 21 3 21 3 8"/>
                                                <rect x="1" y="3" width="22" height="5"/>
                                                <line x1="10" y1="12" x2="14" y2="12"/>
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
<!-- Board Access Modal -->
<div class="fw-admin__modal" id="modalBoardAccess" aria-hidden="true">
    <div class="fw-admin__modal-backdrop"></div>
    <div class="fw-admin__modal-content">
        <header class="fw-admin__modal-header">
            <h2 id="boardAccessTitle">Manage Board Access</h2>
            <button class="fw-admin__modal-close" aria-label="Close">&times;</button>
        </header>
        <div style="padding: var(--fw-spacing-lg);">
            <div id="boardAccessLoading" style="text-align: center; padding: 20px; color: var(--fw-text-muted);">Loading members...</div>

            <!-- Current Members -->
            <div id="boardMembersList" style="display: none;">
                <h3 style="margin: 0 0 12px; font-size: 14px; font-weight: 700;">Current Members</h3>
                <div id="memberRows"></div>
            </div>

            <!-- Add Member -->
            <div id="addMemberSection" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--fw-border);">
                <h3 style="margin: 0 0 12px; font-size: 14px; font-weight: 700;">Add Member</h3>
                <div style="display: flex; gap: 8px;">
                    <select id="addMemberSelect" class="fw-admin__select" style="flex: 1;"></select>
                    <select id="addMemberRole" class="fw-admin__select" style="width: 120px;">
                        <option value="editor">Editor</option>
                        <option value="viewer">Viewer</option>
                    </select>
                    <button class="fw-admin__btn fw-admin__btn--primary" onclick="addBoardMember()">Add</button>
                </div>
            </div>
        </div>
        <footer class="fw-admin__modal-footer">
            <button type="button" class="fw-admin__btn fw-admin__btn--secondary" onclick="closeBoardModal()">Close</button>
        </footer>
    </div>
</div>
<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
let currentBoardId = null;

async function manageBoardAccess(boardId, boardTitle) {
    currentBoardId = boardId;
    const modal = document.getElementById('modalBoardAccess');
    const title = document.getElementById('boardAccessTitle');
    title.textContent = 'Manage Access: ' + boardTitle;

    document.getElementById('boardAccessLoading').style.display = 'block';
    document.getElementById('boardMembersList').style.display = 'none';
    document.getElementById('addMemberSection').style.display = 'none';

    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    try {
        const res = await fetch('/admin/api.php?action=get_board_members&board_id=' + boardId);
        const data = await res.json();

        if (!data.success) {
            alert(data.error || 'Failed to load members');
            return;
        }

        document.getElementById('boardAccessLoading').style.display = 'none';
        document.getElementById('boardMembersList').style.display = 'block';
        document.getElementById('addMemberSection').style.display = 'block';

        // Render current members
        const rows = document.getElementById('memberRows');
        rows.innerHTML = '';

        if (data.members.length === 0) {
            rows.innerHTML = '<p style="color: var(--fw-text-muted); font-size: 13px;">No members assigned to this board.</p>';
        } else {
            data.members.forEach(m => {
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--fw-border);';
                row.innerHTML = `
                    <div style="flex:1;">
                        <div style="font-weight:600;font-size:14px;">${escapeHtml(m.first_name + ' ' + m.last_name)}</div>
                        <div style="font-size:12px;color:var(--fw-text-muted);">${escapeHtml(m.email)}</div>
                    </div>
                    <select class="fw-admin__select" style="width:120px;" onchange="updateBoardMemberRole(${m.user_id}, this.value)" ${m.role === 'owner' ? 'disabled' : ''}>
                        <option value="owner" ${m.role === 'owner' ? 'selected' : ''}>Owner</option>
                        <option value="editor" ${m.role === 'editor' ? 'selected' : ''}>Editor</option>
                        <option value="viewer" ${m.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                    </select>
                    ${m.role !== 'owner' ? `<button class="fw-admin__btn-icon fw-admin__btn-icon--danger" onclick="removeBoardMember(${m.user_id})" title="Remove">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>` : ''}
                `;
                rows.appendChild(row);
            });
        }

        // Populate add member dropdown
        const select = document.getElementById('addMemberSelect');
        select.innerHTML = '';
        if (data.available_users.length === 0) {
            select.innerHTML = '<option value="">No available users</option>';
        } else {
            select.innerHTML = '<option value="">Select a user...</option>';
            data.available_users.forEach(u => {
                select.innerHTML += `<option value="${u.id}">${escapeHtml(u.first_name + ' ' + u.last_name)} (${escapeHtml(u.email)})</option>`;
            });
        }
    } catch (err) {
        console.error(err);
        alert('Network error');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function updateBoardMemberRole(memberId, role) {
    try {
        const res = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'update_board_member', board_id: currentBoardId, user_id: memberId, role: role })
        });
        const data = await res.json();
        if (!data.success) alert(data.error || 'Failed to update role');
    } catch (err) {
        console.error(err);
        alert('Network error');
    }
}

async function removeBoardMember(memberId) {
    if (!confirm('Remove this member from the board?')) return;

    try {
        const res = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'remove_board_member', board_id: currentBoardId, user_id: memberId })
        });
        const data = await res.json();
        if (data.success) {
            manageBoardAccess(currentBoardId, document.getElementById('boardAccessTitle').textContent.replace('Manage Access: ', ''));
        } else {
            alert(data.error || 'Failed to remove member');
        }
    } catch (err) {
        console.error(err);
        alert('Network error');
    }
}

async function addBoardMember() {
    const select = document.getElementById('addMemberSelect');
    const role = document.getElementById('addMemberRole').value;
    const memberId = select.value;

    if (!memberId) { alert('Select a user to add'); return; }

    try {
        const res = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'add_board_member', board_id: currentBoardId, user_id: memberId, role: role })
        });
        const data = await res.json();
        if (data.success) {
            manageBoardAccess(currentBoardId, document.getElementById('boardAccessTitle').textContent.replace('Manage Access: ', ''));
        } else {
            alert(data.error || 'Failed to add member');
        }
    } catch (err) {
        console.error(err);
        alert('Network error');
    }
}

function closeBoardModal() {
    const modal = document.getElementById('modalBoardAccess');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

// Close on backdrop click
document.querySelector('#modalBoardAccess .fw-admin__modal-backdrop')?.addEventListener('click', closeBoardModal);
document.querySelector('#modalBoardAccess .fw-admin__modal-close')?.addEventListener('click', closeBoardModal);

function exportBoard(boardId) {
    window.location.href = `/admin/api.php?action=export_board&board_id=${boardId}`;
}

async function archiveBoard(boardId) {
    if (!confirm('Archive this board? It will be hidden but not deleted.')) return;

    try {
        const res = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'archive_board', board_id: boardId })
        });

        const data = await res.json();

        if (data.success) {
            alert('Board archived');
            location.reload();
        } else {
            alert(data.error || 'Failed to archive');
        }
    } catch (err) {
        console.error(err);
        alert('Network error');
    }
}
</script>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
