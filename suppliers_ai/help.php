<?php
// /suppliers_ai/help.php — how Suppliers AI works: saying what you need, where
// results come from and why they rank where they do, asking for quotes,
// replies, comparing and awarding, and what leaves Flowwork (and what never does).
require_once __DIR__ . '/lib/SaBoot.php';

$page = 'help';
$pageTitle = 'How it works';
include __DIR__ . '/partials/head.php';
$examples = [
    'Need 3 plumbers in Vanderbijlpark for geysers tomorrow under R5k',
    '20 cubes river sand delivered to Sasolburg by Friday',
    'Electrician with COC near Centurion, next week',
    '19mm crushed stone, 10 tons, CIDB grade 5GB, B-BBEE level 1',
    'Ons soek ’n loodgieter naby Meyerton môre, begroting R8000',
];
?>
<header class="fsa-head">
    <div class="fsa-head__text">
        <p class="fsa-eyebrow">Suppliers AI <i>◆</i> How it works</p>
        <h1>From <em>“we need…”</em> to an order</h1>
        <p>Say what you need, see who can do it and why, ask them all for quotes in one go, compare, choose — and the purchase order is ready.</p>
    </div>
    <div class="fsa-head__actions"><?php if ($SA_CAPS['search']): ?><a class="btn btn--primary" href="/suppliers_ai/#find"><svg class="i"><use href="#isa-seal"/></svg>Find suppliers</a><?php endif; ?></div>
</header>

<ol class="fsa-steps">
    <li class="fsa-panel">
        <span class="fsa-steps__n">1</span>
        <h3>Say what you need</h3>
        <p>In your own words, in English or Afrikaans. As you type, Suppliers AI reads the trade, the place (also misspelt), how many or how much, when it is needed, a budget and requirements such as a COC, a B-BBEE level, a CIDB grade, insurance or delivery to site.</p>
        <ul class="fsa-examples">
            <?php foreach ($examples as $x): ?><li><a href="/suppliers_ai/?q=<?= rawurlencode($x) ?>">“<?= $e($x) ?>”</a></li><?php endforeach; ?>
        </ul>
    </li>
    <li class="fsa-panel">
        <span class="fsa-steps__n">2</span>
        <h3>See who can do it — and why</h3>
        <p><b>Your suppliers come first</b>: their industry, tags, name and your notes; whether they are preferred; what you bought from them before (bills in Finance, orders in Procurement); how they did on past requests (who quoted, who won, who never replied); delivery figures; and their compliance documents from the CRM.</p>
        <p><b>Businesses near the site</b> come from Google with the company’s own key: phone, website, rating and how far. <b>AI ideas</b> are kinds of business and well-known chains to look at — names only, with a link to look them up.</p>
        <p class="fsa-note"><svg class="i"><use href="#i-shield"/></svg> Nothing is made up. A phone number or email shows only when it is in your CRM or listed by Google. (The old Suppliers AI asked the AI for phone numbers, and stored the ones it invented. Those old searches are marked.)</p>
    </li>
    <li class="fsa-panel">
        <span class="fsa-steps__n">3</span>
        <h3>Shortlist and ask for quotes</h3>
        <p>Add the right ones to the shortlist and choose <b>Ask for quotes</b>. Say what, where, by when and when you need the quotes. A Google find becomes a supplier in the CRM — a <b>prospect</b>, tagged with its trade — so its emails and replies are kept with it.</p>
        <p>Each supplier gets their own email from your mailbox in Mail (they never see each other), filed in your Sent folder. No email address? Send it by WhatsApp in one tap, or note that you phoned.</p>
    </li>
    <li class="fsa-panel">
        <span class="fsa-steps__n">4</span>
        <h3>Replies, quotes and the winner</h3>
        <p>Replies are noticed by themselves — in the conversation, or a new email from the supplier — and you are told. Write down each quote (with or without VAT, the lead time, until when it is valid) and they are compared like with like, against your budget. Remind those who have not answered.</p>
        <p>Choose one: a prospect becomes an active supplier, and admins and bookkeepers make the <b>purchase order</b> in Procurement (numbered from your document sequence). Thank the others in one go.</p>
    </li>
</ol>

<section class="fsa-section"><h2>What leaves Flowwork</h2><span class="fsa-section__rule"></span></section>
<div class="fsa-cols">
    <section class="fsa-panel"><div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-sparkle"/></svg></span><div class="fsa-panel__title"><h3>To OpenAI</h3><p>Only when an admin switched AI on.</p></div></div>
        <p class="fsa-panel__body">The words of the request, today’s date and your town — to read it and suggest kinds of business. To write an email: the request’s title, details, quantity, place and dates. <b>Never</b> the budget, your suppliers or their details.</p></section>
    <section class="fsa-panel"><div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-globe"/></svg></span><div class="fsa-panel__title"><h3>To Google</h3><p>Only with the company’s Google key.</p></div></div>
        <p class="fsa-panel__body">The search words and the place (“plumber geyser in Vanderbijlpark”). Nothing about your company or your suppliers.</p></section>
    <section class="fsa-panel"><div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-mail"/></svg></span><div class="fsa-panel__title"><h3>To suppliers</h3><p>What is in the email you send.</p></div></div>
        <p class="fsa-panel__body">What you need, where, when, and when quotes are due — you see every word before it goes. The budget stays inside your company.</p></section>
</div>

<section class="fsa-section"><h2>Who may do what</h2><span class="fsa-section__rule"></span></section>
<section class="fsa-panel fsa-panel--flush">
    <table class="fsa-table">
        <thead><tr><th></th><th>Viewer</th><th>Member</th><th>Bookkeeper</th><th>Admin</th></tr></thead>
        <tbody>
            <tr><td>Open searches and requests</td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
            <tr><td>Search, shortlist, ask for quotes</td><td></td><td>✓</td><td>✓</td><td>✓</td></tr>
            <tr><td>Change a request (quotes, award)</td><td></td><td>their own</td><td>✓</td><td>✓</td></tr>
            <tr><td>What was billed, and purchase orders</td><td></td><td></td><td>✓</td><td>✓</td></tr>
            <tr><td>Settings and words</td><td></td><td></td><td>✓</td><td>✓</td></tr>
            <tr><td>The OpenAI and Google keys</td><td></td><td></td><td></td><td>✓</td></tr>
        </tbody>
    </table>
</section>

<section class="fsa-section"><h2>Keys</h2><span class="fsa-section__rule"></span></section>
<section class="fsa-panel"><div class="fwm-kbd-grid fsa-panel__body">
    <?php foreach ([['/ or Ctrl K', 'Find a request, a supplier or a search'], ['Ctrl Enter', 'Search (in the box)'], ['n', 'New search'], ['g then r', 'Requests for quotes'], ['g then s', 'Searches'], ['?', 'All the keys']] as [$k, $l]): ?>
        <div class="fwm-kbd-row"><span><?= $e($l) ?></span><span><?php foreach (explode(' then ', $k) as $i => $part): ?><?= $i ? ' then ' : '' ?><kbd class="fwm-kbd"><?= $e($part) ?></kbd><?php endforeach; ?></span></div>
    <?php endforeach; ?>
</div></section>
<?php include __DIR__ . '/partials/foot.php'; ?>
