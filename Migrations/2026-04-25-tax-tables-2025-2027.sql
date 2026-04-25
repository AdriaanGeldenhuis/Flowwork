-- Add SARS PAYE tax tables for the 2025/2026 and 2026/2027 SA tax years.
-- Without these the calc engine throws "No tax table found for period" when
-- adding employees to any pay run dated on/after 2025-03-01 (e.g. the April
-- 2026 monthly run referenced in /payroll/run_view.php?id=3).
--
-- Brackets, rebates, UIF/SDL and medical credits mirror the existing
-- 2024/2025 system-default row in tax_tables_za. Update with the official
-- SARS values from the relevant Budget Speech once they are confirmed.

INSERT IGNORE INTO `tax_tables_za`
  (`company_id`, `effective_from`, `effective_to`, `bracket_json`,
   `rebate_primary_cents`, `rebate_secondary_cents`, `rebate_tertiary_cents`,
   `uif_cap_annual_cents`, `uif_rate_bp`, `sdl_rate_bp`,
   `medical_credit_main_cents`, `medical_credit_dep_cents`)
VALUES
  (NULL, '2025-03-01', '2026-02-28',
   '[
      {"threshold_cents": 0,         "rate_bp": 1800, "cumulative_cents": 0},
      {"threshold_cents": 23730000,  "rate_bp": 2600, "cumulative_cents": 4271400},
      {"threshold_cents": 37000000,  "rate_bp": 3100, "cumulative_cents": 7738500},
      {"threshold_cents": 51200000,  "rate_bp": 3600, "cumulative_cents": 12170500},
      {"threshold_cents": 67300000,  "rate_bp": 3900, "cumulative_cents": 17967500},
      {"threshold_cents": 87000000,  "rate_bp": 4100, "cumulative_cents": 25643500},
      {"threshold_cents": 179400000, "rate_bp": 4500, "cumulative_cents": 63488500}
    ]',
   1748400, 965700, 322200, 17712200, 10000, 10000, 34700, 23400),

  (NULL, '2026-03-01', '2027-02-28',
   '[
      {"threshold_cents": 0,         "rate_bp": 1800, "cumulative_cents": 0},
      {"threshold_cents": 23730000,  "rate_bp": 2600, "cumulative_cents": 4271400},
      {"threshold_cents": 37000000,  "rate_bp": 3100, "cumulative_cents": 7738500},
      {"threshold_cents": 51200000,  "rate_bp": 3600, "cumulative_cents": 12170500},
      {"threshold_cents": 67300000,  "rate_bp": 3900, "cumulative_cents": 17967500},
      {"threshold_cents": 87000000,  "rate_bp": 4100, "cumulative_cents": 25643500},
      {"threshold_cents": 179400000, "rate_bp": 4500, "cumulative_cents": 63488500}
    ]',
   1748400, 965700, 322200, 17712200, 10000, 10000, 34700, 23400);
