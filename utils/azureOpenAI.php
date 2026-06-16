<?php

require_once __DIR__ . '/fxConverter.php';
require_once __DIR__ . '/aiClient.php';

/**
 * AzureOpenAI
 *
 * Despite the historical name, this is now a provider-agnostic helper: it builds
 * the finance-specific prompts (SMS parsing, statement refinement, duplicate
 * scoring) and delegates the actual HTTP transport to {@see AIClient}, whose
 * provider is selected via env (Gemini Flash-Lite by default, or Azure/OpenAI/
 * Groq). Existing call sites (`new AzureOpenAI()`) keep working unchanged.
 */
class AzureOpenAI {
    private AIClient $client;

    public function __construct() {
        $this->client = new AIClient();
    }

    public function chatCompletion(array $messages, float $temperature = 0.1, bool $jsonMode = true, int $maxRetries = 3): ?array {
        return $this->client->chatCompletion($messages, $temperature, $jsonMode, $maxRetries);
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
            $smsDate = $msg['date'] ?? '';
            $smsTexts[] = ($idx + 1) . ". [sms_index:" . ($idx + 1) . "] From: {$msg['sender']}, Date: {$smsDate}, Body: {$msg['body']}";
        }

        $systemPrompt = <<<'PROMPT'
You are a banking SMS parser for an Indian expense tracker app.

Extract transaction data from each SMS and return a JSON object with a "transactions" array.

REQUIRED fields per transaction:
- bank: (hdfc|sbi|icici|idfc|rbl|axis|kotak|other)
- account_number: last 4 digits only (string)
- transaction_type: "debit" or "credit"
- amount: numeric value only (no currency symbols) — exactly as written in the SMS, in the currency named by the "currency" field below
- currency: ISO 4217 code of the amount as stated in the SMS. Default "INR". Use the foreign code (e.g. MYR, USD, EUR, AED, THB, SGD, GBP) ONLY when the SMS explicitly shows a foreign currency such as "MYR 30.00", "RM 30", "USD 12.50". Do NOT convert; report the number and its stated currency.
- date: "YYYY-MM-DD HH:MM:SS" format
- sms_index: integer index of source SMS from input list (1-based)
- category_id: integer from the canonical list below
- merchant: merchant/payee name (clean, no bank jargon)
- description: short human-friendly description (include action + merchant context)
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
- Most SMS are INR; set "currency" to a foreign code only when the SMS clearly shows one.
- For credit transactions: apply income categories first (14/15/16/17)
- transaction_type MUST be exactly debit or credit (never expense/income/other labels)
- Messages with credited/received/refund/reversal/cashback/payment received/interest credited => transaction_type=credit
- Messages with debited/spent/withdrawn/charged/purchase => transaction_type=debit
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
- Keep merchant concise and clean; avoid bank prefixes, txn IDs, and location suffixes when possible
- description should not be generic; avoid values like "transaction" or "payment"
- Preserve exact SMS timestamp in "date" using the SMS Date field provided in each input row.
- Do NOT default time to 00:00:00 unless the source SMS truly has no time information.

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
                        $smsIndex = isset($transaction['sms_index']) ? (int)$transaction['sms_index'] : 0;
                        if ($smsIndex > 0 && isset($batch[$smsIndex - 1]['date'])) {
                            $normalizedSMSDate = $this->normalizeSmsDate($batch[$smsIndex - 1]['date']);
                            if ($normalizedSMSDate !== null) {
                                $transaction['sms_date'] = $normalizedSMSDate;
                            }
                        }

                        $transaction = $this->applyCurrencyConversion($transaction);

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
     * Extract holdings from a CDSL eCAS (Consolidated Account Statement) — both
     * demat equities and mutual funds. Returns ['stocks' => [...], 'mutual_funds' => [...]].
     */
    public function extractCdslHoldings(string $statementText): array {
        if (trim($statementText) === '') {
            return ['stocks' => [], 'mutual_funds' => []];
        }

        $systemPrompt = <<<'PROMPT'
You extract holdings from an Indian CDSL eCAS (Consolidated Account Statement) PDF.

Return ONLY a JSON object:
{
  "stocks": [
    {"symbol": "", "isin": "", "company_name": "", "quantity": 0, "current_value": 0, "invested_amount": 0}
  ],
  "mutual_funds": [
    {"fund_name": "", "folio_number": "", "isin": "", "units": 0, "current_value": 0, "invested_amount": 0}
  ]
}

Rules:
- "stocks" = demat equity holdings. ISIN is mandatory; if a ticker symbol is absent, derive a short symbol from the company name.
- "mutual_funds" = MF folios. folio_number is mandatory when present.
- Numbers must be plain numerics (no currency symbols, no commas).
- current_value = current market value; invested_amount = cost/purchase value if shown, else 0.
- Omit rows you cannot read with an identifier (ISIN for stocks, folio or fund name for MF).
- Return ONLY valid JSON, no markdown.
PROMPT;

        $userPrompt = "Extract all holdings from this CDSL eCAS statement text:\n\n"
            . mb_substr($statementText, 0, 18000)
            . "\n\nReturn JSON: {\"stocks\": [...], \"mutual_funds\": [...]}";

        $result = $this->chatCompletion([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ], 0.1, true);

        return [
            'stocks' => is_array($result['stocks'] ?? null) ? $result['stocks'] : [],
            'mutual_funds' => is_array($result['mutual_funds'] ?? null) ? $result['mutual_funds'] : [],
        ];
    }

    /**
     * Extract the NPS account summary from a Protean/NSDL NPS statement.
     * Returns ['pran' => , 'account_name' => , 'invested_amount' => , 'current_value' => ] or [].
     */
    public function extractNpsStatement(string $statementText): array {
        if (trim($statementText) === '') {
            return [];
        }

        $systemPrompt = <<<'PROMPT'
You extract the NPS (National Pension System) account summary from an Indian NPS statement PDF.

Return ONLY a JSON object:
{"pran": "", "account_name": "", "invested_amount": 0, "current_value": 0}

Rules:
- pran = the 12-digit PRAN if present.
- account_name = subscriber name.
- invested_amount = total contributions; current_value = current total valuation (Tier I + Tier II if both shown).
- Numbers must be plain numerics (no currency symbols, no commas).
- Return ONLY valid JSON, no markdown.
PROMPT;

        $userPrompt = "Extract the NPS summary from this statement text:\n\n"
            . mb_substr($statementText, 0, 18000)
            . "\n\nReturn JSON: {\"pran\": \"\", \"account_name\": \"\", \"invested_amount\": 0, \"current_value\": 0}";

        $result = $this->chatCompletion([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ], 0.1, true);

        return is_array($result) ? $result : [];
    }

    /**
     * Clean statement transactions with AI and map likely category IDs.
     * Returns indexed rows keyed by input index for safe merge in caller.
     *
     * Output shape per index:
     * [
     *   index => [
     *     'merchant' => string|null,
     *     'description' => string|null,
     *     'category_id' => int|null,
     *   ]
     * ]
     */
    public function refineStatementTransactions(array $transactions, string $bankLabel = 'CARD'): array {
        if (empty($transactions)) {
            return [];
        }

        $canonicalIds = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,51,52,53,54,55,56];
        $items = [];

        foreach (array_values($transactions) as $index => $txn) {
            $items[] = [
                'index' => $index,
                'transaction_type' => (string)($txn['transaction_type'] ?? 'debit'),
                'amount' => (float)($txn['amount'] ?? 0),
                'merchant' => (string)($txn['merchant'] ?? ''),
                'description' => (string)($txn['description'] ?? ''),
                'raw_line' => (string)($txn['raw_line'] ?? ''),
            ];
        }

        $systemPrompt = <<<'PROMPT'
You clean card-statement transactions for an Indian expense tracker.

Task:
1. Improve merchant and description text.
2. Assign canonical category_id from this list only:
1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,51,52,53,54,55,56

Rules:
- Keep merchant short, readable, and human-friendly.
- Remove IDs/codes/location clutter from merchant when obvious.
- Description should be concise and useful (not generic single word).
- For credit transactions use income IDs when appropriate (14/15/16), transfers as 17.
- Fuel/petrol/parking should map to 2.
- Pooja material / religious shopping purchases should map to 3 unless clearly donation intent (55).
- If uncertain on debit, prefer 51 over 18.
- Never invent categories outside canonical IDs.

Return ONLY JSON object:
{
  "items": [
    {"index": 0, "merchant": "...", "description": "...", "category_id": 2}
  ]
}
PROMPT;

        $userPrompt = "Bank context: {$bankLabel}\n";
        $userPrompt .= "Normalize the following parsed statement transactions:\n";
        $userPrompt .= json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $response = $this->chatCompletion($messages, 0.1, true);
        if (!is_array($response) || !isset($response['items']) || !is_array($response['items'])) {
            return [];
        }

        $normalized = [];
        foreach ($response['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $index = isset($row['index']) ? (int)$row['index'] : -1;
            if ($index < 0 || !array_key_exists($index, $items)) {
                continue;
            }

            $merchant = isset($row['merchant']) ? trim((string)$row['merchant']) : '';
            $description = isset($row['description']) ? trim((string)$row['description']) : '';
            $categoryId = isset($row['category_id']) ? (int)$row['category_id'] : 0;

            $normalized[$index] = [
                'merchant' => $merchant !== '' ? mb_substr($merchant, 0, 500) : null,
                'description' => $description !== '' ? mb_substr($description, 0, 1000) : null,
                'category_id' => in_array($categoryId, $canonicalIds, true) ? $categoryId : null,
            ];
        }

        return $normalized;
    }

    /**
     * Normalize currency on a parsed SMS transaction.
     *
     * Home currency is INR. When the SMS reported a foreign currency, keep the
     * foreign figure in original_amount/original_currency and overwrite `amount`
     * with the INR value converted at the transaction date's reference rate.
     * Domestic INR transactions are left unchanged (currency defaults to INR).
     */
    private function applyCurrencyConversion(array $transaction): array {
        $currency = strtoupper(trim((string)($transaction['currency'] ?? 'INR')));
        $amount = isset($transaction['amount']) ? (float)$transaction['amount'] : 0.0;

        if ($currency === '' || $currency === 'INR') {
            $transaction['currency'] = 'INR';
            return $transaction;
        }

        $date = $transaction['sms_date'] ?? ($transaction['date'] ?? null);
        $conv = fxConvertToInr($amount, $currency, $date);

        $transaction['original_amount'] = round($amount, 2);
        $transaction['original_currency'] = $currency;
        $transaction['amount'] = $conv['inr'];
        $transaction['currency'] = 'INR';
        $transaction['fx_rate'] = $conv['rate'];

        return $transaction;
    }

    private function normalizeSmsDate(?string $raw): ?string {
        if (!$raw) {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
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
