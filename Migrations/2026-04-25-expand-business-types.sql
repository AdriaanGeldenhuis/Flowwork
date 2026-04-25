-- Expand companies.business_type to cover the broader set of SMB
-- categories surfaced on the registration and Company Info pages.
-- Keys must stay in sync with includes/business_types.php.

ALTER TABLE `companies`
  MODIFY `business_type` enum(
    'construction',
    'postal',
    'hairdresser',
    'retail',
    'restaurant',
    'manufacturing',
    'supplies',
    'services',
    'healthcare',
    'it',
    'automotive',
    'logistics',
    'cleaning',
    'education',
    'realestate',
    'agriculture',
    'other'
  ) NOT NULL;
