<?php
/**
 * Assertion checks for the cross-source merge rules (payment-app notifications,
 * Scan & Pay records, bank SMS) — pure logic, no MySQL, no network; merge() runs
 * against an in-memory SQLite table when pdo_sqlite is loaded. Run after touching
 * utils/crossSourceMerger.php or utils/upiRef.php:
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
    if (isset($o['account'])) {
        $evidence[0]['account'] = $o['account'];
    }
    unset($o['package'], $o['hash'], $o['instrument'], $o['account']);
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

// --- Scan & Pay (upi_intent) -------------------------------------------------
$intentPhonepe = ['source' => 'upi_intent', 'package' => 'com.phonepe.app'];
$intentCred = ['source' => 'upi_intent', 'package' => 'com.dreamplug.androidapp'];
check('intent family is intent:<package>', CrossSourceMerger::familyOf('upi_intent', 'com.phonepe.app') === 'intent:com.phonepe.app');
check('intent family is not the app family', CrossSourceMerger::familyOf('upi_intent', 'com.phonepe.app') !== CrossSourceMerger::familyOf('app_notification', 'com.phonepe.app'));
check('intent counts as the non-bank side', CrossSourceMerger::isAppFamily('intent:com.phonepe.app') && CrossSourceMerger::isIntentFamily('intent:com.phonepe.app'));
check('a notification is not an intent', !CrossSourceMerger::isIntentFamily('app:com.phonepe.app'));

$r = CrossSourceMerger::pickTwin(incoming(['at' => '2026-10-02 12:00:40']), [row(20, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'account' => 'default'])]);
check('SMS merges into intent row', $r !== null && $r['action'] === 'merge' && $id($r) === 20);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['at' => '2026-10-02 12:00:10']), [row(21, 'sms', '2026-10-02 12:00:00')]);
check('intent merges into SMS row', $id($r) === 21);

$r = CrossSourceMerger::pickTwin(incoming($phonepe + ['at' => '2026-10-02 12:00:20']), [row(22, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('PhonePe notification merges into PhonePe intent', $id($r) === 22);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['at' => '2026-10-02 12:00:20']), [row(23, 'app_notification', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('PhonePe intent merges into PhonePe notification row', $id($r) === 23);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['at' => '2026-10-02 12:01:00']), [row(24, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('two same-amount intents from one app stay separate', $r === null);

$r = CrossSourceMerger::pickTwin(incoming($intentCred + ['at' => '2026-10-02 12:01:00']), [row(25, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app'])]);
check('two same-amount intents from different apps stay separate', $r === null);

$merged = row(26, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app']);
$merged['source_data'] = json_encode(['evidence' => [
    ['family' => 'intent:com.phonepe.app', 'hash' => 'h26', 'package' => 'com.phonepe.app'],
    ['family' => 'bank', 'hash' => 'h26b'],
]]);
$r = CrossSourceMerger::pickTwin(incoming($intentCred + ['at' => '2026-10-02 12:01:00']), [$merged]);
check('an intent never folds into a row another intent reported', $r === null);

$r = CrossSourceMerger::pickTwin(incoming(['account_type' => 'credit_card', 'at' => '2026-10-02 12:00:30']), [row(27, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'instrument' => 'card', 'account_type' => 'credit_card'])]);
check('card SMS merges into a card-paid intent', $id($r) === 27);

$r = CrossSourceMerger::pickTwin(incoming(['account_type' => 'credit_card', 'at' => '2026-10-02 12:00:30']), [row(28, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'instrument' => 'upi'])]);
check('card SMS never folds into a UPI-paid intent', $r === null);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['instrument' => 'card', 'at' => '2026-10-02 12:00:30']), [row(29, 'sms', '2026-10-02 12:00:00', ['account_type' => 'credit_card'])]);
check('card-paid intent merges into a card SMS row', $id($r) === 29);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['instrument' => 'upi', 'at' => '2026-10-02 12:00:30']), [row(30, 'sms', '2026-10-02 12:00:00', ['account_type' => 'credit_card'])]);
check('UPI-paid intent never folds into a card swipe', $r === null);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['hash' => 'cref1']), [row(31, 'upi_intent', '2026-10-02 12:00:00', ['package' => 'com.phonepe.app', 'hash' => 'cref1'])]);
check('same client_ref is a replay', $r !== null && $r['action'] === 'replay' && $id($r) === 31);

$r = CrossSourceMerger::pickTwin(incoming($intentPhonepe + ['hash' => 'cref2', 'amount' => 999.0]), [row(32, 'sms', '2026-10-02 12:00:00', ['hash' => 'x'])]);
check('a new client_ref with no matching report finds nothing', $r === null);

// --- provisional accounts and categories -------------------------------------
$evidenceRow = fn(array $evidence, array $o = []) => $o + ['bank' => 'hdfc', 'account_number' => 'XXXX1234', 'source_data' => json_encode(['evidence' => $evidence])];
check('Scan & Pay default account is provisional', CrossSourceMerger::accountIsProvisional($evidenceRow([['family' => 'intent:com.phonepe.app', 'account' => 'default']])));
check('…still provisional after a notification merged', CrossSourceMerger::accountIsProvisional($evidenceRow([['family' => 'app:com.phonepe.app'], ['family' => 'intent:com.phonepe.app', 'account' => 'default']])));
check('…confirmed once a bank report merged', !CrossSourceMerger::accountIsProvisional($evidenceRow([['family' => 'intent:com.phonepe.app', 'account' => 'default'], ['family' => 'bank']])));
check('SMS row on a real account is not provisional', !CrossSourceMerger::accountIsProvisional($evidenceRow([['family' => 'bank']])));
check('notification on a real account is not provisional', !CrossSourceMerger::accountIsProvisional($evidenceRow([['family' => 'app:com.snapwork.hdfc']])));
check('synthetic account is provisional', CrossSourceMerger::accountIsProvisional($evidenceRow([['family' => 'intent:com.phonepe.app', 'account' => 'none']], ['bank' => 'other', 'account_number' => 'UPI'])));
check('Uncategorized is always replaceable', CrossSourceMerger::categoryReplaceable(['category_id' => 18], []));
check('Miscellaneous is replaced by an MCC category', CrossSourceMerger::categoryReplaceable(['category_id' => 51], ['category_strong' => true]));
check('…but not by a guessed one', !CrossSourceMerger::categoryReplaceable(['category_id' => 51], ['category_strong' => false]));
check('a real category is never replaced', !CrossSourceMerger::categoryReplaceable(['category_id' => 9], ['category_strong' => true]));

// --- merge() against an in-memory table ---------------------------------------
if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, user_id INT, account_id INT, category_id INT, merchant TEXT, notes TEXT,
                reference_number TEXT, upi_ref TEXT, payment_method TEXT, transaction_date TEXT, source_data TEXT)');
    $seed = function (array $r) use ($pdo): array {
        $r += ['user_id' => 1, 'merchant' => null, 'notes' => null, 'reference_number' => null, 'upi_ref' => null, 'payment_method' => null];
        $pdo->prepare('INSERT INTO transactions (id, user_id, account_id, category_id, merchant, notes, reference_number, upi_ref, payment_method, transaction_date, source_data)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['id'], $r['user_id'], $r['account_id'], $r['category_id'], $r['merchant'], $r['notes'], $r['reference_number'], $r['upi_ref'], $r['payment_method'], $r['transaction_date'], $r['source_data']]);
        return $r;
    };
    $fetch = fn(int $rowId) => $pdo->query('SELECT * FROM transactions WHERE id = ' . $rowId)->fetch(PDO::FETCH_ASSOC);

    // SMS into a Scan & Pay row on the CRED default card: moves to the debited account.
    $r = $seed(['id' => 100, 'account_id' => 5, 'category_id' => 51, 'merchant' => 'Ramesh Kumar', 'notes' => 'chai',
        'transaction_date' => '2026-10-02 06:30:00', 'bank' => 'hdfc', 'account_number' => 'XXXX1111', 'source' => 'upi_intent',
        'source_data' => json_encode(['evidence' => [['family' => 'intent:com.dreamplug.androidapp', 'hash' => 'i1', 'account' => 'default']]])]);
    CrossSourceMerger::merge($pdo, 1, $r, incoming(['merchant' => 'UPI/P2A/ramesh@ybl', 'at' => '2026-10-02 06:30:40', 'upi_ref' => '412345678901', 'evidence' => ['family' => 'bank', 'hash' => 's1']]), 7, 9);
    $after = $fetch(100);
    check('merge: SMS moves a default-account row to the debited account', (int)$after['account_id'] === 7);
    check('merge: SMS keeps the Scan & Pay payee and P2P category', $after['merchant'] === 'Ramesh Kumar' && (int)$after['category_id'] === 51);
    check('merge: SMS fills the UPI ref, keeps the earlier time', $after['upi_ref'] === '412345678901' && $after['transaction_date'] === '2026-10-02 06:30:00');
    check('merge: evidence gains the bank report', count(json_decode($after['source_data'], true)['evidence']) === 2);

    // Intent into an SMS row: payee name, MCC category over Miscellaneous, the note.
    $r = $seed(['id' => 101, 'account_id' => 7, 'category_id' => 51, 'merchant' => 'swiggy@icici', 'transaction_date' => '2026-10-02 06:30:00',
        'bank' => 'hdfc', 'account_number' => 'XXXX2222', 'source' => 'sms', 'source_data' => json_encode(['evidence' => [['family' => 'bank', 'hash' => 's2']]])]);
    CrossSourceMerger::merge($pdo, 1, $r, incoming($intentPhonepe + ['merchant' => 'Swiggy', 'notes' => 'dinner', 'category_strong' => true,
        'at' => '2026-10-02 06:30:20', 'evidence' => ['family' => 'intent:com.phonepe.app', 'hash' => 'i2', 'account' => 'default']]), 5, 1);
    $after = $fetch(101);
    check('merge: intent never moves a bank-confirmed account', (int)$after['account_id'] === 7);
    check('merge: intent payee replaces a bare VPA', $after['merchant'] === 'Swiggy');
    check('merge: MCC category replaces Miscellaneous', (int)$after['category_id'] === 1);
    check('merge: intent note fills empty notes', $after['notes'] === 'dinner');

    $r = $seed(['id' => 102, 'account_id' => 7, 'category_id' => 51, 'merchant' => 'Ramesh', 'notes' => 'mine', 'transaction_date' => '2026-10-02 06:30:00',
        'bank' => 'hdfc', 'account_number' => 'XXXX2222', 'source' => 'sms', 'source_data' => json_encode(['evidence' => [['family' => 'bank', 'hash' => 's3']]])]);
    CrossSourceMerger::merge($pdo, 1, $r, incoming($intentPhonepe + ['notes' => 'chai', 'category_strong' => false,
        'evidence' => ['family' => 'intent:com.phonepe.app', 'hash' => 'i3']]), null, 6);
    $after = $fetch(102);
    check('merge: a guessed category leaves Miscellaneous alone', (int)$after['category_id'] === 51);
    check('merge: existing notes are kept', $after['notes'] === 'mine');
} else {
    echo "SKIP merge() checks (pdo_sqlite not loaded)\n";
}

// --- helpers -----------------------------------------------------------------
check('VPA is a weak merchant', CrossSourceMerger::isWeakMerchant('swiggy@icici'));
check('"UPI transfer" is weak', CrossSourceMerger::isWeakMerchant('UPI transfer'));
check('a name is not weak', !CrossSourceMerger::isWeakMerchant('Ramesh Kumar'));
check('UPI (unlinked) is synthetic', CrossSourceMerger::isSyntheticAccount(['bank' => 'other', 'account_number' => 'UPI']));
check('wallet is synthetic', CrossSourceMerger::isSyntheticAccount(['bank' => 'other', 'account_number' => 'WALLET-PAYTM']));
check('real account is not synthetic', !CrossSourceMerger::isSyntheticAccount(['bank' => 'hdfc', 'account_number' => 'XXXX1234']));

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
