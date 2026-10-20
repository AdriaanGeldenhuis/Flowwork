<?php
// /shopping/preferences.php — how Shopping works for you (in the shop, lists,
// directions) and, for admins, the company's AI store ideas. Saved through
// /shopping/api/settings.php; the save bar appears when something changed.
require_once __DIR__ . '/lib/ShBoot.php';

$stores = ShStores::all($DB, $SH_COMPANY_ID, $SH_USER_ID);
$aiOn = ShKit::companySetting($DB, $SH_COMPANY_ID, ShKit::AI_KEY, '0') === '1';
$aiKey = ShKit::aiKey($DB, $SH_COMPANY_ID) !== null;
$town = ShKit::companyTown($DB, $SH_COMPANY_ID);
$p = $SH_PREFS;
$page = 'prefs';
$pageTitle = 'Preferences';
$pageScripts = ['prefs'];
include __DIR__ . '/partials/head.php';
?>
<header class="fws-head">
    <div class="fws-head__text">
        <p class="fws-eyebrow">Shopping <i>◆</i> Preferences</p>
        <h1>Your way</h1>
        <p>How Shopping works for you. These are yours alone<?= $SH_CAPS['admin'] ? ', except the company settings at the bottom' : '' ?>.</p>
    </div>
</header>
<form id="prefs" novalidate>
    <section class="fws-panel">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#is-trolley"/></svg></span><div class="fws-panel__title"><h3>In the shop</h3><p>When you press “Start shopping”.</p></div></div>
        <label class="fws-set fws-set--toggle"><span class="fws-set__text"><b>Ask what it cost as I tick</b><small>A small box under the item: type the shelf price (or skip). It fills the price book and keeps the budget honest.</small></span>
            <span class="switch"><input type="checkbox" name="price_prompt"<?= $p['price_prompt'] ? ' checked' : '' ?>><span class="switch__track"></span></span></label>
        <label class="fws-set fws-set--toggle"><span class="fws-set__text"><b>Keep the screen on</b><small>The phone does not lock while a list is being shopped — no unlocking with full hands.</small></span>
            <span class="switch"><input type="checkbox" name="keep_awake"<?= $p['keep_awake'] ? ' checked' : '' ?>><span class="switch__track"></span></span></label>
        <div class="fws-set"><span class="fws-set__text"><b>Where I usually shop</b><small>Picked when you start shopping. Prices are kept for the store you are at.</small></span>
            <span class="fws-set__control"><select class="select" name="home_store_id"><option value="0">Ask me each time</option>
                <?php foreach ($stores as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$p['home_store_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= $e($s['name']) ?></option><?php endforeach; ?>
            </select></span></div>
    </section>

    <section class="fws-panel">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#is-list"/></svg></span><div class="fws-panel__title"><h3>Lists</h3></div></div>
        <div class="fws-set"><span class="fws-set__text"><b>Show items</b><small>By aisle walks the shop in order; as added keeps your own order.</small></span>
            <span class="fws-set__control"><select class="select" name="group_by"><?php foreach (ShKit::GROUP_BY as $k => $l): ?><option value="<?= $e($k) ?>"<?= $p['group_by'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></span></div>
        <div class="fws-set"><span class="fws-set__text"><b>New lists are for</b><small>Supplies & materials lists start shared with the team.</small></span>
            <span class="fws-set__control"><select class="select" name="default_purpose"><?php foreach (ShKit::PURPOSES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $p['default_purpose'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></span></div>
    </section>

    <section class="fws-panel">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#is-map"/></svg></span><div class="fws-panel__title"><h3>Directions</h3><p>The map buttons in “Where to buy”.</p></div></div>
        <div class="fws-set"><span class="fws-set__text"><b>I get there by</b></span>
            <span class="fws-set__control"><select class="select" name="route_mode"><?php foreach (ShKit::ROUTE_MODES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $p['route_mode'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></span></div>
    </section>

    <?php if ($SH_CAPS['admin']): ?>
    <section class="fws-panel">
        <div class="fws-panel__head"><span class="fws-panel__icon"><svg class="i"><use href="#i-sparkle"/></svg></span><div class="fws-panel__title"><h3>For the company</h3><p>Admins only.</p></div></div>
        <label class="fws-set fws-set--toggle<?= $aiKey ? '' : ' is-off' ?>"><span class="fws-set__text"><b>AI store ideas</b>
            <small>“Where to buy” can ask OpenAI which kinds of shop sell something near <?= $town !== '' ? $e($town) : 'your town' ?>. Only the item’s name and the town are sent — never prices, people or the list. Ideas are marked as ideas; no phone numbers or prices are made up. At most 40 an hour.
            <?= $aiKey ? '' : '<br><b>Needs the company’s OpenAI key</b> — add it under <a href="/suppliers_ai/settings.php">Suppliers AI → Settings</a>.' ?></small></span>
            <span class="switch"><input type="checkbox" name="ai"<?= $aiOn ? ' checked' : '' ?><?= $aiKey ? '' : ' disabled' ?>><span class="switch__track"></span></span></label>
    </section>
    <?php endif; ?>
</form>
<?php include __DIR__ . '/partials/foot.php'; ?>
