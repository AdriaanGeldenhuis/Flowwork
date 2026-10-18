<?php
// /projects/guest-login.php[?token=…] — guests sign in with the email they
// were invited with and the password they chose. Rate limited by address and
// by email.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/guest.php';

ProjectSchema::ensure($DB);
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) $token = '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if ($token !== '' && Guests::current($DB, $token)) {
    header('Location: /projects/guest-view.php?token=' . rawurlencode($token));
    exit;
}
$invite = $token !== '' ? Guests::byToken($DB, $token) : null;

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 255);
    $password = (string)($_POST['password'] ?? '');
    if (!pr_guest_csrf_ok()) {
        $error = 'The form expired — please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (($wait = pr_guest_throttle('login', $email, 8, 900)) > 0) {
        $error = 'Too many sign-in attempts. Please wait ' . (int)ceil($wait / 60) . ' minute' . ($wait > 60 ? 's' : '') . ' and try again.';
    } else {
        try {
            $g = Guests::login($DB, $email, mb_substr($password, 0, 200), $token);
            header('Location: /projects/guest-view.php?token=' . rawurlencode((string)$g['token']));
            exit;
        } catch (ProjectUserError $e) {
            usleep(random_int(200000, 450000)); // blunt timing differences
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[projects/guest-login] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
} elseif ($invite) {
    $email = (string)$invite['email'];
}

pr_guest_page(
    $invite ? $invite['board_title'] : 'Guest sign in',
    $invite ? (string)$invite['company_name'] : 'Flowwork Projects',
    $invite ? 'Sign in to view this board.' : 'Sign in with the email you were invited with to view the board shared with you.',
    ($error ? pr_guest_banner($error) : '') . '
    <form method="post" data-busy autocomplete="on">
        <input type="hidden" name="csrf_token" value="' . $h(Csrf::token()) . '">
        <div class="field"><label class="label" for="em">Email</label><input class="input" type="email" id="em" name="email" maxlength="255" required autocomplete="username" value="' . $h($email) . '"' . ($email === '' ? ' autofocus' : '') . '></div>
        <div class="field"><label class="label" for="pw">Password</label><input class="input" type="password" id="pw" name="password" maxlength="200" required autocomplete="current-password"' . ($email !== '' ? ' autofocus' : '') . '></div>
        <button class="btn btn--primary" type="submit">Sign in</button>
    </form>
    <div class="fwp-auth__alt">Forgot your password? Ask the person who shared the board to send the invitation again.</div>'
);
