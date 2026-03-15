<?php

class AzureOpenAI {
    private string $endpoint;
    private string $apiKey;
    private string $deployment;
    private string $apiVersion;

    public function __construct() {
        $this->endpoint = $_ENV['AZURE_OPENAI_ENDPOINT'] ?? '';
        $this->apiKey = $_ENV['AZURE_OPENAI_API_KEY'] ?? '';
        $this->deployment = $_ENV['AZURE_OPENAI_DEPLOYMENT'] ?? 'gpt-4-turbo';
        $this->apiVersion = '2024-02-15-preview';
    }

    public function chatCompletion(array $messages, float $temperature = 0.1, bool $jsonMode = true, int $maxRetries = 3): ?array {
        if (empty($this->endpoint) || empty($this->apiKey)) {
            error_log('Azure OpenAI credentials not configured');
            return null;
        }

        $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";
        
        $payload = [
            'messages' => $messages,
            'temperature' => $temperature,
            'max_completion_tokens' => 2000
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $attempt = 0;
        $waitTime = 1; // Start with 1 second

        while ($attempt < $maxRetries) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'api-key: ' . $this->apiKey
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $content = $result['choices'][0]['message']['content'] ?? null;
                
                if ($content === null) {
                    return null;
                }
                
                // Parse the JSON content and return as array
                $parsed = json_decode($content, true);
                return $parsed ?? null;
            }

            // Handle rate limit (429)
            if ($httpCode === 429) {
                $errorData = json_decode($response, true);
                $retryAfter = $this->extractRetryAfter($errorData);
                
                $attempt++;
                if ($attempt >= $maxRetries) {
                    error_log("Azure OpenAI rate limit exceeded after $maxRetries attempts");
                    return null;
                }

                $sleepTime = $retryAfter > 0 ? $retryAfter : $waitTime;
                error_log("Rate limit hit. Retrying in $sleepTime seconds (attempt $attempt/$maxRetries)");
                sleep($sleepTime);
                $waitTime *= 2; // Exponential backoff
                continue;
            }

            // Other errors
            error_log("Azure OpenAI API error (HTTP $httpCode): $response");
            return null;
        }

        return null;
    }

    private function extractRetryAfter(array $errorData): int {
        // Extract retry time from error message
        $message = $errorData['error']['message'] ?? '';
        if (preg_match('/retry after (\d+) seconds/', $message, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    /**
     * Generic financial data parser
     * @param string $type - 'sms' or 'email'
     * @param array|string $content - SMS array or email body
     * @param array $options - Additional options (subject for email, etc.)
     * @return array
     */
    private function parseFinancialData(string $type, $content, array $options = []): array {
        if ($type === 'sms') {
            return $this->parseSMSBatch($content);
        } elseif ($type === 'email') {
            return $this->parseEmailBatch($content, $options['subject'] ?? '');
        }
        
        return [];
    }

    /**
     * Parse SMS batch with AI
     */
    private function parseSMSBatch(array $smsMessages): array {
        $smsTexts = [];
        foreach ($smsMessages as $idx => $msg) {
            $smsTexts[] = ($idx + 1) . ". From: {$msg['sender']}, Body: {$msg['body']}";
        }

        $systemPrompt = <<<'PROMPT'
You are a banking SMS parser for an Indian expense tracker app.

Extract transaction data from each SMS and return a JSON object with a "transactions" array.

REQUIRED fields per transaction:
- bank: (hdfc|sbi|icici|idfc|rbl|axis|kotak|other)
- account_number: last 4 digits only (string)
- transaction_type: "debit" or "credit"
- amount: numeric value only (no currency symbols)
- date: "YYYY-MM-DD HH:MM:SS" format
- category_id: integer from the canonical list below
- merchant: merchant/payee name (clean, no bank jargon)
- reference_number: UPI ref, txn ID, chq number (or null)

CANONICAL CATEGORY LIST — you MUST return one of these integer category_id values only:
1  = Food & Dining       (restaurants, cafes, Swiggy, Zomato, food delivery)
2  = Transportation      (Ola, Uber, Rapido, metro, bus, petrol, diesel)
3  = Shopping            (Amazon, Flipkart, Myntra, retail, fashion, electronics)
4  = Entertainment       (streaming, Tata Play, Netflix, Hotstar, movies, games, subscriptions)
5  = Bills & Utilities   (electricity, water, internet, Airtel, Jio, ACT, recharge, BBPS)
6  = Healthcare          (pharmacy, Apollo, Netmeds, 1mg, hospital, clinic, lab tests)
7  = Education           (courses, fees, books, certifications)
8  = Travel              (flights, hotels, MakeMyTrip, Indigo, Goibibo, Cleartrip)
9  = Groceries           (BigBasket, Blinkit, Zepto, DMart, supermarket, kirana)
10 = Insurance           (LIC, HDFC Ergo, premium payments, policy renewals)
11 = Rent/EMI            (rent, EMI, loan installment, amortization)
12 = Personal Care       (salon, grooming, spa, wellness)
13 = Investments         (SIP, mutual fund, stocks, NPS, PPF, FD deposit)
14 = Salary              (salary credit, payroll)
15 = Refund              (refund, reversal, cashback credited)
16 = Other Income        (any credit that is not salary/refund)
17 = Transfer            (self transfer between own accounts)
18 = Uncategorized       (use ONLY if truly unclassifiable)
51 = Miscellaneous       (person-to-person UPI, ATM withdrawal, fees, tax, genuinely unclear debits)
52 = Household Help      (cook, maid, driver, domestic worker salary)
53 = Kids Activities     (karate, dance, swimming, sports, hobby classes, extracurricular fees)
54 = Software & Tools    (SaaS, GitHub, AWS, Azure, domains, hosting, Figma, Notion, developer tools)
55 = Donation            (charity donations, NGO contributions, relief funds, donation drives)
56 = Home Improvement    (home repairs, renovation, plumbing, electrician work, home decor, furnishings)

RULES:
- For credit transactions: apply income categories first (14/15/16/17)
- Interest credited (FD, savings, bond) → category_id 16 (Other Income)
- ATM withdrawals → category_id 51
- UPI to a person's name (not a business) → category_id 51
- Cook/maid/driver payments → category_id 52
- Kids sports, dance, activity class fees → category_id 53
- SaaS, cloud, hosting, dev tool subscriptions → category_id 54
- Charity/NGO/donation payments → category_id 55
- House repair, renovation, home maintenance, decor/furnishing payments → category_id 56
- When in doubt between 18 and 51, prefer 51
- Do NOT invent new category names or IDs

Return ONLY a valid JSON object — no markdown, no explanation.
PROMPT;

        $userPrompt = "Parse these bank SMS messages and return a JSON object with a 'transactions' array.\n\n";
        $userPrompt .= "SMS Messages:\n" . implode("\n", $smsTexts) . "\n\n";
        $userPrompt .= "Return JSON: {\"transactions\": [...]}";

        return $this->callAI($systemPrompt, $userPrompt);
    }

    /**
     * Parse email content with AI
     */
    private function parseEmailBatch(string $emailBody, string $subject): array {
        $systemPrompt = 'You are a financial email parser. Extract investment data from CAMS/KFintech/broker statements. Return structured JSON.';
        
        $userPrompt = "Extract investment/financial data from this email:\n\n";
        $userPrompt .= "Subject: $subject\n\n";
        $userPrompt .= "Body:\n$emailBody\n\n";
        $userPrompt .= "Return JSON with: type (mutual_fund/stock/fd), account_number, holdings array (name, units, current_value, purchase_value, gain_loss), total_value.";

        return $this->callAI($systemPrompt, $userPrompt);
    }

    /**
     * Common AI call method
     */
    private function callAI(string $systemPrompt, string $userPrompt): array {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $response = $this->chatCompletion($messages, 0.1, true);
        return $response ?? [];
    }

    public function parseBankSMS(array $smsMessages): array {
        $transactions = [];
        
        // Process in batches of 10
        $batchSize = 10;
        $batches = array_chunk($smsMessages, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            error_log("Parsing SMS batch " . ($batchIndex + 1) . "/" . count($batches));

            $response = $this->parseSMSBatch($batch);
            error_log("Azure OpenAI parsed response: " . json_encode($response));
            
            if ($response && is_array($response)) {
                error_log("Response keys: " . json_encode(array_keys($response)));
                if (isset($response['transactions']) && is_array($response['transactions'])) {
                    foreach ($response['transactions'] as $transaction) {
                        $transaction['source'] = 'sms';
                        $transaction['parsed_at'] = date('Y-m-d H:i:s');
                        $transactions[] = $transaction;
                    }
                }
            }
        }

        return $transactions;
    }

    public function parseEmailContent(string $emailBody, string $subject): ?array {
        return $this->parseEmailBatch($emailBody, $subject);
    }

    /**
     * Score duplicate probability for a transaction against candidate transactions.
     * Returns integer score 0-100 or null when AI is unavailable.
     */
    public function scoreTransactionDuplicate(array $newTransaction, array $candidateTransactions): ?int {
        if (empty($candidateTransactions)) {
            return 0;
        }

        $candidateLines = array_map(function ($txn) {
            $date = $txn['transaction_date'] ?? $txn['date'] ?? '';
            $merchant = $txn['merchant'] ?? '';
            $type = $txn['transaction_type'] ?? '';
            $amount = isset($txn['amount']) ? number_format((float)$txn['amount'], 2, '.', '') : '0.00';
            $description = $txn['description'] ?? '';
            $reference = $txn['reference_number'] ?? '';

            return sprintf(
                '- %s | %s | %s | INR %s | ref=%s | %s',
                $date,
                $type,
                $merchant,
                $amount,
                $reference !== '' ? $reference : 'none',
                $description
            );
        }, array_slice($candidateTransactions, 0, 15));

        $messages = [
            [
                'role' => 'system',
                'content' => "You are a strict duplicate detector for financial transactions. Return JSON only: {\"score\": <0-100>}.\n"
                    . "Rules: same exact reference_number means duplicate.\n"
                    . "High score only when amount, merchant, transaction type, account-context proxy, and time are strongly aligned.\n"
                    . "If merchant differs clearly for same amount, score must be low."
            ],
            [
                'role' => 'user',
                'content' => "NEW TRANSACTION:\n"
                    . json_encode([
                        'transaction_date' => $newTransaction['transaction_date'] ?? ($newTransaction['date'] ?? null),
                        'transaction_type' => $newTransaction['transaction_type'] ?? null,
                        'amount' => isset($newTransaction['amount']) ? (float)$newTransaction['amount'] : null,
                        'merchant' => $newTransaction['merchant'] ?? null,
                        'description' => $newTransaction['description'] ?? null,
                        'reference_number' => $newTransaction['reference_number'] ?? null,
                    ], JSON_UNESCAPED_SLASHES)
                    . "\n\nCANDIDATES:\n"
                    . implode("\n", $candidateLines)
                    . "\n\nReturn JSON only: {\"score\": number}."
            ]
        ];

        $result = $this->chatCompletion($messages, 0.0, true);
        if (!is_array($result)) {
            return null;
        }

        $score = null;
        if (isset($result['score'])) {
            $score = (int)$result['score'];
        } elseif (isset($result['duplicate_score'])) {
            $score = (int)$result['duplicate_score'];
        }

        if ($score === null) {
            return null;
        }

        return max(0, min(100, $score));
    }
}
