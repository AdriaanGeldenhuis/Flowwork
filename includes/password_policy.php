<?php
// /includes/password_policy.php — the company's password rules (Admin › Security):
// minimum length, upper and lower case, a number, a symbol. Checked wherever
// someone chooses a password: accepting an invitation and resetting a
// forgotten one. Without a company (a brand-new sign-up) the defaults apply.
// Needs fw_company_setting() from includes/auth_sessions.php (loaded by init.php).

/** @return array{min:int, case:bool, number:bool, symbol:bool} */
function fw_password_policy(PDO $db, ?int $companyId): array
{
    $p = ['min' => 8, 'case' => true, 'number' => true, 'symbol' => false];
    if (!$companyId) return $p;
    $get = fn(string $k, string $d) => function_exists('fw_company_setting') ? (string)fw_company_setting($db, $companyId, $k, $d) : $d;
    $p['min'] = min(64, max(8, (int)$get('security_min_password_length', '8') ?: 8));
    $p['case'] = $get('security_require_uppercase', '1') === '1';
    $p['number'] = $get('security_require_numbers', '1') === '1';
    $p['symbol'] = $get('security_require_special', '0') === '1';
    return $p;
}

/** What is wrong with $password under $policy (empty when it is fine). @return string[] */
function fw_password_problems(string $password, array $policy): array
{
    $out = [];
    if (mb_strlen($password) < $policy['min']) $out[] = 'at least ' . $policy['min'] . ' characters';
    if ($policy['case'] && (!preg_match('/\p{Lu}/u', $password) || !preg_match('/\p{Ll}/u', $password))) $out[] = 'upper and lower case letters';
    if ($policy['number'] && !preg_match('/\d/', $password)) $out[] = 'a number';
    if ($policy['symbol'] && !preg_match('/[^\p{L}\p{N}\s]/u', $password)) $out[] = 'a symbol such as ! @ # or $';
    return $out;
}

/** "Use at least 10 characters, with upper and lower case letters and a number." */
function fw_password_rules_text(array $policy): string
{
    $parts = [];
    if ($policy['case']) $parts[] = 'upper and lower case letters';
    if ($policy['number']) $parts[] = 'a number';
    if ($policy['symbol']) $parts[] = 'a symbol';
    $tail = '';
    if ($parts) {
        $last = array_pop($parts);
        $tail = ', with ' . ($parts ? implode(', ', $parts) . ' and ' : '') . $last;
    }
    return 'Use at least ' . $policy['min'] . ' characters' . $tail . '.';
}

/** One sentence for a failed check: "Your password needs a number and a symbol." */
function fw_password_error(array $problems): string
{
    if (!$problems) return '';
    $last = array_pop($problems);
    return 'Your password needs ' . ($problems ? implode(', ', $problems) . ' and ' : '') . $last . '.';
}
