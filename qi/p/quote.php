<?php
// /qi/p/quote.php — the customer's view of a quote: read it, download it, and
// accept (with their name) or decline it. No login: the link token is the key.
require_once __DIR__ . '/_public.php';
require_once __DIR__ . '/../lib/QiActions.php';

[$row, $token] = qi_pub_load($DB, 'quote');
$companyId = (int)$row['company_id'];
$quoteId = (int)$row['id'];
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $sent)) {
        $error = 'Your page expired. Please try again.';
    } elseif (!qi_pub_allow('decide', 12, 600)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $do = (string)($_POST['do'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            if ($do === 'accept') {
                if (empty($_POST['agree'])) throw new QiInvalid('Tick the box to confirm that you accept the quote and its terms.', 'agree');
                QiActions::acceptQuote($DB, $companyId, null, $quoteId, ['by' => 'customer', 'name' => (string)($_POST['name'] ?? ''), 'ip' => $ip]);
                header('Location: /qi/p/quote.php?token=' . rawurlencode($token) . '&done=accepted', true, 303);
                exit;
            }
            if ($do === 'decline') {
                QiActions::declineQuote($DB, $companyId, null, $quoteId, ['by' => 'customer', 'name' => (string)($_POST['name'] ?? ''), 'reason' => (string)($_POST['reason'] ?? ''), 'ip' => $ip]);
                header('Location: /qi/p/quote.php?token=' . rawurlencode($token) . '&done=declined', true, 303);
                exit;
            }
        } catch (QiInvalid $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[qi/p/quote] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again, or reply to the email you received.';
        }
    }
    $st = $DB->prepare("SELECT * FROM quotes WHERE id = ?");
    $st->execute([$quoteId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: $row;
}

qi_pub_mark_viewed($DB, 'quote', $row);
[$m, $design, $company] = qi_pub_model($DB, 'quote', $row);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($v) => $e(QiMoney::format((float)$v, $m['symbol']));
$date = fn($d) => $d ? date('j F Y', strtotime((string)$d)) : '';
$status = (string)$row['status'];
$expired = !empty($row['expiry_date']) && $row['expiry_date'] < date('Y-m-d');
$canDecide = in_array($status, ['sent', 'viewed'], true) && !$expired;
$done = (string)($_GET['done'] ?? '');
$companyName = $m['company']['name'];
$contactEmail = $m['company']['email'];

qi_pub_head('Quote ' . $m['number'] . ' · ' . $companyName, $design);
?>
<header class="pub-top">
    <div class="pub-top__brand">
        <?php if ($m['company']['logo_url']): ?><img src="<?= $e($m['company']['logo_url']) ?>" alt="<?= $e($companyName) ?>"><?php else: ?><span><?= $e($companyName) ?></span><?php endif; ?>
    </div>
    <a class="btn btn--sm" href="/qi/p/pdf.php?type=quote&amp;token=<?= $e($token) ?>"><svg class="i"><use href="#i-download"/></svg>Download PDF</a>
</header>

<main class="pub-main">
    <section class="pub-card" aria-live="polite">
        <div class="pub-card__eyebrow">Quote <?= $e($m['number']) ?> · from <?= $e($companyName) ?></div>
        <div class="pub-card__amount"><?= $money($m['totals']['total']) ?></div>
        <?php if ($done === 'accepted' || in_array($status, ['accepted', 'converted'], true)): ?>
            <div class="pub-state is-good"><svg class="i"><use href="#iq-accept"/></svg><div><b>Accepted<?= !empty($row['accepted_by_name']) ? ' by ' . $e($row['accepted_by_name']) : '' ?></b>
                <span><?= !empty($row['accepted_at']) ? 'on ' . $e($date($row['accepted_at'])) . '. ' : '' ?>Thank you — <?= $e($companyName) ?> has been notified and will be in touch.</span></div></div>
        <?php elseif ($done === 'declined' || $status === 'declined'): ?>
            <div class="pub-state"><svg class="i"><use href="#iq-decline"/></svg><div><b>Declined</b><span>Thank you for letting us know. If anything changes, simply reply to <?= $e($companyName) ?>.</span></div></div>
        <?php elseif ($expired): ?>
            <div class="pub-state is-warn"><svg class="i"><use href="#i-clock"/></svg><div><b>This quote expired on <?= $e($date($row['expiry_date'])) ?></b><span>Please ask <?= $e($companyName) ?><?= $contactEmail ? ' (' . $e($contactEmail) . ')' : '' ?> for an updated quote.</span></div></div>
        <?php else: ?>
            <p class="pub-card__lead">Valid until <b><?= $e($date($row['expiry_date'])) ?></b>. Read it below, then let <?= $e($companyName) ?> know.</p>
        <?php endif; ?>

        <?php if ($error !== ''): ?><div class="pub-error" role="alert"><?= $e($error) ?></div><?php endif; ?>

        <?php if ($canDecide && $done === ''): ?>
        <form class="pub-decide" method="post" id="acceptForm">
            <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>">
            <input type="hidden" name="do" value="accept">
            <label class="field"><span class="label">Your full name</span><input class="input" name="name" maxlength="190" required autocomplete="name" value="<?= $e($_POST['name'] ?? $m['customer']['contact']) ?>"></label>
            <label class="pub-agree"><input type="checkbox" name="agree" value="1" required> <span>I accept this quote<?= $m['terms'] !== '' ? ' and its terms' : '' ?> on behalf of <?= $e($m['customer']['name']) ?>.</span></label>
            <div class="pub-decide__actions">
                <button class="btn btn--primary btn--lg pub-cta" type="submit"><svg class="i"><use href="#iq-accept"/></svg>Accept quote</button>
                <button class="btn btn--ghost" type="button" id="declineToggle">Decline</button>
            </div>
        </form>
        <form class="pub-decide pub-decline" method="post" id="declineForm" hidden>
            <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>">
            <input type="hidden" name="do" value="decline">
            <label class="field"><span class="label">Would you tell us why? <small>(optional)</small></span><textarea class="textarea" name="reason" rows="3" maxlength="400" placeholder="e.g. the timing does not suit us"></textarea></label>
            <div class="pub-decide__actions">
                <button class="btn btn--danger" type="submit">Decline quote</button>
                <button class="btn btn--ghost" type="button" id="declineCancel">Back</button>
            </div>
        </form>
        <?php endif; ?>

        <?php if (!empty($m['milestones'])): ?>
        <div class="pub-schedule">
            <div class="pub-schedule__title">Payment schedule</div>
            <?php foreach ($m['milestones'] as $ms): ?>
                <div class="pub-schedule__row"><span><?= $e($ms['label']) ?></span><b><?= $money($ms['amount']) ?></b></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="pub-paper" id="pubPaper"><div class="pub-paper__inner" id="pubInner"><?= DocHtml::render($m, $design, ['editable' => false]) ?></div></div>
</main>

<footer class="pub-foot">Questions about this quote? <?= $contactEmail ? '<a href="mailto:' . $e($contactEmail) . '">' . $e($contactEmail) . '</a>' : 'Reply to the email you received.' ?><span>Sent with Flowwork</span></footer>
<script src="/qi/js/public.js?v=<?= (string)(@filemtime(__DIR__ . '/../js/public.js') ?: '1') ?>"></script>
</body>
</html>
