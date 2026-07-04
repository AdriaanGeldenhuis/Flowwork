-- CRM opportunities: win/loss capture + stage hygiene (2026-07-04)
--
-- loss_reason: free-text reason recorded when a deal is moved to 'lost'
--   (kanban drop modal / opportunity view).
-- stage_changed_at: stamped by opportunity_update.php whenever the stage
--   changes; used for the board's stale-deal indicator.

ALTER TABLE crm_opportunities
    ADD COLUMN loss_reason VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN stage_changed_at DATETIME NULL DEFAULT NULL;
