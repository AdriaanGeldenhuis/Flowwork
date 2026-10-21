<?php
// /suppliers_ai/request.php?id= — one request for quotes: ask the suppliers
// (email through Mail, WhatsApp, a call), see who replied, write down their
// quotes, compare them with VAT and against the budget, choose one, thank the
// others and make the purchase order. ?ask=1 opens "Ask suppliers" straight away.
require_once __DIR__ . '/lib/SaBoot.php';

$id = (int)($_GET['id'] ?? 0);
try {
    $data = SaRequests::get($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id);
} catch (SaProblem $ex) {
    http_response_code(404);
    $page = 'requests';
    $pageTitle = 'Not found';
    include __DIR__ . '/partials/head.php';
    echo '<div class="fsa-blank"><svg class="i"><use href="#isa-quote"/></svg><h3>That request is not here</h3><p>It may have been deleted, or it belongs to another company. <a href="/suppliers_ai/requests.php">All requests</a></p></div>';
    include __DIR__ . '/partials/foot.php';
    exit;
}
$req = $data['request'];
$page = 'requests';
$pageTitle = $req['title'];
$activeRequestId = $id;
$pageScripts = ['request'];
$pageBoot = ['data' => $data, 'ask' => !empty($_GET['ask'])];
include __DIR__ . '/partials/head.php';
?>
<a class="fsa-back" href="/suppliers_ai/requests.php"><svg class="i i--sm"><use href="#i-arrow-left"/></svg>Requests for quotes</a>
<div id="reqApp" data-id="<?= (int)$id ?>">
    <header class="fsa-reqhead">
        <p class="fsa-eyebrow"><?= $req['trade'] ? $e($req['trade']['label']) : 'Request for quotes' ?> <i>◆</i> <?= $e($req['status_label']) ?></p>
        <h1><?= $e($req['title']) ?></h1>
    </header>
    <noscript><p>Suppliers AI needs JavaScript to work with requests.</p></noscript>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
