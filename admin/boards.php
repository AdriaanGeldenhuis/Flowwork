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
        p.id as project_id,
        (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = pb.board_id) as member_count,
        (SELECT COUNT(*) FROM board_items bi WHERE bi.board_id = pb.board_id) as item_count,
        (SELECT CONCAT(u.first_name, ' ', u.last_name) FROM board_members bm2 
         JOIN users u ON u.id = bm2.user_id 
         WHERE bm2.board_id = pb.board_id AND bm2.role = 'owner' LIMIT 1) as owner_name
    FROM project_boards pb
    LEFT JOIN projects p ON p.id = pb.project_id
    WHERE pb.company_id = ?
    ORDER BY pb.created_at DESC
");
$stmt->execute([$companyId]);
$boards = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boards & Permissions – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>
    
    <main class="fw-admin__main">
        <div class="fw-admin__container">
            
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
                                    <a href="/projects/project.php?id=<?= $board['project_id'] ?>" target="_blank" style="color: inherit; text-decoration: none;">
                                        <?= htmlspecialchars($board['project_name']) ?>
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
                                                onclick="manageBoardAccess(<?= $board['board_id'] ?>)" 
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

        </div>
    </main>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
<script>
function manageBoardAccess(boardId) {
    alert('Board access management: TODO - Open modal with user list + role dropdowns');
    // TODO: Implement board access modal
}

function exportBoard(boardId) {
    window.location.href = `/admin/api.php?action=export_board&board_id=${boardId}`;
}

async function archiveBoard(boardId) {
    if (!confirm('Archive this board? It will be hidden but not deleted.')) return;
    
    try {
        const res = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
</body>
</html>