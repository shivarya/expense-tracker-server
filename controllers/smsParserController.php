<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../utils/categoryResolver.php';
require_once __DIR__ . '/../utils/categoryLearning.php';
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
        $bankSMS = array_filter($messages, function($msg) {
            $sender = strtolower($msg['sender'] ?? '');
            return str_contains($sender, 'hdfc') ||
                   str_contains($sender, 'sbi') ||
                   str_contains($sender, 'icici') ||
                   str_contains($sender, 'idfc') ||
                   str_contains($sender, 'rbl') ||
                   str_contains($sender, 'axis') ||
                   str_contains($sender, 'kotak');
        });

        if (empty($bankSMS)) {
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

        error_log("Processing " . count($bankSMS) . " bank SMS messages");

        // Parse using Azure OpenAI
        $transactions = $this->ai->parseBankSMS($bankSMS);
        error_log("AI parsing complete. Extracted " . count($transactions) . " transactions");
        error_log("Transactions JSON: " . json_encode($transactions));

        // Save transactions to database
        $savedCount = 0;
        $skippedCount = 0;
        $updatedDuplicateTimeCount = 0;
        $flaggedPossibleCount = 0;
        $aiCheckedCount = 0;
        $fallbackUsedCount = 0;
        $savedDebitCount = 0;
        $savedCreditCount = 0;
        $savedDebitAmount = 0.0;
        $savedCreditAmount = 0.0;

        foreach ($transactions as $transaction) {
            $transaction['date'] = $this->resolveTransactionDateTime($transaction);
            $duplicateCheck = $this->evaluateDuplicateTransaction($userId, $transaction, null, true);

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
                continue; // Skip duplicate
            }

            if (!empty($duplicateCheck['possible_duplicate'])) {
                $flaggedPossibleCount++;
            }

            // Get or create bank account
            $accountId = $this->getOrCreateBankAccount($userId, $transaction);
            error_log("Bank account ID: $accountId");
            
            // Resolve to canonical category — never creates rogue categories
            $categoryId = $this->resolveCategoryId($userId, $transaction);

            // Insert transaction
            $insertQuery = "
                INSERT INTO transactions (
                    user_id, account_id, category_id, transaction_type, 
                    amount, merchant, description, transaction_date, reference_number, payment_method, source, duplicate_score
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms', ?)
            ";

            try {
                $this->db->execute($insertQuery, [
                    $userId,
                    $accountId,
                    $categoryId,
                    $transaction['transaction_type'],
                    $transaction['amount'],
                    $transaction['merchant'] ?? null,
                    $transaction['merchant'] ?? 'SMS Transaction',
                    $transaction['date'] ?? date('Y-m-d H:i:s'),
                    $transaction['reference_number'] ?? null,
                    $transaction['payment_method'] ?? null,
                    (int)($duplicateCheck['confidence'] ?? 0),
                ]);
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

                error_log("Transaction saved successfully: Amount={$transaction['amount']}, Merchant=" . ($transaction['merchant'] ?? 'N/A'));
            } catch (Exception $e) {
                error_log("Failed to save transaction: " . $e->getMessage());
            }
        }

        // Log success summary
        error_log("=== SMS PARSING COMPLETE ===");
        error_log("Total messages received: " . count($messages));
        error_log("Bank SMS filtered: " . count($bankSMS));
        error_log("Transactions parsed by AI: " . count($transactions));
        error_log("Transactions saved to DB: $savedCount");
        error_log("Duplicates skipped: $skippedCount");
        error_log("Duplicate rows time-updated: $updatedDuplicateTimeCount");
        error_log("Saved debit txns: $savedDebitCount, amount: $savedDebitAmount");
        error_log("Saved credit txns: $savedCreditCount, amount: $savedCreditAmount");
        error_log("User ID: $userId");
        error_log("===========================");

        // Self-heal: remove any rogue categories this sync may have created
        $autoFixResult = CategoryResolver::autoFix($this->db, $userId);
        if ($autoFixResult['deleted'] > 0) {
            error_log("[CategoryResolver] Auto-fix after SMS sync: {$autoFixResult['fixed']} txns remapped, {$autoFixResult['deleted']} rogues deleted");
        }

        Response::success([
            'message' => 'SMS parsing complete',
            'total_sms' => count($bankSMS),
            'parsed_transactions' => count($transactions),
            'saved_transactions' => $savedCount,
            'skipped_duplicates' => $skippedCount,
            'skipped_high_confidence' => $skippedCount,
            'updated_duplicate_timestamps' => $updatedDuplicateTimeCount,
            'flagged_possible_duplicates' => $flaggedPossibleCount,
            'ai_checked_transactions' => $aiCheckedCount,
            'duplicate_fallback_used' => $fallbackUsedCount,
            'saved_debit_count' => $savedDebitCount,
            'saved_credit_count' => $savedCreditCount,
            'saved_debit_amount' => round($savedDebitAmount, 2),
            'saved_credit_amount' => round($savedCreditAmount, 2),
            'transactions' => $transactions
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

        $duplicateCheck = $this->evaluateDuplicateTransaction($userId, $transaction, null, true);
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

        $insertQuery = "
            INSERT INTO transactions (
                user_id, account_id, category_id, transaction_type, 
                amount, merchant, description, transaction_date, reference_number, payment_method, source, duplicate_score
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms_webhook', ?)
        ";

        $this->db->execute($insertQuery, [
            $userId,
            $accountId,
            $categoryId,
            $transaction['transaction_type'],
            $transaction['amount'],
            $transaction['merchant'] ?? null,
            $transaction['merchant'] ?? 'SMS Transaction',
            $transaction['date'] ?? $date,
            $transaction['reference_number'] ?? null,
            $transaction['payment_method'] ?? null,
            (int)($duplicateCheck['confidence'] ?? 0),
        ]);

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
            'ai_enabled' => $useAi,
            'skip_threshold' => 76,
            'duplicate_threshold' => 51,
        ]);
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

    private function normalizeDateTimeString(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function hasMidnightTime(string $dateTime): bool
    {
        return substr($dateTime, 11, 8) === '00:00:00';
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
        return $this->db->insert(
            "INSERT INTO bank_accounts (user_id, bank, account_number, account_type, card_last_four, balance) VALUES (?, ?, ?, ?, ?, 0)",
            [$userId, $bank, $fullAccountNumber, $accountType, $accountType === 'credit_card' ? $cardLastFour : null]
        );
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
