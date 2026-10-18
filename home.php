<?php
// home.php — the Flowwork home page, built on the Mail "Maison" design system
// (/mail/css/mail.css tokens, then /home/css/home.css). A greeting with today's
// brief and a live clock, three "today" panes (Calendar agenda, Projects work,
// unread Mail), and the suite of modules. Data: home/lib/HomeBrief.php.
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/auth_gate.php';
require_once __DIR__ . '/includes/companies.php';
require_once __DIR__ . '/home/lib/HomeBrief.php';

// Shown in the footer; also busts the company switcher script's cache
define('ASSET_VERSION', '2026-10-18-maison');

$firstName = (string)($_SESSION['user_first_name'] ?? '');
$userId = (int)$_SESSION['user_id'];
$activeCompanyId = (int)$_SESSION['company_id'];
$role = (string)($_SESSION['role'] ?? 'viewer');

// The active company (honours company switching) and every company this user
// can open, for the switcher in the menu.
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$activeCompanyId]);
$companyName = (string)($stmt->fetchColumn() ?: 'Your Company');
$userCompanies = fw_user_companies($userId);
$csrfToken = Csrf::token();

// One-time self-heal: publish the company's existing documents into FlowWork
// Drive the first time the home dashboard loads after deployment. A single
// cheap flag SELECT thereafter. Best-effort — never blocks the page.
try {
    require_once __DIR__ . '/includes/flowdrive/FlowDriveBackfill.php';
    FlowDriveBackfill::autoRunOnce($DB, $activeCompanyId);
} catch (Throwable $e) {
    error_log('home FlowDrive autoRun: ' . $e->getMessage());
}

// Today: calendar, my work, unread mail (each best-effort, see HomeBrief)
$now = HomeBrief::now($DB, $activeCompanyId);
$brief = HomeBrief::collect($DB, $activeCompanyId, $userId, $role, $now);
$cal = $brief['calendar'];
$work = $brief['work'];
$mail = $brief['mail'];

$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$v = fn(string $rel) => (string)(@filemtime(__DIR__ . '/' . $rel) ?: '1');
$plural = fn(int $n, string $one, string $many) => $n === 1 ? $one : $many;
$words = fn(int $n) => [0 => 'no', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
                        7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve'][$n] ?? (string)$n;

// Greeting and date in the company's timezone (home.js keeps the greeting in step with the viewer's clock)
$nowTs = strtotime($now) ?: time();
$hour = (int)date('G', $nowTs);
$dayGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$todayYmd = date('Y-m-d', $nowTs);

// ----------------------------------------------------------------- the brief
// One sentence from what is actually there; parts of a module that could not
// be read are left out rather than reported as empty.
$parts = [];
if ($cal['ok'] && $cal['count'] > 0) {
    $parts[] = '<a href="#agenda">' . $words($cal['count']) . ' ' . $plural($cal['count'], 'event', 'events') . '</a> on the calendar';
}
if ($work['ok'] && $work['overdue'] > 0) {
    $parts[] = '<a href="#work" class="is-alert">' . $words($work['overdue']) . ' overdue ' . $plural($work['overdue'], 'item', 'items') . '</a>';
}
if ($work['ok'] && $work['today'] > 0) {
    $parts[] = '<a href="#work">' . $words($work['today']) . ' due today</a>';
}
if ($mail['ok'] && $mail['unread'] > 0) {
    $parts[] = '<a href="#mail">' . $words($mail['unread']) . ' unread ' . $plural($mail['unread'], 'conversation', 'conversations') . '</a>';
}
if ($parts) {
    $last = array_pop($parts);
    $lede = 'Today you have ' . ($parts ? implode(', ', $parts) . ' and ' : '') . $last . '.';
} else {
    // Nothing pending: say what is clear, from the modules that could be read
    $calm = [];
    if ($cal['ok']) $calm[] = 'nothing scheduled';
    if ($work['ok']) $calm[] = 'nothing overdue';
    if ($mail['ok'] && $mail['accounts'] > 0) $calm[] = 'nothing unread';
    $lastCalm = array_pop($calm);
    $lede = $lastCalm !== null ? 'A clear desk today — ' . ($calm ? implode(', ', $calm) . ' and ' : '') . $lastCalm . '. Enjoy the calm.' : '';
}
if ($lede === '') {
    $lede = 'Everything your business runs on, gathered in one place.';
}

// --------------------------------------------------------------- formatting
$dueLabel = function (?string $due) use ($todayYmd): string {
    if ($due === null) return 'No date';
    $days = (int)round((strtotime($due) - strtotime($todayYmd)) / 86400);
    if ($days < 0) return -$days === 1 ? '1 day late' : -$days . ' days late';
    if ($days === 0) return 'Today';
    if ($days === 1) return 'Tomorrow';
    if ($days < 7) return date('l', strtotime($due));
    return date('j M', strtotime($due));
};
$mailTime = function (string $iso): string {
    $ts = $iso !== '' ? strtotime($iso) : false;
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if (date('Y', $ts) === date('Y')) return date('j M', $ts);
    return date('j M Y', $ts);
};
$initials = function (string $name): string {
    $name = trim(preg_replace('/<[^>]*>|["\']/', '', $name));
    if ($name === '' || $name === '?') return '?';
    if (str_contains($name, '@')) return mb_strtoupper(mb_substr($name, 0, 1));
    $w = preg_split('/\s+/', $name);
    return mb_strtoupper(mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr(end($w), 0, 1) : ''));
};
$hue = fn(string $s) => crc32(mb_strtolower($s)) % 360;

// ------------------------------------------------------------------ modules
// Live lines on the three modules the brief reads.
$projectsLive = '';
$projectsHref = '/projects/';
if ($work['ok']) {
    $bits = [];
    if ($work['overdue']) $bits[] = $work['overdue'] . ' overdue';
    if ($work['today']) $bits[] = $work['today'] . ' due today';
    if ($work['week']) $bits[] = $work['week'] . ' this week';
    if ($work['open']) {
        if (!$bits) $bits[] = $work['open'] . $plural($work['open'], ' open item', ' open items');
        $projectsHref = '/projects/#mywork';
    } elseif ($work['projects']) {
        $bits[] = $work['projects'] . $plural($work['projects'], ' active project', ' active projects');
    }
    $projectsLive = implode(' · ', $bits);
}
$calendarLive = '';
if ($cal['ok']) {
    $calendarLive = $cal['count'] ? $cal['count'] . ' today' : 'Nothing scheduled today';
    if ($cal['next']) $calendarLive .= ' · ' . ($cal['next']['live'] ? 'now' : 'next ' . $cal['next']['time']);
}
$mailLive = '';
if ($mail['ok']) {
    $mailLive = $mail['accounts'] === 0 ? 'Connect your mailbox' : ($mail['unread'] ? $mail['unread'] . ' unread' : 'Inbox at rest');
}

$modules = [
    ['key' => 'projects', 'name' => 'Projects & Boards', 'tag' => 'Work OS', 'desc' => 'Plan, track and deliver every project', 'href' => $projectsHref, 'icon' => 'ih-board', 'c' => '#7a1f30', 'live' => $projectsLive, 'alert' => $work['ok'] && $work['overdue'] > 0, 'kw' => 'boards tasks work items kanban'],
    ['key' => 'crm', 'name' => 'Suppliers & CRM', 'tag' => '', 'desc' => 'Customer and supplier relationships', 'href' => '/crm/', 'icon' => 'i-users', 'c' => '#2f6b6a', 'live' => '', 'kw' => 'customers clients accounts contacts'],
    ['key' => 'qi', 'name' => 'Quotes & Invoices', 'tag' => '', 'desc' => 'Create, send and track quotes and invoices', 'href' => '/qi/', 'icon' => 'i-file-text', 'c' => '#9a7136', 'live' => '', 'kw' => 'quote invoice billing credit note'],
    ['key' => 'finances', 'name' => 'Finance', 'tag' => 'Accounting', 'desc' => 'Ledgers, banking, VAT and reports', 'href' => '/finances/', 'icon' => 'ih-coins', 'c' => '#2f7457', 'live' => '', 'kw' => 'accounting ledger bank vat reports money'],
    ['key' => 'receipts', 'name' => 'Receipts', 'tag' => 'AI', 'desc' => 'Scan, read and file every receipt', 'href' => '/receipts/', 'icon' => 'i-receipt', 'c' => '#6b3a6e', 'live' => '', 'kw' => 'expenses scan slips ai'],
    ['key' => 'payroll', 'name' => 'Payroll', 'tag' => '', 'desc' => 'Employees, wages and pay runs', 'href' => '/payroll/', 'icon' => 'i-wallet', 'c' => '#a4552c', 'live' => '', 'kw' => 'wages salaries employees timesheets'],
    ['key' => 'suppliers_ai', 'name' => 'Suppliers AI', 'tag' => 'AI', 'desc' => 'AI-powered supplier insights', 'href' => '/suppliers_ai/', 'icon' => 'i-sparkle', 'c' => '#3d5a8a', 'live' => '', 'kw' => 'ai insights suppliers'],
    ['key' => 'shopping', 'name' => 'Shopping List', 'tag' => '', 'desc' => 'Procurement and supplies', 'href' => '/shopping/', 'icon' => 'ih-cart', 'c' => '#6f7a35', 'live' => '', 'kw' => 'procurement supplies buy order'],
    ['key' => 'mail', 'name' => 'Mail', 'tag' => '', 'desc' => 'Your business correspondence', 'href' => '/mail/', 'icon' => 'i-mail', 'c' => '#9b3048', 'live' => $mailLive, 'alert' => false, 'kw' => 'email inbox messages'],
    ['key' => 'calendar', 'name' => 'Calendar', 'tag' => '', 'desc' => 'Schedule, meetings and reminders', 'href' => '/calendar/', 'icon' => 'i-calendar', 'c' => '#2f5c84', 'live' => $calendarLive, 'alert' => false, 'kw' => 'events schedule meetings agenda'],
    ['key' => 'drive', 'name' => 'Drive', 'tag' => 'FlowDrive', 'desc' => 'Cloud storage for all your files', 'href' => 'https://flowdrive.co.za/', 'icon' => 'ih-cloud', 'c' => '#4f5d6b', 'live' => '', 'kw' => 'files documents storage cloud'],
    ['key' => 'admin', 'name' => 'Admin', 'tag' => '', 'desc' => 'Company, users and settings', 'href' => '/admin/', 'icon' => 'i-shield', 'c' => '#5a4636', 'live' => '', 'kw' => 'settings users company billing'],
];

$actions = [
    ['name' => 'Compose', 'href' => '/mail/?compose=new', 'icon' => 'i-pen', 'kw' => 'new email write mail'],
    ['name' => 'New event', 'href' => '/calendar/?new=1', 'icon' => 'i-calendar-plus', 'kw' => 'meeting appointment schedule'],
    ['name' => 'New quote', 'href' => '/qi/quote_new.php', 'icon' => 'i-file-text', 'kw' => 'quotation estimate'],
    ['name' => 'New invoice', 'href' => '/qi/invoice_new.php', 'icon' => 'i-receipt', 'kw' => 'bill charge'],
    ['name' => 'Scan receipt', 'href' => '/receipts/upload.php', 'icon' => 'i-upload', 'kw' => 'upload expense slip'],
];

// Everything the "Where to?" box can jump to
$jump = [];
foreach ($modules as $m) $jump[] = ['name' => $m['name'], 'hint' => $m['desc'], 'href' => $m['href'], 'icon' => $m['icon'], 'kw' => $m['kw'] . ' ' . $m['tag'], 'group' => 'Modules'];
foreach ($actions as $a) $jump[] = ['name' => $a['name'], 'hint' => '', 'href' => $a['href'], 'icon' => $a['icon'], 'kw' => $a['kw'], 'group' => 'Actions'];
foreach ([
    ['My work', '/projects/#mywork', 'i-check-square', 'assigned tasks items due'],
    ['Mail settings', '/mail/settings.php', 'i-settings', 'mailbox account signature'],
    ['Calendar settings', '/calendar/settings.php', 'i-settings', 'working hours timezone'],
    ['Signed-in devices', '/account/', 'ih-devices', 'sessions phones security logout'],
] as [$n, $h, $i, $k]) $jump[] = ['name' => $n, 'hint' => '', 'href' => $h, 'icon' => $i, 'kw' => $k, 'group' => 'Go to'];

$boot = ['now' => str_replace(' ', 'T', $now), 'name' => $firstName, 'jump' => $jump];
$who = $firstName !== '' ? $firstName : 'there';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $e($csrfToken) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <title>Home · <?= $e($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,300;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $v('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/home/css/home.css?v=<?= $v('home/css/home.css') ?>">
</head>
<body class="fwm fwh" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/mail/partials/icons.php'; ?>
<?php include __DIR__ . '/calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/home/partials/icons.php'; ?>
<a class="fwh-skip" href="#main">Skip to content</a>

<header class="fwm-top fwh-top">
    <a class="fwm-brand" href="/home.php" aria-label="Flowwork home">
        <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#ih-cube"/></svg></span>
        <span class="fwm-brand__text">
            <span class="fwm-brand__name">Flowwork</span>
            <span class="fwm-brand__company"><?= $e($companyName) ?></span>
        </span>
    </a>

    <div class="fwm-search fwh-jump" id="jump" role="search">
        <label class="fwm-search__box">
            <svg class="i"><use href="#i-search"/></svg>
            <span class="sr-only">Jump to a module or action</span>
            <input type="search" id="jumpInput" placeholder="Where to, <?= $e($who) ?>?" autocomplete="off" spellcheck="false" enterkeyhint="go"
                   role="combobox" aria-expanded="false" aria-controls="jumpList" aria-autocomplete="list">
            <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
        </label>
        <div class="fwh-jump__list" id="jumpList" role="listbox" aria-label="Destinations" hidden></div>
    </div>

    <div class="fwm-top__actions">
        <button class="icon-btn" id="bellBtn" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" data-tip="Notifications">
            <svg class="i"><use href="#i-bell"/></svg>
            <span class="fwh-badge" id="bellBadge" hidden></span>
        </button>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="<?= $fwTheme === 'dark' ? 'Light theme' : 'Dark theme' ?>">
            <svg class="i"><use href="#<?= $fwTheme === 'dark' ? 'i-sun' : 'i-moon' ?>"/></svg>
        </button>
        <button class="icon-btn" id="kebabBtn" type="button" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" aria-controls="kebabMenu" data-tip="Menu" data-tip-pos="left">
            <svg class="i"><use href="#i-more-v"/></svg>
        </button>
    </div>
</header>

<nav class="fwm-pop fwh-menu" id="kebabMenu" role="menu" aria-label="Menu" hidden>
    <?php if (count($userCompanies) > 1): ?>
        <div class="fwm-pop__title">Switch company</div>
        <?php foreach ($userCompanies as $uc): $isActive = ((int)$uc['id'] === $activeCompanyId); ?>
            <button type="button" class="fwm-pop__item fwh-menu__company<?= $isActive ? ' is-active' : '' ?>" role="menuitem"
                    data-fw-switch-company="<?= (int)$uc['id'] ?>"<?= $isActive ? ' aria-current="true" disabled' : '' ?>>
                <span class="fwm-avatar" style="--h:<?= $hue((string)$uc['name']) ?>"><?= $e($initials((string)$uc['name'])) ?></span>
                <span class="fwh-menu__name"><?= $e($uc['name']) ?></span>
                <?php if ($isActive): ?><svg class="i i--sm fwh-menu__tick"><use href="#i-check"/></svg><?php endif; ?>
            </button>
        <?php endforeach; ?>
        <div class="fwm-pop__sep" role="separator"></div>
    <?php endif; ?>
    <a class="fwm-pop__item" role="menuitem" href="/admin/"><svg class="i"><use href="#i-shield"/></svg>Admin &amp; settings</a>
    <a class="fwm-pop__item" role="menuitem" href="/account/"><svg class="i"><use href="#ih-devices"/></svg>Signed-in devices</a>
    <div class="fwm-pop__sep" role="separator"></div>
    <a class="fwm-pop__item" role="menuitem" href="/logout.php"><svg class="i"><use href="#ih-logout"/></svg>Sign out</a>
</nav>

<main class="fwh-main" id="main" tabindex="-1">

    <!-- ============================== Greeting ============================== -->
    <section class="fwh-hero" aria-labelledby="heroTitle">
        <div class="fwh-hero__text">
            <p class="fwh-eyebrow"><?= $e(date('l', $nowTs)) ?> <i aria-hidden="true">◆</i> <?= $e(date('j F Y', $nowTs)) ?></p>
            <h1 class="fwh-hero__title" id="heroTitle"><span id="greeting"><?= $dayGreeting ?></span>,<?php if ($firstName !== ''): ?><br><em><?= $e($firstName) ?></em><?php endif; ?><span class="fwh-dot">.</span></h1>
            <span class="fwm-ornament" aria-hidden="true"></span>
            <p class="fwh-lede"><?= $lede ?></p>
            <nav class="fwh-actions" aria-label="Quick actions">
                <?php foreach ($actions as $i => $a): ?>
                    <a class="btn<?= $i === 0 ? ' btn--primary' : '' ?>" href="<?= $e($a['href']) ?>"><svg class="i"><use href="#<?= $a['icon'] ?>"/></svg><?= $e($a['name']) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="fwh-dial" aria-hidden="true">
            <div class="fwh-dial__face">
            <svg class="fwh-guilloche" id="guilloche" viewBox="-200 -200 400 400"></svg>
            <svg class="fwh-clock" id="clock" viewBox="-100 -100 200 200">
                <circle class="fwh-clock__face" r="92"/>
                <circle class="fwh-clock__ring" r="92"/>
                <circle class="fwh-clock__ring fwh-clock__ring--in" r="86"/>
                <?php for ($t = 0; $t < 60; $t++): if ($t % 15 === 0) continue; ?>
                    <line class="fwh-clock__tick<?= $t % 5 === 0 ? ' is-hour' : '' ?>" x1="0" y1="-84" x2="0" y2="<?= $t % 5 === 0 ? -76 : -80.5 ?>" transform="rotate(<?= $t * 6 ?>)"/>
                <?php endfor; ?>
                <text class="fwh-clock__num" x="0" y="-64">XII</text>
                <text class="fwh-clock__num" x="66" y="5">III</text>
                <text class="fwh-clock__num" x="0" y="73">VI</text>
                <text class="fwh-clock__num" x="-66" y="5">IX</text>
                <text class="fwh-clock__brand" x="0" y="-34">Flowwork</text>
                <text class="fwh-clock__date" x="0" y="42"><?= $e(strtoupper(date('D j', $nowTs))) ?></text>
                <g class="fwh-clock__hand fwh-clock__hand--h" id="handH"><path d="M-3.2 9 L-1.6 -46 L0 -50 L1.6 -46 L3.2 9 Z"/></g>
                <g class="fwh-clock__hand fwh-clock__hand--m" id="handM"><path d="M-2.3 11 L-1.1 -70 L0 -74 L1.1 -70 L2.3 11 Z"/></g>
                <g class="fwh-clock__hand fwh-clock__hand--s" id="handS"><line x1="0" y1="18" x2="0" y2="-78"/><circle cx="0" cy="14" r="3.4"/></g>
                <circle class="fwh-clock__pin" r="5.2"/>
                <circle class="fwh-clock__pin-in" r="1.8"/>
            </svg>
            </div>
            <p class="fwh-dial__meta">
                <span class="fwh-dial__time" id="clockText"><?= date('H:i', $nowTs) ?></span>
                <span class="fwh-dial__sub">Week <?= (int)date('W', $nowTs) ?> <i>◆</i> Day <?= (int)date('z', $nowTs) + 1 ?></span>
            </p>
        </div>
    </section>

    <!-- ============================== Today ================================= -->
    <section class="fwh-today" aria-label="Today">

        <!-- Agenda -->
        <article class="fwh-card" id="agenda" style="--c:#2f5c84; --i:1">
            <header class="fwh-card__head">
                <div>
                    <p class="fwh-eyebrow">Calendar</p>
                    <h2 class="fwh-card__title">Today’s agenda</h2>
                </div>
                <a class="icon-btn" href="/calendar/?view=day" aria-label="Open the calendar" data-tip="Open calendar" data-tip-pos="left"><svg class="i"><use href="#ih-arrow-ur"/></svg></a>
            </header>
            <?php if (!$cal['ok']): ?>
                <div class="fwh-empty">
                    <span class="fwh-empty__art"><svg class="i"><use href="#i-calendar"/></svg></span>
                    <p>Your agenda will appear here.</p>
                    <a class="btn btn--sm" href="/calendar/">Open calendar</a>
                </div>
            <?php elseif (!$cal['events']): ?>
                <div class="fwh-empty">
                    <span class="fwh-empty__art"><svg class="i"><use href="#i-calendar"/></svg></span>
                    <h3>A clear day</h3>
                    <p>Nothing on the calendar today.</p>
                    <a class="btn btn--sm" href="/calendar/?new=1"><svg class="i"><use href="#i-plus"/></svg>New event</a>
                </div>
            <?php else: ?>
                <?php if ($cal['next']): $n = $cal['next']; ?>
                    <a class="fwh-next" href="<?= $e($n['url']) ?>" style="--c:<?= $e($n['color']) ?>">
                        <span class="fwh-next__label"><?= $n['live'] ? 'Happening now' : 'Up next' ?></span>
                        <span class="fwh-next__row">
                            <span class="fwh-next__time"><?= $e($n['time']) ?></span>
                            <span class="fwh-next__title"><?= $e($n['title']) ?></span>
                        </span>
                        <span class="fwh-next__in" data-start="<?= $e(str_replace(' ', 'T', $n['start'])) ?>" data-end="<?= $e(str_replace(' ', 'T', $n['end'])) ?>"><?= $n['live'] ? 'until ' . $e($n['until'] ?: 'later') : '' ?></span>
                    </a>
                <?php endif; ?>
                <ol class="fwh-list">
                    <?php foreach (array_slice($cal['events'], 0, 5) as $ev): ?>
                        <li>
                            <a class="fwh-ev<?= $ev['past'] ? ' is-past' : '' ?><?= $ev['live'] ? ' is-live' : '' ?>" href="<?= $e($ev['url']) ?>" style="--c:<?= $e($ev['color']) ?>">
                                <span class="fwh-ev__time<?= $ev['all_day'] ? ' is-allday' : '' ?>"><?= $ev['all_day'] ? 'All day' : $e($ev['time']) ?></span>
                                <span class="fwh-ev__bar" aria-hidden="true"></span>
                                <span class="fwh-ev__body">
                                    <span class="fwh-ev__title"><?= $e($ev['title']) ?></span>
                                    <?php $meta = array_filter([$ev['until'] !== '' ? 'until ' . $ev['until'] : '', $ev['location'], $ev['calendar']]); ?>
                                    <?php if ($meta): ?><span class="fwh-ev__meta"><?= $e(implode(' · ', $meta)) ?></span><?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($cal['count'] > 5): ?>
                    <a class="fwh-more" href="/calendar/?view=day">+<?= $cal['count'] - 5 ?> more today <svg class="i i--sm"><use href="#i-chev-right"/></svg></a>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <!-- My work -->
        <article class="fwh-card" id="work" style="--c:#7a1f30; --i:2">
            <header class="fwh-card__head">
                <div>
                    <p class="fwh-eyebrow">Projects</p>
                    <h2 class="fwh-card__title">My work</h2>
                </div>
                <a class="icon-btn" href="/projects/#mywork" aria-label="Open my work" data-tip="Open my work" data-tip-pos="left"><svg class="i"><use href="#ih-arrow-ur"/></svg></a>
            </header>
            <?php if (!$work['ok']): ?>
                <div class="fwh-empty">
                    <span class="fwh-empty__art"><svg class="i"><use href="#ih-board"/></svg></span>
                    <p>Your open items will appear here.</p>
                    <a class="btn btn--sm" href="/projects/">Open projects</a>
                </div>
            <?php else: ?>
                <div class="fwh-stats">
                    <a class="fwh-stat<?= $work['overdue'] ? ' is-alert' : '' ?>" href="/projects/#mywork"><b><?= (int)$work['overdue'] ?></b><span>Overdue</span></a>
                    <a class="fwh-stat<?= $work['today'] ? ' is-gold' : '' ?>" href="/projects/#mywork"><b><?= (int)$work['today'] ?></b><span>Due today</span></a>
                    <a class="fwh-stat" href="/projects/#mywork"><b><?= (int)$work['week'] ?></b><span>This week</span></a>
                </div>
                <?php if ($work['items']): ?>
                    <ul class="fwh-list">
                        <?php foreach (array_slice($work['items'], 0, 4) as $it): ?>
                            <li>
                                <a class="fwh-item" href="<?= $e($it['url']) ?>" style="--c:<?= $e($it['color']) ?>">
                                    <span class="fwh-item__dot" aria-hidden="true"></span>
                                    <span class="fwh-item__body">
                                        <span class="fwh-item__title"><?= $e($it['title']) ?></span>
                                        <span class="fwh-item__meta"><?= $e($it['project']) ?><?= $it['status'] ? ' · ' : '' ?><?php if ($it['status']): ?><span class="fwh-item__status" style="--s:<?= $e($it['status']['color']) ?>"><?= $e($it['status']['label']) ?></span><?php endif; ?></span>
                                    </span>
                                    <span class="fwh-due is-<?= $it['when'] ?>"><?= $e($dueLabel($it['due'])) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($work['open'] > 4): ?>
                        <a class="fwh-more" href="/projects/#mywork"><?= (int)$work['open'] ?> open items in all <svg class="i i--sm"><use href="#i-chev-right"/></svg></a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="fwh-empty fwh-empty--sm">
                        <h3>Nothing assigned to you</h3>
                        <p><?= $work['projects'] ? $work['projects'] . $plural($work['projects'], ' active project', ' active projects') . ' in the portfolio.' : 'Start a project to plan the work.' ?></p>
                        <a class="btn btn--sm" href="/projects/">Open projects</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <!-- Mail -->
        <article class="fwh-card" id="mail" style="--c:#9b3048; --i:3">
            <header class="fwh-card__head">
                <div>
                    <p class="fwh-eyebrow">Mail</p>
                    <h2 class="fwh-card__title">Correspondence</h2>
                </div>
                <a class="icon-btn" href="/mail/" aria-label="Open the inbox" data-tip="Open inbox" data-tip-pos="left"><svg class="i"><use href="#ih-arrow-ur"/></svg></a>
            </header>
            <?php if (!$mail['ok']): ?>
                <div class="fwh-empty">
                    <span class="fwh-empty__art"><svg class="i"><use href="#i-mail"/></svg></span>
                    <p>Your unread mail will appear here.</p>
                    <a class="btn btn--sm" href="/mail/">Open mail</a>
                </div>
            <?php elseif ($mail['accounts'] === 0): ?>
                <div class="fwh-empty">
                    <span class="fwh-empty__art"><svg class="i"><use href="#i-mail"/></svg></span>
                    <h3>Connect your mailbox</h3>
                    <p>Read and send business mail right inside Flowwork.</p>
                    <a class="btn btn--sm btn--primary" href="/mail/settings.php"><svg class="i"><use href="#i-plus"/></svg>Add a mailbox</a>
                </div>
            <?php elseif ($mail['unread'] === 0): ?>
                <div class="fwh-empty">
                    <span class="fwh-empty__art"><svg class="i"><use href="#i-mail-open"/></svg></span>
                    <h3>Inbox at rest</h3>
                    <p>No unread conversations. Well done.</p>
                    <a class="btn btn--sm" href="/mail/?compose=new"><svg class="i"><use href="#i-pen"/></svg>Compose</a>
                </div>
            <?php else: ?>
                <a class="fwh-count" href="/mail/">
                    <b><?= (int)$mail['unread'] ?></b>
                    <span>unread<br><?= $plural($mail['unread'], 'conversation', 'conversations') ?></span>
                    <?php if ($mail['drafts']): ?><small><?= (int)$mail['drafts'] ?> <?= $plural($mail['drafts'], 'draft', 'drafts') ?></small><?php endif; ?>
                </a>
                <ul class="fwh-list">
                    <?php foreach ($mail['threads'] as $t): ?>
                        <li>
                            <a class="fwh-thread" href="<?= $e($t['url']) ?>">
                                <span class="fwm-avatar" style="--h:<?= $hue($t['who']) ?>" aria-hidden="true"><?= $e($initials($t['who'])) ?></span>
                                <span class="fwh-thread__body">
                                    <span class="fwh-thread__row">
                                        <span class="fwh-thread__from"><?= $e($t['from']) ?><?= $t['count'] > 1 ? ' <small>' . (int)$t['count'] . '</small>' : '' ?></span>
                                        <time class="fwh-thread__time" datetime="<?= $e($t['date']) ?>"><?= $e($mailTime($t['date'])) ?></time>
                                    </span>
                                    <span class="fwh-thread__subject"><?= $e($t['subject']) ?><?php if ($t['snippet'] !== ''): ?><span> — <?= $e($t['snippet']) ?></span><?php endif; ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="fwh-more" href="/mail/">Open the inbox <svg class="i i--sm"><use href="#i-chev-right"/></svg></a>
            <?php endif; ?>
        </article>
    </section>

    <!-- ============================== The suite ============================= -->
    <section class="fwh-suite" aria-labelledby="suiteTitle">
        <header class="fwh-section">
            <div>
                <p class="fwh-eyebrow">The suite</p>
                <h2 class="fwh-section__title" id="suiteTitle">Your <em>workspace</em></h2>
            </div>
            <span class="fwh-section__rule" aria-hidden="true"></span>
            <span class="fwh-section__count"><?= count($modules) ?> modules</span>
        </header>
        <div class="fwh-grid">
            <?php foreach ($modules as $i => $m): $external = str_starts_with($m['href'], 'http'); ?>
                <a class="fwh-tile" href="<?= $e($m['href']) ?>" data-key="<?= $m['key'] ?>" style="--c:<?= $m['c'] ?>; --i:<?= $i + 4 ?>"<?= $external ? ' rel="noopener"' : '' ?>>
                    <span class="fwh-tile__glow" aria-hidden="true"></span>
                    <span class="fwh-tile__top">
                        <span class="fwh-tile__seal" aria-hidden="true"><svg class="i"><use href="#<?= $m['icon'] ?>"/></svg></span>
                        <span class="fwh-tile__no" aria-hidden="true">No. <?= sprintf('%02d', $i + 1) ?></span>
                    </span>
                    <h3 class="fwh-tile__name"><?= $e($m['name']) ?><?php if ($m['tag'] !== ''): ?> <span class="fwh-tile__tag"><?= $e($m['tag']) ?></span><?php endif; ?></h3>
                    <span class="fwh-tile__desc"><?= $e($m['desc']) ?></span>
                    <span class="fwh-tile__foot">
                        <?php if ($m['live'] !== ''): ?>
                            <span class="fwh-tile__live<?= !empty($m['alert']) ? ' is-alert' : '' ?>"><i aria-hidden="true"></i><?= $e($m['live']) ?></span>
                        <?php else: ?>
                            <span class="fwh-tile__open"><?= $external ? 'Visit' : 'Open' ?></span>
                        <?php endif; ?>
                        <span class="fwh-tile__go" aria-hidden="true"><svg class="i i--sm"><use href="#<?= $external ? 'i-external' : 'ih-arrow-ur' ?>"/></svg></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <footer class="fwh-foot">
        <span class="fwm-ornament" aria-hidden="true"></span>
        <p><span class="fwh-foot__brand">Flowwork</span> <i aria-hidden="true">◆</i> <?= $e($companyName) ?></p>
        <p class="fwh-foot__meta">v<?= ASSET_VERSION ?></p>
    </footer>
</main>

<script id="homeBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/home/js/home.js?v=<?= $v('home/js/home.js') ?>"></script>
<script src="/shared/company_switcher.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
