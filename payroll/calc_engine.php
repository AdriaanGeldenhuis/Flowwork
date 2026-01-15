<?php
// /payroll/calc_engine.php

class PayrollCalcEngine {
    private $DB;
    private $companyId;
    private $taxTable = null;
    private $payitems = [];

    // Constants (basis points = rate * 10000)
    const UIF_RATE_BP = 10000; // 1%
    const UIF_CAP_ANNUAL_CENTS = 17712200; // R 177,122 annual cap (2024/25)
    const SDL_RATE_BP = 10000; // 1%

    public function __construct($DB, $companyId) {
        $this->DB = $DB;
        $this->companyId = $companyId;
    }

    public function loadTaxTables($forDate) {
        $stmt = $this->DB->prepare("
            SELECT * FROM tax_tables_za
            WHERE (company_id = ? OR company_id IS NULL)
            AND effective_from <= ?
            AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY company_id DESC, effective_from DESC
            LIMIT 1
        ");
        $stmt->execute([$this->companyId, $forDate, $forDate]);
        $this->taxTable = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$this->taxTable) {
            throw new Exception('No tax table found for period');
        }

        // Decode bracket JSON
        $this->taxTable['brackets'] = json_decode($this->taxTable['bracket_json'], true);
    }

    public function loadPayitems() {
        $stmt = $this->DB->prepare("
            SELECT * FROM payitems 
            WHERE company_id = ? AND active = 1
            ORDER BY type ASC, name ASC
        ");
        $stmt->execute([$this->companyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $this->payitems[$item['id']] = $item;
        }
    }

    public function calculateEmployee($employee, $run) {
        $result = [
            'employee_id' => $employee['id'],
            'gross_cents' => 0,
            'taxable_income_cents' => 0,
            'paye_cents' => 0,
            'uif_employee_cents' => 0,
            'uif_employer_cents' => 0,
            'sdl_cents' => 0,
            'other_deductions_cents' => 0,
            'reimbursements_cents' => 0,
            'net_cents' => 0,
            'employer_cost_cents' => 0,
            'bank_amount_cents' => 0,
            'lines' => [],
            'debug' => []
        ];

        // Step 1: Calculate earnings
        $this->calculateEarnings($employee, $run, $result);

        // Step 2: Calculate PAYE
        $this->calculatePAYE($employee, $run, $result);

        // Step 3: Calculate UIF
        $this->calculateUIF($employee, $run, $result);

        // Step 4: Calculate SDL
        $this->calculateSDL($employee, $run, $result);

        // Step 5: Other deductions
        $this->calculateDeductions($employee, $run, $result);

        // Step 6: Calculate net
        $result['net_cents'] = $result['gross_cents'] 
            - $result['paye_cents'] 
            - $result['uif_employee_cents'] 
            - $result['other_deductions_cents']
            + $result['reimbursements_cents'];

        // Step 7: Employer cost
        $result['employer_cost_cents'] = $result['gross_cents']
            + $result['uif_employer_cents']
            + $result['sdl_cents'];

        // Bank amount (rounded)
        $result['bank_amount_cents'] = $this->roundCents($result['net_cents']);

        return $result;
    }

    private function calculateEarnings($employee, $run, &$result) {
        $baseSalaryCents = (int)$employee['base_salary_cents'];
        $frequency = $run['frequency'];

        // Calculate period salary
        if ($frequency === 'monthly') {
            // Full month salary
            $periodSalaryCents = $baseSalaryCents;
            
        } elseif ($frequency === 'fortnight') {
            // Monthly / 2
            $periodSalaryCents = (int)($baseSalaryCents / 2);
            
        } elseif ($frequency === 'weekly') {
            // Monthly / 4.33 (avg weeks per month)
            $periodSalaryCents = (int)($baseSalaryCents / 4.33);
        } else {
            $periodSalaryCents = $baseSalaryCents;
        }

        // Find or create basic salary payitem
        $basicPayitemId = $this->getOrCreatePayitem('BASIC_SALARY', 'Basic Salary', 'earning', true, true, true);

        $result['lines'][] = [
            'payitem_id' => $basicPayitemId,
            'description' => 'Basic Salary',
            'qty' => 1,
            'rate_cents' => $periodSalaryCents,
            'amount_cents' => $periodSalaryCents,
            'project_id' => $employee['default_project_id'] ?? null,
            'board_id' => null,
            'item_id' => null,
            'is_adhoc' => 0
        ];

        $result['gross_cents'] += $periodSalaryCents;
        $result['taxable_income_cents'] += $periodSalaryCents;

        // Load employee recurring payitems
        $stmt = $this->DB->prepare("
            SELECT epi.*, pi.code, pi.name, pi.type, pi.taxable, pi.uif_subject, pi.sdl_subject
            FROM employee_payitems epi
            JOIN payitems pi ON pi.id = epi.payitem_id
            WHERE epi.employee_id = ? AND epi.company_id = ? AND epi.active = 1
            AND pi.type IN ('earning', 'benefit')
            AND (epi.effective_from IS NULL OR epi.effective_from <= ?)
            AND (epi.effective_to IS NULL OR epi.effective_to >= ?)
        ");
        $stmt->execute([
            $employee['id'], 
            $this->companyId, 
            $run['period_end'], 
            $run['period_start']
        ]);
        $empPayitems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($empPayitems as $epi) {
            $amountCents = (int)$epi['amount_cents'];
            
            $result['lines'][] = [
                'payitem_id' => $epi['payitem_id'],
                'description' => $epi['name'],
                'qty' => 1,
                'rate_cents' => $amountCents,
                'amount_cents' => $amountCents,
                'project_id' => null,
                'board_id' => null,
                'item_id' => null,
                'is_adhoc' => 0
            ];

            $result['gross_cents'] += $amountCents;
            
            if ($epi['taxable'] == 1) {
                $result['taxable_income_cents'] += $amountCents;
            }
        }

        $result['debug']['earnings'] = [
            'base_salary_cents' => $baseSalaryCents,
            'period_salary_cents' => $periodSalaryCents,
            'recurring_items_count' => count($empPayitems)
        ];
    }

    private function calculatePAYE($employee, $run, &$result) {
        $taxableIncomeCents = $result['taxable_income_cents'];
        
        if ($taxableIncomeCents <= 0) {
            $result['paye_cents'] = 0;
            return;
        }

        // Annualize for monthly
        $frequency = $run['frequency'];
        if ($frequency === 'monthly') {
            $annualIncomeCents = $taxableIncomeCents * 12;
        } elseif ($frequency === 'fortnight') {
            $annualIncomeCents = $taxableIncomeCents * 26;
        } elseif ($frequency === 'weekly') {
            $annualIncomeCents = $taxableIncomeCents * 52;
        } else {
            $annualIncomeCents = $taxableIncomeCents * 12;
        }

        // Calculate annual PAYE using brackets
        $annualPAYECents = $this->calculateAnnualPAYE($annualIncomeCents);

        // Apply primary rebate
        $rebateCents = (int)$this->taxTable['rebate_primary_cents'];
        $annualPAYECents -= $rebateCents;

        if ($annualPAYECents < 0) {
            $annualPAYECents = 0;
        }

        // Convert back to period
        if ($frequency === 'monthly') {
            $periodPAYECents = (int)($annualPAYECents / 12);
        } elseif ($frequency === 'fortnight') {
            $periodPAYECents = (int)($annualPAYECents / 26);
        } elseif ($frequency === 'weekly') {
            $periodPAYECents = (int)($annualPAYECents / 52);
        } else {
            $periodPAYECents = (int)($annualPAYECents / 12);
        }

        $result['paye_cents'] = $periodPAYECents;

        $result['debug']['paye'] = [
            'taxable_income_cents' => $taxableIncomeCents,
            'annual_income_cents' => $annualIncomeCents,
            'annual_paye_before_rebate_cents' => $annualPAYECents + $rebateCents,
            'rebate_cents' => $rebateCents,
            'annual_paye_cents' => $annualPAYECents,
            'period_paye_cents' => $periodPAYECents
        ];
    }

    private function calculateAnnualPAYE($annualIncomeCents) {
        $brackets = $this->taxTable['brackets'];
        $payeCents = 0;

        foreach ($brackets as $bracket) {
            $thresholdCents = (int)$bracket['threshold_cents'];
            $rateBP = (int)$bracket['rate_bp'];
            $cumulativeCents = (int)$bracket['cumulative_cents'];

            if ($annualIncomeCents <= $thresholdCents) {
                // Income falls in this bracket
                $taxableInBracket = $annualIncomeCents;
                $payeCents = (int)($taxableInBracket * $rateBP / 10000);
                break;
            } else {
                // Income exceeds this bracket, use cumulative
                $payeCents = $cumulativeCents;
                
                // Check if there's a next bracket
                $currentIndex = array_search($bracket, $brackets);
                if (isset($brackets[$currentIndex + 1])) {
                    $nextThreshold = (int)$brackets[$currentIndex + 1]['threshold_cents'];
                    if ($annualIncomeCents > $nextThreshold) {
                        // Move to next bracket
                        continue;
                    } else {
                        // Calculate tax for amount above current threshold
                        $taxableInBracket = $annualIncomeCents - $thresholdCents;
                        $nextRateBP = (int)$brackets[$currentIndex + 1]['rate_bp'];
                        $payeCents = $cumulativeCents + (int)($taxableInBracket * $nextRateBP / 10000);
                        break;
                    }
                } else {
                    // Highest bracket
                    $taxableInBracket = $annualIncomeCents - $thresholdCents;
                    $payeCents = $cumulativeCents + (int)($taxableInBracket * $rateBP / 10000);
                    break;
                }
            }
        }

        return $payeCents;
    }

    private function calculateUIF($employee, $run, &$result) {
        if ($employee['uif_included'] != 1) {
            $result['uif_employee_cents'] = 0;
            $result['uif_employer_cents'] = 0;
            return;
        }

        // UIF is 1% employee + 1% employer, capped at annual limit
        $grossCents = $result['gross_cents'];
        
        // Calculate monthly cap
        $frequency = $run['frequency'];
        if ($frequency === 'monthly') {
            $periodCapCents = (int)(self::UIF_CAP_ANNUAL_CENTS / 12);
        } elseif ($frequency === 'fortnight') {
            $periodCapCents = (int)(self::UIF_CAP_ANNUAL_CENTS / 26);
        } elseif ($frequency === 'weekly') {
            $periodCapCents = (int)(self::UIF_CAP_ANNUAL_CENTS / 52);
        } else {
            $periodCapCents = (int)(self::UIF_CAP_ANNUAL_CENTS / 12);
        }

        $cappedGrossCents = min($grossCents, $periodCapCents);

        $uifEmployeeCents = (int)($cappedGrossCents * self::UIF_RATE_BP / 1000000); // 1%
        $uifEmployerCents = (int)($cappedGrossCents * self::UIF_RATE_BP / 1000000); // 1%

        $result['uif_employee_cents'] = $uifEmployeeCents;
        $result['uif_employer_cents'] = $uifEmployerCents;

        $result['debug']['uif'] = [
            'gross_cents' => $grossCents,
            'period_cap_cents' => $periodCapCents,
            'capped_gross_cents' => $cappedGrossCents,
            'employee_cents' => $uifEmployeeCents,
            'employer_cents' => $uifEmployerCents
        ];
    }

    private function calculateSDL($employee, $run, &$result) {
        if ($employee['sdl_included'] != 1) {
            $result['sdl_cents'] = 0;
            return;
        }

        // SDL is 1% of gross, paid by employer
        $grossCents = $result['gross_cents'];
        $sdlCents = (int)($grossCents * self::SDL_RATE_BP / 1000000); // 1%

        $result['sdl_cents'] = $sdlCents;

        $result['debug']['sdl'] = [
            'gross_cents' => $grossCents,
            'sdl_cents' => $sdlCents
        ];
    }

    private function calculateDeductions($employee, $run, &$result) {
        // Load recurring deductions
        $stmt = $this->DB->prepare("
            SELECT epi.*, pi.code, pi.name, pi.type
            FROM employee_payitems epi
            JOIN payitems pi ON pi.id = epi.payitem_id
            WHERE epi.employee_id = ? AND epi.company_id = ? AND epi.active = 1
            AND pi.type = 'deduction'
            AND (epi.effective_from IS NULL OR epi.effective_from <= ?)
            AND (epi.effective_to IS NULL OR epi.effective_to >= ?)
        ");
        $stmt->execute([
            $employee['id'], 
            $this->companyId, 
            $run['period_end'], 
            $run['period_start']
        ]);
        $deductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deductions as $ded) {
            $amountCents = (int)$ded['amount_cents'];
            
            $result['lines'][] = [
                'payitem_id' => $ded['payitem_id'],
                'description' => $ded['name'],
                'qty' => 1,
                'rate_cents' => -$amountCents,
                'amount_cents' => -$amountCents,
                'project_id' => null,
                'board_id' => null,
                'item_id' => null,
                'is_adhoc' => 0
            ];

            $result['other_deductions_cents'] += $amountCents;
        }
    }

    private function getOrCreatePayitem($code, $name, $type, $taxable, $uifSubject, $sdlSubject) {
        $stmt = $this->DB->prepare("
            SELECT id FROM payitems 
            WHERE company_id = ? AND code = ?
        ");
        $stmt->execute([$this->companyId, $code]);
        $item = $stmt->fetch();

        if ($item) {
            return $item['id'];
        }

        // Create it
        $stmt = $this->DB->prepare("
            INSERT INTO payitems (company_id, code, name, type, taxable, uif_subject, sdl_subject, active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $this->companyId, $code, $name, $type,
            $taxable ? 1 : 0,
            $uifSubject ? 1 : 0,
            $sdlSubject ? 1 : 0
        ]);

        return $this->DB->lastInsertId();
    }

    private function roundCents($cents) {
        // Round to nearest cent
        return (int)round($cents);
    }
}