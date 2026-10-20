<?php
// /receipts/index.php — Receipts at a glance: send slips in (drop, paste,
// snap), what is waiting and since when, where the money from receipts went
// (by month, supplier, account and project) and what needs a person.
// Old links (?tab=inbox, ?tab=approved …) go to the matching inbox view.
require_once __DIR__ . '/lib/RcBoot.php';

$oldTabs = ['inbox' => 'todo', 'exceptions' => 'blocked', 'approved' => 'done', 'all' => 'all'];
if (isset($_GET['tab'], $oldTabs[$_GET['tab']])) {
    header('Location: /receipts/inbox.php?view=' . $oldTabs[$_GET['tab']], true, 302);
    exit;
}

$ov = RcReceipts::overview($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE);
$money = fn($rand) => RcKit::money((int)round((float)$rand * 100));
$all = RcKit::canSeeAll($RC_ROLE);
$st = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$st->execute([$RC_USER_ID]);
$first = trim((string)$st->fetchColumn());
$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
$readerLabel = ['ai' => 'Slips are read by AI', 'tesseract' => 'Slips are read on the server', 'manual' => 'Details are typed in'][$RC_SETTINGS['ocr_provider']] ?? '';
$maxBar = max(1.0, max(array_column($ov['months'], 'total')));
$delta = $ov['last_month'] > 0 ? ($ov['this_month'] - $ov['last_month']) / $ov['last_month'] * 100 : null;
$queue = $ov['queue'];

$page = 'index';
$pageTitle = 'Overview';
$pageScripts = ['overview'];
$pageBoot = ['overview' => ['todo' => $ov['todo']]];
include __DIR__ . '/partials/head.php';
?>
<header class="fwr-head">
    <div class="fwr-head__text">
        <p class="fwr-eyebrow">Receipts <i>◆</i> <?= $e(date('l j F')) ?></p>
        <h1><?= $e($greet) ?><?= $first !== '' ? ', <em>' . $e($first) . '</em>' : '' ?></h1>
        <p><?php if ($ov['todo']): ?>
            <b><?= (int)$ov['todo'] ?></b> <?= $ov['todo'] === 1 ? 'receipt is' : 'receipts are' ?> waiting<?php if ($ov['todo_amount'] > 0): ?>, worth <b><?= $e($money($ov['todo_amount'])) ?></b><?php endif; ?>
            <?php if ($ov['oldest']): ?> — the oldest came in on <?= $e(RcKit::date(substr($ov['oldest'], 0, 10))) ?><?php endif; ?>.
        <?php elseif ($ov['total']): ?>
            Everything is reviewed. Snap the next slip the moment you get it — it is read for you.
        <?php else: ?>
            Send in your first slip: drop a PDF or photo anywhere on this page, paste a screenshot, or snap one with your phone.
        <?php endif; ?></p>
    </div>
    <div class="fwr-head__actions">
        <?php if ($RC_CAPS['capture'] && $ov['todo']): ?>
            <a class="btn btn--primary" href="/receipts/review.php?id=<?= (int)($queue[0]['id'] ?? 0) ?>" data-start-review><svg class="i"><use href="#i-eye"/></svg>Start reviewing</a>
        <?php endif; ?>
        <a class="btn" href="/receipts/inbox.php"><svg class="i"><use href="#i-inbox"/></svg><?= $all ? 'To do' : 'My receipts' ?></a>
    </div>
</header>

<?php if ($RC_CAPS['upload']): ?>
<section class="fwr-capture" id="captureCard" aria-label="Send in receipts">
    <span class="fwr-capture__art" aria-hidden="true"><svg class="i"><use href="#ir-seal"/></svg></span>
    <div class="fwr-capture__text">
        <h2><span class="fwr-on-wide">Drop slips here</span><span class="fwr-on-narrow">Snap your slips</span></h2>
        <p><span class="fwr-on-wide">PDFs, photos and ZIPs — or <b>paste a screenshot</b> with Ctrl V.</span><span class="fwr-on-narrow">Take a photo the moment you pay, or choose PDFs and photos.</span> Each one is read and becomes a bill you only need to check.</p>
        <span class="fwr-reader-chip"><svg class="i"><use href="#<?= $RC_SETTINGS['ocr_provider'] === 'ai' ? 'ir-ai' : 'ir-scan' ?>"/></svg><?= $e($readerLabel) ?><?php if ($RC_CAPS['settings']): ?> · <a href="/receipts/settings.php#reading">change</a><?php endif; ?></span>
    </div>
    <div class="fwr-capture__acts">
        <button class="btn btn--primary" type="button" data-capture="camera"><svg class="i"><use href="#ir-camera"/></svg>Take a photo</button>
        <button class="btn" type="button" data-capture="pick"><svg class="i"><use href="#i-upload"/></svg>Choose files</button>
    </div>
</section>
<?php endif; ?>

<div class="fwr-stats">
    <a class="fwr-stat" href="/receipts/inbox.php?view=review" style="--i:0"><span class="fwr-stat__label">To review</span><span class="fwr-stat__value" data-stat="review"><?= (int)($ov['review'] + $ov['reading']) ?></span>
        <span class="fwr-stat__sub"><?= $ov['reading'] ? (int)$ov['reading'] . ' still being read' : 'Read and ready to check' ?></span></a>
    <a class="fwr-stat" href="/receipts/inbox.php?view=draft" style="--i:1"><span class="fwr-stat__label">Draft bills</span><span class="fwr-stat__value"><?= (int)$ov['draft'] ?></span>
        <span class="fwr-stat__sub">Saved, not posted yet</span></a>
    <div class="fwr-stat" style="--i:2"><span class="fwr-stat__label"><?= $e(date('F')) ?></span><span class="fwr-stat__value fwr-num"><?= $e($money($ov['this_month'])) ?></span>
        <span class="fwr-stat__sub"><?php if ($delta !== null): ?><span class="<?= $delta > 0 ? 'is-up' : 'is-down' ?>"><?= $delta > 0 ? '▲' : '▼' ?> <?= abs(round($delta)) ?>%</span> on <?= $e(date('F', strtotime('first day of last month'))) ?><?php else: ?>In the books from receipts<?php endif; ?></span></div>
    <a class="fwr-stat" href="/receipts/inbox.php?view=done" style="--i:3"><span class="fwr-stat__label">In the books</span><span class="fwr-stat__value"><?= (int)$ov['done'] ?></span>
        <span class="fwr-stat__sub"><?= $ov['aside'] ? (int)$ov['aside'] . ' set aside' : 'Posted to Finance' ?></span></a>
</div>

<div class="fwr-cols">
    <div>
        <section class="fwr-panel fwr-panel--flush" id="queuePanel">
            <?php if ($ov['todo']): ?>
                <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#ir-queue"/></svg></span>
                    <div class="fwr-panel__title"><h3>Waiting for you</h3><p><?= $RC_CAPS['capture'] ? 'Check what was read, choose the account, post. j and k move to the next one.' : 'Your bookkeeper turns these into bills.' ?></p></div>
                    <a class="fwr-mini" href="/receipts/inbox.php">All <?= (int)$ov['todo'] ?></a></div>
                <div class="fwr-panel__body">
                    <ul class="fwr-rows" id="queueRows">
                        <?php foreach (array_slice($queue, 0, 6) as $r): ?>
                            <li><a href="/receipts/review.php?id=<?= (int)$r['id'] ?>">
                                <span class="fwr-rows__thumb"><?php if ($r['thumb']): ?><img src="<?= $e($r['thumb']) ?>" alt="" loading="lazy"><?php else: ?><svg class="i"><use href="#ir-pdf"/></svg><?php endif; ?></span>
                                <span class="fwr-rows__text"><b><?= $e($r['supplier'] ?: ($r['vendor'] ?: ($r['state'] === 'reading' ? 'Being read…' : 'Supplier not known yet'))) ?></b>
                                    <small><?= $e(implode(' · ', array_filter([$r['number'] ? '#' . $r['number'] : '', $r['date'] ? RcKit::date($r['date'], false) : '', $all ? $r['uploader'] : '']))) ?: 'Sent in ' . $e(RcKit::date(substr($r['uploaded_at'], 0, 10), false)) ?></small></span>
                                <span class="fwr-rows__side"><span class="fwr-money"><?= $r['amount'] !== null ? $e($money($r['amount'])) : '' ?></span>
                                    <span class="fwr-pill" data-tone="<?= $e(RcKit::STATUS_TONE[$r['state']]) ?>"><?= $e($r['state_label']) ?></span></span>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="fwr-zero">
                    <span class="fwr-zero__seal" aria-hidden="true"><svg class="i"><use href="#i-check"/></svg></span>
                    <div><h3>Inbox zero</h3><p><?= $ov['total'] ? 'Every receipt is reviewed. Nothing is waiting.' : 'No receipts yet. The first one you send in shows up here.' ?></p></div>
                </div>
            <?php endif; ?>
        </section>

        <section class="fwr-panel">
            <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-chart"/></svg></span>
                <div class="fwr-panel__title"><h3>Spend from receipts</h3><p>Bills made from receipts and in the books, by month.</p></div></div>
            <?php if (array_sum(array_column($ov['months'], 'total')) <= 0): ?>
                <div class="fwr-bars__empty">The first receipt you post shows here.</div>
            <?php else: ?>
                <div class="fwr-bars" role="img" aria-label="Spend from receipts over the last 12 months">
                    <?php foreach ($ov['months'] as $i => $m): ?>
                        <div class="fwr-bar<?= $i === 11 ? ' is-now' : '' ?>"><i style="--h:<?= max(3, round($m['total'] / $maxBar * 130)) ?>px" data-v="<?= $e(date('F Y', strtotime($m['ym'] . '-01')) . ': ' . $money($m['total']) . ' · ' . $m['n'] . ' ' . ($m['n'] === 1 ? 'bill' : 'bills')) ?>"></i><small><?= $e($m['label']) ?></small></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="fwr-panel__body"></div>
        </section>

        <?php if ($ov['recent']): ?>
        <section class="fwr-panel fwr-panel--flush">
            <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-clock"/></svg></span>
                <div class="fwr-panel__title"><h3>Recently sent in</h3></div><a class="fwr-mini" href="/receipts/inbox.php?view=all">Every receipt</a></div>
            <div class="fwr-panel__body">
                <ul class="fwr-rows" id="recentRows">
                    <?php foreach (array_slice($ov['recent'], 0, 6) as $r): ?>
                        <li><a href="/receipts/review.php?id=<?= (int)$r['id'] ?>">
                            <span class="fwr-rows__thumb"><?php if ($r['thumb']): ?><img src="<?= $e($r['thumb']) ?>" alt="" loading="lazy"><?php else: ?><svg class="i"><use href="#ir-pdf"/></svg><?php endif; ?></span>
                            <span class="fwr-rows__text"><b><?= $e($r['supplier'] ?: ($r['vendor'] ?: $r['name'])) ?></b><small><?= $e(($all && $r['uploader'] ? $r['uploader'] . ' · ' : '') . RcKit::date(substr($r['uploaded_at'], 0, 10))) ?><?= $r['note'] ? ' · “' . $e(mb_strimwidth($r['note'], 0, 60, '…')) . '”' : '' ?></small></span>
                            <span class="fwr-rows__side"><span class="fwr-money"><?= $r['amount'] !== null ? $e($money($r['amount'])) : '' ?></span><span class="fwr-pill" data-tone="<?= $e(RcKit::STATUS_TONE[$r['state']]) ?>"><?= $e($r['state_label']) ?></span></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php endif; ?>
    </div>
    <div>
        <section class="fwr-panel">
            <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-alert"/></svg></span>
                <div class="fwr-panel__title"><h3>Needs attention</h3><p><?= $ov['attention'] ? 'Sort these out so nothing slips through.' : 'Nothing is stuck.' ?></p></div></div>
            <div class="fwr-steps">
                <?php foreach ($ov['attention'] as $a): ?>
                    <a class="fwr-step" href="<?= $e($a['href']) ?>"><span class="fwr-step__dot"><svg class="i"><use href="#<?= $e($a['icon']) ?>"/></svg></span>
                        <span class="fwr-step__text"><b><?= $e($a['title']) ?></b><small><?= $e($a['text']) ?></small></span><svg class="i"><use href="#i-chev-right"/></svg></a>
                <?php endforeach; ?>
                <?php if (!$ov['attention']): ?>
                    <div class="fwr-step is-done"><span class="fwr-step__dot"><svg class="i"><use href="#i-check"/></svg></span><span class="fwr-step__text"><b>All clear</b><small>No unread slips, held bills or old receipts.</small></span></div>
                <?php endif; ?>
            </div>
            <div class="fwr-panel__body"></div>
        </section>

        <section class="fwr-panel">
            <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-building"/></svg></span>
                <div class="fwr-panel__title"><h3>Where it went</h3><p>The last 90 days, from receipts.</p></div>
                <?php if ($ov['suppliers'] && $ov['accounts']): ?>
                <div class="seg" role="tablist" aria-label="Group by"><button type="button" class="is-on" data-share="suppliers" role="tab" aria-selected="true">Suppliers</button><button type="button" data-share="accounts" role="tab" aria-selected="false">Accounts</button></div>
                <?php endif; ?>
            </div>
            <?php foreach (['suppliers' => $ov['suppliers'], 'accounts' => $ov['accounts']] as $k => $list):
                $max = $list ? max(array_column($list, 'total')) : 1; ?>
                <ul class="fwr-share" data-share-list="<?= $k ?>"<?= $k === 'accounts' ? ' hidden' : '' ?>>
                    <?php foreach ($list as $s): ?>
                        <li><b><?= $e($s['name']) ?></b><span class="fwr-money"><?= $e($money($s['total'])) ?></span><i style="--p:<?= $max > 0 ? round($s['total'] / $max * 100) : 0 ?>%"></i></li>
                    <?php endforeach; ?>
                    <?php if (!$list): ?><li class="fwr-muted" style="display:block">Nothing posted from receipts in the last 90 days.</li><?php endif; ?>
                </ul>
            <?php endforeach; ?>
        </section>

        <?php if ($ov['projects']): ?>
        <section class="fwr-panel">
            <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-briefcase"/></svg></span>
                <div class="fwr-panel__title"><h3>Project costs</h3><p>Receipts booked to projects, last 90 days.</p></div></div>
            <ul class="fwr-share">
                <?php $max = max(array_column($ov['projects'], 'total')); foreach ($ov['projects'] as $p): ?>
                    <li><b><a href="<?= $e($p['url']) ?>"><?= $e($p['name']) ?></a></b><span class="fwr-money"><?= $e($money($p['total'])) ?></span><i style="--p:<?= $max > 0 ? round($p['total'] / $max * 100) : 0 ?>%"></i></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
