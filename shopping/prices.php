<?php
// /shopping/prices.php — the price book (what everything cost, last and best,
// where, and whether it went up) and the stores (with what was bought there;
// linked to the CRM supplier when they are one). #stores opens on the stores.
require_once __DIR__ . '/lib/ShBoot.php';

$book = ShStores::priceBook($DB, $SH_COMPANY_ID, $SH_USER_ID);
$stores = ShStores::all($DB, $SH_COMPANY_ID, $SH_USER_ID);
$suppliers = [];
if ($SH_CAPS['write']) {
    $st = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL ORDER BY name LIMIT 1000");
    $st->execute([$SH_COMPANY_ID]);
    $suppliers = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
}
$up = count(array_filter($book, fn($b) => $b['trend'] > 0));
$page = 'prices';
$pageTitle = 'Prices & stores';
$pageScripts = ['prices'];
$pageBoot = ['book' => $book, 'stores' => $stores, 'suppliers' => $suppliers, 'town' => ShKit::companyTown($DB, $SH_COMPANY_ID)];
include __DIR__ . '/partials/head.php';
?>
<header class="fws-head">
    <div class="fws-head__text">
        <p class="fws-eyebrow">Shopping <i>◆</i> Prices &amp; stores</p>
        <h1>What things cost</h1>
        <p><?php if ($book): ?>
            <b><?= count($book) ?></b> <?= count($book) === 1 ? 'thing' : 'things' ?> in the price book<?= $up ? ' — <b>' . $up . '</b> cost more than the time before' : '' ?>. Every price noted at the till lands here, with the store, so you know where it is cheapest.
        <?php else: ?>
            Tick things off with what they cost and the price book fills itself: the last price, the best price and where, and whether it went up.
        <?php endif; ?></p>
    </div>
</header>
<div class="fws-tabs seg" role="tablist" aria-label="Show">
    <button type="button" class="is-on" data-tab="book" role="tab" aria-selected="true">Price book</button>
    <button type="button" data-tab="stores" role="tab" aria-selected="false">Stores (<?= count($stores) ?>)</button>
</div>
<section id="tabBook" class="fws-panel fws-panel--flush" aria-label="Price book">
    <div class="fws-panel__head">
        <label class="fws-search"><svg class="i"><use href="#i-search"/></svg><span class="sr-only">Find in the price book</span><input type="search" id="bookQ" placeholder="Find cement, milk, gloves…" autocomplete="off"></label>
        <span class="fws-grow"></span>
        <div class="seg" role="tablist" aria-label="Sort"><button type="button" class="is-on" data-sort="recent">Latest</button><button type="button" data-sort="up">Went up</button><button type="button" data-sort="az">A–Z</button></div>
    </div>
    <div class="fws-book__wrap scroll"><table class="fws-book" id="book"></table></div>
</section>
<section id="tabStores" hidden aria-label="Stores">
    <?php if ($SH_CAPS['write']): ?><div class="fws-head__actions" style="margin-bottom:14px"><button class="btn btn--primary" type="button" id="storeNew"><svg class="i"><use href="#i-plus"/></svg>Add a store</button></div><?php endif; ?>
    <div class="fws-stores" id="stores"></div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
