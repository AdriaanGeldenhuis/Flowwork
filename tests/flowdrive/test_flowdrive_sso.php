<?php
/**
 * FlowDriveSso: the one-time, state-bound tokens /drive/ issues so FlowDrive
 * opens already signed in (see includes/flowdrive/FlowDriveSso.php).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/includes/flowdrive/FlowDriveSso.php';

// The table is created on first use, so start without it
$DB->exec("DROP TABLE IF EXISTS fd_sso_tokens");

$state = bin2hex(random_bytes(16));
$url = FlowDriveSso::issue($DB, TEST_USER_ID, TEST_COMPANY_ID, 42, $state, 'light');

// It goes only to the configured FlowDrive, with the token, the state and the theme
assert_true(str_starts_with($url, FlowDriveSso::baseUrl() . '/sso.php?'), 'redirect goes to FlowDrive sso.php');
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
assert_eq($state, $q['state'] ?? null, 'state is passed back unchanged');
assert_eq('light', $q['theme'] ?? null, 'theme carried across');
assert_true((bool)preg_match('/^[a-f0-9]{64}$/', $q['token'] ?? ''), 'token is 32 random bytes in hex');

// Only the hash is stored, with the person, company, device and a short life
$row = $DB->query("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS ttl FROM fd_sso_tokens")->fetch(PDO::FETCH_ASSOC);
assert_eq(hash('sha256', $q['token']), $row['token_hash'], 'token stored as SHA-256 only');
assert_true(!str_contains(json_encode($row), $q['token']), 'raw token never stored');
assert_eq($state, $row['state'], 'state stored with the token');
assert_eq(TEST_USER_ID, (int)$row['user_id'], 'user stored');
assert_eq(TEST_COMPANY_ID, (int)$row['company_id'], 'active company stored');
assert_eq(42, (int)$row['auth_sid'], 'Flowwork device session stored');
assert_true((int)$row['ttl'] > 0 && (int)$row['ttl'] <= FlowDriveSso::TTL, 'expires within two minutes');
assert_true($row['used_at'] === null, 'not used yet');

// Every issue is a different token
$url2 = FlowDriveSso::issue($DB, TEST_USER_ID, TEST_COMPANY_ID, null, $state, 'dark');
parse_str((string)parse_url($url2, PHP_URL_QUERY), $q2);
assert_true($q2['token'] !== $q['token'], 'tokens are unique');
assert_eq('dark', $q2['theme'], 'dark theme');

// A malformed state is refused (it is echoed into a URL and compared by FlowDrive)
$threw = false;
try {
    FlowDriveSso::issue($DB, TEST_USER_ID, TEST_COMPANY_ID, null, 'not-a-state', 'dark');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'invalid state rejected');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM fd_sso_tokens")->fetchColumn(), 'nothing stored for a rejected state');

// Starting from the home tile: FlowDrive begins the hand-over, with a safe landing path
assert_eq(FlowDriveSso::baseUrl() . '/sso.php', FlowDriveSso::startUrl(), 'plain start');
assert_eq(FlowDriveSso::baseUrl() . '/sso.php?next=%2Fmydrive.php%3Fdrive_id%3D2', FlowDriveSso::startUrl('/mydrive.php?drive_id=2'), 'start with a landing path');

$DB->exec("DROP TABLE IF EXISTS fd_sso_tokens");
echo "OK test_flowdrive_sso.php ({$GLOBALS['__assert_count']} assertions)\n";
