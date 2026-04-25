<?php
// Single source of truth for the business types stored in
// `companies.business_type`. Keys MUST match the ENUM values defined for
// that column — update both together (see Migrations).

function fw_business_types(): array
{
    return [
        'construction'  => 'Construction',
        'postal'        => 'Postal/Courier',
        'hairdresser'   => 'Hairdresser/Salon',
        'retail'        => 'Retail',
        'restaurant'    => 'Restaurant/Food Service',
        'manufacturing' => 'Manufacturing',
        'supplies'      => 'Supplies/Wholesale',
        'services'      => 'Professional Services',
        'healthcare'    => 'Healthcare/Medical',
        'it'            => 'IT/Technology',
        'automotive'    => 'Automotive',
        'logistics'     => 'Logistics/Transport',
        'cleaning'      => 'Cleaning Services',
        'education'     => 'Education/Training',
        'realestate'    => 'Real Estate/Property',
        'agriculture'   => 'Agriculture/Farming',
        'other'         => 'Other',
    ];
}

function fw_business_type_keys(): array
{
    return array_keys(fw_business_types());
}

function fw_business_type_label(?string $key): string
{
    $types = fw_business_types();
    return $types[$key] ?? ($types['construction']);
}
