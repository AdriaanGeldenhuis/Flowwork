<?php
// projects/lib/Guests.php
//
// Guests: people outside the company who may view one board (read-only).
// A board manager invites an email address; the guest opens the link, sets a
// password and sees the board at /projects/guest-view.php. Access ends when
// the invitation expires or is revoked. Links use APP_BASE_URL (never the
// request's Host header); tokens and password hashes never leave the server.

final class Guests
{
    public const SESSION_KEY = 'pr_guest';

    private static function baseUrl(): string
    {
        $u = defined('APP_BASE_URL') ? (string)APP_BASE_URL : (string)getenv('APP_BASE_URL');
        return rtrim($u !== '' ? $u : 'https://www.flowwork.app', '/');
    }

    public static function inviteUrl(string $token): string
    {
        return self::baseUrl() . '/projects/guest-confirm.php?token=' . rawurlencode($token);
    }

    private static function out(array $g): array
    {
        $expired = $g['expires_at'] && strtotime($g['expires_at']) < time();
        return ['id' => (int)$g['id'], 'email' => $g['email'], 'status' => $expired ? 'expired' : $g['status'], 'invited_at' => $g['invited_at'],
                'expires_at' => $g['expires_at'], 'confirmed_at' => $g['confirmed_at'], 'last_access_at' => $g['last_access_at'],
                'access_count' => (int)$g['access_count']];
    }

    public static function list(ProjectRepo $repo, int $boardId): array
    {
        $repo->requireBoard($boardId, 3);
        $st = $repo->db->prepare("SELECT * FROM board_guests WHERE board_id = ? AND company_id = ? ORDER BY invited_at DESC");
        $st->execute([$boardId, $repo->companyId]);
        return array_map([self::class, 'out'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Invite (or re-invite) an email address. @return array ['guest' => …, 'sent' => bool, 'link' => url]
     */
    public static function invite(ProjectRepo $repo, int $boardId, string $email, int $days = 30): array
    {
        $b = $repo->requireBoard($boardId, 3);
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) throw new ProjectUserError('Enter a valid email address.');
        $st = $repo->db->prepare("SELECT 1 FROM users u LEFT JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = ?
                                   WHERE u.email = ? AND (u.company_id = ? OR uc.company_id = ?)");
        $st->execute([$repo->companyId, $email, $repo->companyId, $repo->companyId]);
        if ($st->fetchColumn()) throw new ProjectUserError('That person is in your company — add them to the project instead.');
        $st = $repo->db->prepare("SELECT COUNT(*) FROM board_guests WHERE board_id = ?");
        $st->execute([$boardId]);
        if ((int)$st->fetchColumn() >= 100) throw new ProjectUserError('A board can have up to 100 guests.');
        $days = max(1, min(365, $days));
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
        // A guest who already accepted keeps their link (Extend only moves the expiry); a pending invitation gets a new one.
        $repo->db->prepare("INSERT INTO board_guests (board_id, company_id, email, token, status, invited_by, invited_at, expires_at)
                            VALUES (?, ?, ?, ?, 'pending', ?, NOW(), ?)
                            ON DUPLICATE KEY UPDATE token = IF(password_hash IS NULL, VALUES(token), token), invited_by = VALUES(invited_by), invited_at = NOW(),
                                                    expires_at = VALUES(expires_at), status = IF(password_hash IS NULL, 'pending', 'active')")
            ->execute([$boardId, $repo->companyId, $email, $token, $repo->userId, $expires]);
        Activity::log($repo->db, $repo->companyId, $repo->userId, $boardId, null, 'guest_invited', ['email' => $email, 'days' => $days]);
        $st = $repo->db->prepare("SELECT * FROM board_guests WHERE board_id = ? AND email = ?");
        $st->execute([$boardId, $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $sent = self::sendInvite($repo, $b, $email, (string)$row['token'], $expires);
        return ['guest' => self::out($row), 'sent' => $sent, 'link' => self::inviteUrl((string)$row['token'])];
    }

    /** New link and expiry for an existing guest. */
    public static function resend(ProjectRepo $repo, int $boardId, int $guestId, int $days = 30): array
    {
        $repo->requireBoard($boardId, 3);
        $st = $repo->db->prepare("SELECT email FROM board_guests WHERE id = ? AND board_id = ? AND company_id = ?");
        $st->execute([$guestId, $boardId, $repo->companyId]);
        $email = $st->fetchColumn();
        if ($email === false) throw new ProjectUserError('Guest not found.');
        return self::invite($repo, $boardId, (string)$email, $days);
    }

    public static function revoke(ProjectRepo $repo, int $boardId, int $guestId): void
    {
        $repo->requireBoard($boardId, 3);
        $st = $repo->db->prepare("SELECT email FROM board_guests WHERE id = ? AND board_id = ? AND company_id = ?");
        $st->execute([$guestId, $boardId, $repo->companyId]);
        $email = $st->fetchColumn();
        if ($email === false) return;
        $repo->db->prepare("DELETE FROM board_guest_activity WHERE guest_id = ?")->execute([$guestId]);
        $repo->db->prepare("DELETE FROM board_guests WHERE id = ?")->execute([$guestId]);
        Activity::log($repo->db, $repo->companyId, $repo->userId, $boardId, null, 'guest_revoked', ['email' => $email]);
    }

    private static function sendInvite(ProjectRepo $repo, array $board, string $email, string $token, string $expires): bool
    {
        try {
            $file = ProjectPaths::root() . '/shared/email_sender.php';
            if (!is_file($file)) return false;
            require_once $file;
            if (!class_exists('EmailSender')) return false;
            $st = $repo->db->prepare("SELECT name FROM companies WHERE id = ?");
            $st->execute([$repo->companyId]);
            $company = (string)$st->fetchColumn();
            $who = $repo->personName($repo->userId);
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $link = self::inviteUrl($token);
            $html = '<div style="font-family:Georgia,serif;max-width:520px;margin:0 auto;padding:32px;background:#fffdf9;border:1px solid #e8dfd2;border-radius:14px;color:#1d1613">'
                  . '<p style="font:600 11px/1 Arial,sans-serif;letter-spacing:.2em;text-transform:uppercase;color:#87672f;margin:0 0 14px">' . $h($company) . '</p>'
                  . '<h1 style="font-weight:400;font-size:26px;margin:0 0 12px">You’re invited to “' . $h($board['title']) . '”</h1>'
                  . '<p style="font:15px/1.6 Arial,sans-serif;color:#4a3e37">' . $h($who) . ' shared a project board with you on Flowwork. You can view it until ' . $h(date('j F Y', strtotime($expires))) . '.</p>'
                  . '<p style="margin:26px 0"><a href="' . $h($link) . '" style="background:#7a1f30;color:#fff8f0;padding:12px 22px;border-radius:999px;text-decoration:none;font:600 14px Arial,sans-serif">Open the board</a></p>'
                  . '<p style="font:12px/1.5 Arial,sans-serif;color:#85766b">You will choose a password the first time. If you did not expect this, you can ignore this email.</p></div>';
            return (bool)(new EmailSender())->send($email, $who . ' shared “' . $board['title'] . '” with you', $html, true);
        } catch (Throwable $e) {
            error_log('[projects] guest invite mail: ' . $e->getMessage());
            return false;
        }
    }

    // ================================================================= guest side

    /** The invitation for a token, if it is still valid. */
    public static function byToken(PDO $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $st = $db->prepare("SELECT g.*, b.title AS board_title, b.project_id, b.item_noun, b.default_view, b.description AS board_description, p.name AS project_name, c.name AS company_name
                              FROM board_guests g JOIN project_boards b ON b.board_id = g.board_id AND b.company_id = g.company_id
                              JOIN projects p ON p.project_id = b.project_id JOIN companies c ON c.id = g.company_id
                             WHERE g.token = ?");
        $st->execute([$token]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g || ($g['expires_at'] && strtotime($g['expires_at']) < time())) return null;
        return $g;
    }

    /** Set the password for an invitation and sign the guest in. */
    public static function confirm(PDO $db, array $g, string $password, string $confirm): void
    {
        if (mb_strlen($password) < 10) throw new ProjectUserError('Choose a password of at least 10 characters.');
        if ($password !== $confirm) throw new ProjectUserError('The passwords do not match.');
        $db->prepare("UPDATE board_guests SET password_hash = ?, password_set_at = NOW(), status = 'active', confirmed_at = COALESCE(confirmed_at, NOW()) WHERE id = ?")
           ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$g['id']]);
        self::signIn($db, (int)$g['id']);
    }

    /**
     * Sign in with email and password. A guest invited to several boards picks
     * the board through its link (token); without one, the most recent board
     * whose password matches.
     */
    public static function login(PDO $db, string $email, string $password, string $token = ''): array
    {
        $email = mb_strtolower(trim($email));
        // Exactly the stored (lower-case, plain ASCII) address: the column's collation would also match
        // accented, full-width or padded spellings, each with a sign-in limit of its own. (email = ? lets the index narrow it first.)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ProjectUserError('That email and password do not match an active invitation.');
        $st = $db->prepare("SELECT * FROM board_guests WHERE email = ? AND BINARY LOWER(email) = ? AND status = 'active' AND password_hash IS NOT NULL
                               AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY (token = ?) DESC, last_access_at DESC, invited_at DESC");
        $st->execute([$email, $email, $token]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            if (password_verify($password, (string)$g['password_hash'])) {
                self::signIn($db, (int)$g['id']);
                return $g;
            }
        }
        throw new ProjectUserError('That email and password do not match an active invitation.');
    }

    private static function signIn(PDO $db, int $guestId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $guestId;
        $db->prepare("UPDATE board_guests SET last_access_at = NOW(), access_count = access_count + 1 WHERE id = ?")->execute([$guestId]);
        try {
            $db->prepare("INSERT INTO board_guest_activity (guest_id, action, ip_address, user_agent, timestamp) VALUES (?, 'login', ?, ?, NOW())")
               ->execute([$guestId, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
        } catch (Throwable $e) { /* best effort */ }
    }

    /** The signed-in guest for this token, or null. */
    public static function current(PDO $db, string $token): ?array
    {
        $g = self::byToken($db, $token);
        if (!$g || $g['status'] !== 'active' || (int)($_SESSION[self::SESSION_KEY] ?? 0) !== (int)$g['id']) return null;
        return $g;
    }

    public static function signOut(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** What the read-only board shows a guest (no members, preferences or automations). */
    public static function boardPayload(PDO $db, array $g): array
    {
        $companyId = (int)$g['company_id'];
        $boardId = (int)$g['board_id'];
        $repo = new ProjectRepo($db, $companyId, 0, 'guest');
        $boards = new BoardRepo($repo);
        $cols = $boards->columnRows($boardId);
        $items = $boards->itemsPayload($boardId, $cols, null);
        $used = [];
        $peopleCols = array_filter($cols, fn($c) => $c['type'] === 'people');
        foreach ($items as $it) {
            foreach ($peopleCols as $c) foreach ((array)(((array)$it['values'])[(int)$c['column_id']] ?? []) as $u) $used[(int)$u] = true;
        }
        $people = [];
        foreach ($repo->people() as $id => $p) if (isset($used[$id])) $people[] = ['id' => $id, 'name' => $p['name']];
        // Like people: only the suppliers the board's cells name, not the company's supplier list.
        $usedSup = [];
        $supCols = array_filter($cols, fn($c) => $c['type'] === 'supplier');
        foreach ($items as $it) {
            foreach ($supCols as $c) if ($v = (int)(((array)$it['values'])[(int)$c['column_id']] ?? 0)) $usedSup[$v] = true;
        }
        $suppliers = [];
        if ($usedSup) {
            $ids = array_keys($usedSup);
            $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute(array_merge([$companyId], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $suppliers[] = ['id' => (int)$s['id'], 'name' => $s['name']];
        }
        // The item panel shows the description (the board payload only says whether there is one).
        $st = $db->prepare("SELECT id, description FROM board_items WHERE board_id = ? AND company_id = ? AND (archived = 0 OR archived IS NULL)");
        $st->execute([$boardId, $companyId]);
        $desc = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($items as &$it) {
            $it['files'] = array_map(fn($f) => $f + ['guest' => true], $it['files']);
            $it['description'] = (string)($desc[$it['id']] ?? '');
        }
        unset($it);
        return [
            'board' => ['id' => $boardId, 'title' => $g['board_title'], 'project_name' => $g['project_name'], 'level' => 0, 'guest' => true,
                        'item_noun' => (string)($g['item_noun'] ?? '') ?: 'item', 'default_view' => in_array($g['default_view'] ?? '', BoardRepo::VIEWS, true) ? $g['default_view'] : 'table',
                        'description' => (string)($g['board_description'] ?? '')],
            'columns' => array_map([BoardRepo::class, 'columnOut'], $cols),
            'groups' => array_map(fn($x) => BoardRepo::groupOut($x), $boards->groupRows($boardId)),
            'items' => $items,
            'people' => $people,
            'suppliers' => $suppliers,
            'deps' => [],
            'cursor' => Activity::cursor($db, $boardId),
        ];
    }
}
