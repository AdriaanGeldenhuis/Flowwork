-- Add neighbouring SADC countries to crm_regions so accounts outside
-- South Africa (e.g. Kalengwa, Zambia) can be captured.

INSERT INTO `crm_regions` (`name`) VALUES
('Botswana'),
('Lesotho'),
('Mozambique'),
('Namibia'),
('eSwatini'),
('Zimbabwe'),
('Zambia');
