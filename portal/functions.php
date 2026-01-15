<?php
// Shared utility functions for the public client and supplier portals.
// The portals provide token‑based access to customer and supplier data
// without requiring a user login. Tokens are derived from the account ID
// and a server secret (in this case the database password). No state
// is stored in the database for tokens. If you change the token
// generation algorithm here, ensure any generated links are updated.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

/**
 * Generate a deterministic portal token for an account ID.
 *
 * We use a simple hash of the account ID and DB password to create
 * a token. While this is not cryptographically perfect, it avoids
 * storing tokens in the database and is sufficient for a lightweight
 * portal. You may enhance this with a stronger secret in production.
 *
 * @param int|string $accountId The CRM account ID (customer or supplier).
 * @return string A SHA‑256 token.
 */
function generatePortalToken($accountId)
{
    // Cast to string to avoid issues with integer types
    $data = (string)$accountId . ':' . DB_PASS;
    return hash('sha256', $data);
}

/**
 * Verify that a provided token matches the expected one for an account.
 *
 * @param int|string $accountId The CRM account ID to verify against.
 * @param string $token The token provided via GET or POST.
 * @return bool True if the token matches, false otherwise.
 */
function verifyPortalToken($accountId, $token)
{
    $expected = generatePortalToken($accountId);
    // Use hash_equals to prevent timing attacks
    return hash_equals($expected, $token);
}
