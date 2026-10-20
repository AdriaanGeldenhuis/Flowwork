<?php
// /admin/api/people.php — the company's people.
//   GET  ?id=5                                         → {person}
//   POST {action:'create', email, first_name, last_name, role}  → {person, temp_password|null, linked}
//   POST {action:'update', id, first_name, last_name, role, is_seat}
//   POST {action:'suspend' | 'reactivate' | 'signout' | 'remove', id}
// Rules: a company always keeps at least one active admin; you cannot
// suspend or remove yourself; seats are checked whenever one gets used.
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
    $st = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.is_seat, u.last_login_at, u.created_at,
                               u.company_id AS primary_company_id, uc.role
                          FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.user_id = ? AND uc.company_id = ?");
    $st->execute([$userId, $companyId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) admin_error('That person is not part of this company.', 404);
    $p['id'] = (int)$p['id'];
    $p['is_seat'] = (int)$p['is_seat'];
    $p['primary'] = (int)$p['primary_company_id'] === $companyId;
    unset($p['primary_company_id']);
    return $p;
}

function people_seat_check(PDO $db, int $companyId): void
{
    $s = AdminKit::seats($db, $companyId);
    if ($s['used'] >= $s['max']) {
        admin_error('All ' . $s['max'] . ' paid seats are in use. Free a seat or move to a bigger plan under Subscription.', 409, ['reason' => 'seats']);
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

    $st = $DB->prepare("SELECT id, status, is_seat FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $st = $DB->prepare("SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?");
        $st->execute([$existing['id'], $ADMIN_COMPANY_ID]);
        if ($st->fetchColumn()) throw new AdminInvalid(['email' => 'They are already part of this company.']);
        if ($existing['status'] !== 'active') throw new AdminInvalid(['email' => 'That Flowwork account is suspended by its own company, so it cannot be added.']);
        if ((int)$existing['is_seat'] === 1) people_seat_check($DB, $ADMIN_COMPANY_ID);
    } elseif ($role !== 'bookkeeper') {
        people_seat_check($DB, $ADMIN_COMPANY_ID);
    }

    $temp = null;
    $DB->beginTransaction();
    try {
        if ($existing) {
            $newId = (int)$existing['id'];
            $DB->prepare("INSERT INTO user_companies (user_id, company_id, role, created_at) VALUES (?, ?, ?, NOW())")->execute([$newId, $ADMIN_COMPANY_ID, $role]);
        } else {
            // A readable one-time password: four groups of four, no look-alike characters.
            $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $groups = [];
            for ($g = 0; $g < 4; $g++) {
                $chunk = '';
                for ($i = 0; $i < 4; $i++) $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                $groups[] = $chunk;
            }
            $temp = implode('-', $groups) . '-' . random_int(10, 99) . '!';
            $DB->prepare("INSERT INTO users (company_id, email, password_hash, first_name, last_name, role, is_seat, status, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())")
               ->execute([$ADMIN_COMPANY_ID, $email, password_hash($temp, PASSWORD_DEFAULT), $first, $last, $role, $role === 'bookkeeper' ? 0 : 1]);
            $newId = (int)$DB->lastInsertId();
            $DB->prepare("INSERT INTO user_companies (user_id, company_id, role, created_at) VALUES (?, ?, ?, NOW())")->execute([$newId, $ADMIN_COMPANY_ID, $role]);
        }
        // Any open invitation for this address is now answered.
        $DB->prepare("UPDATE invites SET used = 1, used_by = ?, used_at = NOW() WHERE company_id = ? AND email = ? AND used = 0")->execute([$newId, $ADMIN_COMPANY_ID, $email]);
        $DB->commit();
    } catch (Throwable $e) {
        if ($DB->inTransaction()) $DB->rollBack();
        throw $e;
    }
    AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_created', ['email' => $email, 'role' => $role, 'account' => $existing ? 'existing Flowwork account linked' : 'new account'], 'user', $newId);
    admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, $newId), 'temp_password' => $temp, 'linked' => (bool)$existing]);
}

$id = admin_int($in, 'id');
$p = people_row($DB, $ADMIN_COMPANY_ID, $id);
$self = $id === $ADMIN_USER_ID;
$name = trim($p['first_name'] . ' ' . $p['last_name']) ?: $p['email'];

switch ($action) {
    case 'update':
        $first = admin_str($in, 'first_name', 100);
        $last = admin_str($in, 'last_name', 100);
        $role = admin_str($in, 'role', 20);
        $errors = [];
        if ($first === '') $errors['first_name'] = 'Enter a first name.';
        if ($last === '') $errors['last_name'] = 'Enter a surname.';
        if (!isset(AdminKit::ROLES[$role])) $errors['role'] = 'Choose a role.';
        if ($errors) throw new AdminInvalid($errors);
        if ($p['role'] === 'admin' && $role !== 'admin' && !people_other_admins($DB, $ADMIN_COMPANY_ID, $id)) {
            throw new AdminInvalid(['role' => 'The company needs at least one admin. Make someone else an admin first.']);
        }
        $seat = array_key_exists('is_seat', $in) ? (!empty($in['is_seat']) && $in['is_seat'] !== '0' ? 1 : 0) : $p['is_seat'];
        if (!$p['primary']) $seat = $p['is_seat']; // seat and status belong to the person's own company
        if ($seat === 1 && $p['is_seat'] === 0 && $p['status'] === 'active') people_seat_check($DB, $ADMIN_COMPANY_ID);

        $DB->beginTransaction();
        try {
            $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = ? AND company_id = ?")->execute([$role, $id, $ADMIN_COMPANY_ID]);
            if ($p['primary']) {
                $DB->prepare("UPDATE users SET first_name = ?, last_name = ?, role = ?, is_seat = ?, updated_at = NOW() WHERE id = ?")->execute([$first, $last, $role, $seat, $id]);
            } else {
                $DB->prepare("UPDATE users SET first_name = ?, last_name = ?, updated_at = NOW() WHERE id = ?")->execute([$first, $last, $id]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            if ($DB->inTransaction()) $DB->rollBack();
            throw $e;
        }
        $changes = [];
        if ($p['role'] !== $role) $changes['role'] = (AdminKit::ROLES[$p['role']][0] ?? $p['role']) . ' → ' . AdminKit::ROLES[$role][0];
        if ($p['first_name'] !== $first || $p['last_name'] !== $last) $changes['name'] = trim($first . ' ' . $last);
        if ($seat !== $p['is_seat']) $changes['seat'] = $seat ? 'paid seat' : 'no seat';
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
        if ($p['is_seat'] === 1) people_seat_check($DB, $ADMIN_COMPANY_ID);
        $DB->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_reactivated', ['person' => $name], 'user', $id);
        admin_json(['person' => people_row($DB, $ADMIN_COMPANY_ID, $id)]);

    case 'signout':
        $n = fw_auth_revoke_all($DB, $id, 'signed_out', $self ? (int)($_SESSION['auth_sid'] ?? 0) : null);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_signed_out', ['person' => $name, 'devices' => $n], 'user', $id);
        admin_json(['devices' => $n]);

    case 'remove':
        if ($self) admin_error('You cannot remove yourself. Ask another admin.');
        if ($p['role'] === 'admin' && !people_other_admins($DB, $ADMIN_COMPANY_ID, $id)) admin_error('The company needs at least one active admin.');

        // Take them off this company's projects first (safe to repeat if a later
        // step fails): memberships, board access and the people cells they are
        // on. The items' assignee and people lists are recomputed from the cells
        // by the Projects engine.
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
            // (kept, not deleted, so their history stays readable).
            $DB->prepare("UPDATE users SET status = 'suspended', session_token = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
            fw_auth_revoke_all($DB, $id, 'suspended');
        }
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'user_deleted', ['person' => $name, 'email' => $p['email']], 'user', $id);
        admin_json();
}
admin_error('Unknown action');
