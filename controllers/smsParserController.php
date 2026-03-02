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
            // Check for duplicates using multiple strategies for robustness
            // Strategy 1: Check by reference number (most reliable if available)
            $isDuplicate = false;
            
            if (!empty($transaction['reference_number'])) {
                $refCheckQuery = "
                    SELECT id FROM transactions 
                    WHERE user_id = ? AND reference_number = ?
                    LIMIT 1
                ";
                $refCheck = $this->db->fetchAll($refCheckQuery, [
                    $userId,
                    $transaction['reference_number']
                ]);
                
                if (count($refCheck) > 0) {
                    $isDuplicate = true;
                    error_log("Skipping duplicate (ref number): {$transaction['reference_number']}");
                }
            }
            
            // Strategy 2: Check by account, amount, and date (fallback for transactions without ref numbers)
            if (!$isDuplicate) {
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
                    $isDuplicate = true;
                    error_log("Skipping duplicate (amount+date): " . json_encode($transaction));
                }
            }

            if ($isDuplicate) {
                $skippedCount++;
                continue; // Skip duplicate
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
        $categoryId = $this->resolveCategoryId($userId, $transaction);

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

    /**
     * Canonical category map — single source of truth.
     * AI now returns category_id directly; this is the fallback for name-based lookup
     * and the guard that prevents new rogue categories from being created.
     */
    private static array $CANONICAL_CATEGORIES = [
        // ID → [name, icon, color, type]
        1  => ['Food & Dining',    'restaurant-outline',       '#FF4757', 'expense'],
        2  => ['Transportation',   'car-outline',              '#FFA502', 'expense'],
        3  => ['Shopping',         'bag-handle-outline',       '#2B7BE5', 'expense'],
        4  => ['Entertainment',    'tv-outline',               '#9C27B0', 'expense'],
        5  => ['Bills & Utilities','flash-outline',            '#FF6B6B', 'expense'],
        6  => ['Healthcare',       'medical-outline',          '#00C48C', 'expense'],
        7  => ['Education',        'school-outline',           '#3F51B5', 'expense'],
        8  => ['Travel',           'airplane-outline',         '#FF9800', 'expense'],
        9  => ['Groceries',        'cart-outline',             '#4CAF50', 'expense'],
        10 => ['Insurance',        'shield-checkmark-outline', '#607D8B', 'expense'],
        11 => ['Rent/EMI',         'home-outline',             '#795548', 'expense'],
        12 => ['Personal Care',    'person-outline',           '#E91E63', 'expense'],
        13 => ['Investments',      'trending-up-outline',      '#00BCD4', 'investment'],
        14 => ['Salary',           'cash-outline',             '#4CAF50', 'income'],
        15 => ['Refund',           'return-down-back-outline', '#8BC34A', 'income'],
        16 => ['Other Income',     'wallet-outline',           '#CDDC39', 'income'],
        17 => ['Transfer',         'swap-horizontal-outline',  '#9E9E9E', 'transfer'],
        18 => ['Uncategorized',    'help-circle-outline',      '#BDBDBD', 'expense'],
        51 => ['Miscellaneous',    'ellipsis-horizontal-circle-outline', '#FF5722', 'expense'],
    ];

    /**
     * Name aliases → canonical ID. Normalised lowercase key → ID.
     */
    private static array $NAME_ALIASES = [
        // Food
        'food & dining' => 1, 'food and dining' => 1, 'food' => 1, 'food & beverage' => 1,
        'food_and_beverage' => 1, 'food_delivery' => 1, 'dining' => 1, 'restaurant' => 1,
        'cafe' => 1, 'swiggy' => 1, 'zomato' => 1,
        // Transportation
        'transportation' => 2, 'transport' => 2, 'fuel' => 2, 'cab' => 2, 'petrol' => 2,
        // Shopping
        'shopping' => 3, 'retail' => 3, 'e-commerce' => 3, 'ecommerce' => 3, 'purchase' => 3,
        // Entertainment
        'entertainment' => 4, 'streaming' => 4, 'subscription' => 4, 'tata play' => 4,
        'ott' => 4, 'movies' => 4,
        // Bills & Utilities
        'bills & utilities' => 5, 'bills and utilities' => 5, 'bills' => 5, 'utilities' => 5,
        'bill payment' => 5, 'recharge' => 5, 'mobile recharge' => 5, 'internet' => 5,
        'broadband' => 5, 'electricity' => 5, 'bill pay' => 5,
        // Healthcare
        'healthcare' => 6, 'health' => 6, 'medical' => 6, 'pharmacy' => 6, 'hospital' => 6,
        // Education
        'education' => 7,
        // Travel
        'travel' => 8, 'flight' => 8, 'hotel' => 8,
        // Groceries
        'groceries' => 9, 'grocery' => 9, 'supermarket' => 9, 'kirana' => 9,
        // Insurance
        'insurance' => 10, 'premium' => 10,
        // Rent/EMI
        'rent/emi' => 11, 'rent' => 11, 'emi' => 11, 'loan_payment' => 11,
        'emi principal/amortization' => 11, 'amortization' => 11, 'loan installment' => 11,
        // Personal Care
        'personal care' => 12, 'grooming' => 12, 'salon' => 12,
        // Investments
        'investments' => 13, 'investment' => 13, 'sip' => 13, 'mutual fund' => 13,
        'stocks' => 13, 'nps' => 13, 'ppf' => 13,
        // Salary
        'salary' => 14, 'payroll' => 14,
        // Refund
        'refund' => 15, 'reversal' => 15, 'cashback' => 15,
        // Other Income
        'other income' => 16, 'income' => 16,
        // Transfer
        'transfer' => 17, 'self transfer' => 17, 'internal transfer' => 17,
        // Uncategorized — map explicit "unknown" here
        'uncategorized' => 18, 'unknown' => 18,
        // Miscellaneous — catch all old junk categories
        'miscellaneous' => 51, 'other' => 51, 'upi' => 51, 'upi payment' => 51,
        'upi transfer' => 51, 'card' => 51, 'card_spend' => 51, 'atm' => 51,
        'atm withdrawal' => 51, 'cash withdrawal' => 51, 'tax' => 51,
        'tax (igst)' => 51, 'tax component' => 51, 'interest' => 51,
        'fees' => 51, 'online services' => 51, 'purchase (tax/fee)' => 51,
    ];

    /**
     * Resolve a transaction to a canonical category ID.
     * Priority: AI-returned category_id > AI-returned category name > merchant keyword > 51 fallback
     * NEVER creates a new category row.
     */
    private function resolveCategoryId(int $userId, array $transaction): int
    {
        // 1. AI already returned a valid canonical ID
        if (!empty($transaction['category_id']) && isset(self::$CANONICAL_CATEGORIES[(int)$transaction['category_id']])) {
            return (int)$transaction['category_id'];
        }

        // 2. Map AI-returned category name to canonical ID
        if (!empty($transaction['category'])) {
            $normalised = strtolower(trim((string)$transaction['category']));
            if (isset(self::$NAME_ALIASES[$normalised])) {
                return self::$NAME_ALIASES[$normalised];
            }
            // Partial substring match
            foreach (self::$NAME_ALIASES as $alias => $id) {
                if (str_contains($normalised, $alias) || str_contains($alias, $normalised)) {
                    return $id;
                }
            }
        }

        // 3. Infer from transaction type for credits
        if (($transaction['transaction_type'] ?? '') === 'credit') {
            return 16; // Other Income
        }

        // 4. Ultimate fallback
        return 51; // Miscellaneous
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
        $fullAccountNumber = 'XXXX' . str_pad($accountNumber, 4, '0', STR_PAD_LEFT);
        return $this->db->insert(
            "INSERT INTO bank_accounts (user_id, bank, account_number, account_type, balance) VALUES (?, ?, ?, 'savings', 0)",
            [$userId, $bank, $fullAccountNumber]
        );
    }
}
}