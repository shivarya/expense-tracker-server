<?php
/**
 * Pure-logic checks for the ICICI statement "second amount layer" (letters standing for digits):
 *   php scripts/check_icici_scrambled_amounts.php
 * No DB. Exit code 1 on any failure.
 *
 * The cipher is a letter shift that differs per statement (Aug/Sep 2026: X..G, Oct 2026: H..Q), and
 * the readable amounts beside it are a decoy at 1/1.2 of the real ones. The statement's own summary
 * line is what tells the right shift apart.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../controllers/statementsController.php';

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

$ctl = (new ReflectionClass(StatementController::class))->newInstanceWithoutConstructor();
$parse = new ReflectionMethod($ctl, 'parseIciciStatement');
$parse->setAccessible(true);

/** digit 1 = letter $shift ... digit 9 = $shift+8, digit 0 = $shift+9 (A=0, wraps at Z) */
function scramble(string $amount, int $shift): string
{
    $out = '';
    foreach (str_split($amount) as $ch) {
        if ($ch === ',' || $ch === '.') {
            $out .= $ch;
            continue;
        }
        $d = (int)$ch;
        $out .= chr(65 + ($shift + ($d === 0 ? 9 : $d - 1)) % 26);
    }
    return $out;
}

function fmt(float $v): string
{
    return number_format($v, 2, '.', ',');
}

/**
 * A statement page the way the PDF text comes out: the summary, a readable block (decoy amounts),
 * then the same rows again with the real amounts as letters. The third row's description wraps.
 */
function statementText(int $shift, ?array $summary = null): string
{
    $rows = [
        ['26/09/2026', '14234766966', 'UPI-663509626388-PAVITHRA N IN 0', 1200.00, false],
        ['27/09/2026', '14238436367', "UPI-663528755257-VENUGOPA L BALAPPA\nIN\n3", 874.00, false],
        ['28/09/2026', '14251716174', 'WESTSIDE 52', 2605.00, false],
        ['01/10/2026', '14267075039', 'BBPS Payment received 0', 600.00, true],
    ];
    $debits = 1200.00 + 874.00 + 2605.00;
    $credits = 600.00;
    [$d, $c] = $summary ?? [$debits, $credits];

    $text = "CREDIT CARD STATEMENT\nPrevious Balance Purchases / Charges Cash Advances Payments / Credits\n"
        . 'Á0.00 Á' . fmt($d) . ' Á0.00 Á' . fmt($c) . "\n"
        . "Date SerNo. Transaction Details Reward Points Intl.# amount Amount (inÁ)\n6529XXXXXXXX7003\n";
    foreach ($rows as [$date, $serial, $desc, $amount, $credit]) {
        $text .= "$date $serial $desc " . fmt(round($amount / 1.2, 2)) . ($credit ? ' CR' : '') . "\n";
    }
    $text .= "Credit Limit (Including cash) Available Credit\n";
    $text .= "Date SerNo. Transaction Details Reward Points Intl.# amount Amount (inÁ)\n6529XXXXXXXX7003\n";
    foreach ($rows as [$date, $serial, $desc, $amount, $credit]) {
        $text .= "$date $serial $desc " . scramble(fmt($amount), $shift) . ($credit ? ' CR' : '') . "\n";
    }
    return $text;
}

function amountsOf(array $result): array
{
    return array_map(fn($t) => $t['transaction_type'] . ':' . number_format((float)$t['amount'], 2, '.', ''), $result['transactions']);
}

$expected = ['debit:1200.00', 'debit:874.00', 'debit:2605.00', 'credit:600.00'];

// --- every shift reconciles, including the two seen in real statements (X=23, H=7) -------------
foreach ([23, 7, 0, 25] as $shift) {
    $r = $parse->invoke($ctl, statementText($shift), '7003');
    check("shift $shift: amounts recovered from the letter layer", amountsOf($r) === $expected);
    check("shift $shift: statement reconciles", $r['reconciled'] === true);
    check("shift $shift: wrapped row kept", count($r['transactions']) === 4);
}

// --- no shift reconciles: keep the readable rows and refuse (reconciled = false) -----------------
$r = $parse->invoke($ctl, statementText(7, [9999.00, 600.00]), '7003');
check('summary no shift can satisfy: not reconciled', $r['reconciled'] === false);
check('summary no shift can satisfy: readable amounts left alone', amountsOf($r)[0] === 'debit:1000.00');

// --- a statement with no letter layer and correct amounts is untouched ---------------------------
$plain = "Previous Balance Purchases / Charges Cash Advances Payments / Credits\nÁ0.00 Á1,500.00 Á0.00 Á0.00\n"
    . "Date SerNo. Transaction Details\n26/09/2026 14234766966 UPI-663509626388-SHOP IN 0 1,000.00\n"
    . "27/09/2026 14234908568 UPI-663509637520-CAFE IN 1 500.00\n";
$r = $parse->invoke($ctl, $plain, '7003');
check('plain statement reconciles without a second layer', $r['reconciled'] === true && count($r['transactions']) === 2);

exit($failures === 0 ? 0 : 1);
