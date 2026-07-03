<?php
// /includes/yoco_signature.php
//
// Shared verification for Yoco (Svix-style) webhook signatures.
// Yoco signs each webhook with the company's registered signing secret
// (format: "whsec_<base64>"). The signed content is:
//     {webhook-id}.{webhook-timestamp}.{raw-body}
// and the "webhook-signature" header carries one or more space-separated
// "v1,<base64-hmac>" entries.
//
// verify returns TRUE only for a request that carries all three headers,
// a fresh timestamp (replay protection) and a matching HMAC. Callers MUST
// treat FALSE as "reject" — a missing signature is never acceptable.

/**
 * Pull the three Yoco signature headers, case-insensitively.
 * @return array{id: ?string, timestamp: ?string, signature: ?string}
 */
function yoco_get_webhook_headers(): array {
    $raw = function_exists('getallheaders') ? getallheaders() : [];
    $norm = [];
    if (is_array($raw)) {
        foreach ($raw as $k => $v) {
            $norm[strtolower((string)$k)] = $v;
        }
    }
    // Fallback to $_SERVER for SAPIs where getallheaders() is unavailable.
    $fromServer = static function (string $name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    };
    return [
        'id'        => $norm['webhook-id']        ?? $fromServer('webhook-id'),
        'timestamp' => $norm['webhook-timestamp'] ?? $fromServer('webhook-timestamp'),
        'signature' => $norm['webhook-signature'] ?? $fromServer('webhook-signature'),
    ];
}

/**
 * Verify a Yoco webhook signature. Fail-closed on any missing input.
 *
 * @param string  $secret            The company's yoco_webhook_secret ("whsec_...").
 * @param ?string $id                webhook-id header.
 * @param ?string $timestamp         webhook-timestamp header (unix seconds).
 * @param ?string $signatureHeader   webhook-signature header.
 * @param string  $rawBody           The exact raw request body.
 * @param int     $toleranceSeconds  Max clock skew for replay protection.
 */
function yoco_verify_signature(
    string $secret,
    ?string $id,
    ?string $timestamp,
    ?string $signatureHeader,
    string $rawBody,
    int $toleranceSeconds = 300
): bool {
    if ($secret === '' || $id === null || $id === '' ||
        $timestamp === null || $timestamp === '' ||
        $signatureHeader === null || $signatureHeader === '') {
        return false;
    }

    // Replay protection: reject stale or future-dated timestamps.
    if (!ctype_digit((string)$timestamp)) {
        return false;
    }
    if (abs(time() - (int)$timestamp) > $toleranceSeconds) {
        return false;
    }

    $signedContent = $id . '.' . $timestamp . '.' . $rawBody;

    // The secret is base64 after the "whsec_" (or similar) prefix.
    $parts       = explode('_', $secret, 2);
    $base        = (count($parts) === 2) ? $parts[1] : $parts[0];
    $secretBytes = base64_decode($base, true);
    if ($secretBytes === false || $secretBytes === '') {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

    // Header may contain several space-separated "v1,<sig>" entries.
    foreach (explode(' ', trim($signatureHeader)) as $part) {
        if ($part === '') {
            continue;
        }
        $segments = explode(',', $part);
        $sig = end($segments);
        if (is_string($sig) && $sig !== '' && hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}
