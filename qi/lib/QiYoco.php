<?php
/**
 * QiYoco — card payments for invoices through Yoco Checkout.
 *
 * "Pay now" (on the public invoice page, in the email and on the PDF) asks
 * Yoco for a hosted checkout for the balance *at that moment* and sends the
 * customer there, so a link never charges a stale amount. Yoco then calls
 * /webhooks/yoco.php with payment.succeeded; the payment is recorded and
 * posted like any other receipt (QiActions::recordPayment).
 *
 * Keys live on the company (companies.yoco_sec_key / yoco_webhook_secret),
 * managed under Admin → Integrations.
 */

require_once __DIR__ . '/QiActivity.php';

final class QiYoco
{
    public const API = 'https://payments.yoco.com/api/checkouts';

    public static function configured(PDO $db, int $companyId): bool
    {
        $st = $db->prepare("SELECT yoco_sec_key FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        return trim((string)$st->fetchColumn()) !== '';
    }

    /** Whether "Pay now" applies to this invoice row. */
    public static function payable(array $inv): bool
    {
        return empty($inv['deleted_at'])
            && in_array($inv['status'], ['sent', 'viewed', 'overdue', 'part-paid'], true)
            && strtoupper((string)($inv['currency'] ?: 'ZAR')) === 'ZAR'
            && (float)$inv['balance_due'] >= 2.0; // Yoco's minimum is R2
    }

    /**
     * A hosted checkout for the invoice's current balance.
     * @return array{url:string, id:string, amount:float}
     */
    public static function checkout(PDO $db, int $companyId, int $invoiceId, string $returnUrl): array
    {
        $st = $db->prepare("SELECT i.*, c.yoco_sec_key, c.name AS company_name FROM invoices i JOIN companies c ON c.id = i.company_id
                             WHERE i.id = ? AND i.company_id = ?");
        $st->execute([$invoiceId, $companyId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new RuntimeException('Invoice not found');
        $key = trim((string)$inv['yoco_sec_key']);
        if ($key === '') throw new RuntimeException('Card payments are not set up for this business yet.');
        if (!self::payable($inv)) {
            throw new RuntimeException((float)$inv['balance_due'] < 2.0 ? 'Nothing is owing on this invoice.' : 'This invoice cannot be paid by card.');
        }
        $amountC = (int)round((float)$inv['balance_due'] * 100);
        $sep = str_contains($returnUrl, '?') ? '&' : '?';
        $body = [
            'amount' => $amountC,
            'currency' => 'ZAR',
            'successUrl' => $returnUrl . $sep . 'paid=1',
            'cancelUrl' => $returnUrl . $sep . 'pay=cancelled',
            'failureUrl' => $returnUrl . $sep . 'pay=failed',
            'externalId' => (string)$inv['invoice_number'],
            'metadata' => ['invoiceId' => (string)$invoiceId, 'companyId' => (string)$companyId, 'invoiceNumber' => (string)$inv['invoice_number']],
        ];
        $res = self::post($key, $body, 'inv-' . $invoiceId . '-' . $amountC . '-' . date('YmdHi'));
        if (empty($res['id']) || empty($res['redirectUrl'])) {
            throw new RuntimeException('Yoco did not return a checkout. ' . (string)($res['message'] ?? $res['description'] ?? ''));
        }
        $db->prepare("UPDATE invoices SET yoco_checkout_id = ?, yoco_reference = ?, yoco_payment_link = ?, updated_at = updated_at WHERE id = ? AND company_id = ?")
           ->execute([(string)$res['id'], (string)$res['id'], (string)$res['redirectUrl'], $invoiceId, $companyId]);
        try {
            $db->prepare("INSERT INTO yoco_payment_events (company_id, event_type, event_id, payload_json) VALUES (?, 'checkout_created', ?, ?)")
               ->execute([$companyId, (string)$res['id'], json_encode(['invoice_id' => $invoiceId, 'amount' => $amountC])]);
        } catch (Throwable $e) {
            // audit table optional
        }
        QiActivity::log($db, $companyId, 'invoice', $invoiceId, 'pay_link', 'Card checkout for R ' . number_format($amountC / 100, 2), null, 'Customer');
        return ['url' => (string)$res['redirectUrl'], 'id' => (string)$res['id'], 'amount' => $amountC / 100];
    }

    /**
     * The parts of a webhook delivery that matter.
     * @return array{type:string, checkout_id:?string, payment_id:?string, amount:?float, currency:string, invoice_id:?int, company_id:?int, status:string}
     */
    public static function parseEvent(array $p): array
    {
        $payload = is_array($p['payload'] ?? null) ? $p['payload'] : (is_array($p['data'] ?? null) ? $p['data'] : []);
        $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $amount = $payload['amount'] ?? ($p['amount'] ?? null);
        return [
            'type' => strtolower((string)($p['type'] ?? '')),
            'checkout_id' => isset($meta['checkoutId']) ? (string)$meta['checkoutId'] : null,
            'payment_id' => isset($payload['id']) ? (string)$payload['id'] : null,
            'amount' => is_numeric($amount) ? (float)$amount / 100 : null,
            'currency' => strtoupper((string)($payload['currency'] ?? 'ZAR')),
            'invoice_id' => isset($meta['invoiceId']) && ctype_digit((string)$meta['invoiceId']) ? (int)$meta['invoiceId'] : null,
            'company_id' => isset($meta['companyId']) && ctype_digit((string)$meta['companyId']) ? (int)$meta['companyId'] : null,
            'status' => strtolower((string)($payload['status'] ?? '')),
        ];
    }

    private static function post(string $key, array $body, string $idempotencyKey): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Card payments need the PHP curl extension.');
        $ch = curl_init(self::API);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'Idempotency-Key: ' . substr(hash('sha256', $idempotencyKey), 0, 40),
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Could not reach Yoco: ' . $err);
        $data = json_decode((string)$raw, true);
        if ($code >= 400 || !is_array($data)) {
            error_log('[qi] yoco checkout HTTP ' . $code . ': ' . substr((string)$raw, 0, 300));
            throw new RuntimeException($code === 401 || $code === 403 ? 'Yoco rejected the secret key. Check it under Admin → Integrations.' : 'Yoco could not start the payment (HTTP ' . $code . ').');
        }
        return $data;
    }
}
