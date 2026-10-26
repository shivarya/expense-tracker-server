<?php
/**
 * Pure-logic checks for payments to yourself and for learned-rule direction:
 *   php scripts/check_self_transfer_rules.php
 * No DB. Exit code 1 on any failure.
 */

require_once __DIR__ . '/../utils/selfTransfer.php';
require_once __DIR__ . '/../utils/categoryLearning.php';

date_default_timezone_set('Asia/Kolkata');

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

// --- SelfTransfer::isOwnName -------------------------------------------------
$owner = 'Ashish Tripathi (Shivam)';
check('UPI payee name in capitals is the owner', SelfTransfer::isOwnName('ASHISH TRIPATHI', $owner));
check('surname-first order still matches', SelfTransfer::isOwnName('TRIPATHI ASHISH', $owner));
check('a title before the name is ignored', SelfTransfer::isOwnName('Mr. Ashish Tripathi', $owner));
check('punctuation is ignored', SelfTransfer::isOwnName('ASHISH  TRIPATHI.', $owner));
check('a different surname is not the owner', !SelfTransfer::isOwnName('Ashish Sharma', $owner));
check('first name alone never matches', !SelfTransfer::isOwnName('Ashish', $owner));
check('an extra middle name is not the owner', !SelfTransfer::isOwnName('Ashish Kumar Tripathi', $owner));
check('a shop name containing the name is not the owner', !SelfTransfer::isOwnName('Tripathi General Store', $owner));
check('a one-word owner name never matches', !SelfTransfer::isOwnName('Shivam', 'Shivam'));
check('empty payee never matches', !SelfTransfer::isOwnName('', $owner));
check('a bare UPI ID is not a name match', !SelfTransfer::isOwnName('shivarya3@axl', $owner));
check('bracketed nickname dropped from the owner', SelfTransfer::words($owner) === ['ashish', 'tripathi']);

// --- CategoryLearning::ruleFitsDirection -------------------------------------
check('a debit never takes an income rule', !CategoryLearning::ruleFitsDirection('income', 'debit'));
check('a debit takes an expense rule', CategoryLearning::ruleFitsDirection('expense', 'debit'));
check('a debit takes a transfer rule', CategoryLearning::ruleFitsDirection('transfer', 'debit'));
check('a debit takes an investment rule', CategoryLearning::ruleFitsDirection('investment', 'debit'));
check('a credit takes an income rule', CategoryLearning::ruleFitsDirection('income', 'credit'));
check('a credit keeps an expense rule (refund under the spend it reverses)', CategoryLearning::ruleFitsDirection('expense', 'credit'));
check('unknown direction keeps every rule', CategoryLearning::ruleFitsDirection('income', ''));
check('direction match ignores case', !CategoryLearning::ruleFitsDirection('INCOME', 'Debit'));

echo PHP_EOL . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
