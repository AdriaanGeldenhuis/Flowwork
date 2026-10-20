<?php
// /admin/api/people.php — the company's people.
//   GET  ?id=5                                         → {person}
//   POST {action:'create', email, first_name, last_name, role}  → {person, temp_password|null, reactivated}
//   POST {action:'update', id, first_name, last_name, role}
//   POST {action:'suspend' | 'reactivate' | 'signout' | 'remove', id}
// Rules: a company always keeps at least one active admin; you cannot
// suspend or remove yourself; a paid seat is any active member who is not a
// Bookkeeper, checked under a lock whenever one gets used. Someone whose
// account belongs to another company ("guest") joins only by accepting an
// invitation, and this company never changes their account itself (name,
// status, devices) — only their role here, or their membership.
require_once __DIR__ . '/_bootstrap.php';

/** Active admins of the company other than $exceptId. */
function people_other_admins(PDO $db, int $companyId, int $exceptId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_companies uc JOIN users u ON u.id = uc.user_id
                         WHERE uc.company_id = ? AND uc.role = 'admin' AND u.status = 'active' AND uc.user_id <> ?");
    $st->execute([$companyId, $exceptId]);
    return (int)$st->fetchColumn();
}

function people_row(PDO $db, int $companyId, int $userId): array
{
    $st = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.last_login_at, u.created_at,
                               u.company_id AS primary_company_id, uc.role
                          FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.user_id = ? AND uc.company_id = ?");
    $st->execute([$userId, $companyId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) admin_error('That person is not part of this company.', 404);
    $p['id'] = (int)$p['id'];
    $p['is_seat'] = $p['role'] !== 'bookkeeper' ? 1 : 0;
    $p['primary'] = (int)$p['primary_company_id'] === $companyId;
    unset($p['primary_company_id']);
    return $p;
}

/**
 * Lock the company row (so two people cannot take the last seat at once) and
 * refuse when every paid seat is in use. Call inside a transaction.
 */
function people_seat_check(PDO $db, int $companyId): void
{
    $db->prepare("SELECT id FROM companies WHERE id = ? FOR UPDATE")->execute([$companyId]);
    $s = AdminKit::seats($db, $companyId);
    if ($s['used'] >= $s['max']) {
        if ($db->inTransaction()) $db->rollBack();
        admin_error('All ' . $s['max'] . ' paid seats are in use. Free a seat or move to a bigger plan under Subscription.', 409, ['reason' => 'seats']);
    }
}

/** Run $fn in a transaction (rolled back on any error). */
function people_tx(PDO $db, callable $fn)
{
    $db->beginTransaction();
    try {
        $r = $fn();
        $db->commit();
        return $r;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

if (!admin_is_post()) {
    admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, (int)($_GET['id'] ?? 0))]);
}

$in = admin_input();
$action = admin_str($in, 'action', 20);
admin_rate_limit('admin-people', 120, 600);

if ($action === 'create') {
    $email = mb_strtolower(admin_str($in, 'email', 190));
    $first = admin_str($in, 'first_name', 100);
    $last = admin_str($in, 'last_name', 100);
    $role = admin_str($in, 'role', 20);
    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($first === '') $errors['first_name'] = 'Enter their first name.';
    if ($last === '') $errors['last_name'] = 'Enter their surname.';
    if (!isset(AdminKit::ROLES[$role])) $errors['role'] = 'Choose a role.';
    if ($errors) throw new AdminInvalid($errors);

    $st = $DB->prepare("SELECT id, status, company_id FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existing) {
        $st = $DB->prepare("SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?");
        $st->execute([$existing['id'], $ADMIN_COMPANY_ID]);
        if ($st->fetchColumn()) throw new AdminInvalid(['email' => 'They are already part of this company.']);
        if ((int)$existing['company_id'] !== $ADMIN_COMPANY_ID) {
            // Their account belongs to another company: they must agree to join.
            throw new AdminInvalid(['email' => 'They already have a Flowwork account with another company. Send them an invitation instead — they join by accepting it.']);
        }
    }

    $temp = null;
    $reactivated = false;
    $newId = people_tx($DB, function () use ($DB, $ADMIN_COMPANY_ID, $existing, $email, $first, $last, $role, &$temp, &$reactivated) {
        if ($role !== 'bookkeeper') people_seat_check($DB, $ADMIN_COMPANY_ID);
        if ($existing) {
            // Their own account, removed from the company earlier: bring it back as it was.
            $id = (int)$existing['id'];
            $DB->prepare("UPDATE users SET first_name = ?, last_name = ?, role = ?, is_seat = ?, status = 'active', updated_at = NOW() WHERE id = ?")
               ->execute([$first, $last, $role, $role === 'bookkeeper' ? 0 : 1, $id]);
            $reactivated = true;
        } else {
            // A readable one-time password: groups of four without look-alike characters,
            // long enough for the company's password rules.
            require_once __DIR__ . '/../../includes/password_policy.php';
            $min = fw_password_policy($DB, $ADMIN_COMPANY_ID)['min'];
            $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $groups = [];
            do {
                $chunk = '';
                for ($i = 0; $i < 4; $i++) $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                $groups[] = $chunk;
                $temp = implode('-', $groups) . '-' . random_int(10, 99) . '!';
            } while (count($groups) < 4 || strlen($temp) < $min);
            $DB->prepare("INSERT INTO users (company_id, email, password_hash, first_name, last_name, role, is_seat, status, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())")
               ->execute([$ADMIN_COMPANY_ID, $email, password_hash($temp, PASSWORD_DEFAULT), $first, $last, $role, $role === 'bookkeeper' ? 0 : 1]);
            $id = (int)$DB->lastInsertId();
        }
        $DB->prepare("INSERT INTO user_companies (user_id, company_id, role, created_at) VALUES (?, ?, ?, NOW())")->execute([$id, $ADMIN_COMPANY_ID, $role]);
        // Any open invitation for this address is now answered.
        $DB->prepare("UPDATE invites SET used = 1, used_by = ?, used_at = NOW() WHERE company_id = ? AND email = ? AND used = 0")->execute([$id, $ADMIN_COMPANY_ID, $email]);
        return $id;
    });
    AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, $reactivated ? 'user_reactivated' : 'user_created',
        ['email' => $email, 'role' => AdminKit::ROLES[$role][0], 'account' => $reactivated ? 'their earlier account, brought back' : 'new account'], 'user', $newId);
    admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, $newId), 'temp_password' => $temp, 'reactivated' => $reactivated]);
}

$id = admin_int($in, 'id');
$p = people_row($DB, $ADMIN_COMPANY_ID, $id);
$self = $id === $ADMIN_USER_ID;
$name = trim($p['first_name'] . ' ' . $p['last_name']) ?: $p['email'];

switch ($action) {
    case 'update':
        $role = admin_str($in, 'role', 20);
        $first = $p['primary'] ? admin_str($in, 'first_name', 100) : $p['first_name'];
        $last = $p['primary'] ? admin_str($in, 'last_name', 100) : $p['last_name'];
        $errors = [];
        if ($first === '') $errors['first_name'] = 'Enter a first name.';
        if ($last === '') $errors['last_name'] = 'Enter a surname.';
        if (!isset(AdminKit::ROLES[$role])) $errors['role'] = 'Choose a role.';
        if ($errors) throw new AdminInvalid($errors);
        if ($p['role'] === 'admin' && $role !== 'admin' && !people_other_admins($DB, $ADMIN_COMPANY_ID, $id)) {
            throw new AdminInvalid(['role' => 'The company needs at least one admin. Make someone else an admin first.']);
        }
        people_tx($DB, function () use ($DB, $ADMIN_COMPANY_ID, $p, $id, $role, $first, $last) {
            // A bookkeeper who becomes anything else starts using a seat.
            if ($p['role'] === 'bookkeeper' && $role !== 'bookkeeper' && $p['status'] === 'active') people_seat_check($DB, $ADMIN_COMPANY_ID);
            $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = ? AND company_id = ?")->execute([$role, $id, $ADMIN_COMPANY_ID]);
            if ($p['primary']) {
                // Their account belongs to this company: name and the account-wide role/seat follow.
                $DB->prepare("UPDATE users SET first_name = ?, last_name = ?, role = ?, is_seat = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$first, $last, $role, $role === 'bookkeeper' ? 0 : 1, $id]);
            }
        });
        $changes = [];
        if ($p['role'] !== $role) $changes['role'] = (AdminKit::ROLES[$p['role']][0] ?? $p['role']) . ' → ' . AdminKit::ROLES[$role][0];
        if ($p['first_name'] !== $first || $p['last_name'] !== $last) $changes['name'] = trim($first . ' ' . $last);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_updated', ['person' => $name] + $changes, 'user', $id);
        admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, $id)]);

    case 'suspend':
        if ($self) admin_error('You cannot suspend yourself.');
        if (!$p['primary']) admin_error($name . ' belongs to another company first. Remove them from this company instead.');
        if ($p['role'] === 'admin' && !people_other_admins($DB, $ADMIN_COMPANY_ID, $id)) admin_error('The company needs at least one active admin.');
        $DB->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?")->execute([$id]);
        fw_auth_revoke_all($DB, $id, 'suspended');
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_suspended', ['person' => $name], 'user', $id);
        admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, $id)]);

    case 'reactivate':
        if (!$p['primary']) admin_error($name . '’s own company manages whether their account is active.');
        people_tx($DB, function () use ($DB, $ADMIN_COMPANY_ID, $p, $id) {
            if ($p['role'] !== 'bookkeeper') people_seat_check($DB, $ADMIN_COMPANY_ID);
            $DB->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]);
        });
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_reactivated', ['person' => $name], 'user', $id);
        admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, $id)]);

    case 'signout':
        // A guest's devices belong to their own company; you can only remove them from this one.
        if (!$p['primary'] && !$self) admin_error($name . '’s account belongs to another company, which looks after their devices. Remove them from this company if they should no longer have access.');
        $n = fw_auth_revoke_all($DB, $id, 'signed_out', $self ? (int)($_SESSION['auth_sid'] ?? 0) : null);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_signed_out', ['person' => $name, 'devices' => $n], 'user', $id);
        admin_json(['devices' => $n]);

    case 'remove':
        if ($self) admin_error('You cannot remove yourself. Ask another admin.');
        if ($p['role'] === 'admin' && !people_other_admins($DB, $ADMIN_COMPANY_ID, $id)) admin_error('The company needs at least one active admin.');

        // Take them off this company's projects first (safe to repeat if
        // a later step fails): memberships, board access and the people
        // cells they are on. The items' assignee and people lists are
        // recomputed from the cells by the Projects engine.
        require_once __DIR__ . '/../../projects/lib/autoload.php';
        ProjectSchema::ensure($DB);
        $DB->prepare("DELETE FROM project_members WHERE user_id = ? AND company_id = ?")->execute([$id, $ADMIN_COMPANY_ID]);
        try {
            $DB->prepare("DELETE bm FROM board_members bm JOIN project_boards pb ON pb.board_id = bm.board_id WHERE bm.user_id = ? AND pb.company_id = ?")
               ->execute([$id, $ADMIN_COMPANY_ID]);
        } catch (Throwable $e) { /* no board_members table */ }
        $st = $DB->prepare("SELECT v.item_id, v.column_id, v.value, bi.board_id
                              FROM board_item_values v
                              JOIN board_columns bc ON bc.column_id = v.column_id AND bc.type = 'people' AND bc.company_id = ?
                              JOIN board_items bi ON bi.id = v.item_id AND bi.company_id = bc.company_id
                             WHERE v.value LIKE ?");
        $st->execute([$ADMIN_COMPANY_ID, '%' . $id . '%']);
        $setCell = $DB->prepare("UPDATE board_item_values SET value = ? WHERE item_id = ? AND column_id = ?");
        $clearCell = $DB->prepare("DELETE FROM board_item_values WHERE item_id = ? AND column_id = ?");
        $touched = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cell) {
            $people = Columns::decode(['type' => 'people'], $cell['value']) ?: [];
            if (!in_array($id, $people, true)) continue;
            $people = array_values(array_diff($people, [$id]));
            if ($people) $setCell->execute([implode(',', $people), $cell['item_id'], $cell['column_id']]);
            else $clearCell->execute([$cell['item_id'], $cell['column_id']]);
            $touched[(int)$cell['board_id']][] = (int)$cell['item_id'];
        }
        foreach ($touched as $touchedBoard => $touchedItems) {
            BoardRepo::computeFormulas($DB, $ADMIN_COMPANY_ID, $touchedBoard, $touchedItems);
            BoardRepo::refreshMirrors($DB, $ADMIN_COMPANY_ID, $touchedBoard, $touchedItems);
        }
        $DB->prepare("DELETE FROM board_item_people WHERE user_id = ? AND company_id = ?")->execute([$id, $ADMIN_COMPANY_ID]);
        $DB->prepare("UPDATE board_items SET assigned_to = NULL, updated_at = updated_at WHERE assigned_to = ? AND company_id = ?")->execute([$id, $ADMIN_COMPANY_ID]);
        $DB->prepare("UPDATE board_subitems SET assigned_to = NULL WHERE assigned_to = ? AND company_id = ?")->execute([$id, $ADMIN_COMPANY_ID]);
        $DB->prepare("DELETE w FROM board_watchers w JOIN board_items i ON i.id = w.item_id WHERE w.user_id = ? AND i.company_id = ?")->execute([$id, $ADMIN_COMPANY_ID]);

        $DB->prepare("DELETE FROM user_companies WHERE user_id = ? AND company_id = ?")->execute([$id, $ADMIN_COMPANY_ID]);
        if ($p['primary']) {
            // This was their own company: the account is switched off as well
            // (kept, not deleted, so their history stays readable; adding the
            // same email again brings it back).
            $DB->prepare("UPDATE users SET status = 'suspended', session_token = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
            fw_auth_revoke_all($DB, $id, 'suspended');
        }
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_deleted', ['person' => $name, 'email' => $p['email']], 'user', $id);
        admin_json();
}
admin_error('Unknown action');
