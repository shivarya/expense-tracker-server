<?php

require_once __DIR__ . '/alertEmailParsers.php';
require_once __DIR__ . '/accountBalance.php';
require_once __DIR__ . '/../controllers/smsParserController.php';

/**
 * BankAlertIngestor
 *
 * Turns one bank/wallet alert email (HDFC UPI alerts and daily balance, Pluxee meal card) into account balances
 * and — only where nothing else already recorded the payment — transactions:
 *  - a UPI alert whose 12-digit reference is already stored (the bank SMS or a payment-app notification got it)
 *    is skipped outright: re-recording it adds nothing;
 *  - the rest go through SMSParserController::persistParsedTransactions(source 'email'), so CrossSourceMerger
 *    folds one into a payment-app notification row (giving it the real HDFC account) and the duplicate detector
 *    still guards against an SMS row that lacks a reference. What's left is genuinely missing — e.g. UPI debits
 *    under ₹100, for which HDFC sends no SMS.
 *
 * Kept free of the Gmail client so it can be driven from fixtures.
 */
class BankAlertIngestor
{
    private Database $db;
    private SMSParserController $sms;

    public function __construct(Database $db, ?SMSParserController $sms = null)
    {
        $this->db = $db;
        $this->sms = $sms ?? new SMSParserController();
    }

    /**
     * @param string $receivedAt when Gmail received the alert, 'Y-m-d H:i:s' IST — the payment time to within
     *                           seconds, where the body only gives a date.
     * @return array{transactions: int, duplicates: int, balances: int, items: int}
     */
    public function ingest(int $userId, string $from, string $subject, string $body, string $receivedAt, string $messageId): array
    {
        $items = AlertEmailParsers::parse($from, $subject, $body);
        $transactions = [];
        $duplicates = 0;
        $balances = 0;

        foreach ($items as $index => $item) {
            if ($item['kind'] === 'balance') {
                $asOf = $item['as_of'] ?? $receivedAt;
                $updated = isset($item['wallet'])
                    ? AccountBalance::updateWallet($this->db, $userId, $item['wallet'], self::walletName($item['wallet']), $item['balance'], $asOf)
                    : AccountBalance::updateBankAccount($this->db, $userId, $item['bank'], $item['account_last4'], $item['balance'], $asOf);
                $balances += $updated ? 1 : 0;
                continue;
            }

            if (!empty($item['upi_ref']) && $this->referenceKnown($userId, $item['upi_ref'])) {
                $duplicates++;
                continue;
            }
            $transactions[] = $this->toTransaction($item, $from, $receivedAt, $messageId, $index);
        }

        $saved = 0;
        if ($transactions) {
            $result = $this->sms->ingestAlertTransactions($userId, $transactions);
            $saved = (int)$result['saved_transactions'];
            $duplicates += (int)$result['skipped_duplicates'];
        }

        return ['transactions' => $saved, 'duplicates' => $duplicates, 'balances' => $balances, 'items' => count($items)];
    }

    /** Any row (deleted ones too — a deleted payment stays deleted) already carrying this UPI reference. */
    private function referenceKnown(int $userId, string $ref): bool
    {
        $row = $this->db->fetchOne(
            "SELECT id FROM transactions WHERE user_id = ? AND (upi_ref = ? OR reference_number LIKE ?) LIMIT 1",
            [$userId, $ref, '%' . $ref . '%']
        );
        return !empty($row);
    }

    private function toTransaction(array $item, string $from, string $receivedAt, string $messageId, int $index): array
    {
        $credit = $item['transaction_type'] === 'credit';
        $counterparty = trim((string)($item['counterparty'] ?? ''));
        $when = $this->eventTime($item['date'] ?? null, $receivedAt);

        $txn = [
            'transaction_type' => $item['transaction_type'],
            'amount' => $item['amount'],
            'currency' => 'INR',
            'date' => $when,
            'sms_date' => $when,
            'merchant' => $counterparty,
            'source_hash' => hash('sha256', 'gmail:' . $messageId . ':' . $index),
            'source_sender' => $from,
        ];

        if (isset($item['wallet'])) {
            return $txn + [
                'bank' => 'other',
                'account_mode' => 'wallet',
                'source_app_key' => $item['wallet'],
                'wallet_name' => self::walletName($item['wallet']),
                'description' => self::walletName($item['wallet']) . ': ' . ($credit ? 'loaded' : 'paid at ' . $counterparty),
                'reference_number' => $item['reference'] ?? null,
                'payment_method' => 'Meal card',
                // A meal card can only be spent on food; its monthly load is an allowance.
                'category_id' => $credit ? 16 : 1,
            ];
        }

        $vpa = trim((string)($item['vpa'] ?? ''));
        return $txn + [
            'bank' => $item['bank'],
            'account_number' => $item['account_last4'],
            'account_mode' => 'bank',
            'description' => 'UPI ' . ($credit ? 'from ' : 'to ') . $counterparty . ($vpa !== '' && $vpa !== $counterparty ? " ({$vpa})" : ''),
            'reference_number' => $item['upi_ref'] ?? null,
            'payment_method' => 'UPI',
        ];
    }

    /**
     * The body gives the payment date (HDFC) or date + minute (Pluxee); Gmail's receive time is the payment time
     * to within seconds. Use the receive time when it falls on the stated day, else the stated date as date-only.
     */
    private function eventTime(?string $stated, string $receivedAt): string
    {
        if ($stated === null || $stated === '') {
            return $receivedAt;
        }
        if (strlen($stated) > 10) {
            return $stated; // already has a time
        }
        return substr($receivedAt, 0, 10) === $stated ? $receivedAt : $stated . ' 00:00:00';
    }

    private static function walletName(string $key): string
    {
        return $key === 'PLUXEE' ? 'Pluxee meal card' : ucfirst(strtolower($key)) . ' wallet';
    }
}
