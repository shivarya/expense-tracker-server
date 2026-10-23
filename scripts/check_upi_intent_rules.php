<?php
/**
 * Assertion checks for Scan & Pay (POST /parse/upi-intent) — the MCC category map
 * and payload validation. Pure logic, no DB, no network. Run after touching
 * utils/mccCategories.php or utils/upiIntentPayload.php:
 *
 *   php scripts/check_upi_intent_rules.php
 */

require_once __DIR__ . '/../utils/mccCategories.php';
require_once __DIR__ . '/../utils/upiIntentPayload.php';
require_once __DIR__ . '/../utils/categoryResolver.php';

date_default_timezone_set('Asia/Kolkata');

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}

// --- MCC map -----------------------------------------------------------------
$expected = [
    '5411' => 9, '5499' => 9,
    '5812' => 1, '5813' => 1, '5814' => 1,
    '5541' => 2, '5542' => 2, '4121' => 2, '4111' => 2,
    '4112' => 8, '4511' => 8, '7011' => 8,
    '5912' => 6, '8011' => 6, '8062' => 6, '8099' => 6,
    '8211' => 7, '8220' => 7, '8299' => 7,
    '4814' => 5, '4899' => 5, '4900' => 5,
    '5651' => 58, '5691' => 58, '5699' => 58,
    '5311' => 3, '5399' => 3, '5732' => 3,
    '7832' => 4, '7922' => 4,
    '7230' => 12, '7298' => 12,
    '6300' => 10,
];
$wrong = [];
foreach ($expected as $mcc => $category) {
    if (MccCategories::categoryFor((string)$mcc) !== $category) {
        $wrong[] = $mcc;
    }
}
check('every listed MCC maps to its category' . ($wrong ? ' (wrong: ' . implode(', ', $wrong) . ')' : ''), $wrong === []);
check('every mapped category is canonical', array_diff(array_unique(array_values($expected)), array_keys(CategoryResolver::CANONICAL)) === []);
check('0000 is person-to-person', MccCategories::categoryFor('0000') === null);
check('no MCC is person-to-person', MccCategories::categoryFor(null) === null && MccCategories::categoryFor('') === null);
check('unlisted MCC has no category', MccCategories::categoryFor('5999') === null);
check('8xxx outside 80/82 has no category', MccCategories::categoryFor('8398') === null);
check('malformed MCC is ignored', MccCategories::categoryFor('541') === null && MccCategories::categoryFor('54111') === null && MccCategories::categoryFor('ab12') === null);
check('MCC with spaces is trimmed', MccCategories::categoryFor(' 5411 ') === 9);

// --- payload validation ------------------------------------------------------
function payload(array $o = []): array
{
    return $o + [
        'client_ref' => '3f2b8c1e-9d4a-4b6e-a1c2-7e5f0d9b8a61',
        'package' => 'com.phonepe.app',
        'app_label' => 'PhonePe',
        'vpa' => 'Ramesh.Kumar@ybl',
        'payee_name' => 'RAMESH KUMAR',
        'amount' => '250.00',
        'note' => 'chai',
        'mcc' => '0000',
        'tr' => 'TR123',
        'status' => 'success',
        'txn_id' => 'YBL0123456789abc',
        'approval_ref' => '412345678901',
        'response_code' => '00',
        'completed_at' => '2026-10-23T06:30:00Z',
        'account_id' => 7,
        'mode' => 'record',
    ];
}
$ok = fn(array $p) => !isset($p['error']);

$p = UpiIntentPayload::normalize(payload());
check('valid payload is accepted', $ok($p));
check('amount is a rounded float', $p['amount'] === 250.0);
check('UPI ID is lower-cased', $p['vpa'] === 'ramesh.kumar@ybl');
check('known package takes its own label', UpiIntentPayload::normalize(payload(['app_label' => 'Fake']))['app_label'] === 'PhonePe');
check('unknown package keeps a sanitised app label', UpiIntentPayload::normalize(payload(['package' => 'com.mobikwik_new', 'app_label' => " MobiKwik\u{0007}\n"]))['app_label'] === 'MobiKwik');
check('unknown package without a label falls back to the package', UpiIntentPayload::normalize(payload(['package' => 'com.example.pay', 'app_label' => null]))['app_label'] === 'com.example.pay');
check('account_id is optional', $ok($p = UpiIntentPayload::normalize(payload(['account_id' => null]))) && $p['account_id'] === null);
check('account_id as a string is accepted', UpiIntentPayload::normalize(payload(['account_id' => '7']))['account_id'] === 7);
check('mode defaults to record', UpiIntentPayload::normalize(payload(['mode' => null]))['mode'] === 'record');
check('merge_only is accepted', UpiIntentPayload::normalize(payload(['mode' => 'merge_only']))['mode'] === 'merge_only');
check('status is case-insensitive', UpiIntentPayload::normalize(payload(['status' => 'SUBMITTED']))['status'] === 'submitted');
check('optional fields may be missing', $ok(UpiIntentPayload::normalize(['client_ref' => 'abcdef12', 'package' => 'com.phonepe.app', 'vpa' => 'a@ybl', 'amount' => 1, 'status' => 'confirmed'])));
check('amount with a thousands comma', UpiIntentPayload::normalize(payload(['amount' => '1,250.5']))['amount'] === 1250.5);
check('MCC is kept as 4 digits', UpiIntentPayload::normalize(payload(['mcc' => '5411']))['mcc'] === '5411');
check('malformed MCC is dropped', UpiIntentPayload::normalize(payload(['mcc' => 'xx']))['mcc'] === null);
check('note is cut to 500 characters', mb_strlen(UpiIntentPayload::normalize(payload(['note' => str_repeat('a', 900)]))['note']) === 500);
check('empty note becomes null', UpiIntentPayload::normalize(payload(['note' => '   ']))['note'] === null);

check('zero amount is rejected', !$ok(UpiIntentPayload::normalize(payload(['amount' => 0]))));
check('negative amount is rejected', !$ok(UpiIntentPayload::normalize(payload(['amount' => -5]))));
check('non-numeric amount is rejected', !$ok(UpiIntentPayload::normalize(payload(['amount' => '25O']))));
check('absurd amount is rejected', !$ok(UpiIntentPayload::normalize(payload(['amount' => 5000000]))));
check('missing amount is rejected', !$ok(UpiIntentPayload::normalize(payload(['amount' => null]))));
check('UPI ID without @ is rejected', !$ok(UpiIntentPayload::normalize(payload(['vpa' => 'ramesh']))));
check('UPI ID with a space is rejected', !$ok(UpiIntentPayload::normalize(payload(['vpa' => 'ram esh@ybl']))));
check('UPI ID with two @ is rejected', !$ok(UpiIntentPayload::normalize(payload(['vpa' => 'a@b@ybl']))));
check('UPI ID with a one-letter handle is rejected', !$ok(UpiIntentPayload::normalize(payload(['vpa' => 'ramesh@y']))));
check('missing client_ref is rejected', !$ok(UpiIntentPayload::normalize(payload(['client_ref' => '']))));
check('client_ref with odd characters is rejected', !$ok(UpiIntentPayload::normalize(payload(['client_ref' => "abc'; DROP"]))));
check('package must look like a package', !$ok(UpiIntentPayload::normalize(payload(['package' => 'phonepe']))));
check('failed status is rejected', !$ok(UpiIntentPayload::normalize(payload(['status' => 'failure']))));
check('unknown mode is rejected', !$ok(UpiIntentPayload::normalize(payload(['mode' => 'insert']))));
check('zero account_id is rejected', !$ok(UpiIntentPayload::normalize(payload(['account_id' => 0]))));
check('non-integer account_id is rejected', !$ok(UpiIntentPayload::normalize(payload(['account_id' => '7abc']))));

check('valid UPI IDs', UpiIntentPayload::isVpa('9876543210@ybl') && UpiIntentPayload::isVpa('paytmqr281005050101@paytm') && UpiIntentPayload::isVpa('shop.name-1_x@okhdfcbank'));

// --- UPI ref -----------------------------------------------------------------
check('12-digit approval ref is the UPI ref', UpiIntentPayload::upiRef(['approval_ref' => '412345678901', 'txn_id' => 'YBL1']) === '412345678901');
check('12-digit txn id is used when the approval ref is not', UpiIntentPayload::upiRef(['approval_ref' => 'AB12', 'txn_id' => '512345678901']) === '512345678901');
check('approval ref wins over txn id', UpiIntentPayload::upiRef(['approval_ref' => '412345678901', 'txn_id' => '512345678901']) === '412345678901');
check('prefixed ref is not trusted', UpiIntentPayload::upiRef(['approval_ref' => 'YBL412345678901', 'txn_id' => null]) === null);
check('11 or 13 digits are not a UPI ref', UpiIntentPayload::upiRef(['approval_ref' => '41234567890', 'txn_id' => '4123456789012']) === null);
check('no refs, no UPI ref', UpiIntentPayload::upiRef(['approval_ref' => null, 'txn_id' => null]) === null);

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
