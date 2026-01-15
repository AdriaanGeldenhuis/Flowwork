<?php
// /crm/tools/backfill_industry_region.php – One‑off script to backfill CRM account
// region assignments based on address data. It can run in dry‑run mode to
// preview changes or update missing region_id fields when called with
// ?commit=1.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Only allow administrators or users with appropriate rights to run this
// tool. For simplicity, we rely on the existing auth_gate, which
// restricts access to authenticated users. In a real deployment, further
// permission checks would be advisable.

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$commit = isset($_GET['commit']) && $_GET['commit'] === '1';

// Build a mapping of region names (lowercase) to ids for this company. Regions
// are global, not company specific.
$regionsStmt = $DB->query("SELECT id, name FROM crm_regions");
$regionMap = [];
while ($row = $regionsStmt->fetch(PDO::FETCH_ASSOC)) {
    $regionMap[strtolower($row['name'])] = (int)$row['id'];
}

// Fetch all accounts missing region_id
$stmtAccounts = $DB->prepare(
    "SELECT a.id, a.name
     FROM crm_accounts a
     WHERE a.company_id = ? AND a.region_id IS NULL"
);
$stmtAccounts->execute([$companyId]);
$accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

// Prepare statement to get address for account (prefer region column, fallback city)
$stmtAddr = $DB->prepare(
    "SELECT region, city
     FROM crm_addresses
     WHERE company_id = ? AND account_id = ?
       AND (region IS NOT NULL OR city IS NOT NULL)
     ORDER BY FIELD(type, 'head_office','billing','shipping','site') ASC, id ASC
     LIMIT 1"
);

// Prepare update statement if commit
if ($commit) {
    $updateStmt = $DB->prepare(
        "UPDATE crm_accounts SET region_id = ? WHERE company_id = ? AND id = ? AND region_id IS NULL"
    );
}

// Collect changes for output
$changes = [];

foreach ($accounts as $acc) {
    $accountId = (int)$acc['id'];
    $stmtAddr->execute([$companyId, $accountId]);
    $addr = $stmtAddr->fetch(PDO::FETCH_ASSOC);
    if (!$addr) {
        continue; // No address to infer from
    }
    $regionStr = '';
    if (!empty($addr['region'])) {
        $regionStr = trim($addr['region']);
    } elseif (!empty($addr['city'])) {
        // Use city as fallback – some CRM setups may put province in city
        $regionStr = trim($addr['city']);
    }
    if ($regionStr === '') {
        continue;
    }
    $key = strtolower($regionStr);
    if (isset($regionMap[$key])) {
        $regionId = $regionMap[$key];
        $changes[] = ['account_id' => $accountId, 'account_name' => $acc['name'], 'region_id' => $regionId, 'region_name' => $regionStr];
        if ($commit) {
            $updateStmt->execute([$regionId, $companyId, $accountId]);
        }
    }
}

// Output results
header('Content-Type: text/plain');
if (!$commit) {
    echo "Dry run – no changes written. Use ?commit=1 to apply.\n\n";
} else {
    echo "Applied region backfill to " . count($changes) . " accounts.\n\n";
}
if (empty($changes)) {
    echo "No accounts required updates.\n";
} else {
    foreach ($changes as $change) {
        echo 'Account #' . $change['account_id'] . ' (' . $change['account_name'] . ") -> Region ID " . $change['region_id'] . ' (' . $change['region_name'] . ")\n";
    }
}
