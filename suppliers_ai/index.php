<?php
// /suppliers_ai/index.php — Find suppliers: say what you need in plain words
// (it is read as you type: the trade, where, how many, when, the budget and
// requirements), and Suppliers AI finds who can do it — your own suppliers
// first (with why), businesses near the site on Google, and ideas from AI.
// Shortlist the right ones and ask them all for quotes in one go.
// ?search=ID opens a past search; ?q=…&go=1 searches straight away.
require_once __DIR__ . '/lib/SaBoot.php';

$st = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$st->execute([$SA_USER_ID]);
$first = trim((string)$st->fetchColumn());
$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
$counts = SaRequests::counts($DB, $SA_COMPANY_ID, $SA_USER_ID);
$st = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL AND status IN ('active', 'prospect')");
$st->execute([$SA_COMPANY_ID]);
$supplierCount = (int)$st->fetchColumn();
$st = $DB->prepare("SELECT COUNT(*) FROM ai_queries WHERE company_id = ? AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$st->execute([$SA_COMPANY_ID]);
$monthSearches = (int)$st->fetchColumn();
$recent = SaSearch::history($DB, $SA_COMPANY_ID, $SA_USER_ID, ['mine' => true])['rows'];
$recent = array_slice($recent, 0, 6);
$waiting = array_slice(SaRequests::list($DB, $SA_COMPANY_ID, $SA_USER_ID, ['status' => 'active']), 0, 4);
$s = $SA_SETTINGS;

$page = 'index';
$pageTitle = 'Find suppliers';
$pageScripts = ['find'];
$pageBoot = ['open_search' => (int)($_GET['search'] ?? 0), 'q' => SaKit::text($_GET['q'] ?? '', 500), 'go' => !empty($_GET['go'])];
include __DIR__ . '/partials/head.php';
?>
<header class="fsa-head">
    <div class="fsa-head__text">
        <p class="fsa-eyebrow">Suppliers AI <i>◆</i> <?= $e(date('l j F')) ?></p>
        <h1><?= $e($greet) ?><?= $first !== '' ? ', <em>' . $e($first) . '</em>' : '' ?></h1>
        <p><?php if ($counts['to_compare']): ?>
            <b><?= (int)$counts['to_compare'] ?></b> <?= $counts['to_compare'] === 1 ? 'request has' : 'requests have' ?> quotes in to <a href="/suppliers_ai/requests.php">compare</a><?= $counts['late'] ? ' — <b class="fsa-late">' . (int)$counts['late'] . ' past the date</b>' : '' ?>.
        <?php elseif ($counts['open']): ?>
            <b><?= (int)$counts['open'] ?></b> <?= $counts['open'] === 1 ? 'request is' : 'requests are' ?> <a href="/suppliers_ai/requests.php">waiting for quotes</a>.
        <?php endif; ?>
        Say what you need in your own words — your own suppliers come first, with the reasons they fit.</p>
    </div>
    <div class="fsa-head__actions">
        <a class="btn" href="/suppliers_ai/requests.php"><svg class="i"><use href="#isa-quote"/></svg>Requests for quotes</a>
    </div>
</header>

<?php if ($SA_CAPS['search']): ?>
<section class="fsa-composer" id="find" aria-labelledby="findTitle">
    <span class="fsa-composer__art" aria-hidden="true"><svg class="i"><use href="#isa-seal"/></svg></span>
    <div class="fsa-composer__body">
        <div class="fsa-composer__head">
            <h2 id="findTitle">What do you need?</h2>
            <button class="fsa-link" type="button" id="exampleBtn">Try an example</button>
        </div>
        <label class="sr-only" for="findText">What you need, in your own words</label>
        <textarea class="fsa-composer__text" id="findText" rows="2" maxlength="500" spellcheck="true"
                  placeholder="Need 3 plumbers in Vanderbijlpark for geysers tomorrow, under R5k, with COC"></textarea>
        <div class="fsa-reading" id="reading" aria-live="polite" hidden></div>
        <div class="fsa-composer__foot">
            <div class="fsa-where">
                <label class="fsa-where__field" title="Where the work or delivery is">
                    <svg class="i i--sm"><use href="#i-pin"/></svg>
                    <span class="sr-only">Where</span>
                    <input id="findWhere" maxlength="160" autocomplete="off" placeholder="<?= $e($SA_COMPANY['town'] ?: 'Town or area') ?>">
                </label>
                <label class="fsa-where__radius">
                    <span class="sr-only">Within</span>
                    <select class="select" id="findRadius">
                        <?php foreach ([5, 10, 25, 50, 100, 250] as $km): ?>
                            <option value="<?= $km ?>"<?= (int)round($s['radius_km']) === $km ? ' selected' : '' ?>>within <?= $km ?> km</option>
                        <?php endforeach; ?>
                        <?php if (!in_array((int)round($s['radius_km']), [5, 10, 25, 50, 100, 250], true)): ?>
                            <option value="<?= $e($s['radius_km']) ?>" selected>within <?= $e(rtrim(rtrim(number_format($s['radius_km'], 1), '0'), '.')) ?> km</option>
                        <?php endif; ?>
                    </select>
                </label>
            </div>
            <div class="fsa-sources" role="group" aria-label="Look in">
                <label class="fsa-source"><input type="checkbox" id="srcCrm" checked><span><svg class="i i--sm"><use href="#i-building"/></svg>Your suppliers</span></label>
                <label class="fsa-source<?= $s['google_ready'] ? '' : ' is-off' ?>" title="<?= $s['google_ready'] ? 'Businesses near the place, from Google' : 'Needs a Google key — Settings' ?>">
                    <input type="checkbox" id="srcGoogle"<?= $s['google_ready'] ? ' checked' : ' disabled' ?>><span><svg class="i i--sm"><use href="#i-globe"/></svg>Google</span></label>
                <label class="fsa-source<?= $s['ai_ready'] ? '' : ' is-off' ?>" title="<?= $s['ai_ready'] ? 'AI reads the request and suggests kinds of business to look at' : 'Needs the OpenAI key switched on — Settings' ?>">
                    <input type="checkbox" id="srcAi"<?= $s['ai_ready'] ? ' checked' : ' disabled' ?>><span><svg class="i i--sm"><use href="#i-sparkle"/></svg>AI ideas</span></label>
                <label class="fsa-source" title="Only suppliers whose compliance documents are in order"><input type="checkbox" id="srcCompliant"<?= $s['compliant_only'] ? ' checked' : '' ?>><span><svg class="i i--sm"><use href="#i-shield"/></svg>Documents in order</span></label>
            </div>
            <button class="btn btn--primary" type="button" id="findGo"><svg class="i"><use href="#i-search"/></svg><span>Find suppliers</span></button>
        </div>
    </div>
</section>
<?php else: ?>
<div class="fwm-banner"><svg class="i"><use href="#i-eye"/></svg><div class="fwm-banner__body">You can open past searches and requests. Searching for new suppliers is for members, bookkeepers and admins.</div></div>
<?php endif; ?>

<section class="fsa-results" id="results" aria-live="polite" hidden></section>

<div id="home">
    <div class="fsa-stats">
        <a class="fsa-stat<?= $counts['late'] ? ' is-alert' : '' ?>" href="/suppliers_ai/requests.php" style="--i:0"><span class="fsa-stat__label">Waiting for quotes</span><span class="fsa-stat__value"><?= (int)$counts['open'] ?></span>
            <span class="fsa-stat__sub"><?= $counts['late'] ? (int)$counts['late'] . ' past the date' : ($counts['drafts'] ? (int)$counts['drafts'] . ' not sent yet' : 'Requests out with suppliers') ?></span></a>
        <a class="fsa-stat" href="/suppliers_ai/requests.php" style="--i:1"><span class="fsa-stat__label">Quotes to compare</span><span class="fsa-stat__value"><?= (int)$counts['to_compare'] ?></span>
            <span class="fsa-stat__sub">Requests with quotes in</span></a>
        <a class="fsa-stat" href="/crm/#suppliers" style="--i:2"><span class="fsa-stat__label">Your suppliers</span><span class="fsa-stat__value"><?= $supplierCount ?></span>
            <span class="fsa-stat__sub">In the CRM, searched first</span></a>
        <a class="fsa-stat" href="/suppliers_ai/history.php" style="--i:3"><span class="fsa-stat__label"><?= $e(date('F')) ?></span><span class="fsa-stat__value"><?= $monthSearches ?></span>
            <span class="fsa-stat__sub"><?= $monthSearches === 1 ? 'search' : 'searches' ?> by the team</span></a>
    </div>

    <div class="fsa-cols">
        <section class="fsa-panel fsa-panel--flush">
            <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#isa-quote"/></svg></span>
                <div class="fsa-panel__title"><h3>Waiting for quotes</h3><p>Requests out with suppliers.</p></div>
                <a class="fsa-mini" href="/suppliers_ai/requests.php">All</a></div>
            <?php if ($waiting): ?>
            <ul class="fsa-rows">
                <?php foreach ($waiting as $w): ?>
                    <li><a href="<?= $e($w['url']) ?>">
                        <span class="fsa-rows__icon" style="--c:<?= $e($w['trade']['color'] ?? '#7a1f30') ?>"><svg class="i"><use href="#<?= $e($w['trade']['icon'] ?? 'isa-quote') ?>"/></svg></span>
                        <span class="fsa-rows__text"><b><?= $e($w['title']) ?></b><small><?= $w['status'] === 'draft' ? 'Not sent yet' : (int)$w['quoted'] . ' of ' . (int)$w['suppliers'] . ' quoted' . ($w['replied'] > $w['quoted'] ? ' · ' . ((int)$w['replied'] - (int)$w['quoted']) . ' replied' : '') ?></small></span>
                        <span class="fsa-rows__side<?= $w['late'] ? ' is-late' : '' ?>"><?= $w['quote_due'] ? $e(($w['late'] ? 'was due ' : 'due ') . $w['quote_due']) : '' ?><?php if ($w['best'] !== null): ?><span class="fsa-num"><?= $e(SaKit::money($w['best'])) ?></span><?php endif; ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="fsa-panel__empty">Nothing out with suppliers. Search, shortlist two or three, and ask them all for quotes in one go.</p>
            <?php endif; ?>
        </section>

        <section class="fsa-panel fsa-panel--flush">
            <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-clock"/></svg></span>
                <div class="fsa-panel__title"><h3>Your recent searches</h3><p>Open one again, or search again.</p></div>
                <a class="fsa-mini" href="/suppliers_ai/history.php">All</a></div>
            <?php if ($recent): ?>
            <ul class="fsa-rows">
                <?php foreach ($recent as $r): ?>
                    <li><a href="/suppliers_ai/?search=<?= (int)$r['id'] ?>">
                        <span class="fsa-rows__icon"><svg class="i"><use href="#<?= $r['ai'] ? 'i-sparkle' : 'i-search' ?>"/></svg></span>
                        <span class="fsa-rows__text"><b class="fsa-quoteline">“<?= $e($r['text']) ?>”</b><small><?= (int)$r['results'] ?> found<?= $r['location'] !== '' ? ' · ' . $e($r['location']) : '' ?><?= $r['requests'] ? ' · ' . (int)$r['requests'] . ' request' . ($r['requests'] === 1 ? '' : 's') : '' ?></small></span>
                        <span class="fsa-rows__side"><small data-ago="<?= $e($r['at']) ?>"></small></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="fsa-panel__empty">Your searches show here. Try “electrician with COC in Centurion” or “20 cubes river sand delivered to Sasolburg”.</p>
            <?php endif; ?>
        </section>

        <section class="fsa-panel">
            <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#isa-scale"/></svg></span>
                <div class="fsa-panel__title"><h3>How suppliers are ranked</h3><p>Every result says why it is there.</p></div></div>
            <ul class="fsa-how">
                <li><svg class="i"><use href="#i-building"/></svg><span><b>Your suppliers first</b> — their industry, tags, name and your notes, and whether they are preferred.</span></li>
                <li><svg class="i"><use href="#i-receipt"/></svg><span><b>What you bought before</b> — bills in Finance and orders in Procurement for the same thing.</span></li>
                <li><svg class="i"><use href="#ic-trophy"/></svg><span><b>Past quotes</b> — who quoted, who won, who never replied.</span></li>
                <li><svg class="i"><use href="#i-shield"/></svg><span><b>Documents</b> — from the CRM; your policy can block expired ones.</span></li>
                <li><svg class="i"><use href="#i-globe"/></svg><span><b>Near the site</b> — Google’s rating and the distance<?= $s['google_ready'] ? '' : ($SA_CAPS['keys'] ? ' (<a href="/suppliers_ai/settings.php#sources">add a Google key</a>)' : ' (once an admin adds a Google key)') ?>.</span></li>
            </ul>
            <p class="fsa-how__foot">Nothing is made up: a phone number or email only shows when it is in your CRM or on Google. <a href="/suppliers_ai/help.php">How it works</a></p>
        </section>
    </div>
</div>

<div class="fsa-tray" id="tray" hidden>
    <div class="fsa-tray__faces" id="trayFaces"></div>
    <div class="fsa-tray__text"><b id="trayCount">0 on the shortlist</b><small id="trayHint">Ask them all for quotes in one go</small></div>
    <button class="btn btn--ghost" type="button" id="trayClear">Clear</button>
    <button class="btn btn--primary" type="button" id="trayAsk"><svg class="i"><use href="#isa-send-all"/></svg><span>Ask for quotes</span></button>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
