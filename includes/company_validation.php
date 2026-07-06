<?php
// /includes/company_validation.php
//
// Shared validation for the company profile save paths (admin/company.php and
// qi/settings.php). Keeps the SARS registration-number format rules in one
// place so both editors reject the same bad input with the same message.

/**
 * Validate company profile fields. Only validates what is present in $fields;
 * empty values are allowed (a company need not be VAT-registered) — but a
 * non-empty value must be well-formed.
 *
 * @param array $fields e.g. ['vat_number' => ..., 'tax_reference' => ...]
 * @return array Error strings; empty array = valid.
 */
function validate_company_profile(array $fields): array
{
    $errors = [];

    $vat = trim((string)($fields['vat_number'] ?? ''));
    if ($vat !== '' && !preg_match('/^4\d{9}$/', $vat)) {
        $errors[] = 'South African VAT numbers are 10 digits starting with 4';
    }

    $taxRef = trim((string)($fields['tax_reference'] ?? ''));
    if ($taxRef !== '' && !preg_match('/^\d{10}$/', $taxRef)) {
        $errors[] = 'SARS income tax reference numbers are 10 digits';
    }

    return $errors;
}
