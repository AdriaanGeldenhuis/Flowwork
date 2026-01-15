<?php
// /payroll/ajax/employee_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$id = $_POST['id'] ?? 0;
$employeeNo = trim($_POST['employee_no'] ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$idNumber = trim($_POST['id_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$hireDate = $_POST['hire_date'] ?? '';
$employmentType = $_POST['employment_type'] ?? 'permanent';
$payFrequency = $_POST['pay_frequency'] ?? 'monthly';
$baseSalary = floatval($_POST['base_salary'] ?? 0);
$taxNumber = trim($_POST['tax_number'] ?? '');
$uifIncluded = isset($_POST['uif_included']) ? 1 : 0;
$sdlIncluded = isset($_POST['sdl_included']) ? 1 : 0;
$bankName = trim($_POST['bank_name'] ?? '');
$branchCode = trim($_POST['branch_code'] ?? '');
$bankAccountNo = trim($_POST['bank_account_no'] ?? '');

// Validation
if (empty($employeeNo)) {
    echo json_encode(['ok' => false, 'error' => 'Employee number required']);
    exit;
}
if (empty($firstName)) {
    echo json_encode(['ok' => false, 'error' => 'First name required']);
    exit;
}
if (empty($lastName)) {
    echo json_encode(['ok' => false, 'error' => 'Last name required']);
    exit;
}
if (empty($hireDate)) {
    echo json_encode(['ok' => false, 'error' => 'Hire date required']);
    exit;
}

$baseSalaryCents = (int)($baseSalary * 100);

try {
    $DB->beginTransaction();

    if ($id) {
        // Update existing
        $stmt = $DB->prepare("
            UPDATE employees SET
                employee_no = ?,
                first_name = ?,
                last_name = ?,
                id_number = ?,
                email = ?,
                phone = ?,
                hire_date = ?,
                employment_type = ?,
                pay_frequency = ?,
                base_salary_cents = ?,
                tax_number = ?,
                uif_included = ?,
                sdl_included = ?,
                bank_name = ?,
                branch_code = ?,
                bank_account_no = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $employeeNo, $firstName, $lastName, $idNumber, $email, $phone,
            $hireDate, $employmentType, $payFrequency, $baseSalaryCents,
            $taxNumber, $uifIncluded, $sdlIncluded,
            $bankName, $branchCode, $bankAccountNo,
            $id, $companyId
        ]);

        // Audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
            VALUES (?, ?, 'employee_updated', ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $userId,
            json_encode(['employee_id' => $id, 'name' => "$firstName $lastName"]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

    } else {
        // Insert new
        $stmt = $DB->prepare("
            INSERT INTO employees (
                company_id, employee_no, first_name, last_name, id_number,
                email, phone, hire_date, employment_type, pay_frequency,
                base_salary_cents, tax_number, uif_included, sdl_included,
                bank_name, branch_code, bank_account_no, created_by, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, NOW()
            )
        ");
        $stmt->execute([
            $companyId, $employeeNo, $firstName, $lastName, $idNumber,
            $email, $phone, $hireDate, $employmentType, $payFrequency,
            $baseSalaryCents, $taxNumber, $uifIncluded, $sdlIncluded,
            $bankName, $branchCode, $bankAccountNo, $userId
        ]);
        $id = $DB->lastInsertId();

        // Audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
            VALUES (?, ?, 'employee_created', ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $userId,
            json_encode(['employee_id' => $id, 'name' => "$firstName $lastName"]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'id' => $id
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Employee save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}