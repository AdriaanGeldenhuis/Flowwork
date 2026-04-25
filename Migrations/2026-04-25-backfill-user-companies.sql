-- Backfill `user_companies` for users created via the old admin
-- "Add User" flow and via accept-invite.php — both only ever wrote to
-- `users`, so existing users have no per-tenant link row and now drop
-- out of the user_companies-driven listings (Users & Roles, audit
-- filter, seat counts, etc.). Safe to re-run; the LEFT JOIN guard
-- skips anyone who already has the link.

INSERT INTO `user_companies` (`user_id`, `company_id`, `role`, `created_at`)
SELECT u.id, u.company_id, u.role, COALESCE(u.created_at, NOW())
FROM `users` u
LEFT JOIN `user_companies` uc
       ON uc.user_id = u.id
      AND uc.company_id = u.company_id
WHERE u.company_id IS NOT NULL
  AND uc.id IS NULL;
