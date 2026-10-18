<?php
// /projects/partials/guest.php — the pages people outside the company see:
// accepting an invitation, signing in and out. A centred Maison card.

/** Same-origin check plus the session token, for the guest forms (they have no auth_gate). */
function pr_guest_csrf_ok(): bool
{
    $sent = (string)($_POST['csrf_token'] ?? '');
    return $sent !== '' && hash_equals(Csrf::token(), $sent);
}

/** Rate limit by client address (and an extra key such as the email). @return int seconds to wait, 0 = go */
function pr_guest_throttle(string $bucket, string $extra, int $max, int $window): int
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $wait = ProjectRepo::rateLimit('guest-' . $bucket . ':ip:' . $ip, $max * 3, $window);
    if ($wait === 0 && $extra !== '') $wait = ProjectRepo::rateLimit('guest-' . $bucket . ':k:' . mb_strtolower($extra), $max, $window);
    return $wait;
}

/** The whole page: a card with an eyebrow, a title, a lead and the given body HTML (already escaped). */
function pr_guest_page(string $title, string $eyebrow, string $lead, string $bodyHtml, int $code = 200): void
{
    http_response_code($code);
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    $pageTitle = $title;
    $companyName = $eyebrow !== '' ? $eyebrow : 'Flowwork';
    $bodyExtraClass = 'fwp-guestpage';
    include __DIR__ . '/head.php';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
<main class="fwp-auth">
    <button class="icon-btn fwp-auth__theme" id="themeToggle" type="button" aria-label="Switch light or dark theme"><svg class="i"><use href="#i-moon"/></svg></button>
    <div class="fwp-auth__card">
        <span class="fwm-brand__tile fwp-seal fwp-auth__seal" aria-hidden="true"><svg class="i" viewBox="0 0 24 24"><use href="#i-seal-board"/></svg></span>
        <?php if ($eyebrow !== ''): ?><div class="fwp-eyebrow"><?= $h($eyebrow) ?></div><?php endif; ?>
        <h1><?= $h($title) ?></h1>
        <?php if ($lead !== ''): ?><p class="fwp-auth__lead"><?= $lead ?></p><?php endif; ?>
        <?= $bodyHtml ?>
    </div>
    <p class="fwp-auth__foot">Flowwork Projects · read-only guest access</p>
</main>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script>
(function () {
  var b = document.body, t = document.getElementById('themeToggle');
  function ic(n) { return '<svg class="i"><use href="#i-' + n + '"/></svg>'; }
  function apply(x) { b.dataset.theme = x; t.innerHTML = ic(x === 'dark' ? 'sun' : 'moon'); }
  apply(b.dataset.theme === 'light' ? 'light' : 'dark');
  t.addEventListener('click', function () { var x = b.dataset.theme === 'dark' ? 'light' : 'dark'; apply(x); document.cookie = 'fw_theme=' + x + ';max-age=31536000;path=/;SameSite=Lax'; });
  var f = document.querySelector('form[data-busy]');
  if (f) f.addEventListener('submit', function () { var s = f.querySelector('[type=submit]'); if (s) s.classList.add('is-busy'); });
})();
</script>
</body>
</html>
<?php
    exit;
}

/** Inline error / notice banner. */
function pr_guest_banner(string $text, bool $error = true): string
{
    return '<div class="fwp-banner' . ($error ? ' fwp-banner--error' : '') . '" role="' . ($error ? 'alert' : 'status') . '"><svg class="i"><use href="#i-' . ($error ? 'alert' : 'check') . '"/></svg><span class="grow">'
         . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span></div>';
}
