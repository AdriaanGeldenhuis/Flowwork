<?php
// /suppliers_ai/requests.php — every request for quotes: what is out with
// suppliers, what has quotes in to compare, what was awarded (and its order),
// what was closed. ?deleted=ID offers to bring back the one just deleted.
require_once __DIR__ . '/lib/SaBoot.php';

$counts = SaRequests::counts($DB, $SA_COMPANY_ID, $SA_USER_ID);
$page = 'requests';
$pageTitle = 'Requests for quotes';
$pageScripts = ['requests'];
$pageBoot = ['requests' => SaRequests::list($DB, $SA_COMPANY_ID, $SA_USER_ID, ['status' => 'all']), 'deleted' => (int)($_GET['deleted'] ?? 0)];
include __DIR__ . '/partials/head.php';
?>
<header class="fsa-head">
    <div class="fsa-head__text">
        <p class="fsa-eyebrow">Suppliers AI <i>◆</i> Requests for quotes</p>
        <h1>Requests for <em>quotes</em></h1>
        <p><?php if ($counts['active']): ?>
            <b><?= (int)$counts['open'] ?></b> waiting for quotes<?= $counts['drafts'] ? ', <b>' . (int)$counts['drafts'] . '</b> not sent yet' : '' ?><?= $counts['to_compare'] ? ' — <b>' . (int)$counts['to_compare'] . '</b> with quotes in to compare' : '' ?><?= $counts['late'] ? ' · <b class="fsa-late">' . (int)$counts['late'] . ' past the date</b>' : '' ?>.
        <?php else: ?>
            Nothing is out with suppliers right now. Find suppliers, shortlist the right ones and ask them all at once.
        <?php endif; ?></p>
    </div>
    <div class="fsa-head__actions">
        <?php if ($SA_CAPS['search']): ?><a class="btn btn--primary" href="/suppliers_ai/#find"><svg class="i"><use href="#isa-seal"/></svg>Find suppliers</a><?php endif; ?>
    </div>
</header>

<div class="fsa-toolbar">
    <div class="fsa-chips" role="tablist" aria-label="Show">
        <button class="fwm-chip is-on" type="button" role="tab" aria-selected="true" data-filter="active">Open</button>
        <button class="fwm-chip" type="button" role="tab" aria-selected="false" data-filter="compare"><svg class="i"><use href="#isa-scale"/></svg>To compare</button>
        <button class="fwm-chip" type="button" role="tab" aria-selected="false" data-filter="awarded"><svg class="i"><use href="#isa-medal"/></svg>Awarded</button>
        <button class="fwm-chip" type="button" role="tab" aria-selected="false" data-filter="done">Closed</button>
        <button class="fwm-chip" type="button" role="tab" aria-selected="false" data-filter="all">All</button>
    </div>
    <label class="fsa-check"><input type="checkbox" id="onlyMine"> Only mine</label>
    <label class="fsa-search"><svg class="i i--sm"><use href="#i-search"/></svg><span class="sr-only">Filter</span><input id="reqFilter" placeholder="Filter by what" autocomplete="off"></label>
</div>
<div class="fsa-reqs" id="reqs" aria-live="polite"></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
