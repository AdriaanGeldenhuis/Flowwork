<?php
// /drive/ — open FlowDrive (cloud files) already signed in as you.
// The home page's Drive tile links here. Without ?state it hands over to
// FlowDrive, which starts the sign-in hand-over and comes back with a state;
// with one, a single-use token is issued and the browser goes back to Drive.
// See includes/flowdrive/FlowDriveSso.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/../includes/flowdrive/FlowDriveSso.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$state = (string)($_GET['state'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $state)) {
    $next = fw_safe_redirect(is_string($_GET['next'] ?? null) ? $_GET['next'] : '/', '/');
    header('Location: ' . FlowDriveSso::startUrl($next));
    exit;
}

try {
    $theme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
    $url = FlowDriveSso::issue(
        $DB,
        (int)$_SESSION['user_id'],
        (int)$_SESSION['company_id'],
        !empty($_SESSION['auth_sid']) ? (int)$_SESSION['auth_sid'] : null,
        $state,
        $theme
    );
    header('Location: ' . $url);
    exit;
} catch (Throwable $e) {
    error_log('[drive] sign-in hand-over failed: ' . $e->getMessage());
}

http_response_code(503);
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$retry = FlowDriveSso::startUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Drive · Flowwork</title>
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= (int)@filemtime(__DIR__ . '/../mail/css/mail.css') ?>">
</head>
<body class="fwm" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/../mail/partials/icons.php'; ?>
<main class="fwm-empty" style="min-height:100vh">
    <span class="fwm-empty__art" aria-hidden="true"><svg class="i"><use href="#i-alert"/></svg></span>
    <h3>Drive could not be opened</h3>
    <p>Something went wrong while signing you in to Drive. Please try again in a moment.</p>
    <p><a class="btn btn--primary" href="<?= htmlspecialchars($retry, ENT_QUOTES, 'UTF-8') ?>">Try again</a>
       <a class="btn" href="/home.php">Back to home</a></p>
</main>
</body>
</html>
