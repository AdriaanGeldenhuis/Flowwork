<?php
// /suppliers_ai/lib/SaHttp.php — the one way out to Google and OpenAI: JSON
// over HTTPS with short timeouts. Tests replace the transport (no network).

final class SaHttp
{
    /** @var null|callable(string $url, array $headers, string $body): array{0:int,1:string} */
    public static $transport = null;

    /** @return array{0:int,1:?array,2:string} HTTP status (0 = not reached), decoded body, raw body */
    public static function postJson(string $url, array $headers, array $payload, int $timeout = 25): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers[] = 'Content-Type: application/json';
        if (self::$transport !== null) {
            [$code, $raw] = (self::$transport)($url, $headers, (string)$body);
        } else {
            if (!function_exists('curl_init')) throw new SaProblem('The server cannot reach the internet (cURL is missing).', 503);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_CONNECTTIMEOUT => 8,
                                    CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
            $raw = curl_exec($ch);
            $code = $raw === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        }
        $raw = is_string($raw) ? $raw : '';
        $json = json_decode($raw, true);
        return [(int)$code, is_array($json) ? $json : null, $raw];
    }
}
