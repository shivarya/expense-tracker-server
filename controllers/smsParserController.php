<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../config/database.php';

class SMSParserController {
    private Database $db;
    private AzureOpenAI $ai;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ai = new AzureOpenAI();
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

        foreach ($transactions as $transaction) {
            // Check for duplicates (same bank, account, amount, date within 1 hour)
            $existingQuery = "
                SELECT id FROM transactions 
                WHERE user_id = ? 
                AND account_id IN (SELECT id FROM bank_accounts WHERE account_number LIKE ?)
                AND amount = ?
                AND ABS(TIMESTAMPDIFF(MINUTE, transaction_date, ?)) < 60
                LIMIT 1
            ";
            
            $accountPattern = '%' . ($transaction['account_number'] ?? '0000');
            $existing = $this->db->fetchAll($existingQuery, [
                $userId,
                $accountPattern,
                $transaction['amount'],
                $transaction['date'] ?? date('Y-m-d H:i:s')
            ]);

            if (count($existing) > 0) {
                error_log("Skipping duplicate transaction: " . json_encode($transaction));
                $skippedCount++;
                continue; // Skip duplicate
            }

            // Get or create bank account
            $accountId = $this->getOrCreateBankAccount($userId, $transaction);
            error_log("Bank account ID: $accountId");
            
            // Get or create category
            $categoryId = $this->getOrCreateCategory($userId, $transaction);

            // Insert transaction
            $insertQuery = "
                INSERT INTO transactions (
                    user_id, account_id, category_id, transaction_type, 
                    amount, merchant, description, transaction_date, reference_number, source
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms')
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
                    $transaction['reference_number'] ?? null
                ]);
                $savedCount++;
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
        error_log("User ID: $userId");
        error_log("===========================");

        Response::success([
            'message' => 'SMS parsing complete',
            'total_sms' => count($bankSMS),
            'parsed_transactions' => count($transactions),
            'saved_transactions' => $savedCount,
            'skipped_duplicates' => $skippedCount,
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
        $accountId = $this->getOrCreateBankAccount($userId, $transaction);
        $categoryId = $this->getOrCreateCategory($userId, $transaction);

        $insertQuery = "
            INSERT INTO transactions (
                user_id, account_id, category_id, transaction_type, 
                amount, merchant, description, date, reference_number, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sms_webhook')
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
            $transaction['reference_number'] ?? null
        ]);

        Response::success([
            'message' => 'SMS processed successfully',
            'processed' => true,
            'transaction' => $transaction
        ]);
    }

    private function getOrCreateBankAccount(int $userId, array $transaction): int {
        $bankName = strtolower($transaction['bank'] ?? 'other');
        $accountNumber = $transaction['account_number'] ?? '0000';

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

        // Check if account exists
        $query = "SELECT id FROM bank_accounts WHERE user_id = ? AND bank = ? AND account_number LIKE ?";
        $existing = $this->db->fetchAll($query, [$userId, $bank, "%$accountNumber%"]);

        if (!empty($existing)) {
            return $existing[0]['id'];
        }

        // Create new account
        $insertQuery = "
            INSERT INTO bank_accounts (user_id, bank, account_number, account_type, balance)
            VALUES (?, ?, ?, 'savings', 0)
        ";
        
        $fullAccountNumber = 'XXXX' . str_pad($accountNumber, 4, '0', STR_PAD_LEFT);
        return $this->db->insert($insertQuery, [$userId, $bank, $fullAccountNumber]);
    }

    private function getOrCreateCategory(int $userId, array $transaction): int {
        $categoryName = $transaction['category'] ?? 'Other';

        // Check if category exists (system categories or user's custom)
        $query = "SELECT id FROM categories WHERE (user_id = ? OR user_id IS NULL) AND name = ? LIMIT 1";
        $existing = $this->db->fetchAll($query, [$userId, $categoryName]);

        if (!empty($existing)) {
            return $existing[0]['id'];
        }

        // Create new category
        $insertQuery = "
            INSERT INTO categories (user_id, name, type, monthly_budget, icon, color)
            VALUES (?, ?, 'expense', 0, 'card-outline', '#9C27B0')
        ";
        
        return $this->db->insert($insertQuery, [$userId, $categoryName]);
    }
}
