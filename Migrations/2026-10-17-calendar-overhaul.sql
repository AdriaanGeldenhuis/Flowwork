-- 2026-10-17 Calendar overhaul
--
-- Same result as calendar/lib/CalendarSchema.php, which applies this
-- automatically the first time the calendar (or its reminder job) runs.
-- Safe to run more than once (MariaDB 10.5+).
--
-- 1. utf8mb4: the calendar tables were latin1, which rejected emoji, "✓" and
--    any non-Latin-1 text (and made the old invoice / board syncs fail).
-- 2. Link types: CRM follow-ups wrote 'account' / 'opportunity', which the
--    enum rejected. Follow-ups now use crm_account / crm_opportunity; pay runs
--    can be linked too.
-- 3. New columns: imported events keep their iCalendar UID (re-importing a
--    file updates instead of duplicating), changed occurrences of a repeating
--    event point at their series, repeating reminders remember the occurrence
--    they last fired for, and per-user preferences live in prefs_json.

ALTER TABLE `calendars` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `calendar_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `calendar_event_participants` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `calendar_event_reminders` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `calendar_event_links` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `calendar_event_attachments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `calendar_settings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `calendar_event_links`
  MODIFY `linked_type` enum('project','board','item','quote','invoice','rfq','po','email',
                            'crm_account','crm_interaction','crm_opportunity','pay_run') NOT NULL;

ALTER TABLE `calendar_events`
  ADD COLUMN IF NOT EXISTS `ics_uid` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `series_id` bigint(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recurrence_id` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_by` int(11) DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_cal_start` (`calendar_id`, `start_datetime`),
  ADD INDEX IF NOT EXISTS `idx_series` (`series_id`),
  ADD INDEX IF NOT EXISTS `idx_ics_uid` (`calendar_id`, `ics_uid`(191));

ALTER TABLE `calendar_event_reminders`
  ADD COLUMN IF NOT EXISTS `last_occurrence` datetime DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_pending` (`sent_at`, `event_id`);

ALTER TABLE `calendar_settings`
  ADD COLUMN IF NOT EXISTS `enable_invoice_due` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `enable_project_dates` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `prefs_json` longtext DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- Optional tidy-up (not required; review before running).
--
-- Project, task, invoice and quote dates are now shown live from their
-- modules. The retired syncs had copied them into calendar events; those
-- copies are already hidden from the calendar (they are the only events that
-- carry such a link and have no participants — anything a person created has
-- an organiser). To delete them, and then the empty calendars the syncs
-- created, uncomment:
--
-- DELETE e FROM calendar_events e
--  WHERE NOT EXISTS (SELECT 1 FROM calendar_event_participants p WHERE p.event_id = e.id)
--    AND EXISTS (SELECT 1 FROM calendar_event_links l WHERE l.event_id = e.id
--                 AND l.linked_type IN ('project','item','invoice','quote'));
--
-- DELETE c FROM calendars c
--  WHERE c.name IN ('Projects','Tasks','Finance','Q&I')
--    AND NOT EXISTS (SELECT 1 FROM calendar_events e WHERE e.calendar_id = c.id);
