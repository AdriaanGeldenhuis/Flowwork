-- Admin console overhaul (Maison): invitations remember the personal note
-- they were sent with and when the email last went out. The admin console
-- also adds these on first use (admin/lib/AdminSchema.php), so running this
-- file is optional; it is safe to run more than once.

ALTER TABLE `invites`
  ADD COLUMN IF NOT EXISTS `custom_message` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_sent_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `send_count` int(11) NOT NULL DEFAULT 0;
