<?php
// /suppliers_ai/lib/SaAi.php — what OpenAI does for Suppliers AI (only when an
// admin switched it on and the company's key is in place):
//   - read a request: the trade, better search words, the place, quantity,
//     date, budget and requirements, and ideas of kinds of business or
//     well-known chains to look at — names only. It is never asked for phone
//     numbers, emails, addresses or prices: those would be guesses (the old
//     module asked for them, and stored made-up numbers as real suppliers).
//   - write a request-for-quote email from what the person typed (never the budget).
// Only the request's words, today's date and the company's town are sent.

final class SaAi
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** Read a request. @return array normalised (see read keys below) */
    public static function read(array $settings, string $text, string $today, string $town): array
    {
        $cats = array_merge(array_keys(SaParse::TRADES), ['other']);
        $schema = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['summary', 'category', 'search_terms', 'location', 'quantity', 'needed_by', 'budget_rand', 'requirements', 'ideas'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => $cats],
                'search_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                'location' => ['type' => ['string', 'null']],
                'quantity' => ['type' => ['string', 'null']],
                'needed_by' => ['type' => ['string', 'null']],
                'budget_rand' => ['type' => ['number', 'null']],
                'requirements' => ['type' => 'array', 'items' => ['type' => 'string']],
                'ideas' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'kind', 'why'],
                    'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'why' => ['type' => 'string']]]],
            ]];
        $system = 'You read requests from a South African business looking for a supplier or contractor. Return:'
            . ' summary: one short sentence restating what is needed (no budget or prices);'
            . ' category: the closest category key;'
            . ' search_terms: 1 to 3 short phrases a person would type into Google Maps to find such a business (e.g. "plumber", "geyser installation"), without the place;'
            . ' location: the town or area only if the request names one, else null;'
            . ' quantity: how many or how much if stated (e.g. "3 plumbers", "20 m³"), else null;'
            . ' needed_by: the date it is needed as YYYY-MM-DD if stated or clearly implied (today is ' . $today . '), else null;'
            . ' budget_rand: the budget in rand if stated, else null;'
            . ' requirements: certificates or conditions asked for (e.g. "COC", "B-BBEE level 1", "CIDB grade 5GB", "insured", "delivers to site");'
            . ' ideas: up to 5 kinds of business, or well-known South African chains or franchises, that usually supply this — the name and kind only.'
            . ' Never give phone numbers, email addresses, street addresses, websites or prices, and never invent a business: if unsure, give a kind of business instead of a name.';
        $res = self::call($settings, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Request: " . mb_substr($text, 0, 500) . "\nCompany town: " . ($town !== '' ? mb_substr($town, 0, 120) : 'South Africa')],
        ], $schema, 'request', 700, 0.2);

        $needed = SaKit::ymd($res['needed_by'] ?? '');
        if ($needed !== null && ($needed < $today || $needed > date('Y-m-d', strtotime('+1 year', strtotime($today))))) $needed = null;
        $budget = isset($res['budget_rand']) && is_numeric($res['budget_rand']) && $res['budget_rand'] > 0 && $res['budget_rand'] < 1000000000 ? (int)round($res['budget_rand'] * 100) : null;
        $terms = [];
        foreach ((array)($res['search_terms'] ?? []) as $t) {
            $t = SaKit::text($t, 60);
            if ($t !== '' && !preg_match('/\d{6,}|@|https?:/i', $t)) $terms[] = $t;
        }
        $ideas = [];
        foreach ((array)($res['ideas'] ?? []) as $i) {
            $name = SaKit::text($i['name'] ?? '', 80);
            // A name with digits, an @ or a web address is not a name: drop it rather than show a guess
            if ($name === '' || preg_match('/\d{5,}|@|https?:|www\./i', $name)) continue;
            $ideas[] = ['name' => $name, 'kind' => SaKit::text($i['kind'] ?? '', 80), 'why' => SaKit::text($i['why'] ?? '', 200)];
            if (count($ideas) >= 5) break;
        }
        return [
            'summary' => SaKit::text($res['summary'] ?? '', 200),
            'category' => in_array($res['category'] ?? '', $cats, true) && $res['category'] !== 'other' ? $res['category'] : null,
            'search_terms' => array_slice(array_values(array_unique($terms)), 0, 3),
            'location' => SaKit::text($res['location'] ?? '', 120) ?: null,
            'quantity' => SaKit::text($res['quantity'] ?? '', 80) ?: null,
            'needed_by' => $needed,
            'budget' => $budget,
            'requirements' => array_slice(array_values(array_filter(array_map(fn($r) => SaKit::text($r, 80), (array)($res['requirements'] ?? [])))), 0, 8),
            'ideas' => $ideas,
        ];
    }

    /** A request-for-quote email. Placeholders {first_name} and {supplier} are kept for each supplier. */
    public static function draft(array $settings, array $req, string $company, string $sender): array
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['subject', 'body'],
                   'properties' => ['subject' => ['type' => 'string'], 'body' => ['type' => 'string']]];
        $facts = array_filter([
            'What is needed' => $req['title'] ?? '',
            'Details' => $req['details'] ?? '',
            'Quantity' => $req['quantity'] ?? '',
            'Where' => $req['location'] ?? '',
            'Needed by' => !empty($req['needed_by']) ? date('j F Y', strtotime($req['needed_by'])) : '',
            'Quotes needed by' => !empty($req['quote_by']) ? date('j F Y', strtotime($req['quote_by'])) : '',
            'Requirements' => implode(', ', (array)($req['requirements'] ?? [])),
        ], fn($v) => trim((string)$v) !== '');
        $lines = '';
        foreach ($facts as $k => $v) $lines .= $k . ': ' . mb_substr((string)$v, 0, 1500) . "\n";
        $res = self::call($settings, [
            ['role' => 'system', 'content' => 'You write short, friendly and professional requests for quotation in South African English for a business to send to suppliers.'
                . ' Start with "Hi {first_name}," exactly (it is filled in per supplier). Ask for a quotation with lead or delivery time and payment terms, and the date quotes are needed by when given.'
                . ' List what is needed clearly. Never mention a budget, a price or other suppliers. Do not invent facts that were not given. Sign off with the sender and company. Under 220 words. Plain text, no markdown.'],
            ['role' => 'user', 'content' => $lines . 'Company: ' . $company . "\nSender: " . $sender],
        ], $schema, 'rfq_email', 700, 0.4);
        $subject = SaKit::text($res['subject'] ?? '', 200);
        $body = SaKit::lines($res['body'] ?? '', 6000);
        if ($subject === '' || $body === '') throw new SaProblem('The AI did not write a usable email this time — the standard one is still there.', 502);
        return ['subject' => $subject, 'body' => $body];
    }

    /** Is the key accepted and can it use the model? */
    public static function test(array $settings): array
    {
        $t0 = microtime(true);
        $res = self::call($settings, [['role' => 'user', 'content' => 'Reply with ok.']],
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'string']]], 'ping', 20, 0.0);
        return ['ok' => true, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'model' => $settings['openai_model'], 'reply' => SaKit::text($res['ok'] ?? '', 40)];
    }

    private static function call(array $settings, array $messages, array $schema, string $name, int $maxTokens, float $temperature): array
    {
        if ($settings['openai_key'] === '') throw new SaProblem('Add the company’s OpenAI key under Settings first.', 409);
        [$code, $json] = SaHttp::postJson(self::ENDPOINT, ['Authorization: Bearer ' . $settings['openai_key']], [
            'model' => $settings['openai_model'], 'temperature' => $temperature, 'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $name, 'strict' => true, 'schema' => $schema]],
            'messages' => $messages,
        ], 30);
        if ($code !== 200 || !is_array($json)) {
            $msg = SaKit::text($json['error']['message'] ?? '', 300);
            error_log('[suppliers_ai] openai ' . $code . ': ' . $msg);
            if ($code === 0) throw new SaProblem('The AI could not be reached — try again in a moment.', 502);
            if ($code === 401) throw new SaProblem('OpenAI refused the company’s key. An admin can replace it under Settings.', 502);
            if ($code === 429) throw new SaProblem('OpenAI is busy or the account is out of credit — try again later.', 502);
            if ($code === 404 || ($code === 400 && stripos($msg, 'model') !== false)) throw new SaProblem('This OpenAI key cannot use ' . $settings['openai_model'] . '. Choose another model under Settings.', 502);
            throw new SaProblem('The AI did not answer properly (' . $code . ').', 502);
        }
        $out = json_decode((string)($json['choices'][0]['message']['content'] ?? ''), true);
        if (!is_array($out)) throw new SaProblem('The AI’s answer could not be read.', 502);
        return $out;
    }
}
