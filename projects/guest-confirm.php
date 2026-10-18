<?php
// /projects/guest-confirm.php?token=… — a guest accepts an invitation by
// choosing a password, then lands on the board. Invitations that already have
// a password go to sign in.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/guest.php';

ProjectSchema::ensure($DB);
$token = (string)($_GET['token'] ?? '');
$g = Guests::byToken($DB, $token);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!$g || $g['status'] === 'revoked') {
    pr_guest_page('This link no longer works', '', 'The invitation has expired or was withdrawn. Ask the person who shared the board to send a new one.',
        '<a class="btn btn--primary" href="/projects/guest-login.php" style="justify-content:center">I already have a password</a>', 410);
}
if (!empty($g['password_hash']) && $g['status'] === 'active') {
    // Already accepted: signed in → the board, otherwise sign in.
    if (Guests::current($DB, $token)) { header('Location: /projects/guest-view.php?token=' . rawurlencode($token)); exit; }
    header('Location: /projects/guest-login.php?token=' . rawurlencode($token));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pr_guest_csrf_ok()) {
        $error = 'The form expired — please try again.';
    } elseif (($wait = pr_guest_throttle('confirm', $token, 10, 900)) > 0) {
        $error = 'Too many attempts. Please wait ' . (int)ceil($wait / 60) . ' minute' . ($wait > 60 ? 's' : '') . ' and try again.';
    } else {
        try {
            Guests::confirm($DB, $g, (string)($_POST['password'] ?? ''), (string)($_POST['confirm'] ?? ''));
            header('Location: /projects/guest-view.php?token=' . rawurlencode($token));
            exit;
        } catch (ProjectUserError $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[projects/guest-confirm] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

$until = $g['expires_at'] ? date('j F Y', strtotime($g['expires_at'])) : '';
pr_guest_page(
    $g['board_title'],
    (string)$g['company_name'],
    'You’re invited to view this board' . ($g['project_name'] ? ' on <b>' . $h($g['project_name']) . '</b>' : '') . ($until ? ' until ' . $h($until) : '') . '. Choose a password to open it — you’ll use it with your email next time.',
    ($error ? pr_guest_banner($error) : '') . '
    <form method="post" data-busy autocomplete="on">
        <input type="hidden" name="csrf_token" value="' . $h(Csrf::token()) . '">
        <div class="field"><span class="label">Email</span><input class="input" type="email" value="' . $h($g['email']) . '" readonly autocomplete="username"></div>
        <div class="field"><label class="label" for="pw">Password</label><input class="input" type="password" id="pw" name="password" minlength="10" maxlength="200" required autocomplete="new-password" autofocus>
            <span class="hint">At least 10 characters.</span></div>
        <div class="field"><label class="label" for="pw2">Repeat password</label><input class="input" type="password" id="pw2" name="confirm" minlength="10" maxlength="200" required autocomplete="new-password"></div>
        <button class="btn btn--primary" type="submit">Open the board</button>
    </form>
    <div class="fwp-auth__alt">Already chose a password? <a href="/projects/guest-login.php?token=' . $h(rawurlencode($token)) . '">Sign in</a></div>'
);
