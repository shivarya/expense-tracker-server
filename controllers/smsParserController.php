<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../utils/categoryResolver.php';
require_once __DIR__ . '/../utils/categoryLearning.php';
require_once __DIR__ . '/../utils/merchantSubscriptionDetector.php';
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

        $totalTransactions = count($transactions);

        $savedCount = 0;
        $skippedCount = 0;
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
                $transaction['date'] = $this->resolveTransactionDateTime($transaction);
                $transaction['transaction_type'] = $this->normalizeTransactionTypeValue(
                    (string)($transaction['transaction_type'] ?? ''),
                    (string)($transaction['description'] ?? '')
                );
                $transaction['merchant'] = $this->normalizeMerchantName((string)($transaction['merchant'] ?? ''));
                $transaction['description'] = $this->resolveTransactionDescription($transaction);

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

                $accountId = $this->getOrCreateBankAccount($userId, $transaction);
                $categoryId = $this->resolveCategoryId($userId, $transaction);

                // amount is INR (home currency); original_* set by the parser for foreign spend.
                [$txnCurrency, $txnOrigAmount, $txnOrigCurrency] = $this->resolveCurrencyFields($transaction);

                $insertQuery = "
                    INSERT INTO transactions (
                        user_id, account_id, category_id, transaction_type,
                        amount, currency, original_amount, original_currency,
                        merchant, description, transaction_date, reference_number, payment_method, source, duplicate_score
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms', ?)
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
                        $transaction['payment_method'] ?? null,
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
        $transaction['date'] = $this->resolveTransactionDateTime($transaction, $date);

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
                merchant, description, transaction_date, reference_number, payment_method, source, duplicate_score
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms_webhook', ?)
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
            $transaction['payment_method'] ?? null,
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
