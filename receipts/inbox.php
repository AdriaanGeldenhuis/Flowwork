<?php
// /receipts/inbox.php — every receipt as a card: to do (being read, to
// review, draft bills), held by a check, in the books, set aside, all.
// Search by supplier, number, note or amount; bookkeepers can select cards
// to read again, set aside or delete in one go. Members see their own.
require_once __DIR__ . '/lib/RcBoot.php';

$views = [
    'todo' => [RcKit::canSeeAll($RC_ROLE) ? 'To do' : 'My receipts', 'Being read, to review and draft bills — oldest first.'],
    'review' => ['To review', 'Read and waiting for someone to check them.'],
    'reading' => ['Being read', 'Sent in moments ago, or waiting to be read.'],
    'draft' => ['Draft bills', 'Saved as bills in Finance, not posted yet.'],
    'blocked' => ['Held by a check', 'A missing VAT number, compliance document or purchase order is holding these bills back.'],
    'done' => ['In the books', 'Posted to Finance — and paid, when they were paid on the spot.'],
    'aside' => ['Set aside', 'Duplicates, personal slips and documents that are not bills. Bring one back if it was a mistake.'],
    'all' => ['Every receipt', 'Everything sent in.'],
];
$view = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'todo';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$page = 'inbox';
$navView = $view;
$pageTitle = $views[$view][0];
$pageScripts = ['inbox'];
$pageBoot = ['view' => $view, 'q' => $q, 'sort' => (string)($_GET['sort'] ?? ''), 'views' => array_map(fn($v) => $v[0], $views), 'aside' => RcKit::ASIDE_REASONS];
include __DIR__ . '/partials/head.php';
?>
<header class="fwr-head">
    <div class="fwr-head__text">
        <p class="fwr-eyebrow">Receipts <i>◆</i> <span id="viewEyebrow"><?= $e($views[$view][0]) ?></span></p>
        <h1 id="viewTitle"><?= $e($views[$view][0]) ?></h1>
        <p id="viewLead"><?= $e($views[$view][1]) ?></p>
    </div>
    <div class="fwr-head__actions">
        <?php if (RcKit::canSeeAll($RC_ROLE)): ?>
            <a class="btn" id="exportBtn" href="/receipts/api/receipts.php?op=export&view=<?= $e($view) ?>"><svg class="i"><use href="#i-download"/></svg>Export</a>
        <?php endif; ?>
        <?php if ($RC_CAPS['upload']): ?>
            <button class="btn btn--primary" type="button" data-capture="pick"><svg class="i"><use href="#ir-drop"/></svg>Send in</button>
        <?php endif; ?>
    </div>
</header>

<div class="fwr-chips" role="tablist" aria-label="Show" id="chips">
    <?php foreach (['todo', 'review', 'draft', 'blocked', 'done', 'aside', 'all'] as $k): ?>
        <a class="fwr-chip<?= $k === $view ? ' is-on' : '' ?><?= $k === 'blocked' ? ' is-alert' : '' ?>" href="?view=<?= $e($k) ?>" data-view="<?= $e($k) ?>" role="tab" aria-selected="<?= $k === $view ? 'true' : 'false' ?>"><?= $e($views[$k][0]) ?> <small data-chip="<?= $e($k) ?>">·</small></a>
    <?php endforeach; ?>
</div>

<div class="fwr-tools">
    <label class="fwr-search"><svg class="i"><use href="#i-search"/></svg><span class="sr-only">Search these receipts</span>
        <input type="search" id="q" value="<?= $e($q) ?>" placeholder="Supplier, number, note or amount" autocomplete="off"></label>
    <select class="select" id="sort" style="width:auto;height:38px" aria-label="Order">
        <option value="">Usual order</option>
        <option value="new">Newest first</option>
        <option value="old">Oldest first</option>
        <option value="date">By slip date</option>
        <option value="amount">Largest first</option>
    </select>
    <span class="fwr-grow"></span>
    <?php if ($RC_CAPS['capture']): ?>
        <button class="btn btn--ghost btn--sm" type="button" id="selectAll"><svg class="i"><use href="#i-check-square"/></svg>Select all</button>
    <?php endif; ?>
</div>
<p class="fwr-sum" id="sum" aria-live="polite"></p>

<div class="fwr-cards" id="cards" aria-live="polite"><div class="fwr-loading"><span class="fwr-spin"></span>Loading receipts…</div></div>
<div class="fwr-more" id="more" hidden><button class="btn" type="button" id="moreBtn">Show more</button></div>

<?php if ($RC_CAPS['capture']): ?>
<div class="fwr-bulk" id="bulk" role="region" aria-label="Selected receipts">
    <span class="fwr-bulk__count" id="bulkCount">0 selected</span>
    <button class="btn btn--sm" type="button" data-bulk="read"><svg class="i"><use href="#ir-scan"/></svg>Read again</button>
    <button class="btn btn--sm" type="button" data-bulk="aside"><svg class="i"><use href="#i-archive"/></svg>Set aside</button>
    <button class="btn btn--sm" type="button" data-bulk="restore" hidden><svg class="i"><use href="#i-undo"/></svg>Bring back</button>
    <button class="btn btn--sm btn--danger" type="button" data-bulk="delete"><svg class="i"><use href="#i-trash"/></svg>Delete</button>
    <button class="btn btn--sm btn--ghost" type="button" data-bulk="clear" aria-label="Clear the selection"><svg class="i"><use href="#i-x"/></svg></button>
</div>
<?php endif; ?>
<?php include __DIR__ . '/partials/foot.php'; ?>
