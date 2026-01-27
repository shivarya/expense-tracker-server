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
        return $result['choices'][0]['message']['content'] ?? null;
    }

    public function parseBankSMS(array $smsMessages): array {
        $transactions = [];
        
        // Process in batches of 10
        $batchSize = 10;
        $batches = array_chunk($smsMessages, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            error_log("Parsing SMS batch " . ($batchIndex + 1) . "/" . count($batches));

            $smsTexts = [];
            foreach ($batch as $idx => $msg) {
                $smsTexts[] = ($idx + 1) . ". From: {$msg['sender']}, Body: {$msg['body']}";
            }

            $prompt = "Extract transaction details from these bank SMS messages. Return JSON object with 'transactions' array containing: bank, account_number, transaction_type (debit/credit), amount, merchant, category, date, reference_number.\n\n";
            $prompt .= "SMS Messages:\n" . implode("\n", $smsTexts) . "\n\n";
            $prompt .= "Return ONLY valid JSON object with transactions array, no markdown.";

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a banking SMS parser. Extract transaction data accurately. Return JSON with transactions array. Always include bank name, account_number (last 4 digits), transaction_type (debit or credit), amount (as number), date (YYYY-MM-DD format), and optional merchant, category, reference_number.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ];

            $response = $this->chatCompletion($messages, 0.1, true);
            error_log("Azure OpenAI raw response: " . substr($response ?? 'null', 0, 500));
            
            if ($response) {
                $parsed = json_decode($response, true);
                error_log("Parsed JSON structure: " . json_encode(array_keys($parsed ?? [])));
                if (isset($parsed['transactions']) && is_array($parsed['transactions'])) {
                    foreach ($parsed['transactions'] as $transaction) {
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
        $prompt = "Extract investment/financial data from this email:\n\n";
        $prompt .= "Subject: $subject\n\n";
        $prompt .= "Body:\n$emailBody\n\n";
        $prompt .= "Return JSON with: type (mutual_fund/stock/fd), account_number, holdings array (name, units, current_value, purchase_value, gain_loss), total_value.";

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a financial email parser. Extract investment data from CAMS/KFintech/broker statements. Return structured JSON.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ];

        $response = $this->chatCompletion($messages, 0.1, true);
        
        if ($response) {
            return json_decode($response, true);
        }

        return null;
    }
}
