<?php
// /finances/period_locks.php
//
// UI to manage GL period locks. Allows users with appropriate permissions
// (admin or bookkeeper) to view existing period locks and create/delete locks.
// A period lock prevents any GL postings on or before the specified date.

// Dynamically load init, auth and permissions for root or /app structures.
$__fin_root = realpath(__DIR__ . '/..');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}

// Only allow admins or bookkeepers to access this page
requireRoles(['admin','bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    header('Location: /login.php');
    exit;
}

// Fetch existing period locks for this company. Column names follow the
// schema: lock_id, lock_date, lock_reason, locked_by, locked_at.
$stmt = $DB->prepare(
    "SELECT lock_id, lock_date, lock_reason, locked_by, locked_at\n" .
    "FROM gl_period_locks WHERE company_id = ? ORDER BY lock_date DESC"
);
$stmt->execute([$companyId]);
$locks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user names for display. Build map of user_id => full name
$userIds = array_column($locks, 'locked_by');
$userNames = [];
if ($userIds) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT user_id, CONCAT(first_name, ' ', last_name) AS full_name\n" .
           "FROM users WHERE user_id IN ($placeholders)";
    $stmtU = $DB->prepare($sql);
    $stmtU->execute($userIds);
    $userNames = $stmtU->fetchAll(PDO::FETCH_KEY_PAIR);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Period Locks</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        h1 { margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; }
        th { background-color: #f1f1f1; }
        form { max-width: 500px; background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: bold; margin-bottom: 0.5rem; }
        input[type="date"], input[type="text"] { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 0.6rem 1.2rem; background-color: #28a745; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        button:disabled { background-color: #aaa; }
        .message { margin-top: 0.5rem; font-weight: bold; }
        .danger-btn { background-color: #dc3545; color: #fff; border: none; padding: 0.4rem 0.8rem; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Period Locks</h1>
    <form id="lockForm">
        <div class="form-group">
            <label for="lock_date">Lock Date (YYYY-MM-DD)</label>
            <input type="date" id="lock_date" name="lock_date" required>
        </div>
        <div class="form-group">
            <label for="reason">Reason (optional)</label>
            <input type="text" id="reason" name="reason" placeholder="Reason for lock">
        </div>
        <button type="submit" id="saveLockBtn">Add Lock</button>
        <div class="message" id="formMessage"></div>
    </form>
    <table>
        <thead>
            <tr>
                <th>Lock Date</th>
                <th>Reason</th>
                <th>Created By</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="locksTableBody">
            <?php foreach ($locks as $lock): ?>
            <tr data-lock-id="<?php echo htmlspecialchars($lock['lock_id']); ?>">
                <td><?php echo htmlspecialchars($lock['lock_date']); ?></td>
                <td><?php echo htmlspecialchars($lock['lock_reason']); ?></td>
                <td><?php echo htmlspecialchars($userNames[$lock['locked_by']] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($lock['locked_at']); ?></td>
                <td><button class="danger-btn" data-id="<?php echo htmlspecialchars($lock['lock_id']); ?>">Delete</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script>
        // Handle lock form submission
        document.getElementById('lockForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var lockDate = document.getElementById('lock_date').value;
            var reason = document.getElementById('reason').value;
            document.getElementById('saveLockBtn').disabled = true;
            document.getElementById('formMessage').textContent = '';
            fetch('/finances/ajax/period_lock_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lock_date: lockDate, reason: reason })
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('saveLockBtn').disabled = false;
                if (data.ok) {
                    document.getElementById('formMessage').textContent = 'Lock added successfully.';
                    // Add new row to table
                    var tbody = document.getElementById('locksTableBody');
                    var tr = document.createElement('tr');
                    tr.setAttribute('data-lock-id', data.lock_id);
                    tr.innerHTML = '<td>' + lockDate + '</td>' +
                                   '<td>' + (reason || '') + '</td>' +
                                   '<td><?php echo htmlspecialchars($userNames[$userId] ?? (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))); ?>' + '</td>' +
                                   '<td>' + data.locked_at + '</td>' +
                                   '<td><button class="danger-btn" data-id="' + data.lock_id + '">Delete</button></td>';
                    tbody.insertBefore(tr, tbody.firstChild);
                    // Reset form
                    document.getElementById('lock_date').value = '';
                    document.getElementById('reason').value = '';
                } else {
                    document.getElementById('formMessage').textContent = data.error || 'Failed to add lock.';
                }
            })
            .catch(err => {
                document.getElementById('saveLockBtn').disabled = false;
                document.getElementById('formMessage').textContent = 'Error adding lock.';
            });
        });
        // Handle delete buttons
        document.getElementById('locksTableBody').addEventListener('click', function(e) {
            if (e.target && e.target.matches('button.danger-btn')) {
                var id = e.target.getAttribute('data-id');
                if (confirm('Delete this lock?')) {
                    fetch('/finances/ajax/period_lock_delete.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ lock_id: id })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.ok) {
                            // Remove row
                            var row = document.querySelector('tr[data-lock-id="' + id + '"]');
                            if (row) row.remove();
                        } else {
                            alert(data.error || 'Failed to delete lock');
                        }
                    })
                    .catch(err => { alert('Error deleting lock'); });
                }
            }
        });
    </script>
</body>
</html>