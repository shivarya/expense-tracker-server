<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../utils/categoryResolver.php';
require_once __DIR__ . '/../utils/categoryLearning.php';
require_once __DIR__ . '/../utils/merchantSubscriptionDetector.php';
require_once __DIR__ . '/../utils/crossSourceMerger.php';
require_once __DIR__ . '/../utils/paymentApps.php';
require_once __DIR__ . '/../utils/upiRef.php';
require_once __DIR__ . '/../config/database.php';

class SMSParserController {
    private Database $db;
    private AzureOpenAI $ai;
    private TransactionDuplicateDetector $duplicateDetector;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ai = new AzureOpenAI();
        $this->duplicateDetector = new TransactionDuplicateDetector($this->db->getConnection(), $this->ai);
    }

    /**
     * POST /api/parse/sms
     * Parse SMS messages and extract transactions
     * Body: { "messages": [{ "sender": "VK-HDFCBK", "body": "...", "date": "2026-01-20 14:30:00" }] }
     */
    public function parseSMS(): void {
        // Require authentication
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['messages']) || !is_array($input['messages'])) {
            Response::error('Invalid input. Provide "messages" array.', 400);
            return;
        }

        $messages = $input['messages'];

        // Filter bank SMS
        $bankSMS = array_values(array_filter($messages, function($msg) {
            $sender = strtolower($msg['sender'] ?? '');
            return str_contains($sender, 'hdfc') ||
                   str_contains($sender, 'sbi') ||
                   str_contains($sender, 'icici') ||
                   str_contains($sender, 'idfc') ||
                   str_contains($sender, 'rbl') ||
                   str_contains($sender, 'axis') ||
                   str_contains($sender, 'kotak');
        }));

        if (empty($bankSMS)) {
            error_log('[TX_SYNC_SUMMARY][SMS_PARSE] user_id=' . $userId . ' total_tx=0 duplicates_found=0 duplicates_skipped=0 possible_duplicates=0 synced=0 failed_saves=0');
            Response::success([
                'message' => 'No bank SMS found',
                'parsed_count' => 0,
                'total_sms' => 0,
                'parsed_transactions' => 0,
                'saved_transactions' => 0,
                'skipped_duplicates' => 0,
                'saved_debit_count' => 0,
                'saved_credit_count' => 0,
                'saved_debit_amount' => 0,
                'saved_credit_amount' => 0,
                'transactions' => []
            ]);
            return;
        }

        if ($this->isAsyncManualRequest($input)) {
            $jobId = null;
            $payloadPath = null;

            try {
                $jobId = $this->createSmsSyncJob($userId, count($bankSMS));
                $payloadPath = $this->writeSmsJobPayload($jobId, $userId, $bankSMS);
                $this->launchSmsBackgroundWorker($jobId, $payloadPath);

                Response::success([
                    'message' => 'Manual SMS re-sync started in background',
                    'async' => true,
                    'job_id' => $jobId,
                    'status' => 'pending',
                    'total_sms' => count($bankSMS),
                ]);
                return;
            } catch (Exception $e) {
                if ($jobId !== null) {
                    $this->markSyncJobFailed($jobId, 'Failed to start background SMS sync: ' . $e->getMessage());
                }

                if ($payloadPath !== null) {
                    $this->safeUnlink($payloadPath);
                }

                Response::error('Failed to start background SMS sync: ' . $e->getMessage(), 500);
                return;
            }
        }

        $result = $this->processBankMessagesForUser($userId, $bankSMS, [
            'summary_tag' => 'SMS_PARSE',
            'chunk_size' => 20,
            'sleep_ms' => 0,
        ]);

        // Self-heal: remove any rogue categories this sync may have created
        $autoFixResult = CategoryResolver::autoFix($this->db, $userId);
        if ($autoFixResult['deleted'] > 0) {
            error_log("[CategoryResolver] Auto-fix after SMS sync: {$autoFixResult['fixed']} txns remapped, {$autoFixResult['deleted']} rogues deleted");
        }

        Response::success([
            'message' => 'SMS parsing complete',
            'total_sms' => count($bankSMS),
            'parsed_transactions' => $result['parsed_transactions'],
            'saved_transactions' => $result['saved_transactions'],
            'skipped_duplicates' => $result['skipped_duplicates'],
            'skipped_high_confidence' => $result['skipped_duplicates'],
            'updated_duplicate_timestamps' => $result['updated_duplicate_timestamps'],
            'flagged_possible_duplicates' => $result['flagged_possible_duplicates'],
            'ai_checked_transactions' => $result['ai_checked_transactions'],
            'duplicate_fallback_used' => $result['duplicate_fallback_used'],
            'saved_debit_count' => $result['saved_debit_count'],
            'saved_credit_count' => $result['saved_credit_count'],
            'saved_debit_amount' => round($result['saved_debit_amount'], 2),
            'saved_credit_amount' => round($result['saved_credit_amount'], 2),
            'transactions' => $result['transactions']
        ]);
    }

    /**
     * POST /parse/sms/structured — free-tier path: the app parses bank SMS
     * on-device (regex) and posts already-structured transactions; the server
     * categorizes (rules), dedupes and inserts WITHOUT calling AI.
     * Body: { "transactions": [ { bank, account_number, transaction_type,
     *         amount, currency?, merchant, description, date, reference_number? } ] }
     */
    public function parseStructuredSMS(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();
        $transactions = (isset($input['transactions']) && is_array($input['transactions'])) ? $input['transactions'] : [];

        if (empty($transactions)) {
            Response::error('transactions array is required', 400);
            return;
        }
        if (count($transactions) > 500) {
            $transactions = array_slice($transactions, 0, 500);
        }

        try {
            $result = $this->ingestStructuredTransactions($userId, $transactions);
            Response::success($result, 'Transactions synced');
        } catch (Throwable $e) {
            error_log('parseStructuredSMS error: ' . $e->getMessage());
            Response::error('Failed to sync transactions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /parse/notification
     * Payment-app / bank-app notifications forwarded by the Android notification
     * listener (PhonePe, Google Pay, Paytm, BHIM, Amazon Pay, CRED, bank apps).
     * Body: { "notifications": [{ "package", "title", "text", "big_text"?, "sub_text"?,
     *         "posted_at" (ISO 8601), "hash" }] }
     * Responds in the /parse/sms shape (plus merged_cross_source), so the app raises
     * its transaction alert from either endpoint with the same code. A notification
     * for a payment whose bank SMS is already stored merges into that row and
     * creates nothing (and so raises no second alert).
     */
    public function parseNotifications(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();
        if (!isset($input['notifications']) || !is_array($input['notifications'])) {
            Response::error('Invalid input. Provide "notifications" array.', 400);
            return;
        }

        $allowTest = filter_var(
            getenv('ALLOW_DEV_LOGIN') ?: ($_ENV['ALLOW_DEV_LOGIN'] ?? 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        $items = [];
        $replayed = 0;
        foreach (array_slice($input['notifications'], 0, 50) as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            $package = trim((string)($notification['package'] ?? ''));
            $app = PaymentApps::find($package, $allowTest);
            $text = $this->notificationText($notification);
            if ($app === null || $text === '') {
                continue;
            }

            $title = mb_substr(trim((string)($notification['title'] ?? '')), 0, 300);
            $postedAt = $this->normalizeDateTimeString($notification['posted_at'] ?? null) ?? date('Y-m-d H:i:s');
            $hash = (string)($notification['hash'] ?? '');
            if (!preg_match('/^[a-f0-9]{16,64}$/', $hash)) {
                $hash = hash('sha256', $package . "\n" . $title . "\n" . $text . "\n" . substr($postedAt, 0, 16));
            }

            // Skip before spending an AI call: the app re-sends after a reinstall or a
            // lost response, and an already-stored hash means it was handled.
            if ($this->notificationAlreadyStored($userId, $hash, $postedAt)) {
                $replayed++;
                continue;
            }

            $items[] = [
                'index' => count($items) + 1,
                'package' => $package,
                'app' => $app['label'],
                'kind' => $app['kind'],
                'meta' => $app,
                'title' => $title,
                'text' => $text,
                'posted_at' => $postedAt,
                'hash' => $hash,
            ];
        }

        $transactions = [];
        $ignored = 0;
        if (!empty($items)) {
            $byIndex = array_column($items, null, 'index');
            foreach ($this->ai->parsePaymentNotifications($items) as $row) {
                $index = (int)($row['index'] ?? 0);
                $item = $byIndex[$index] ?? null;
                unset($byIndex[$index]); // one transaction per notification
                $txn = ($item !== null && strtolower((string)($row['status'] ?? '')) === 'success')
                    ? $this->notificationToTransaction($row, $item)
                    : null;
                if ($txn === null) {
                    $ignored++;
                    continue;
                }
                $transactions[] = $txn;
            }
        }

        $result = $this->persistParsedTransactions($userId, $transactions, [
            'summary_tag' => 'NOTIF_PARSE',
            'source' => 'app_notification',
        ]);

        if ($result['saved_transactions'] > 0) {
            CategoryResolver::autoFix($this->db, $userId);
        }

        error_log('[NOTIF_PARSE] user_id=' . $userId . ' received=' . count($input['notifications'])
            . ' parsed=' . count($items) . ' replayed=' . $replayed . ' ignored=' . $ignored
            . ' saved=' . $result['saved_transactions'] . ' merged=' . $result['merged_cross_source']);

        Response::success([
            'message' => 'Notification parsing complete',
            'total_notifications' => count($items),
            'replayed_notifications' => $replayed,
            'ignored_notifications' => $ignored,
            'parsed_transactions' => $result['parsed_transactions'],
            'saved_transactions' => $result['saved_transactions'],
            'skipped_duplicates' => $result['skipped_duplicates'],
            'merged_cross_source' => $result['merged_cross_source'],
            'flagged_possible_duplicates' => $result['flagged_possible_duplicates'],
            'saved_debit_count' => $result['saved_debit_count'],
            'saved_credit_count' => $result['saved_credit_count'],
            'saved_debit_amount' => round($result['saved_debit_amount'], 2),
            'saved_credit_amount' => round($result['saved_credit_amount'], 2),
            'transactions' => $result['transactions'],
        ]);
    }

    /** Title-less body of a notification: the expanded text when the app provides one. */
    private function notificationText(array $notification): string
    {
        $text = trim((string)($notification['text'] ?? ''));
        $bigText = trim((string)($notification['big_text'] ?? ''));
        if (mb_strlen($bigText) > mb_strlen($text)) {
            $text = $bigText;
        }
        $subText = trim((string)($notification['sub_text'] ?? ''));
        if ($subText !== '' && !str_contains($text, $subText)) {
            $text = trim($text . ' ' . $subText);
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 1000);
    }

    private function notificationAlreadyStored(int $userId, string $hash, string $postedAt): bool
    {
        $at = strtotime($this->toStoredTime($postedAt)) ?: time();
        // Deleted rows count too: a notification the user deleted stays deleted.
        $row = $this->db->fetchOne(
            "SELECT id FROM transactions
             WHERE user_id = ? AND transaction_date BETWEEN ? AND ? AND source_data LIKE ?
             LIMIT 1",
            [$userId, date('Y-m-d H:i:s', $at - 86400), date('Y-m-d H:i:s', $at + 86400), '%"' . $hash . '"%']
        );

        return !empty($row);
    }

    /**
     * Map one AI-parsed notification to the transaction shape persistParsedTransactions
     * consumes. Returns null when the parse can't be trusted (amount not in the text,
     * no direction).
     */
    private function notificationToTransaction(array $row, array $item): ?array
    {
        $type = strtolower(trim((string)($row['transaction_type'] ?? '')));
        if (!in_array($type, ['debit', 'credit'], true)) {
            return null;
        }

        // original_amount is the figure as written when the model reported a foreign currency.
        $statedAmount = (float)($row['original_amount'] ?? $row['amount'] ?? 0);
        if ($statedAmount <= 0 || !$this->amountAppearsIn($statedAmount, $item['title'] . ' ' . $item['text'])) {
            error_log('[NOTIF_PARSE] dropped: amount ' . $statedAmount . ' not in text of ' . $item['package']);
            return null;
        }

        $meta = $item['meta'];
        $instrument = strtolower(trim((string)($row['instrument'] ?? 'upi'))) ?: 'upi';
        $aiBank = strtolower(trim((string)($row['bank'] ?? '')));
        $bank = $meta['bank'] ?? (($aiBank !== '' && $aiBank !== 'other' && $aiBank !== 'null') ? $aiBank : null);
        $last4Digits = preg_replace('/\D+/', '', (string)($row['account_last4'] ?? '')) ?? '';
        $last4 = strlen($last4Digits) >= 4 ? substr($last4Digits, -4) : null;
        if ($instrument === 'bill_payment') {
            // "Paid ₹X towards HDFC card XX5678" names the card being paid, not the
            // account the money left — leave the account for the bank SMS to supply.
            $last4 = null;
            $bank = $meta['bank'] ?? null;
        }

        $isCard = $instrument === 'card';
        $accountType = null;
        if ($isCard) {
            // Notifications rarely say which kind of card; a debit card is a savings account.
            $accountType = preg_match('/debit\s*card/i', $item['text']) ? 'savings' : 'credit_card';
        }

        if ($bank !== null && $last4 !== null) {
            $accountMode = 'bank';
        } elseif ($bank !== null) {
            $accountMode = 'bank_guess';
        } elseif ($instrument === 'wallet') {
            $accountMode = 'wallet';
        } else {
            $accountMode = 'unlinked';
        }

        $counterparty = trim((string)($row['counterparty'] ?? ''));
        if ($counterparty === '' || strcasecmp($counterparty, $item['app']) === 0) {
            $counterparty = trim((string)($row['upi_id'] ?? ''));
        }
        $description = trim((string)($row['description'] ?? ''));

        $paymentMethods = ['upi' => 'UPI', 'card' => 'Card', 'wallet' => $item['app'] . ' Wallet', 'bill_payment' => 'Bill payment', 'bank_transfer' => 'Bank transfer'];

        return [
            'bank' => $bank ?? 'other',
            'account_number' => $last4,
            'card_last_four' => $isCard ? $last4 : null,
            'account_type' => $accountType,
            'account_mode' => $accountMode,
            'transaction_type' => $type,
            'amount' => round((float)($row['amount'] ?? 0), 2),
            'currency' => $row['currency'] ?? 'INR',
            'original_amount' => $row['original_amount'] ?? null,
            'original_currency' => $row['original_currency'] ?? null,
            'date' => $item['posted_at'],
            'sms_date' => $item['posted_at'],
            'category_id' => $row['category_id'] ?? null,
            'merchant' => $counterparty,
            'description' => $item['app'] . ': ' . ($description !== '' ? $description : ($item['title'] !== '' ? $item['title'] : mb_substr($item['text'], 0, 120))),
            'reference_number' => trim((string)($row['reference_number'] ?? '')) ?: null,
            'payment_method' => $paymentMethods[$instrument] ?? 'UPI',
            'instrument' => $instrument,
            'source_package' => $item['package'],
            'source_app' => $item['app'],
            'source_app_key' => $meta['key'],
            'source_hash' => $item['hash'],
            'source_title' => $item['title'],
            'source_text' => $item['text'],
        ];
    }

    /** Hallucination guard: the parsed amount must literally appear in the notification. */
    private function amountAppearsIn(float $amount, string $text): bool
    {
        $plain = preg_replace('/(?<=\d),(?=\d)/', '', $text) ?? $text;
        if (!preg_match_all('/\d+(?:\.\d+)?/', $plain, $matches)) {
            return false;
        }
        foreach ($matches[0] as $number) {
            if (abs((float)$number - $amount) < 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Transactions parsed deterministically from bank/wallet alert emails (utils/bankAlertIngestor.php). Same
     * merge + dedupe + insert path as SMS; no AI involved, so none for duplicate scoring either.
     */
    public function ingestAlertTransactions(int $userId, array $transactions): array
    {
        return $this->persistParsedTransactions($userId, $transactions, [
            'summary_tag' => 'GMAIL_ALERT',
            'source' => 'email',
            'use_ai_dedupe' => false,
        ]);
    }

    public function processBankMessagesForUser(int $userId, array $bankSMS, array $options = []): array
    {
        error_log("Processing " . count($bankSMS) . " bank SMS messages");

        $transactions = $this->ai->parseBankSMS($bankSMS);
        error_log("AI parsing complete. Extracted " . count($transactions) . " transactions");
        $this->logTransactionsInChunks($transactions);

        return $this->persistParsedTransactions($userId, $transactions, $options);
    }

    /**
     * Ingest already-parsed transactions from the on-device (free-tier) SMS
     * parser, skipping AI for both parsing and duplicate scoring.
     */
    public function ingestStructuredTransactions(int $userId, array $transactions, array $options = []): array
    {
        return $this->persistParsedTransactions(
            $userId,
            $transactions,
            array_merge(['summary_tag' => 'SMS_ONDEVICE', 'use_ai_dedupe' => false], $options)
        );
    }

    /**
     * Persist already-parsed transactions (AI- or on-device-parsed). Honors
     * $options['use_ai_dedupe'] (default true). Shared by the AI SMS path and
     * the on-device free-tier path.
     */
    private function persistParsedTransactions(int $userId, array $transactions, array $options = []): array
    {
        $summaryTag = strtoupper((string)($options['summary_tag'] ?? 'SMS_PARSE'));
        $chunkSize = max(1, (int)($options['chunk_size'] ?? 20));
        $sleepMs = max(0, (int)($options['sleep_ms'] ?? 0));
        $jobId = isset($options['job_id']) ? (int)$options['job_id'] : null;
        $useAiDedupe = (bool)($options['use_ai_dedupe'] ?? true);
        $source = (string)($options['source'] ?? 'sms');

        $totalTransactions = count($transactions);

        $savedCount = 0;
        $skippedCount = 0;
        $mergedCount = 0;
        $updatedDuplicateTimeCount = 0;
        $flaggedPossibleCount = 0;
        $aiCheckedCount = 0;
        $fallbackUsedCount = 0;
        $failedSaveCount = 0;
        $savedDebitCount = 0;
        $savedCreditCount = 0;
        $savedDebitAmount = 0.0;
        $savedCreditAmount = 0.0;
        $processedCount = 0;
        $createdTransactions = [];
        // category_id -> name, so the notification title costs one query per
        // distinct category rather than one per transaction.
        $categoryNameCache = [];

        if ($jobId !== null) {
            $this->updateSyncJobProgress(
                $jobId,
                [
                    'status' => 'processing',
                    'progress' => 0,
                    'total_items' => $totalTransactions,
                    'processed_items' => 0,
                    'saved_items' => 0,
                    'skipped_items' => 0,
                    'error_message' => null,
                ]
            );
        }

        foreach (array_chunk($transactions, $chunkSize) as $chunkIndex => $chunk) {
            foreach ($chunk as $transaction) {
                $transaction['date'] = $this->toStoredTime($this->resolveTransactionDateTime($transaction));
                $transaction['transaction_type'] = $this->normalizeTransactionTypeValue(
                    (string)($transaction['transaction_type'] ?? ''),
                    (string)($transaction['description'] ?? '')
                );
                $transaction['merchant'] = $this->normalizeMerchantName((string)($transaction['merchant'] ?? ''));
                $transaction['description'] = $this->resolveTransactionDescription($transaction);
                $transaction['upi_ref'] = UpiRef::extract($transaction['reference_number'] ?? null, $transaction['source_text'] ?? null);

                // Another source already reported this payment (the payment app's
                // notification vs the bank SMS), or this exact report was stored before.
                $incoming = $this->buildMergeIncoming($transaction, $source);
                $twin = $this->findCrossSourceTwin($userId, $incoming);
                if ($twin !== null && ($twin['action'] === 'replay' || $this->mergeIntoTwin($userId, $twin['row'], $incoming, $transaction))) {
                    if ($twin['action'] === 'merge') {
                        $mergedCount++;
                    }
                    $skippedCount++;
                    $processedCount++;
                    continue;
                }

                $duplicateCheck = $this->evaluateDuplicateTransactionSafely($userId, $transaction, null, $useAiDedupe);

                if (!empty($duplicateCheck['ai_used'])) {
                    $aiCheckedCount++;
                }
                if (!empty($duplicateCheck['fallback_used'])) {
                    $fallbackUsedCount++;
                }

                if (!empty($duplicateCheck['should_skip'])) {
                    if ($this->updateMatchedDuplicateTimestamp(
                        $userId,
                        isset($duplicateCheck['matched_transaction_id']) ? (int)$duplicateCheck['matched_transaction_id'] : null,
                        $transaction['date']
                    )) {
                        $updatedDuplicateTimeCount++;
                    }
                    $skippedCount++;
                    $processedCount++;
                    continue;
                }

                if (!empty($duplicateCheck['possible_duplicate'])) {
                    $flaggedPossibleCount++;
                }

                $accountId = $this->resolveAccountId($userId, $transaction);
                $categoryId = $this->resolveCategoryId($userId, $transaction);

                // amount is INR (home currency); original_* set by the parser for foreign spend.
                [$txnCurrency, $txnOrigAmount, $txnOrigCurrency] = $this->resolveCurrencyFields($transaction);

                $insertQuery = "
                    INSERT INTO transactions (
                        user_id, account_id, category_id, transaction_type,
                        amount, currency, original_amount, original_currency,
                        merchant, description, transaction_date, reference_number, upi_ref, payment_method,
                        source, source_data, duplicate_score
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";

                try {
                    $newId = $this->db->insert($insertQuery, [
                        $userId,
                        $accountId,
                        $categoryId,
                        $transaction['transaction_type'],
                        $transaction['amount'],
                        $txnCurrency,
                        $txnOrigAmount,
                        $txnOrigCurrency,
                        $transaction['merchant'] ?? null,
                        $transaction['description'] ?? 'SMS Transaction',
                        $transaction['date'] ?? date('Y-m-d H:i:s'),
                        $transaction['reference_number'] ?? null,
                        $transaction['upi_ref'],
                        $transaction['payment_method'] ?? null,
                        $source,
                        CrossSourceMerger::initialSourceData($incoming),
                        (int)($duplicateCheck['confidence'] ?? 0),
                    ]);

                    MerchantSubscriptionDetector::evaluateTransaction($this->db, $userId, (int)$newId);

                    $savedCount++;

                    $txnType = strtolower((string)($transaction['transaction_type'] ?? 'debit'));
                    $txnAmount = (float)($transaction['amount'] ?? 0);
                    if ($txnType === 'credit') {
                        $savedCreditCount++;
                        $savedCreditAmount += $txnAmount;
                    } else {
                        $savedDebitCount++;
                        $savedDebitAmount += $txnAmount;
                    }

                    if ($categoryId !== null && !array_key_exists((int)$categoryId, $categoryNameCache)) {
                        $categoryRow = $this->db->fetchOne(
                            'SELECT name FROM categories WHERE id = ?',
                            [(int)$categoryId]
                        );
                        $categoryNameCache[(int)$categoryId] = $categoryRow['name'] ?? null;
                    }

                    $createdTransactions[] = [
                        'id' => (int)$newId,
                        'category_id' => $categoryId,
                        'category_name' => $categoryId !== null ? ($categoryNameCache[(int)$categoryId] ?? null) : null,
                        'merchant' => $transaction['merchant'] ?? null,
                        'amount' => $txnAmount,
                        'transaction_type' => $transaction['transaction_type'],
                        'description' => $transaction['description'] ?? 'SMS Transaction',
                        'transaction_date' => $transaction['date'] ?? date('Y-m-d H:i:s'),
                    ];
                } catch (Exception $e) {
                    $failedSaveCount++;
                    error_log("Failed to save transaction: " . $e->getMessage());
                }

                $processedCount++;
            }

            if ($jobId !== null) {
                $progress = $totalTransactions > 0 ? (int)round(($processedCount / $totalTransactions) * 100) : 100;
                $this->updateSyncJobProgress(
                    $jobId,
                    [
                        'status' => 'processing',
                        'progress' => min(100, max(0, $progress)),
                        'total_items' => $totalTransactions,
                        'processed_items' => $processedCount,
                        'saved_items' => $savedCount,
                        'skipped_items' => $skippedCount,
                    ]
                );
            }

            error_log('[TX_SYNC_CHUNK][' . $summaryTag . '] user_id=' . $userId
                . ' chunk=' . ($chunkIndex + 1)
                . ' processed=' . $processedCount . '/' . $totalTransactions
                . ' saved=' . $savedCount
                . ' skipped=' . $skippedCount
                . ' failed=' . $failedSaveCount);

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $duplicatesFound = $skippedCount + $flaggedPossibleCount;
        error_log('[TX_SYNC_SUMMARY][' . $summaryTag . '] user_id=' . $userId
            . ' total_tx=' . $totalTransactions
            . ' duplicates_found=' . $duplicatesFound
            . ' duplicates_skipped=' . $skippedCount
            . ' possible_duplicates=' . $flaggedPossibleCount
            . ' merged_cross_source=' . $mergedCount
            . ' synced=' . $savedCount
            . ' failed_saves=' . $failedSaveCount);

        if ($jobId !== null) {
            $errorMessage = null;
            if ($failedSaveCount > 0) {
                $errorMessage = 'Completed with ' . $failedSaveCount . ' failed transaction inserts';
            }

            $this->updateSyncJobProgress(
                $jobId,
                [
                    'status' => 'completed',
                    'progress' => 100,
                    'total_items' => $totalTransactions,
                    'processed_items' => $processedCount,
                    'saved_items' => $savedCount,
                    'skipped_items' => $skippedCount,
                    'error_message' => $errorMessage,
                    'completed' => true,
                ]
            );
        }

        return [
            'parsed_transactions' => $totalTransactions,
            'saved_transactions' => $savedCount,
            'skipped_duplicates' => $skippedCount,
            'merged_cross_source' => $mergedCount,
            'updated_duplicate_timestamps' => $updatedDuplicateTimeCount,
            'flagged_possible_duplicates' => $flaggedPossibleCount,
            'ai_checked_transactions' => $aiCheckedCount,
            'duplicate_fallback_used' => $fallbackUsedCount,
            'failed_saves' => $failedSaveCount,
            'saved_debit_count' => $savedDebitCount,
            'saved_credit_count' => $savedCreditCount,
            'saved_debit_amount' => $savedDebitAmount,
            'saved_credit_amount' => $savedCreditAmount,
            'transactions' => $createdTransactions,
        ];
    }

    private function isAsyncManualRequest(array $input): bool
    {
        $asyncRequested = filter_var($input['async'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $mode = strtolower((string)($input['mode'] ?? 'manual'));

        return $asyncRequested && $mode === 'manual';
    }

    private function createSmsSyncJob(int $userId, int $totalSms): int
    {
        $sql = "INSERT INTO sync_jobs (user_id, type, status, progress, total_items, processed_items, saved_items, skipped_items, created_at)
                VALUES (?, 'sms', 'pending', 0, ?, 0, 0, 0, NOW())";
        return (int)$this->db->insert($sql, [$userId, $totalSms]);
    }

    private function markSyncJobFailed(int $jobId, string $errorMessage): void
    {
        $sql = "UPDATE sync_jobs
                SET status = 'failed', completed_at = NOW(), error_message = ?
                WHERE id = ?";
        $this->db->execute($sql, [substr($errorMessage, 0, 500), $jobId]);
    }

    private function writeSmsJobPayload(int $jobId, int $userId, array $bankSMS): string
    {
        $dir = __DIR__ . '/../tmp/sms-sync-jobs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('Failed to create SMS sync temp directory');
        }

        $payloadPath = $dir . '/sms-sync-job-' . $jobId . '-' . time() . '.json';
        $payload = [
            'job_id' => $jobId,
            'user_id' => $userId,
            'messages' => $bankSMS,
            'created_at' => date('c'),
        ];

        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new Exception('Failed to encode SMS sync job payload');
        }

        if (file_put_contents($payloadPath, $encoded) === false) {
            throw new Exception('Failed to persist SMS sync job payload');
        }

        return $payloadPath;
    }

    private function launchSmsBackgroundWorker(int $jobId, string $payloadPath): void
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $scriptPath = __DIR__ . '/background_sms_sync.php';

        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            $command = sprintf(
                'start /B "" %s %s %d %s',
                escapeshellarg($phpBinary),
                escapeshellarg($scriptPath),
                $jobId,
                escapeshellarg($payloadPath)
            );

            if (function_exists('popen') && function_exists('pclose')) {
                @pclose(@popen($command, 'r'));
                return;
            }

            exec($command);
            return;
        }

        $command = sprintf(
            '%s %s %d %s > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($scriptPath),
            $jobId,
            escapeshellarg($payloadPath)
        );
        exec($command);
    }

    private function safeUnlink(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function updateSyncJobProgress(int $jobId, array $fields): void
    {
        $status = $fields['status'] ?? 'processing';
        $progress = (int)($fields['progress'] ?? 0);
        $totalItems = (int)($fields['total_items'] ?? 0);
        $processedItems = (int)($fields['processed_items'] ?? 0);
        $savedItems = (int)($fields['saved_items'] ?? 0);
        $skippedItems = (int)($fields['skipped_items'] ?? 0);
        $errorMessage = array_key_exists('error_message', $fields) ? $fields['error_message'] : null;
        $completed = !empty($fields['completed']);

        if ($completed) {
            $sql = "UPDATE sync_jobs
                    SET status = ?, progress = ?, total_items = ?, processed_items = ?, saved_items = ?, skipped_items = ?,
                        error_message = ?, started_at = COALESCE(started_at, NOW()), completed_at = NOW()
                    WHERE id = ?";
            $this->db->execute($sql, [
                $status,
                $progress,
                $totalItems,
                $processedItems,
                $savedItems,
                $skippedItems,
                $errorMessage !== null ? substr((string)$errorMessage, 0, 500) : null,
                $jobId,
            ]);
            return;
        }

        $sql = "UPDATE sync_jobs
                SET status = ?, progress = ?, total_items = ?, processed_items = ?, saved_items = ?, skipped_items = ?,
                    error_message = ?, started_at = COALESCE(started_at, NOW())
                WHERE id = ?";
        $this->db->execute($sql, [
            $status,
            $progress,
            $totalItems,
            $processedItems,
            $savedItems,
            $skippedItems,
            $errorMessage !== null ? substr((string)$errorMessage, 0, 500) : null,
            $jobId,
        ]);
    }

    /**
     * GET /api/parse/sms/webhook
     * Webhook endpoint for real-time SMS forwarding (e.g., from Android app)
     */
    public function smsWebhook(): void {
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        $input = json_decode(file_get_contents('php://input'), true);
        
        $sender = $input['sender'] ?? '';
        $body = $input['body'] ?? '';
        $date = $input['date'] ?? date('Y-m-d H:i:s');

        // Check if it's a bank SMS
        $senderLower = strtolower($sender);
        $isBank = str_contains($senderLower, 'hdfc') ||
                  str_contains($senderLower, 'sbi') ||
                  str_contains($senderLower, 'icici') ||
                  str_contains($senderLower, 'idfc') ||
                  str_contains($senderLower, 'rbl') ||
                  str_contains($senderLower, 'axis') ||
                  str_contains($senderLower, 'kotak');

        if (!$isBank) {
            Response::success([
                'message' => 'Not a bank SMS',
                'processed' => false
            ]);
            return;
        }

        // Parse single SMS
        $transactions = $this->ai->parseBankSMS([
            ['sender' => $sender, 'body' => $body, 'date' => $date]
        ]);

        if (empty($transactions)) {
            Response::success([
                'message' => 'No transaction found in SMS',
                'processed' => false
            ]);
            return;
        }

        // Save transaction
        $transaction = $transactions[0];
        $transaction['date'] = $this->toStoredTime($this->resolveTransactionDateTime($transaction, $date));
        $transaction['upi_ref'] = UpiRef::extract($transaction['reference_number'] ?? null);

        $incoming = $this->buildMergeIncoming($transaction, 'sms_webhook');
        $twin = $this->findCrossSourceTwin($userId, $incoming);
        if ($twin !== null && ($twin['action'] === 'replay' || $this->mergeIntoTwin($userId, $twin['row'], $incoming, $transaction))) {
            Response::success([
                'message' => 'Already recorded from another source',
                'processed' => true,
                'saved' => false,
                'duplicate' => true,
                'merged_into' => (int)$twin['row']['id'],
                'saved_transactions' => 0,
                'saved_debit_count' => 0,
                'saved_credit_count' => 0,
                'saved_debit_amount' => 0,
                'saved_credit_amount' => 0,
                'transaction' => $transaction
            ]);
            return;
        }

        $duplicateCheck = $this->evaluateDuplicateTransactionSafely($userId, $transaction, null, true);
        if (!empty($duplicateCheck['should_skip'])) {
            $updatedDuplicateTimestamp = $this->updateMatchedDuplicateTimestamp(
                $userId,
                isset($duplicateCheck['matched_transaction_id']) ? (int)$duplicateCheck['matched_transaction_id'] : null,
                $transaction['date']
            );

            Response::success([
                'message' => 'Duplicate transaction skipped',
                'processed' => true,
                'saved' => false,
                'duplicate' => true,
                'updated_duplicate_timestamp' => $updatedDuplicateTimestamp,
                'duplicate_confidence' => (int)($duplicateCheck['confidence'] ?? 0),
                'duplicate_reason' => $duplicateCheck['reason'] ?? 'unknown',
                'saved_transactions' => 0,
                'saved_debit_count' => 0,
                'saved_credit_count' => 0,
                'saved_debit_amount' => 0,
                'saved_credit_amount' => 0,
                'transaction' => $transaction
            ]);
            return;
        }

        $accountId = $this->getOrCreateBankAccount($userId, $transaction);
        $categoryId = $this->resolveCategoryId($userId, $transaction);

        // amount is INR (home currency); original_* set by the parser for foreign spend.
        [$txnCurrency, $txnOrigAmount, $txnOrigCurrency] = $this->resolveCurrencyFields($transaction);

        $insertQuery = "
            INSERT INTO transactions (
                user_id, account_id, category_id, transaction_type,
                amount, currency, original_amount, original_currency,
                merchant, description, transaction_date, reference_number, upi_ref, payment_method,
                source, source_data, duplicate_score
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms_webhook', ?, ?)
        ";

        $newId = $this->db->insert($insertQuery, [
            $userId,
            $accountId,
            $categoryId,
            $transaction['transaction_type'],
            $transaction['amount'],
            $txnCurrency,
            $txnOrigAmount,
            $txnOrigCurrency,
            $transaction['merchant'] ?? null,
            $transaction['merchant'] ?? 'SMS Transaction',
            $transaction['date'] ?? $date,
            $transaction['reference_number'] ?? null,
            $transaction['upi_ref'],
            $transaction['payment_method'] ?? null,
            CrossSourceMerger::initialSourceData($incoming),
            (int)($duplicateCheck['confidence'] ?? 0),
        ]);

        MerchantSubscriptionDetector::evaluateTransaction($this->db, $userId, (int)$newId);

        $txnType = strtolower((string)($transaction['transaction_type'] ?? 'debit'));
        $txnAmount = round((float)($transaction['amount'] ?? 0), 2);
        $savedDebitCount = $txnType === 'credit' ? 0 : 1;
        $savedCreditCount = $txnType === 'credit' ? 1 : 0;

        Response::success([
            'message' => 'SMS processed successfully',
            'processed' => true,
            'saved' => true,
            'duplicate' => !empty($duplicateCheck['possible_duplicate']),
            'duplicate_confidence' => (int)($duplicateCheck['confidence'] ?? 0),
            'duplicate_reason' => $duplicateCheck['reason'] ?? 'new_transaction',
            'saved_transactions' => 1,
            'saved_debit_count' => $savedDebitCount,
            'saved_credit_count' => $savedCreditCount,
            'saved_debit_amount' => $savedDebitCount === 1 ? $txnAmount : 0,
            'saved_credit_amount' => $savedCreditCount === 1 ? $txnAmount : 0,
            'transaction' => $transaction
        ]);
    }

    /** The incoming report in the shape CrossSourceMerger matches on. */
    private function buildMergeIncoming(array $transaction, string $source): array
    {
        $package = $source === 'app_notification' ? (string)($transaction['source_package'] ?? '') : '';
        $family = CrossSourceMerger::familyOf($source, $package);
        // Match on the device-side time (SMS receipt / notification post), not the
        // model's reading of it — in the stored (UTC) convention, like transaction_date.
        $deviceAt = $this->normalizeDateTimeString($transaction['sms_date'] ?? null);
        $eventAt = $deviceAt !== null ? $this->toStoredTime($deviceAt) : (string)$transaction['date'];
        $hash = (string)($transaction['source_hash'] ?? '');

        $evidence = ['family' => $family, 'source' => $source, 'at' => $eventAt];
        if ($hash !== '') {
            $evidence['hash'] = $hash;
        }
        if ($source === 'app_notification') {
            $evidence += [
                'package' => $package,
                'app' => (string)($transaction['source_app'] ?? ''),
                'instrument' => (string)($transaction['instrument'] ?? ''),
                'title' => (string)($transaction['source_title'] ?? ''),
                'text' => (string)($transaction['source_text'] ?? ''),
            ];
        } elseif (!empty($transaction['source_sender'])) {
            $evidence['sender'] = (string)$transaction['source_sender'];
        }

        return [
            'family' => $family,
            'source' => $source,
            'package' => $package,
            'transaction_type' => strtolower((string)($transaction['transaction_type'] ?? 'debit')),
            'amount' => round((float)($transaction['amount'] ?? 0), 2),
            'at' => $eventAt,
            'upi_ref' => $transaction['upi_ref'] ?? null,
            'hash' => $hash,
            'instrument' => (string)($transaction['instrument'] ?? ''),
            'account_type' => $source === 'app_notification'
                ? ($transaction['account_type'] ?? null)
                : $this->inferAccountType($transaction),
            'merchant' => (string)($transaction['merchant'] ?? ''),
            'reference_number' => (string)($transaction['reference_number'] ?? ''),
            'payment_method' => (string)($transaction['payment_method'] ?? ''),
            'evidence' => $evidence,
        ];
    }

    private function findCrossSourceTwin(int $userId, array $incoming): ?array
    {
        try {
            return CrossSourceMerger::findTwin($this->db->getConnection(), $userId, $incoming);
        } catch (Throwable $e) {
            // Never lose a transaction to the merge step: fall back to the normal path.
            error_log('[CROSS_SOURCE] twin lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Returns false (and the caller inserts normally) if the merge could not be applied. */
    private function mergeIntoTwin(int $userId, array $row, array $incoming, array $transaction): bool
    {
        try {
            $accountId = null;
            if (CrossSourceMerger::isSyntheticAccount($row)) {
                $mode = $transaction['account_mode'] ?? 'bank';
                if ($mode === 'bank') {
                    $accountId = $this->getOrCreateBankAccount($userId, $transaction);
                } elseif ($mode === 'bank_guess') {
                    $accountId = $this->findSoleBankAccount($userId, $transaction);
                }
            }
            $categoryId = (int)$row['category_id'] === 18 ? $this->resolveCategoryId($userId, $transaction) : null;

            CrossSourceMerger::merge($this->db->getConnection(), $userId, $row, $incoming, $accountId, $categoryId);
            error_log('[CROSS_SOURCE] user_id=' . $userId . ' merged ' . $incoming['family']
                . ' into txn ' . $row['id'] . ' (' . $row['source'] . ', ' . $incoming['amount'] . ')');
            return true;
        } catch (Throwable $e) {
            error_log('[CROSS_SOURCE] merge into txn ' . ($row['id'] ?? '?') . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Account for a new row. Bank SMS (and notifications naming bank + last 4) use the
     * real account; a payment-app notification that doesn't say which account paid
     * goes on a per-user "UPI (unlinked)" account until the bank SMS merges in and
     * moves it — or on "<App> Wallet" when it was paid from an app balance.
     */
    private function resolveAccountId(int $userId, array $transaction): int
    {
        switch ($transaction['account_mode'] ?? 'bank') {
            case 'wallet':
                return $this->getOrCreateSyntheticAccount(
                    $userId,
                    'WALLET-' . ($transaction['source_app_key'] ?? 'APP'),
                    $transaction['wallet_name'] ?? (($transaction['source_app'] ?? 'App') . ' Wallet')
                );
            case 'bank_guess':
                return $this->findSoleBankAccount($userId, $transaction)
                    ?? $this->getOrCreateSyntheticAccount($userId, 'UPI', 'UPI (unlinked)');
            case 'unlinked':
                return $this->getOrCreateSyntheticAccount($userId, 'UPI', 'UPI (unlinked)');
            default:
                return $this->getOrCreateBankAccount($userId, $transaction);
        }
    }

    /** The user's only active account at this bank of the right kind, if there is exactly one. */
    private function findSoleBankAccount(int $userId, array $transaction): ?int
    {
        $bank = strtolower((string)($transaction['bank'] ?? ''));
        if ($bank === '' || $bank === 'other') {
            return null;
        }
        $types = $this->inferAccountType($transaction) === 'credit_card' ? ['credit_card'] : ['savings', 'current'];
        $rows = $this->db->fetchAll(
            "SELECT id FROM bank_accounts
             WHERE user_id = ? AND bank = ? AND account_type IN (" . implode(',', array_fill(0, count($types), '?')) . ")
               AND (status IS NULL OR status = 'active')",
            array_merge([$userId, $bank], $types)
        );

        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }

    private function getOrCreateSyntheticAccount(int $userId, string $accountNumber, string $name): int
    {
        $sql = "SELECT id FROM bank_accounts WHERE user_id = ? AND bank = 'other' AND account_number = ? LIMIT 1";
        $existing = $this->db->fetchOne($sql, [$userId, $accountNumber]);
        if ($existing) {
            return (int)$existing['id'];
        }

        try {
            return (int)$this->db->insert(
                "INSERT INTO bank_accounts (user_id, bank, account_number, account_type, account_name, balance)
                 VALUES (?, 'other', ?, 'savings', ?, 0)",
                [$userId, $accountNumber, $name]
            );
        } catch (Exception $e) {
            // Created concurrently by another request (unique user+bank+number).
            $existing = $this->db->fetchOne($sql, [$userId, $accountNumber]);
            if ($existing) {
                return (int)$existing['id'];
            }
            throw $e;
        }
    }

    private function evaluateDuplicateTransaction(int $userId, array $transaction, ?int $accountId = null, bool $useAi = true): array
    {
        return $this->duplicateDetector->evaluate($userId, $transaction, [
            'account_id' => $accountId,
            'expand_linked_accounts' => true,
            'source_hint' => 'sms_parser',
            'ai_enabled' => $useAi,
            'skip_threshold' => 76,
            'duplicate_threshold' => 51,
        ]);
    }

    private function evaluateDuplicateTransactionSafely(int $userId, array $transaction, ?int $accountId = null, bool $useAi = true): array
    {
        try {
            return $this->evaluateDuplicateTransaction($userId, $transaction, $accountId, $useAi);
        } catch (Exception $e) {
            if (!$this->isGoneAwayMessage($e->getMessage())) {
                throw $e;
            }

            error_log('[DB] Duplicate evaluation failed due to dropped connection. Reconnecting and retrying once.');
            $this->db->forceReconnect();
            $this->duplicateDetector = new TransactionDuplicateDetector($this->db->getConnection(), $this->ai);

            return $this->evaluateDuplicateTransaction($userId, $transaction, $accountId, $useAi);
        }
    }

    private function isGoneAwayMessage(string $message): bool
    {
        $normalized = strtolower($message);
        return str_contains($normalized, 'server has gone away') || str_contains($normalized, 'lost connection');
    }

    /**
     * Convert a parsed (IST wall-clock) timestamp to what transactions.transaction_date stores.
     *
     * The column holds UTC without an offset — GET /transactions labels it "Z" and both clients add +05:30 —
     * because the RN app sent SMS dates as ISO UTC and the model copied that clock time. This pipeline works
     * in IST (normalizeDateTimeString/normalizeSmsDate format in Asia/Kolkata), so a precise time must be
     * shifted here or it displays 5h30 late; that is exactly what the native app's local-time real-time SMS
     * did. Date-only values (midnight) stay as they are, like every statement row.
     */
    private function toStoredTime(string $istDateTime): string
    {
        if ($this->hasMidnightTime($istDateTime)) {
            return $istDateTime;
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $istDateTime, new DateTimeZone('Asia/Kolkata'));
        if (!$dt instanceof DateTime) {
            return $istDateTime;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function resolveTransactionDateTime(array $transaction, ?string $fallbackSmsDate = null): string
    {
        $aiDate = $this->normalizeDateTimeString($transaction['date'] ?? null);
        $smsDate = $this->normalizeDateTimeString($transaction['sms_date'] ?? $fallbackSmsDate);

        if ($aiDate === null && $smsDate === null) {
            return date('Y-m-d H:i:s');
        }
        if ($aiDate === null) {
            return $smsDate;
        }
        if ($smsDate === null) {
            return $aiDate;
        }

        // Prefer SMS time when AI returned date-only midnight.
        if ($this->hasMidnightTime($aiDate) && !$this->hasMidnightTime($smsDate)) {
            return $smsDate;
        }

        return $aiDate;
    }

    private function logTransactionsInChunks(array $transactions, int $transactionsPerChunk = 10, int $maxCharsPerLine = 3000): void
    {
        if (empty($transactions)) {
            error_log('Transactions JSON: []');
            return;
        }

        $transactionsPerChunk = max(1, $transactionsPerChunk);
        $maxCharsPerLine = max(500, $maxCharsPerLine);
        $chunks = array_chunk($transactions, $transactionsPerChunk);
        $chunkCount = count($chunks);

        error_log("Transactions JSON logging in {$chunkCount} chunks ({$transactionsPerChunk} tx/chunk)");

        foreach ($chunks as $chunkIndex => $chunk) {
            $encoded = json_encode($chunk);
            if ($encoded === false) {
                error_log('Transactions JSON chunk encoding failed: ' . json_last_error_msg());
                continue;
            }

            $chunkLabel = ($chunkIndex + 1) . '/' . $chunkCount;
            if (strlen($encoded) <= $maxCharsPerLine) {
                error_log("Transactions JSON chunk {$chunkLabel}: " . $encoded);
                continue;
            }

            $parts = str_split($encoded, $maxCharsPerLine);
            $partCount = count($parts);
            foreach ($parts as $partIndex => $part) {
                $partLabel = ($partIndex + 1) . '/' . $partCount;
                error_log("Transactions JSON chunk {$chunkLabel} part {$partLabel}: " . $part);
            }
        }
    }

    private function normalizeDateTimeString($raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            $numeric = (int)$raw;
            // Accept Unix timestamps; reject short numeric values like year-only "2026".
            if ($numeric >= 1000000000 && $numeric <= 4102444800) {
                return date('Y-m-d H:i:s', $numeric);
            }
            return null;
        }

        $value = trim((string)$raw);
        if ($value === '') {
            return null;
        }

        // Guard against low-fidelity AI outputs that should defer to sms_date.
        if (preg_match('/^\d{4}$/', $value) || preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function hasMidnightTime(string $dateTime): bool
    {
        return substr($dateTime, 11, 8) === '00:00:00';
    }

    private function normalizeTransactionTypeValue(string $rawType, string $description = ''): string
    {
        $value = strtolower(trim($rawType));
        if ($value === 'expense') {
            return 'debit';
        }
        if ($value === 'income') {
            return 'credit';
        }
        if (in_array($value, ['debit', 'credit', 'transfer'], true)) {
            return $value;
        }

        if (preg_match('/\b(refund|reversal|cashback|credited|credit\s+interest|interest\s+credited|salary\s+credit|received)\b/i', $description)) {
            return 'credit';
        }

        return 'debit';
    }

    private function normalizeMerchantName(string $merchant): string
    {
        $value = preg_replace('/\s+/', ' ', trim($merchant)) ?? trim($merchant);
        if ($value !== '' && strtoupper($value) === $value) {
            $value = ucwords(strtolower($value));
        }

        return mb_substr($value, 0, 500);
    }

    private function resolveTransactionDescription(array $transaction): string
    {
        $description = preg_replace('/\s+/', ' ', trim((string)($transaction['description'] ?? ''))) ?? trim((string)($transaction['description'] ?? ''));
        if ($description !== '') {
            return mb_substr($description, 0, 1000);
        }

        $merchant = trim((string)($transaction['merchant'] ?? ''));
        if ($merchant !== '') {
            return mb_substr('Transaction at ' . $merchant, 0, 1000);
        }

        return 'SMS transaction';
    }

    private function updateMatchedDuplicateTimestamp(int $userId, ?int $matchedTransactionId, ?string $incomingDateTime): bool
    {
        if (!$matchedTransactionId || !$incomingDateTime) {
            return false;
        }

        $newDateTime = $this->normalizeDateTimeString($incomingDateTime);
        if ($newDateTime === null) {
            return false;
        }

        $existing = $this->db->fetchOne(
            "SELECT transaction_date FROM transactions WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
            [$matchedTransactionId, $userId]
        );

        if (!$existing || empty($existing['transaction_date'])) {
            return false;
        }

        $existingDateTime = $this->normalizeDateTimeString($existing['transaction_date']);
        if ($existingDateTime === null || $existingDateTime === $newDateTime) {
            return false;
        }

        // Never downgrade a known precise time to midnight.
        if (!$this->hasMidnightTime($existingDateTime) && $this->hasMidnightTime($newDateTime)) {
            return false;
        }

        $this->db->execute(
            "UPDATE transactions SET transaction_date = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
            [$newDateTime, $matchedTransactionId, $userId]
        );

        return true;
    }

    /**
     * Resolve a transaction to a canonical category ID.
     * First checks trusted contacts (own UPI IDs / account names) → Transfer (17).
     * Falls back to shared CategoryResolver — never creates new category rows.
     */
    /**
     * Resolve the currency columns for a parsed transaction.
     * `amount` is always stored in INR; for foreign spend the parser supplies
     * original_amount + original_currency (the foreign figure, for display).
     *
     * @return array{0: string, 1: float|null, 2: string|null} [currency, original_amount, original_currency]
     */
    private function resolveCurrencyFields(array $transaction): array
    {
        $currency = strtoupper(trim((string)($transaction['currency'] ?? 'INR'))) ?: 'INR';
        $originalCurrency = isset($transaction['original_currency']) && trim((string)$transaction['original_currency']) !== ''
            ? strtoupper(trim((string)$transaction['original_currency'])) : null;
        $originalAmount = $originalCurrency !== null && isset($transaction['original_amount']) && is_numeric($transaction['original_amount'])
            ? round((float)$transaction['original_amount'], 2) : null;

        return [$currency, $originalAmount, $originalCurrency];
    }

    private function resolveCategoryId(int $userId, array $transaction): int
    {
        // Highest priority: user-provided learning from manual recategorization.
        $learnedCategoryId = CategoryLearning::resolveFromTransaction($this->db, $userId, $transaction);
        if ($learnedCategoryId !== null) {
            return $learnedCategoryId;
        }

        // A payment TOWARDS your own credit card bill settles debt already counted
        // when the card's own line items were recorded — categorizing it as a fresh
        // expense would double-count that spend. Deterministic backstop independent
        // of whether the AI followed the equivalent prompt instruction.
        if ($this->looksLikeCreditCardBillPayment($transaction)) {
            return 17; // Transfer
        }

        // Check trusted contacts first — self-transfers should always be Transfer (17)
        $merchant = strtolower(trim($transaction['merchant'] ?? ''));
        if ($merchant !== '') {
            $contacts = $this->db->fetchAll(
                "SELECT name, upi_id FROM trusted_contacts WHERE user_id = ?",
                [$userId]
            );
            foreach ($contacts as $contact) {
                $contactName  = strtolower(trim($contact['name'] ?? ''));
                $contactUpiId = strtolower(trim($contact['upi_id'] ?? ''));

                if ($contactName !== '' && str_contains($merchant, $contactName)) {
                    return 17; // Transfer
                }
                if ($contactUpiId !== '' && str_contains($merchant, $contactUpiId)) {
                    return 17; // Transfer
                }
            }
        }

        return CategoryResolver::resolveTransaction($transaction);
    }

    /**
     * Detect a debit that pays down the user's own credit card bill (autopay,
     * NACH/e-mandate, or a manual "credit card bill payment" transfer). This is
     * distinct from a plain card purchase — it's money settling debt that was
     * already counted as spend when the card's own line items were recorded.
     */
    private function looksLikeCreditCardBillPayment(array $transaction): bool
    {
        if (($transaction['transaction_type'] ?? '') !== 'debit') {
            return false;
        }

        $haystack = strtolower(trim(($transaction['merchant'] ?? '') . ' ' . ($transaction['description'] ?? '')));
        if ($haystack === '') {
            return false;
        }

        $explicitPhrases = [
            'credit card bill', 'credit card payment', 'card bill payment',
            'towards your credit card', 'towards credit card', 'cc bill payment',
            'card bill', 'credit card autopay', 'credit card auto debit',
        ];
        foreach ($explicitPhrases as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        // Generic combo: mentions "credit card" alongside payment/bill/due wording.
        if (str_contains($haystack, 'credit card')
            && (str_contains($haystack, 'payment') || str_contains($haystack, 'bill') || str_contains($haystack, 'due'))) {
            return true;
        }

        return false;
    }

    private function getOrCreateBankAccount(int $userId, array $transaction): int {
        $bankName = strtolower($transaction['bank'] ?? 'other');
        $accountNumber = $transaction['account_number'] ?? '0000';
        $accountType = $this->inferAccountType($transaction);
        $cardLastFour = $this->inferCardLastFour($transaction);

        // Map bank names to enum values
        $bankMap = [
            'hdfc bank' => 'hdfc',
            'hdfc' => 'hdfc',
            'icici bank' => 'icici',
            'icici' => 'icici',
            'sbi' => 'sbi',
            'state bank' => 'sbi',
            'idfc' => 'idfc',
            'rbl bank' => 'rbl',
            'rbl' => 'rbl',
            'axis bank' => 'axis',
            'axis' => 'axis',
            'kotak' => 'kotak',
            'kotak mahindra' => 'kotak'
        ];
        
        $bank = $bankMap[$bankName] ?? 'other';

        // Check if account exists (same bank + same account type)
        if ($accountType === 'credit_card' && $cardLastFour) {
            $query = "SELECT id, card_last_four
                      FROM bank_accounts
                      WHERE user_id = ? AND bank = ? AND account_type = 'credit_card'
                        AND (card_last_four = ? OR account_number LIKE ?)";
            $existing = $this->db->fetchAll($query, [$userId, $bank, $cardLastFour, "%$cardLastFour%"]);
        } else {
            $query = "SELECT id, card_last_four
                      FROM bank_accounts
                      WHERE user_id = ? AND bank = ? AND account_type = ? AND account_number LIKE ?";
            $existing = $this->db->fetchAll($query, [$userId, $bank, $accountType, "%$accountNumber%"]);
        }

        if (!empty($existing)) {
            if ($accountType === 'credit_card' && $cardLastFour && empty($existing[0]['card_last_four'])) {
                $this->db->execute(
                    "UPDATE bank_accounts SET card_last_four = ? WHERE id = ?",
                    [$cardLastFour, $existing[0]['id']]
                );
            }
            return $existing[0]['id'];
        }

        // Create new account
        $digits = preg_replace('/\D+/', '', (string)$accountNumber);
        $lastFour = substr($digits, -4);
        $fullAccountNumber = 'XXXX' . str_pad($lastFour ?: $accountNumber, 4, '0', STR_PAD_LEFT);

        try {
            return $this->db->insert(
                "INSERT INTO bank_accounts (user_id, bank, account_number, account_type, card_last_four, balance) VALUES (?, ?, ?, ?, ?, 0)",
                [$userId, $bank, $fullAccountNumber, $accountType, $accountType === 'credit_card' ? $cardLastFour : null]
            );
        } catch (Exception $e) {
            // An account with this (user, bank, account_number) already exists under a
            // different type (e.g. the SMS lacked a card hint so it was classified as
            // savings, but the number is actually a credit card). Attach to the
            // existing account instead of failing the whole sync.
            $existingByNumber = $this->db->fetchOne(
                "SELECT id FROM bank_accounts WHERE user_id = ? AND bank = ? AND account_number = ? LIMIT 1",
                [$userId, $bank, $fullAccountNumber]
            );
            if ($existingByNumber && isset($existingByNumber['id'])) {
                return (int)$existingByNumber['id'];
            }
            throw $e;
        }
    }

    private function inferAccountType(array $transaction): string {
        $explicit = strtolower(trim((string)($transaction['account_type'] ?? '')));
        if (in_array($explicit, ['credit_card', 'credit card', 'card'], true)) {
            return 'credit_card';
        }
        if ($explicit === 'current') {
            return 'current';
        }

        $paymentMethod = strtolower(trim((string)($transaction['payment_method'] ?? '')));
        if (str_contains($paymentMethod, 'card')) {
            return 'credit_card';
        }

        return 'savings';
    }

    private function inferCardLastFour(array $transaction): ?string {
        $candidate = (string)($transaction['card_last_four'] ?? $transaction['account_number'] ?? '');
        $digits = preg_replace('/\D+/', '', $candidate);
        if (empty($digits)) {
            return null;
        }

        return substr($digits, -4);
    }
}
