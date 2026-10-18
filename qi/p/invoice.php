<?php
// /qi/p/invoice.php — the customer's view of an invoice: what is owed, how to
// pay (EFT details, or by card through Yoco), the payments so far and the PDF.
//   &pay=1      start a card checkout for the balance right now and go to Yoco
//   &paid=1     back from Yoco: thank the customer (the webhook records the payment)
require_once __DIR__ . '/_public.php';
require_once __DIR__ . '/../lib/QiYoco.php';

[$row, $token] = qi_pub_load($DB, 'invoice');
$companyId = (int)$row['company_id'];
$self = QiDocument::url('/qi/p/invoice.php?token=' . rawurlencode($token));
$yoco = QiYoco::configured($DB, $companyId) && QiYoco::payable($row);
$payError = '';

if (($_GET['pay'] ?? '') === '1') {
    if (!qi_pub_allow('pay', 10, 600)) {
        $payError = 'Too many payment attempts. Please wait a few minutes.';
    } elseif (!$yoco) {
        $payError = (float)$row['balance_due'] <= 0.004 ? 'This invoice is already paid — thank you.' : 'Card payment is not available for this invoice.';
    } else {
        try {
            $c = QiYoco::checkout($DB, $companyId, (int)$row['id'], $self);
            header('Location: ' . $c['url'], true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('[qi/p/invoice] checkout: ' . $e->getMessage());
            $payError = 'The card payment could not be started. Please try again in a moment, or pay by EFT.';
        }
    }
}

qi_pub_mark_viewed($DB, 'invoice', $row);
[$m, $design, $company] = qi_pub_model($DB, 'invoice', $row);
if ($yoco) $m['links']['pay'] = $self . '&pay=1';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($v) => $e(QiMoney::format((float)$v, $m['symbol']));
$date = fn($d) => $d ? date('j F Y', strtotime((string)$d)) : '';
$balance = (float)($m['totals']['balance'] ?? $m['totals']['total']);
$paidUp = $balance <= 0.004 && in_array($row['status'], ['paid', 'part-paid'], true);
$closed = in_array($row['status'], ['cancelled', 'written_off', 'refunded'], true);
$overdue = !$paidUp && !$closed && !empty($row['due_date']) && $row['due_date'] < date('Y-m-d');
$bank = $m['company']['bank'];
$companyName = $m['company']['name'];
$contactEmail = $m['company']['email'];

qi_pub_head('Invoice ' . $m['number'] . ' · ' . $companyName, $design);
?>
<header class="pub-top">
    <div class="pub-top__brand">
        <?php if ($m['company']['logo_url']): ?><img src="<?= $e($m['company']['logo_url']) ?>" alt="<?= $e($companyName) ?>"><?php else: ?><span><?= $e($companyName) ?></span><?php endif; ?>
    </div>
    <a class="btn btn--sm" href="/qi/p/pdf.php?type=invoice&amp;token=<?= $e($token) ?>"><svg class="i"><use href="#i-download"/></svg>Download PDF</a>
</header>

<main class="pub-main">
    <section class="pub-card" aria-live="polite">
        <div class="pub-card__eyebrow"><?= $e($m['company']['vat'] !== '' ? 'Tax invoice' : 'Invoice') ?> <?= $e($m['number']) ?> · from <?= $e($companyName) ?></div>
        <?php if (($_GET['paid'] ?? '') === '1' && !$paidUp): ?>
            <div class="pub-state is-good"><svg class="i"><use href="#i-check"/></svg><div><b>Thank you!</b><span>Your card payment is being confirmed — this page updates once it is through.</span></div></div>
        <?php elseif (($_GET['pay'] ?? '') === 'cancelled' || ($_GET['pay'] ?? '') === 'failed'): ?>
            <div class="pub-state is-warn"><svg class="i"><use href="#i-alert"/></svg><div><b><?= ($_GET['pay'] ?? '') === 'failed' ? 'The payment did not go through' : 'Payment cancelled' ?></b><span>Nothing was charged. You can try again or pay by EFT.</span></div></div>
        <?php endif; ?>

        <?php if ($closed): ?>
            <div class="pub-card__amount"><?= $money($m['totals']['total']) ?></div>
            <div class="pub-state"><svg class="i"><use href="#iq-void"/></svg><div><b>This invoice is no longer payable</b><span>Please contact <?= $e($companyName) ?> if you have questions.</span></div></div>
        <?php elseif ($paidUp): ?>
            <div class="pub-card__amount is-paid"><svg class="i"><use href="#i-check"/></svg><?= $money($m['totals']['total']) ?></div>
            <div class="pub-state is-good"><div><b>Paid in full — thank you.</b><span><?= !empty($row['paid_at']) ? 'Settled on ' . $e($date($row['paid_at'])) . '.' : '' ?></span></div></div>
        <?php else: ?>
            <div class="pub-card__label">Amount due</div>
            <div class="pub-card__amount"><?= $money($balance) ?></div>
            <p class="pub-card__lead"><?= $overdue ? '<b class="is-late">Was due on ' . $e($date($row['due_date'])) . '</b>' : 'Due on <b>' . $e($date($row['due_date'])) . '</b>' ?><?= abs($balance - (float)$m['totals']['total']) > 0.004 ? ' · of ' . $money($m['totals']['total']) : '' ?></p>
            <?php if ($payError !== ''): ?><div class="pub-error" role="alert"><?= $e($payError) ?></div><?php endif; ?>
            <div class="pub-pay">
                <?php if ($yoco): ?>
                    <a class="btn btn--primary btn--lg pub-cta" href="<?= $e($self . '&pay=1') ?>"><svg class="i"><use href="#iq-pay"/></svg>Pay <?= $money($balance) ?> by card</a>
                    <span class="pub-pay__hint">Secure checkout by Yoco</span>
                <?php endif; ?>
                <?php if ($bank['account'] !== ''): ?>
                <div class="pub-bank">
                    <div class="pub-bank__title">Pay by EFT</div>
                    <dl>
                        <?php if ($bank['name'] !== ''): ?><div><dt>Bank</dt><dd><?= $e($bank['name']) ?></dd></div><?php endif; ?>
                        <div><dt>Account name</dt><dd><?= $e($bank['holder']) ?></dd></div>
                        <div><dt>Account number</dt><dd><?= $e($bank['account']) ?> <button class="pub-copy" type="button" data-copy="<?= $e($bank['account']) ?>" aria-label="Copy account number"><svg class="i i--sm"><use href="#i-copy"/></svg></button></dd></div>
                        <?php if ($bank['branch'] !== ''): ?><div><dt>Branch code</dt><dd><?= $e($bank['branch']) ?></dd></div><?php endif; ?>
                        <div><dt>Reference</dt><dd><?= $e($m['number']) ?> <button class="pub-copy" type="button" data-copy="<?= $e($m['number']) ?>" aria-label="Copy reference"><svg class="i i--sm"><use href="#i-copy"/></svg></button></dd></div>
                    </dl>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($m['payments'])): ?>
        <div class="pub-schedule">
            <div class="pub-schedule__title">Payments received</div>
            <?php foreach ($m['payments'] as $p): ?>
                <div class="pub-schedule__row"><span><?= $e($date($p['date'])) ?> · <?= $e($p['method']) ?></span><b><?= $money($p['amount']) ?></b></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="pub-paper" id="pubPaper"><div class="pub-paper__inner" id="pubInner"><?= DocHtml::render($m, $design, ['editable' => false]) ?></div></div>
</main>

<footer class="pub-foot">Questions about this invoice? <?= $contactEmail ? '<a href="mailto:' . $e($contactEmail) . '">' . $e($contactEmail) . '</a>' : 'Reply to the email you received.' ?><span>Sent with Flowwork</span></footer>
<script src="/qi/js/public.js?v=<?= (string)(@filemtime(__DIR__ . '/../js/public.js') ?: '1') ?>"></script>
</body>
</html>
