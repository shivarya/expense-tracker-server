<?php
/**
 * Assertion checks for the Gmail alert/statement parsers — pure logic, no DB, no network. The fixtures follow the
 * real templates (Oct 2026) with made-up values. Run after touching utils/alertEmailParsers.php or
 * utils/statementBalanceExtractor.php:
 *
 *   php scripts/check_alert_parsers.php
 */

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/../utils/alertEmailParsers.php';
require_once __DIR__ . '/../utils/statementBalanceExtractor.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' && !$ok ? "  [$detail]" : '') . "\n";
}

$hdfc = 'HDFC Bank InstaAlerts <alerts@hdfcbank.bank.in>';

// --- HDFC UPI debit (HTML body, as delivered) --------------------------------------------------------------
$debit = AlertEmailParsers::parse($hdfc, '❗  You have done a UPI txn. Check details!', '<html><body><div>Dear Customer,<br>
Greetings from HDFC Bank!<br>Rs.1,250.50 is debited from your account ending 4321 towards VPA shop.owner@okaxis (MANGO GENERAL STORE) on 02-10-26.<br>
UPI transaction reference no.: 512345678901.<br>If you did not authorize this transaction&#44; please report it</div></body></html>');
check('debit: one transaction', count($debit) === 1 && $debit[0]['kind'] === 'transaction');
check('debit: fields', ($debit[0]['transaction_type'] ?? '') === 'debit' && ($debit[0]['amount'] ?? 0) === 1250.5
    && ($debit[0]['account_last4'] ?? '') === '4321' && ($debit[0]['vpa'] ?? '') === 'shop.owner@okaxis'
    && ($debit[0]['counterparty'] ?? '') === 'MANGO GENERAL STORE' && ($debit[0]['date'] ?? '') === '2026-10-02'
    && ($debit[0]['upi_ref'] ?? '') === '512345678901', json_encode($debit));

// --- HDFC UPI credit (arrives as "Account update") ---------------------------------------------------------
$credit = AlertEmailParsers::parse($hdfc, 'View: Account update for your HDFC Bank A/c', "Dear Customer,
Greetings from HDFC Bank!
We're writing to inform you that Rs.500.00 has been successfully credited to your HDFC Bank account ending in 4321.
Transaction Details:
a. Date: 01-10-26
b. Sender: RAVI KUMAR (VPA: ravi.k@oksbi)
c. UPI Reference No.: 612345678901");
check('credit: fields', count($credit) === 1 && $credit[0]['transaction_type'] === 'credit' && $credit[0]['amount'] === 500.0
    && $credit[0]['counterparty'] === 'RAVI KUMAR' && $credit[0]['vpa'] === 'ravi.k@oksbi'
    && $credit[0]['date'] === '2026-10-01' && $credit[0]['upi_ref'] === '612345678901', json_encode($credit));

// --- HDFC daily balance ---------------------------------------------------------------------------------------
$balance = AlertEmailParsers::parse($hdfc, 'View: Account update for your HDFC Bank A/c', 'Dear Customer, Greetings from HDFC Bank!
The available balance in your account ending XX4321 is Rs. INR 54,321.09 as of 02-OCT-26.
The balance in the account does not include the uncleared cheque amount, if any.');
check('balance: fields', count($balance) === 1 && $balance[0]['kind'] === 'balance' && $balance[0]['account_last4'] === '4321'
    && $balance[0]['balance'] === 54321.09 && $balance[0]['as_of'] === '2026-10-02', json_encode($balance));

// --- HDFC mail that must yield nothing ------------------------------------------------------------------------
check('login notice ignored', AlertEmailParsers::parse($hdfc, 'View: Account update for your HDFC Bank A/c',
    'Dear Customer, Thank you for using HDFC Bank online banking. You have successfully read secure usage tips') === []);
check('e-mandate debit left to the SMS', AlertEmailParsers::parse($hdfc, 'Account update for your HDFC Bank A / c',
    'Dear Customer, Rs.2500.00 has been debited from HDFC Bank Account Number XXXXXXXXXX4321 towards Example Insurer/12345 with UMRN HDFC0001 on 01-Sep-2026.') === []);

// --- Pluxee meal card -----------------------------------------------------------------------------------------
$pluxee = 'Pluxee <noreply-cardinfo@services.pluxee.in>';
$spend = AlertEmailParsers::parse($pluxee, 'Transaction confirmation on your Pluxee Card', "Dear ASHA,
 You&#39;ve made a payment of ₹341.00 at SWIGGY
. Please call 18002101234 if this was not made by you.
 Receipt ID:
 778899
 13:05,28 Sep
 SWIGGY
 Updated
 Account
 Balance
 ₹1,234.56");
check('pluxee spend: transaction + balance', count($spend) === 2);
check('pluxee spend: fields', $spend[0]['transaction_type'] === 'debit' && $spend[0]['amount'] === 341.0
    && $spend[0]['counterparty'] === 'SWIGGY' && $spend[0]['reference'] === '778899'
    && str_ends_with((string)$spend[0]['date'], '-09-28 13:05:00'), json_encode($spend));
check('pluxee spend: wallet balance', $spend[1]['kind'] === 'balance' && $spend[1]['wallet'] === 'PLUXEE' && $spend[1]['balance'] === 1234.56);

$load = AlertEmailParsers::parse($pluxee, 'Your Pluxee Card has been credited', 'Dear ASHA
 Your Pluxee Card has been successfully loaded with ₹2200 towards Meal Card Wallet on Fri Sep 26 2026 10:15:00. Your current Meal Card Wallet Balance is Rs.2,345.00');
check('pluxee load: credit + balance', count($load) === 2 && $load[0]['transaction_type'] === 'credit' && $load[0]['amount'] === 2200.0
    && $load[0]['date'] === '2026-09-26 10:15:00' && $load[1]['balance'] === 2345.0, json_encode($load));

check('unknown sender ignored', AlertEmailParsers::parse('someone@example.com', 'x', 'Rs.10 is debited from your account ending 1111 towards VPA a@b on 01-01-26.') === []);

// --- Statement closing balance (IDFC-style layouts) -------------------------------------------------------------
$inline = "ACCOUNT STATEMENT\nAccount No : 10012345678\nStatement Period: 01-Sep-2026 to 30-Sep-2026\n...\nClosing Balance : 1,02,345.67\n";
$r = StatementBalanceExtractor::closingBalance($inline);
check('statement inline: balance/last4/date', $r !== null && $r['balance'] === 102345.67 && $r['account_last4'] === '5678'
    && $r['as_of'] === '2026-09-30', json_encode($r));

$table = "Account Number: XXXXXXX9876\nPeriod 01/09/2026 - 30/09/2026\n"
    . "  Opening Balance        Total Credit      Total Debit       Closing Balance\n"
    . "      12,000.00            50,000.00         45,500.25          16,499.75\n";
$r = StatementBalanceExtractor::closingBalance($table);
check('statement table: value under its header', $r !== null && $r['balance'] === 16499.75 && $r['account_last4'] === '9876'
    && $r['as_of'] === '2026-09-30', json_encode($r));

check('statement without a balance', StatementBalanceExtractor::closingBalance("Account No 123456\nno summary here") === null);

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
