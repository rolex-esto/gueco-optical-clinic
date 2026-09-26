<?php
/**
 * Unit Tests for Walk-in Patient Data Validation
 */
require_once __DIR__ . '/../config/functions.php';

echo "============================================================\n";
echo " RUNNING WALKIN PATIENT VALIDATION TESTS\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $message) {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] $message\n";
        $passCount++;
    } else {
        echo " [FAIL] $message\n";
        $failCount++;
    }
}

// Test 1: Excessive repetitive characters in name (user screenshot example)
$res = validateWalkinPatientData(['full_name' => 'SSSSSSSSS', 'phone' => '0928']);
assertCondition(!$res['valid'] && isset($res['errors']['full_name']), 'Blocks repetitive full name ("SSSSSSSSS")');
assertCondition(!$res['valid'] && isset($res['errors']['phone']), 'Blocks incomplete 4-digit phone number ("0928")');

// Test 2: Single word name
$res = validateWalkinPatientData(['full_name' => 'John']);
assertCondition(!$res['valid'] && isset($res['errors']['full_name']), 'Requires at least two words (First and Last Name)');

// Test 3: Name without vowels (nonsense gibberish)
$res = validateWalkinPatientData(['full_name' => 'Zxcvb Nmlkj']);
assertCondition(!$res['valid'] && isset($res['errors']['full_name']), 'Blocks name words lacking vowels');

// Test 4: Placeholder/Test names
$res = validateWalkinPatientData(['full_name' => 'Test Patient']);
assertCondition(!$res['valid'] && isset($res['errors']['full_name']), 'Blocks placeholder strings like "Test Patient"');

// Test 5: Phone validation rules
$res = validateWalkinPatientData(['full_name' => 'Juan Dela Cruz', 'phone' => '09111111111']);
assertCondition(!$res['valid'] && isset($res['errors']['phone']), 'Blocks repetitive digit phone numbers ("09111111111")');

$res = validateWalkinPatientData(['full_name' => 'Juan Dela Cruz', 'phone' => '09123456789']);
assertCondition(!$res['valid'] && isset($res['errors']['phone']), 'Blocks sequential test phone numbers ("09123456789")');

// Test 6: Birthdate rules
$res = validateWalkinPatientData(['full_name' => 'Juan Dela Cruz', 'birthdate' => date('Y-m-d', strtotime('+1 day'))]);
assertCondition(!$res['valid'] && isset($res['errors']['birthdate']), 'Blocks future birthdates');

$res = validateWalkinPatientData(['full_name' => 'Juan Dela Cruz', 'birthdate' => '1880-01-01']);
assertCondition(!$res['valid'] && isset($res['errors']['birthdate']), 'Blocks birthdates prior to 1900');

// Test 7: Address rules
$res = validateWalkinPatientData(['full_name' => 'Juan Dela Cruz', 'address' => '12']);
assertCondition(!$res['valid'] && isset($res['errors']['address']), 'Blocks address shorter than 3 characters');

$res = validateWalkinPatientData(['full_name' => 'Juan Dela Cruz', 'address' => '123456']);
assertCondition(!$res['valid'] && isset($res['errors']['address']), 'Blocks address with numbers only');

// Test 8: Valid Filipino walk-in record with optional fields
$valid = validateWalkinPatientData([
    'full_name' => 'Maria Dela Santos',
    'phone'     => '09181234567',
    'email'     => 'maria.santos@gmail.com',
    'gender'    => 'female',
    'birthdate' => '1995-05-15',
    'address'   => 'Angeles City, Pampanga'
]);
assertCondition($valid['valid'] === true && empty($valid['errors']), 'Accepts valid patient record');

// Test 9: Valid minimal record (name only)
$minimal = validateWalkinPatientData(['full_name' => 'Roberto Garcia']);
assertCondition($minimal['valid'] === true && empty($minimal['errors']), 'Accepts minimal valid record with optional fields omitted');

echo "\n============================================================\n";
echo " TEST RESULTS: $passCount PASSED, $failCount FAILED\n";
echo "============================================================\n";
