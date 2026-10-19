<?php
// /qi/api/action.php — every action on a quote, invoice, credit note or
// recurring invoice from the document page. POST JSON {type, id, action, ...}.
// Viewers are read-only; refunds and write-offs need a finance role
// (admin / bookkeeper), as do restoring and deleting issued invoices.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiActions.php';
require_once __DIR__ . '/../lib/QiRecurring.php';
require_once __DIR__ . '/../lib/QiYoco.php';
require_once __DIR__ . '/../services/QiMail.php';

qi_require_post();
qi_require_writer();
qi_rate_limit('qi_action', 90, 60);

$in = qi_input();
$type = qi_str($in, 'type', 20);
$id = qi_int($in, 'id');
$action = qi_str($in, 'action', 30);
$C = $QI_COMPANY_ID;
$U = $QI_USER_ID;
if (!in_array($type, ['quote', 'invoice', 'credit_note', 'recurring'], true) || $id <= 0) qi_error('Unknown document');

/** Email a document; on success the document records that it was sent. */
function qi_send(string $kind, int $id, array $in): array
{
    global $DB, $C, $U;
    qi_rate_limit('qi_send', 20, 600);
    $opts = [
        'to' => is_array($in['to'] ?? null) ? $in['to'] : (string)($in['to'] ?? ''),
        'cc' => is_array($in['cc'] ?? null) ? $in['cc'] : (string)($in['cc'] ?? ''),
        'subject' => QiWriter::text($in['subject'] ?? '', 255),
        'body' => QiWriter::text($in['body'] ?? '', 10000, true),
        'account_id' => qi_int($in, 'account_id'),
        'attach' => !isset($in['attach']) || !empty($in['attach']),
    ];
    return QiMail::sendDocument($DB, $C, $U, $kind, $id, $opts);
}

try {
    $r = null;
    switch ($type . ':' . $action) {
        // ---------------------------------------------------------- quotes
        case 'quote:send':
            $st = $DB->prepare("SELECT status, expiry_date FROM quotes WHERE id = ? AND company_id = ?");
            $st->execute([$id, $C]);
            $q = $st->fetch(PDO::FETCH_ASSOC);
            if (!$q) qi_error('Quote not found', 404);
            if (!in_array($q['status'], ['draft', 'sent', 'viewed'], true)) qi_error('Only a draft or open quote can be sent.');
            if (!empty($q['expiry_date']) && $q['expiry_date'] < date('Y-m-d')) qi_error('This quote has expired. Change its "valid until" date first.', 422, ['field' => 'expiry_date']);
            QiMail::ensurePublicLink($DB, $C, 'quote', $id);
            $mail = qi_send('quote', $id, $in);
            if (!$mail['ok']) qi_error('The email was not sent: ' . $mail['error'], 422, ['mail_failed' => true]);
            $r = QiActions::markQuoteSent($DB, $C, $U, $id, 'Emailed to ' . implode(', ', $mail['to'])) + ['emailed' => $mail['to']];
            break;
        case 'quote:mark_sent':
            $r = QiActions::markQuoteSent($DB, $C, $U, $id);
            break;
        case 'quote:accept':
            $r = QiActions::acceptQuote($DB, $C, $U, $id, ['by' => 'user', 'name' => qi_str($in, 'name', 190), 'convert' => !empty($in['convert'])]);
            break;
        case 'quote:decline':
            $r = QiActions::declineQuote($DB, $C, $U, $id, ['by' => 'user', 'reason' => qi_str($in, 'reason', 400)]);
            break;
        case 'quote:convert':
            $r = QiActions::convertQuote($DB, $C, $U, $id);
            break;
        case 'quote:delete':
            $r = QiActions::deleteQuote($DB, $C, $U, $id);
            break;

        // ---------------------------------------------------------- invoices
        case 'invoice:issue':
            $r = QiActions::issueInvoice($DB, $C, $U, $id);
            break;
        case 'invoice:send':
        case 'invoice:remind':
            // a draft is issued first: what the customer receives is a tax invoice in the ledger
            $issued = QiActions::issueInvoice($DB, $C, $U, $id);
            QiMail::ensurePublicLink($DB, $C, 'invoice', $id);
            $mail = qi_send($action === 'remind' ? 'reminder' : 'invoice', $id, $in);
            if ($mail['ok']) {
                QiActivity::log($DB, $C, 'invoice', $id, $action === 'remind' ? 'reminder' : 'sent', 'Emailed to ' . implode(', ', $mail['to']), $U);
                $r = $issued + ['emailed' => $mail['to']];
            } else {
                QiActivity::log($DB, $C, 'invoice', $id, 'send_failed', $mail['error'], $U);
                if ($issued['issued']) qi_error('The invoice is issued, but the email was not sent: ' . $mail['error'], 422, ['mail_failed' => true, 'issued' => true]);
                qi_error('The email was not sent: ' . $mail['error'], 422, ['mail_failed' => true]);
            }
            break;
        case 'invoice:payment':
            $r = QiActions::recordPayment($DB, $C, $U, $id, [
                'amount' => $in['amount'] ?? null, 'date' => qi_str($in, 'date', 10), 'method' => qi_str($in, 'method', 20),
                'reference' => qi_str($in, 'reference', 190), 'notes' => qi_str($in, 'notes', 2000),
                'exchange_rate' => $in['exchange_rate'] ?? null, 'idempotency_key' => qi_str($in, 'idempotency_key', 64),
            ]);
            break;
        case 'invoice:refund':
            qi_require_admin();
            $r = QiActions::refund($DB, $C, $U, $id, ['amount' => $in['amount'] ?? null, 'reference' => qi_str($in, 'reference', 190), 'reason' => qi_str($in, 'reason', 1000)]);
            break;
        case 'invoice:void':
            $r = QiActions::voidInvoice($DB, $C, $U, $id, qi_str($in, 'reason', 255));
            break;
        case 'invoice:reopen':
            $r = QiActions::reopenInvoice($DB, $C, $U, $id);
            break;
        case 'invoice:write_off':
            qi_require_admin();
            $r = QiActions::writeOff($DB, $C, $U, $id, $in['amount'] ?? null, qi_str($in, 'reason', 255));
            break;
        case 'invoice:uncollectible':
            $r = QiActions::markUncollectible($DB, $C, $U, $id, qi_str($in, 'reason', 255));
            break;
        case 'invoice:archive':
        case 'invoice:unarchive':
            $r = QiActions::archiveInvoice($DB, $C, $U, $id, $action === 'archive');
            break;
        case 'invoice:delete':
            $r = QiActions::deleteInvoice($DB, $C, $U, $id, qi_is_admin(), qi_str($in, 'reason', 255));
            break;
        case 'invoice:restore':
            qi_require_admin();
            $r = QiActions::restoreInvoice($DB, $C, $U, $id);
            break;
        case 'invoice:unapply':
            $r = QiActions::unapplyPayments($DB, $C, $U, $id);
            break;
        case 'invoice:credit_full':
            $r = QiActions::creditInFull($DB, $C, $U, $id, qi_str($in, 'reason', 2000));
            break;
        case 'invoice:pay_link':
            $publicUrl = QiMail::ensurePublicLink($DB, $C, 'invoice', $id);
            $st = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ?");
            $st->execute([$id, $C]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) qi_error('Invoice not found', 404);
            if (!QiYoco::configured($DB, $C)) qi_error('Card payments are not set up. Add your Yoco keys under Admin → Integrations.');
            if (!QiYoco::payable($inv)) qi_error('This invoice cannot be paid by card (it must be issued, in rand, and owe at least R2).');
            // the link opens the invoice page, which starts a checkout for the balance at that moment
            $r = ['url' => $publicUrl . '&pay=1'];
            break;

        // ---------------------------------------------------------- credit notes
        case 'credit_note:send':
            $st = $DB->prepare("SELECT status FROM credit_notes WHERE id = ? AND company_id = ?");
            $st->execute([$id, $C]);
            $s = $st->fetchColumn();
            if ($s === false) qi_error('Credit note not found', 404);
            if (!in_array($s, ['approved', 'applied'], true)) qi_error('Approve the credit note before sending it.');
            $mail = qi_send('credit_note', $id, $in);
            if (!$mail['ok']) qi_error('The email was not sent: ' . $mail['error'], 422, ['mail_failed' => true]);
            QiActivity::log($DB, $C, 'credit_note', $id, 'sent', 'Emailed to ' . implode(', ', $mail['to']), $U);
            $r = ['emailed' => $mail['to']];
            break;
        case 'credit_note:approve':
            $r = QiActions::approveCredit($DB, $C, $U, $id);
            break;
        case 'credit_note:apply':
            $r = QiActions::applyCredit($DB, $C, $U, $id, qi_int($in, 'invoice_id') ?: null);
            break;
        case 'credit_note:unapply':
            $r = QiActions::unapplyCredit($DB, $C, $U, $id);
            break;
        case 'credit_note:cancel':
            $r = QiActions::cancelCredit($DB, $C, $U, $id, qi_str($in, 'reason', 255));
            break;
        case 'credit_note:delete':
            $r = QiActions::deleteCredit($DB, $C, $U, $id);
            break;

        // ---------------------------------------------------------- recurring
        case 'recurring:run':
            qi_rate_limit('qi_recurring_run', 20, 600);
            try {
                $r = QiRecurring::generate($DB, $C, $id, $U, true);
            } catch (RuntimeException $e) {
                throw new QiInvalid($e->getMessage());
            } catch (Exception $e) {
                throw new QiInvalid($e->getMessage());
            }
            break;
        case 'recurring:pause':
        case 'recurring:resume':
            try {
                $r = QiRecurring::setActive($DB, $C, $id, $action === 'resume', $U);
            } catch (RuntimeException $e) {
                throw new QiInvalid($e->getMessage());
            }
            break;
        case 'recurring:delete':
            $r = QiActions::deleteRecurring($DB, $C, $U, $id);
            break;

        // ---------------------------------------------------------- shared
        case 'quote:link':
        case 'invoice:link':
            $url = QiMail::ensurePublicLink($DB, $C, $type, $id);
            if ($url === null) qi_error('Document not found', 404);
            $r = ['url' => $url];
            break;

        default:
            qi_error('Unknown action', 400);
    }
    qi_json(['result' => $r]);
} catch (QiInvalid $e) {
    qi_error($e->getMessage(), $e->getCode() ?: 422, ['field' => $e->field] + $e->extra);
} catch (QiForbidden $e) {
    qi_error($e->getMessage(), 403);
} catch (QiUserError $e) {
    qi_error($e->getMessage(), 400);
} catch (PDOException $e) {
    error_log('[qi/action] ' . $type . ':' . $action . ' #' . $id . ': ' . $e->getMessage());
    qi_error('The change could not be saved. Please try again.', 500);
} catch (Exception $e) {
    // ledger and validation errors from PostingService / InvoiceLifecycle are written for people
    error_log('[qi/action] ' . $type . ':' . $action . ' #' . $id . ': ' . $e->getMessage());
    qi_error($e->getMessage(), 422);
}
