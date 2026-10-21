<?php
// /suppliers_ai/history.php — every search the team ran, newest first: what
// was asked, where, how many were found, what was done with them. Open one
// again (as it was found, documents as they are now) or search again.
require_once __DIR__ . '/lib/SaBoot.php';

$page = 'history';
$pageTitle = 'Searches';
$pageScripts = ['history'];
$pageBoot = ['history' => SaSearch::history($DB, $SA_COMPANY_ID, $SA_USER_ID, ['page' => (int)($_GET['page'] ?? 1)])];
include __DIR__ . '/partials/head.php';
?>
<header class="fsa-head">
    <div class="fsa-head__text">
        <p class="fsa-eyebrow">Suppliers AI <i>◆</i> Searches</p>
        <h1>Every <em>search</em></h1>
        <p>What the team looked for, what was found and what came of it. Open a search to shortlist from it again, or run it again for today’s results.</p>
    </div>
    <div class="fsa-head__actions">
        <?php if ($SA_CAPS['search']): ?><a class="btn btn--primary" href="/suppliers_ai/#find"><svg class="i"><use href="#isa-seal"/></svg>New search</a><?php endif; ?>
    </div>
</header>
<div class="fsa-toolbar">
    <div class="fsa-chips" role="tablist" aria-label="Whose">
        <button class="fwm-chip is-on" type="button" role="tab" aria-selected="true" data-mine="0"><svg class="i"><use href="#i-users"/></svg>Everyone</button>
        <button class="fwm-chip" type="button" role="tab" aria-selected="false" data-mine="1">Mine</button>
    </div>
    <label class="fsa-search"><svg class="i i--sm"><use href="#i-search"/></svg><span class="sr-only">Filter</span><input id="histFilter" placeholder="Filter by words" autocomplete="off"></label>
</div>
<section class="fsa-panel fsa-panel--flush"><ul class="fsa-hist" id="hist" aria-live="polite"></ul></section>
<div class="fsa-pager" id="pager"></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
