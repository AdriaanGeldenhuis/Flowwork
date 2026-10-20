<?php
// /shopping/index.php — Shopping at a glance: a greeting with what is waiting,
// "What do you need?" (type or paste a list — it is read as you type and
// becomes a list, or goes onto one that is open), every list as a card with
// its progress and budget, what is needed soon, what was bought lately and
// where this month's money went by aisle.
// ?deleted=ID offers to bring back the list that was just deleted.
require_once __DIR__ . '/lib/ShBoot.php';

$cards = ShLists::cards($DB, $SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE);
$ov = ShLists::overview($DB, $SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE);
$st = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$st->execute([$SH_USER_ID]);
$first = trim((string)$st->fetchColumn());
$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');

$active = array_values(array_filter($cards, fn($c) => $c['status'] !== 'done'));
$toBuy = array_sum(array_column($active, 'left'));
$soon = $ov['needed_count'];
$late = $ov['late_count'];
$shopping = array_values(array_filter($active, fn($c) => $c['shopping']));
$monthTotal = array_sum(array_column($ov['aisles'], 'total'));
$templates = (int)$DB->query("SELECT COUNT(*) FROM shopping_templates WHERE company_id = " . (int)$SH_COMPANY_ID)->fetchColumn();
$openLists = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], array_values(array_filter($active, fn($c) => $c['edit'])));

$page = 'index';
$pageTitle = 'Overview';
$pageScripts = ['overview'];
$pageBoot = ['lists' => $cards, 'open' => $openLists, 'deleted' => (int)($_GET['deleted'] ?? 0)];
include __DIR__ . '/partials/head.php';
?>
<header class="fws-head">
    <div class="fws-head__text">
        <p class="fws-eyebrow">Shopping <i>◆</i> <?= $e(date('l j F')) ?></p>
        <h1><?= $e($greet) ?><?= $first !== '' ? ', <em>' . $e($first) . '</em>' : '' ?></h1>
        <p><?php if ($shopping): ?>
            <b><?= $e($shopping[0]['shopping']['name'] ?: 'Someone') ?></b> is out shopping for “<a href="/shopping/list.php?id=<?= (int)$shopping[0]['id'] ?>"><?= $e($shopping[0]['name']) ?></a>” right now<?= count($shopping) > 1 ? ' (and ' . (count($shopping) - 1) . ' more)' : '' ?>.
        <?php endif; ?>
        <?php if ($toBuy): ?>
            <b><?= (int)$toBuy ?></b> <?= $toBuy === 1 ? 'thing' : 'things' ?> to buy on <b><?= count($active) ?></b> <?= count($active) === 1 ? 'list' : 'lists' ?><?php if ($late): ?> — <b class="fws-late"><?= $late ?> late</b><?php elseif ($soon): ?> — <b><?= $soon ?></b> needed this week<?php endif; ?>.
        <?php elseif ($cards): ?>
            Everything is bought. Type the next thing you need below — it is read for you.
        <?php else: ?>
            Type or paste what you need below — quantities, sizes and prices are read for you, and it is sorted by aisle.
        <?php endif; ?></p>
    </div>
    <div class="fws-head__actions">
        <?php if ($SH_CAPS['write'] && $templates): ?><a class="btn" href="/shopping/templates.php"><svg class="i"><use href="#i-template"/></svg>From a template</a><?php endif; ?>
        <?php if ($SH_CAPS['write']): ?><button class="btn btn--primary" type="button" data-new-list><svg class="i"><use href="#i-plus"/></svg>New list</button><?php endif; ?>
    </div>
</header>

<?php if ($SH_CAPS['write']): ?>
<section class="fws-composer" id="composer" aria-labelledby="composerTitle">
    <span class="fws-composer__art" aria-hidden="true"><svg class="i"><use href="#is-wand"/></svg></span>
    <div class="fws-composer__body">
        <div class="fws-composer__head">
            <h2 id="composerTitle">What do you need?</h2>
            <button class="fws-link" type="button" id="composerExample">Try an example</button>
        </div>
        <label class="sr-only" for="composerText">Items, one a line</label>
        <textarea class="fws-composer__text" id="composerText" rows="3" spellcheck="true" placeholder="10 bags cement @ R95&#10;2x PVC elbow 20mm&#10;milk 2L, bread, eggs!"></textarea>
        <div class="fws-composer__preview" id="composerPreview" aria-live="polite" hidden></div>
        <div class="fws-composer__foot" id="composerFoot" hidden>
            <div class="fws-composer__where">
                <label class="sr-only" for="composerTarget">Add to</label>
                <select class="select" id="composerTarget">
                    <option value="new">A new list</option>
                    <?php foreach ($openLists as $ol): ?><option value="<?= (int)$ol['id'] ?>">Add to “<?= $e($ol['name']) ?>”</option><?php endforeach; ?>
                </select>
                <label class="sr-only" for="composerName">Name of the new list</label>
                <input class="input" id="composerName" maxlength="120" placeholder="Name it (optional)" autocomplete="off">
            </div>
            <button class="btn btn--primary" type="button" id="composerGo" disabled><svg class="i"><use href="#is-list"/></svg><span>Make the list</span></button>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="fws-stats">
    <div class="fws-stat" style="--i:0"><span class="fws-stat__label">Open lists</span><span class="fws-stat__value"><?= count($active) ?></span>
        <span class="fws-stat__sub"><?= $shopping ? count($shopping) . ' out shopping now' : ($cards ? count($cards) - count($active) . ' finished' : 'Start your first one') ?></span></div>
    <div class="fws-stat" style="--i:1"><span class="fws-stat__label">To buy</span><span class="fws-stat__value"><?= (int)$toBuy ?></span>
        <span class="fws-stat__sub"><?php $urgent = array_sum(array_column($active, 'urgent')); echo $urgent ? $urgent . ' marked urgent' : 'Nothing urgent'; ?></span></div>
    <div class="fws-stat<?= $late ? ' is-alert' : '' ?>" style="--i:2"><span class="fws-stat__label">Needed this week</span><span class="fws-stat__value"><?= (int)$soon ?></span>
        <span class="fws-stat__sub"><?= $late ? $late . ' already late' : 'Give items a date to see them here' ?></span></div>
    <a class="fws-stat" href="/shopping/prices.php" style="--i:3"><span class="fws-stat__label"><?= $e(date('F')) ?></span><span class="fws-stat__value fws-num"><?= $e(ShKit::money($monthTotal)) ?></span>
        <span class="fws-stat__sub">Noted at the till · price book</span></a>
</div>

<div class="fws-section">
    <h2>Your lists</h2><span class="fws-section__rule"></span>
    <div class="fws-chips" role="tablist" aria-label="Show">
        <button class="fwm-chip is-on" type="button" data-filter="active" role="tab" aria-selected="true">Open</button>
        <button class="fwm-chip" type="button" data-filter="mine" role="tab" aria-selected="false">Mine</button>
        <button class="fwm-chip" type="button" data-filter="team" role="tab" aria-selected="false"><svg class="i"><use href="#i-users"/></svg>Team</button>
        <button class="fwm-chip" type="button" data-filter="done" role="tab" aria-selected="false"><svg class="i"><use href="#i-check"/></svg>Done</button>
    </div>
</div>
<div class="fws-cards" id="cards" aria-live="polite"></div>

<div class="fws-cols">
    <section class="fws-panel fws-panel--flush">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#i-clock"/></svg></span>
            <div class="fws-panel__title"><h3>Needed soon</h3><p>The next seven days, and anything late.</p></div></div>
        <?php if ($ov['needed']): ?>
        <ul class="fws-rows">
            <?php foreach ($ov['needed'] as $n): ?>
                <li><a href="/shopping/list.php?id=<?= (int)$n['list_id'] ?>#item-<?= (int)$n['id'] ?>">
                    <span class="fws-rows__date<?= $n['late'] ? ' is-late' : '' ?>"><b><?= $e(date('j', strtotime($n['due']))) ?></b><small><?= $e(date('M', strtotime($n['due']))) ?></small></span>
                    <span class="fws-rows__text"><b><?= $e($n['name']) ?><?= $n['urgent'] ? ' <span class="fws-flag fws-flag--danger">Urgent</span>' : '' ?></b><small><?= $e($n['qty'] . ' ' . ($n['unit'] === 'ea' ? '×' : $n['unit']) . ' · ' . $n['list']) ?></small></span>
                    <span class="fws-rows__side<?= $n['late'] ? ' is-late' : '' ?>"><?= $e($n['due_label']) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="fws-panel__empty">Nothing has a date coming up. Give a list or an item a “needed by” date and it shows here.</p>
        <?php endif; ?>
    </section>

    <section class="fws-panel fws-panel--flush">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#is-trolley"/></svg></span>
            <div class="fws-panel__title"><h3>Lately in the trolley</h3><p>What was bought, by whom and for how much.</p></div></div>
        <?php if ($ov['recent']): ?>
        <ul class="fws-rows">
            <?php foreach ($ov['recent'] as $r): ?>
                <li><a href="/shopping/list.php?id=<?= (int)$r['list_id'] ?>">
                    <span class="fws-rows__tick" aria-hidden="true"><svg class="i i--sm"><use href="#i-check"/></svg></span>
                    <span class="fws-rows__text"><b><?= $e($r['name']) ?></b><small><?= $e(implode(' · ', array_filter([$r['by'], $r['store'], $r['list']]))) ?></small></span>
                    <span class="fws-rows__side"><span class="fws-num"><?= $r['paid'] !== null ? $e(ShKit::money($r['paid'])) : '' ?></span><small data-ago="<?= $e($r['at']) ?>"></small></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="fws-panel__empty">Tick things off in the shop and they show here — note the price and the price book fills itself.</p>
        <?php endif; ?>
    </section>

    <section class="fws-panel">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#i-chart"/></svg></span>
            <div class="fws-panel__title"><h3><?= $e(date('F')) ?> by aisle</h3><p>Prices noted at the till this month.</p></div></div>
        <?php if ($ov['aisles']): $max = max(array_column($ov['aisles'], 'total')); ?>
        <ul class="fws-share">
            <?php foreach ($ov['aisles'] as $a): ?>
                <li style="--c:<?= $e($a['color']) ?>"><b><?= $e($a['label']) ?></b><span class="fws-num"><?= $e(ShKit::money($a['total'])) ?></span><i style="--p:<?= $max > 0 ? round($a['total'] / $max * 100) : 0 ?>%"></i></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="fws-panel__empty">Nothing noted yet this month.</p>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
