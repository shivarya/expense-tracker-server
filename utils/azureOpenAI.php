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

    public function chatCompletion(array $messages, float $temperature = 0.1, bool $jsonMode = true): ?array {
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

        if ($httpCode !== 200) {
            error_log("Azure OpenAI API error (HTTP $httpCode): $response");
            return null;
        }

        $result = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? null;
        
        if ($content === null) {
            return null;
        }
        
        // Parse the JSON content and return as array
        $parsed = json_decode($content, true);
        return $parsed ?? null;
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

        $systemPrompt = 'You are a banking SMS parser. Extract transaction data accurately. Return JSON with transactions array. Always include bank name, account_number (last 4 digits), transaction_type (debit or credit), amount (as number), date (YYYY-MM-DD format), and optional merchant, category, reference_number.';
        
        $userPrompt = "Extract transaction details from these bank SMS messages. Return JSON object with 'transactions' array containing: bank, account_number, transaction_type (debit/credit), amount, merchant, category, date, reference_number.\n\n";
        $userPrompt .= "SMS Messages:\n" . implode("\n", $smsTexts) . "\n\n";
        $userPrompt .= "Return ONLY valid JSON object with transactions array, no markdown.";

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
}
