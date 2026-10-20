<?php
// accept-invite.php — where an invitation link lands (Admin › Invitations).
//   New to Flowwork  → choose a name and password (the company's password
//                      rules apply), then signed straight in.
//   Already has an account with the invited email → sign in (or already
//                      signed in) and join with one click; the account is
//                      linked to the company and switched to it.
// Every POST carries the CSRF token; a full company (no free paid seat)
// cannot take on another member until a seat is freed.
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/companies.php';
require_once __DIR__ . '/includes/password_policy.php';

/** A reason this attempt cannot go ahead, shown to the person as is. */
final class InviteProblem extends Exception {}

$token = trim((string)($_GET['token'] ?? ''));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$error = '';
$invite = null;

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'This invitation link is incomplete. Copy the whole link from the email and try again.';
} else {
    $st = $DB->prepare("SELECT i.*, c.name AS company_name, c.subscription_active, CONCAT_WS(' ', u.first_name, u.last_name) AS inviter
                          FROM invites i JOIN companies c ON c.id = i.company_id LEFT JOIN users u ON u.id = i.created_by
                         WHERE i.token = ?");
    $st->execute([$token]);
    $invite = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$invite) $error = 'This invitation was withdrawn or replaced by a newer one. Ask for a fresh invitation.';
    elseif ((int)$invite['used']) $error = 'This invitation has already been used. Sign in to continue.';
    elseif (strtotime($invite['expires_at']) < time()) $error = 'This invitation expired on ' . date('j F Y', strtotime($invite['expires_at'])) . '. Ask ' . (trim((string)$invite['inviter']) ?: 'the person who invited you') . ' to send it again.';
    elseif (!(int)$invite['subscription_active']) $error = 'This company’s Flowwork subscription is not active, so it cannot take on new people right now.';
}

$existing = null;
$signedInAs = null;
$policy = fw_password_policy($DB, $invite ? (int)$invite['company_id'] : null);
if ($invite && !$error) {
    $st = $DB->prepare("SELECT id, email, first_name, last_name, status, company_id, is_seat FROM users WHERE email = ? LIMIT 1");
    $st->execute([mb_strtolower($invite['email'])]);
    $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!empty($_SESSION['user_id'])) {
        $st = $DB->prepare("SELECT id, email, first_name FROM users WHERE id = ?");
        $st->execute([(int)$_SESSION['user_id']]);
        $signedInAs = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($existing && $existing['status'] !== 'active') $error = 'The Flowwork account for ' . $invite['email'] . ' is suspended, so it cannot join. Ask the company that manages it.';
    if ($existing && !$error) {
        $st = $DB->prepare("SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?");
        $st->execute([$existing['id'], $invite['company_id']]);
        if ($st->fetchColumn()) {
            $DB->prepare("UPDATE invites SET used = 1, used_by = ?, used_at = NOW() WHERE id = ?")->execute([$existing['id'], $invite['id']]);
            $error = 'You are already part of ' . $invite['company_name'] . '. Sign in to continue.';
        }
    }
}

/** A paid seat is free for this invitation (bookkeepers never need one). */
function invite_has_seat(PDO $db, array $invite, bool $usesSeat): bool
{
    if (!$usesSeat) return true;
    $st = $db->prepare("SELECT COUNT(*) FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ? AND u.status = 'active' AND u.is_seat = 1");
    $st->execute([$invite['company_id']]);
    $used = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT COALESCE(p.max_users, c.max_users, 1) FROM companies c LEFT JOIN plans p ON p.id = c.plan_id WHERE c.id = ?");
    $st->execute([$invite['company_id']]);
    return $used < max(1, (int)$st->fetchColumn());
}

function invite_audit(PDO $db, array $invite, int $userId, string $how): void
{
    try {
        $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, timestamp, created_at)
                      VALUES (?, ?, 'invite_accepted', 'invite', ?, ?, ?, NOW(), NOW())")
           ->execute([$invite['company_id'], $userId, $invite['id'], json_encode(['email' => $invite['email'], 'role' => $invite['role'], 'account' => $how]), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        error_log('accept-invite audit: ' . $e->getMessage());
    }
}

$fieldErrors = [];
$formError = ''; // a problem with this attempt (no free seat, a race); the link itself still works
$form = ['first_name' => '', 'last_name' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite && !$error) {
    Csrf::validate();
    $step = (string)($_POST['step'] ?? '');
    try {
        if ($step === 'create' && !$existing) {
            $form['first_name'] = mb_substr(trim((string)($_POST['first_name'] ?? '')), 0, 100);
            $form['last_name'] = mb_substr(trim((string)($_POST['last_name'] ?? '')), 0, 100);
            $password = (string)($_POST['password'] ?? '');
            if ($form['first_name'] === '') $fieldErrors['first_name'] = 'Enter your first name.';
            if ($form['last_name'] === '') $fieldErrors['last_name'] = 'Enter your surname.';
            if ($problems = fw_password_problems($password, $policy)) $fieldErrors['password'] = fw_password_error($problems);
            elseif ($password !== (string)($_POST['password_confirm'] ?? '')) $fieldErrors['password_confirm'] = 'The two passwords are not the same.';
            $isSeat = $invite['role'] === 'bookkeeper' ? 0 : 1;
            if (!$fieldErrors && !invite_has_seat($DB, $invite, (bool)$isSeat)) {
                throw new InviteProblem($invite['company_name'] . ' has no free seat right now. Ask ' . (trim((string)$invite['inviter']) ?: 'an admin') . ' to free one or add seats, then open this link again.');
            }
            if (!$fieldErrors) {
                $DB->beginTransaction();
                $DB->prepare("INSERT INTO users (company_id, email, password_hash, first_name, last_name, role, is_seat, status, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())")
                   ->execute([$invite['company_id'], mb_strtolower($invite['email']), password_hash($password, PASSWORD_DEFAULT), $form['first_name'], $form['last_name'], $invite['role'], $isSeat]);
                $newId = (int)$DB->lastInsertId();
                $DB->prepare("INSERT INTO user_companies (user_id, company_id, role, created_at) VALUES (?, ?, ?, NOW())")->execute([$newId, $invite['company_id'], $invite['role']]);
                $st = $DB->prepare("UPDATE invites SET used = 1, used_by = ?, used_at = NOW() WHERE id = ? AND used = 0");
                $st->execute([$newId, $invite['id']]);
                if ($st->rowCount() !== 1) throw new InviteProblem('This invitation was used a moment ago. Sign in instead.');
                $DB->commit();
                invite_audit($DB, $invite, $newId, 'new account');
                fw_auth_begin($DB, ['id' => $newId, 'company_id' => (int)$invite['company_id'], 'first_name' => $form['first_name'], 'last_name' => $form['last_name']], false);
                redirect('/home.php');
            }
        } elseif ($step === 'join' && $existing && $signedInAs && (int)$signedInAs['id'] === (int)$existing['id']) {
            if (!invite_has_seat($DB, $invite, (int)$existing['is_seat'] === 1)) {
                throw new InviteProblem($invite['company_name'] . ' has no free seat right now. Ask ' . (trim((string)$invite['inviter']) ?: 'an admin') . ' to free one or add seats, then open this link again.');
            }
            $DB->beginTransaction();
            $DB->prepare("INSERT IGNORE INTO user_companies (user_id, company_id, role, created_at) VALUES (?, ?, ?, NOW())")->execute([$existing['id'], $invite['company_id'], $invite['role']]);
            $st = $DB->prepare("UPDATE invites SET used = 1, used_by = ?, used_at = NOW() WHERE id = ? AND used = 0");
            $st->execute([$existing['id'], $invite['id']]);
            if ($st->rowCount() !== 1) throw new InviteProblem('This invitation was used a moment ago.');
            $DB->commit();
            invite_audit($DB, $invite, (int)$existing['id'], 'existing account');
            fw_set_active_company((int)$existing['id'], (int)$invite['company_id']);
            redirect('/home.php');
        }
    } catch (Throwable $ex) {
        if ($DB->inTransaction()) $DB->rollBack();
        error_log('accept-invite: ' . $ex->getMessage());
        $formError = $ex instanceof InviteProblem ? $ex->getMessage() : 'Something went wrong. Please try again.';
    }
}

$fwTheme = (($_COOKIE['fw_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$roleNames = ['admin' => 'an admin', 'member' => 'a member', 'bookkeeper' => 'the bookkeeper', 'viewer' => 'a viewer', 'pos' => 'counter staff'];
$v = fn(string $rel) => (string)(@filemtime(__DIR__ . '/' . $rel) ?: '1');
$here = '/accept-invite.php?token=' . rawurlencode($token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <title><?= $invite ? 'Join ' . $e($invite['company_name']) : 'Invitation' ?> · Flowwork</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $v('mail/css/mail.css') ?>">
    <style>
        html, body.fwm { height: auto; min-height: 100%; }
        .inv { min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 28px 16px; }
        .inv__card { width: min(480px, 100%); background: var(--glass-strong); -webkit-backdrop-filter: var(--blur); backdrop-filter: var(--blur); border-radius: 26px; box-shadow: var(--shadow-dialog); padding: 34px 34px 28px; position: relative; animation: fwm-dialog 0.4s var(--ease) both; }
        .inv__card::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), transparent); opacity: 0.6; }
        .inv__brand { display: flex; align-items: center; gap: 12px; margin-bottom: 26px; color: var(--ink); }
        .inv__eyebrow { margin: 0; font-size: 10px; font-weight: 600; letter-spacing: 0.24em; text-transform: uppercase; color: var(--gold-ink); }
        .inv h1 { margin: 6px 0 0; font-family: var(--font-display); font-weight: 400; font-size: 34px; letter-spacing: -0.022em; line-height: 1.1; }
        .inv h1 em { color: var(--accent-ink); }
        .inv__lede { color: var(--ink-3); line-height: 1.6; margin: 14px 0 22px; }
        .inv__lede b { color: var(--ink); }
        .inv__note { margin: 0 0 22px; padding: 12px 16px; border-left: 3px solid var(--gold); background: var(--gold-soft); border-radius: 0 12px 12px 0; color: var(--ink-2); font-style: italic; white-space: pre-line; }
        .inv form { display: grid; gap: 14px; }
        .inv .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .inv form .field + .field, .inv .row2 + .field { margin-top: 0; }
        .inv .field__error { color: var(--danger); font-size: 12px; font-weight: 500; }
        .inv .field.is-invalid .input { border-color: var(--danger); box-shadow: 0 0 0 4px var(--danger-soft); }
        .inv__rules { font-size: 12px; color: var(--ink-3); display: flex; flex-wrap: wrap; gap: 6px; }
        .inv__rules span { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; background: var(--surface-sunken); }
        .inv__rules span.is-ok { background: var(--success-soft); color: var(--success); }
        .inv__err { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 14px; background: var(--danger-soft); color: var(--danger); margin-bottom: 18px; line-height: 1.5; }
        .inv__foot { margin-top: 18px; font-size: 12px; color: var(--ink-3); text-align: center; }
        @media (max-width: 480px) { .inv__card { padding: 26px 20px 22px; } .inv .row2 { grid-template-columns: 1fr; } .inv h1 { font-size: 29px; } }
    </style>
</head>
<body class="fwm" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/mail/partials/icons.php'; ?>
<main class="inv">
    <div class="inv__card">
        <a class="inv__brand fwm-brand" href="/" aria-label="Flowwork">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#i-mail-open"/></svg></span>
            <span class="fwm-brand__text"><span class="fwm-brand__name">Flowwork</span><?php if ($invite): ?><span class="fwm-brand__company"><?= $e($invite['company_name']) ?></span><?php endif; ?></span>
        </a>

        <?php if ($error): ?>
            <p class="inv__eyebrow">Invitation</p>
            <h1>This link can’t be used</h1>
            <p class="inv__lede"><?= $e($error) ?></p>
            <a class="btn btn--primary btn--block btn--lg" href="/login.php"><svg class="i"><use href="#i-arrow-left"/></svg>Go to sign in</a>
        <?php else: ?>
            <p class="inv__eyebrow">You’re invited</p>
            <h1>Join <em><?= $e($invite['company_name']) ?></em></h1>
            <p class="inv__lede"><?= trim((string)$invite['inviter']) !== '' ? '<b>' . $e($invite['inviter']) . '</b> invited' : 'You were invited' ?> <b><?= $e($invite['email']) ?></b> to work in Flowwork as <?= $e($roleNames[$invite['role']] ?? 'a member') ?>.</p>
            <?php if (trim((string)($invite['custom_message'] ?? '')) !== ''): ?><p class="inv__note"><?= $e($invite['custom_message']) ?></p><?php endif; ?>
            <?php if ($formError): ?><div class="inv__err" role="alert"><svg class="i"><use href="#i-alert"/></svg><span><?= $e($formError) ?></span></div><?php endif; ?>

            <?php if (!$existing): ?>
                <form method="post" action="<?= $e($here) ?>" novalidate id="joinForm">
                    <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
                    <input type="hidden" name="step" value="create">
                    <div class="row2">
                        <div class="field<?= isset($fieldErrors['first_name']) ? ' is-invalid' : '' ?>"><label class="label" for="fn">First name</label><input class="input" id="fn" name="first_name" maxlength="100" autocomplete="given-name" value="<?= $e($form['first_name']) ?>" required autofocus><?php if (isset($fieldErrors['first_name'])): ?><span class="field__error"><?= $e($fieldErrors['first_name']) ?></span><?php endif; ?></div>
                        <div class="field<?= isset($fieldErrors['last_name']) ? ' is-invalid' : '' ?>"><label class="label" for="ln">Surname</label><input class="input" id="ln" name="last_name" maxlength="100" autocomplete="family-name" value="<?= $e($form['last_name']) ?>" required><?php if (isset($fieldErrors['last_name'])): ?><span class="field__error"><?= $e($fieldErrors['last_name']) ?></span><?php endif; ?></div>
                    </div>
                    <div class="field<?= isset($fieldErrors['password']) ? ' is-invalid' : '' ?>"><label class="label" for="pw">Choose a password</label><input class="input" id="pw" name="password" type="password" autocomplete="new-password" minlength="<?= (int)$policy['min'] ?>" required>
                        <span class="inv__rules" id="rules" aria-live="polite">
                            <span data-r="min"><?= (int)$policy['min'] ?>+ characters</span>
                            <?php if ($policy['case']): ?><span data-r="case">Aa</span><?php endif; ?>
                            <?php if ($policy['number']): ?><span data-r="number">0–9</span><?php endif; ?>
                            <?php if ($policy['symbol']): ?><span data-r="symbol">! @ #</span><?php endif; ?>
                        </span>
                        <?php if (isset($fieldErrors['password'])): ?><span class="field__error"><?= $e($fieldErrors['password']) ?></span><?php endif; ?></div>
                    <div class="field<?= isset($fieldErrors['password_confirm']) ? ' is-invalid' : '' ?>"><label class="label" for="pw2">Type it again</label><input class="input" id="pw2" name="password_confirm" type="password" autocomplete="new-password" required><?php if (isset($fieldErrors['password_confirm'])): ?><span class="field__error"><?= $e($fieldErrors['password_confirm']) ?></span><?php endif; ?></div>
                    <button class="btn btn--primary btn--lg btn--block" type="submit"><svg class="i"><use href="#i-check"/></svg>Join <?= $e($invite['company_name']) ?></button>
                </form>
                <p class="inv__foot"><?= $e(fw_password_rules_text($policy)) ?></p>
            <?php elseif ($signedInAs && (int)$signedInAs['id'] === (int)$existing['id']): ?>
                <form method="post" action="<?= $e($here) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
                    <input type="hidden" name="step" value="join">
                    <button class="btn btn--primary btn--lg btn--block" type="submit"><svg class="i"><use href="#i-check"/></svg>Join <?= $e($invite['company_name']) ?></button>
                </form>
                <p class="inv__foot">You keep your other companies — switch between them from the menu.</p>
            <?php elseif ($signedInAs): ?>
                <p class="inv__lede">You are signed in as <b><?= $e($signedInAs['email']) ?></b>, but this invitation is for <b><?= $e($invite['email']) ?></b>. Sign out, then open this link again and sign in as <?= $e($invite['email']) ?>.</p>
                <a class="btn btn--primary btn--lg btn--block" href="/logout.php"><svg class="i"><use href="#ih-logout"/></svg>Sign out</a>
            <?php else: ?>
                <p class="inv__lede" style="margin-top:0">You already have a Flowwork account with this email. Sign in and you can join <?= $e($invite['company_name']) ?> in one click.</p>
                <a class="btn btn--primary btn--lg btn--block" href="/login.php?redirect=<?= $e(rawurlencode($here)) ?>"><svg class="i"><use href="#i-user"/></svg>Sign in to join</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script>
(function () {
  var pw = document.getElementById('pw'), rules = document.getElementById('rules');
  if (!pw || !rules) return;
  var min = <?= (int)$policy['min'] ?>;
  var tests = { min: function (v) { return v.length >= min; }, 'case': function (v) { return /\p{Lu}/u.test(v) && /\p{Ll}/u.test(v); },
                number: function (v) { return /\d/.test(v); }, symbol: function (v) { return /[^\p{L}\p{N}\s]/u.test(v); } };
  pw.addEventListener('input', function () {
    rules.querySelectorAll('[data-r]').forEach(function (s) { s.classList.toggle('is-ok', tests[s.dataset.r](pw.value)); });
  });
})();
</script>
</body>
</html>
