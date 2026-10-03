<?php
/**
 * Assertion checks for the payment-notification merge rules — pure logic, no DB,
 * no network. Run after touching utils/crossSourceMerger.php or utils/upiRef.php:
 *
 *   php scripts/check_cross_source_rules.php
 */

require_once __DIR__ . '/../utils/crossSourceMerger.php';
require_once __DIR__ . '/../utils/upiRef.php';

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

// --- UpiRef ------------------------------------------------------------------
check('bare reference', UpiRef::extract('412345678901') === '412345678901');
check('prefixed reference', UpiRef::extract('SBI_412345678901') === '412345678901');
check('HDFC UPI/P2A layout', UpiRef::extract('UPI/P2A/412345678901/RAMESH') === '412345678901');
check('labelled in text', UpiRef::extract(null, 'Paid Rs 50. UPI Ref No 412345678901.') === '412345678901');
check('unlabelled phone number ignored', UpiRef::extract(null, 'Paid ₹50 to 919876543210') === null);
check('13 digits ignored', UpiRef::extract('4123456789012') === null);
check('nothing', UpiRef::extract(null, '') === null);

// --- pickTwin ----------------------------------------------------------------
function incoming(array $o = []): array
{
    $family = CrossSourceMerger::familyOf($o['source'] ?? 'sms', $o['package'] ?? null);
    return $o + [
        'family' => $family, 'source' => 'sms', 'package' => '', 'transaction_type' => 'debit',
        'amount' => 100.0, 'at' => '2026-10-02 12:00:00', 'upi_ref' => null, 'hash' => '',
        'instrument' => '', 'account_type' => 'savings', 'evidence' => [],
    ];
}
function row(int $id, string $source, string $at, array $o = []): array
{
    $family = CrossSourceMerger::familyOf($source, $o['package'] ?? null);
    $evidence = [['family' => $family, 'source' => $source, 'hash' => $o['hash'] ?? "h$id", 'instrument' => $o['instrument'] ?? '', 'package' => $o['package'] ?? '']];
    unset($o['package'], $o['hash'], $o['instrument']);
    return $o + [
        'id' => $id, 'source' => $source, 'transaction_type' => 'debit', 'amount' => '100.00',
        'transaction_date' => $at, 'upi_ref' => null, 'account_type' => 'savings', 'deleted_at' => null,
        'source_data' => json_encode(['evidence' => $evidence]),
    ];
}
$phonepe = ['source' => 'app_notification', 'package' => 'com.phonepe.app'];
$id = fn($r) => $r === null ? null : $r['row']['id'];

$r = CrossSourceMerger::pickTwin(incoming(['at' => '2026-10-02 12:00:40']), [row(1, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('SMS merges into notification row', $r !== null && $r['action'] === 'merge' && $id($r) === 1);

$r = CrossSourceMerger::pickTwin(incoming($phonepe + ['at' => '2026-10-02 12:00:10']), [row(2, 'sms', '2026-10-02 12:00:00')]);
check('notification merges into SMS row', $id($r) === 2);

$r = CrossSourceMerger::pickTwin(incoming(['at' => '2026-10-02 12:00:00']), [row(3, 'sms', '2026-10-02 12:00:05')]);
check('bank vs bank left to the duplicate detector', $r === null);

$r = CrossSourceMerger::pickTwin(incoming($phonepe), [row(4, 'app_notification', '2026-10-02 12:00:05', ['package' => 'com.phonepe.app'])]);
check('same app twice = two payments', $r === null);

$r = CrossSourceMerger::pickTwin(incoming(['source' => 'app_notification', 'package' => 'com.snapwork.hdfc']), [row(5, 'app_notification', '2026-10-02 12:00:05', ['package' => 'com.phonepe.app'])]);
check('bank app merges into another app\'s row', $id($r) === 5);

$r = CrossSourceMerger::pickTwin(incoming(['at' => '2026-10-02 12:20:00']), [row(6, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('outside the 15-minute window', $r === null);

$r = CrossSourceMerger::pickTwin(incoming(['at' => '2026-10-02 12:03:20']), [
    row(7, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app']),
    row(8, 'app_notification', '2026-10-02 12:03:00', ['package' => 'com.phonepe.app']),
]);
check('closest in time wins', $id($r) === 8);

$r = CrossSourceMerger::pickTwin(incoming(['amount' => 100.5]), [row(9, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('amount must match', $r === null);

$r = CrossSourceMerger::pickTwin(incoming(['transaction_type' => 'credit']), [row(10, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('type must match', $r === null);

$r = CrossSourceMerger::pickTwin(incoming(['upi_ref' => '412345678901', 'at' => '2026-10-02 18:00:00']), [row(11, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'upi_ref' => '412345678901'])]);
check('equal UPI ref merges beyond the window', $r !== null && $r['reason'] === 'upi_ref');

$r = CrossSourceMerger::pickTwin(incoming(['upi_ref' => '412345678901']), [row(12, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'upi_ref' => '999999999999'])]);
check('different UPI refs never merge', $r === null);

$r = CrossSourceMerger::pickTwin(incoming(['hash' => 'abc', 'amount' => 1.0]), [row(13, 'sms', '2026-10-02 12:00:00', ['hash' => 'abc'])]);
check('same hash is a replay', $r !== null && $r['action'] === 'replay');

$r = CrossSourceMerger::pickTwin(incoming(['hash' => 'abc']), [row(14, 'sms', '2026-10-02 12:00:00', ['hash' => 'abc', 'deleted_at' => '2026-10-02 13:00:00'])]);
check('replay of a deleted row stays skipped', $r !== null && $r['action'] === 'replay');

$r = CrossSourceMerger::pickTwin(incoming(), [row(15, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'deleted_at' => '2026-10-02 13:00:00'])]);
check('deleted rows are not merge targets', $r === null);

$r = CrossSourceMerger::pickTwin(incoming($phonepe), [row(16, 'sms', '2026-10-02 12:00:05', ['account_type' => 'credit_card'])]);
check('UPI notification never folds into a card swipe', $r === null);

$r = CrossSourceMerger::pickTwin(incoming($phonepe + ['instrument' => 'card']), [row(17, 'sms', '2026-10-02 12:00:05', ['account_type' => 'credit_card'])]);
check('card-paid notification may fold into a card row', $id($r) === 17);

$r = CrossSourceMerger::pickTwin(incoming(['account_type' => 'credit_card']), [row(18, 'app_notification', '2026-10-02 12:00:05', ['package' => 'com.dreamplug.androidapp'])]);
check('card SMS may fold into a CRED row', $id($r) === 18);

$legacy = row(19, 'sms', '2026-10-02 12:00:05');
$legacy['source_data'] = null;
$r = CrossSourceMerger::pickTwin(incoming($phonepe), [$legacy]);
check('row stored before evidence existed still merges', $id($r) === 19);

// --- helpers -----------------------------------------------------------------
check('VPA is a weak merchant', CrossSourceMerger::isWeakMerchant('swiggy@icici'));
check('"UPI transfer" is weak', CrossSourceMerger::isWeakMerchant('UPI transfer'));
check('a name is not weak', !CrossSourceMerger::isWeakMerchant('Ramesh Kumar'));
check('UPI (unlinked) is synthetic', CrossSourceMerger::isSyntheticAccount(['bank' => 'other', 'account_number' => 'UPI']));
check('wallet is synthetic', CrossSourceMerger::isSyntheticAccount(['bank' => 'other', 'account_number' => 'WALLET-PAYTM']));
check('real account is not synthetic', !CrossSourceMerger::isSyntheticAccount(['bank' => 'hdfc', 'account_number' => 'XXXX1234']));

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
