<?php
// /shopping/templates.php — reusable lists for the things bought again and
// again. Use one (a new list in a tap), make one by typing it out or from a
// list ("Save as a template" on the list), change it, delete it.
require_once __DIR__ . '/lib/ShBoot.php';

$templates = ShTemplates::all($DB, $SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE);
$page = 'templates';
$pageTitle = 'Templates';
$pageScripts = ['templates'];
$pageBoot = ['templates' => $templates];
include __DIR__ . '/partials/head.php';
?>
<header class="fws-head">
    <div class="fws-head__text">
        <p class="fws-eyebrow">Shopping <i>◆</i> Templates</p>
        <h1>The usual</h1>
        <p>Lists you buy again and again — the monthly office run, the site start-up kit, the braai. Start a list from one in a tap; everyone in the company can use them.</p>
    </div>
    <?php if ($SH_CAPS['write']): ?>
    <div class="fws-head__actions"><button class="btn btn--primary" type="button" id="tplNew"><svg class="i"><use href="#i-plus"/></svg>New template</button></div>
    <?php endif; ?>
</header>
<div class="fws-tpls" id="tpls" aria-live="polite"></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
