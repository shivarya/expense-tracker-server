<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../config/database.php';

class EmailParserController {
    private Database $db;
    private AzureOpenAI $ai;
    private ?\Google_Client $gmailClient = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ai = new AzureOpenAI();
    }

    /**
     * POST /api/parse/email/setup
     * Setup Gmail OAuth2 credentials and get authorization URL
     */
    public function setupGmail(): void {
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        $input = json_decode(file_get_contents('php://input'), true);
        $credentialsJson = $input['credentials'] ?? null;

        if (!$credentialsJson) {
            Response::error('Provide Google OAuth2 credentials JSON', 400);
            return;
        }

        // Save credentials to user-specific file
        $credentialsPath = __DIR__ . "/../data/gmail_credentials_$userId.json";
        file_put_contents($credentialsPath, json_encode($credentialsJson));

        // Initialize Gmail client
        $client = $this->getGmailClient($userId);
        
        // Generate authorization URL
        $authUrl = $client->createAuthUrl();

        Response::success([
            'message' => 'Gmail OAuth setup initiated',
            'auth_url' => $authUrl,
            'instructions' => 'Visit the auth_url and authorize. Then call /api/parse/email/callback with the code.'
        ]);
    }

    /**
     * GET /api/parse/email/callback
     * Handle OAuth2 callback from Google redirect
     * Query params: ?code=authorization_code_from_google&state=user_id
     */
    public function gmailCallback(): void {
        // Get code from query parameter (OAuth redirect)
        $authCode = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null; // Should contain user_id
        
        if (!$authCode) {
            // Display user-friendly error page
            http_response_code(400);
            echo '<html><body style="font-family: Arial; padding: 50px; text-align: center;">
                <h1>❌ Authorization Failed</h1>
                <p>No authorization code received from Google.</p>
                <p>Please try authorizing again from the app.</p>
                </body></html>';
            return;
        }

        // Try to get userId from state or from JWT in cookie
        $userId = $state;
        if (!$userId) {
            // Try to extract from Authorization header if available
            try {
                $tokenData = JWTHandler::requireAuth();
                $userId = $tokenData['userId'];
            } catch (Exception $e) {
                http_response_code(401);
                echo '<html><body style="font-family: Arial; padding: 50px; text-align: center;">
                    <h1>❌ Authentication Required</h1>
                    <p>Could not identify user. Please try authorizing from the app again.</p>
                    </body></html>';
                return;
            }
        }

        $client = $this->getGmailClient($userId);
        
        try {
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            
            if (isset($accessToken['error'])) {
                http_response_code(400);
                echo '<html><body style="font-family: Arial; padding: 50px; text-align: center;">
                    <h1>❌ Authorization Failed</h1>
                    <p>Error: ' . htmlspecialchars($accessToken['error']) . '</p>
                    <p>Please try authorizing again from the app.</p>
                    </body></html>';
                return;
            }

            // Save token to database
            $updateQuery = "UPDATE users SET gmail_token = ?, gmail_authorized_at = NOW() WHERE id = ?";
            $this->db->execute($updateQuery, [json_encode($accessToken), $userId]);

            // Also save to file as backup
            $tokenPath = __DIR__ . "/../data/gmail_token_$userId.json";
            @mkdir(__DIR__ . '/../data', 0755, true);
            file_put_contents($tokenPath, json_encode($accessToken));

            // Display success page
            http_response_code(200);
            echo '<html><body style="font-family: Arial; padding: 50px; text-align: center;">
                <h1>✅ Gmail Authorization Successful!</h1>
                <p>You can now sync mutual fund statements from Gmail.</p>
                <p><strong>You can close this window and return to the app.</strong></p>
                </body></html>';
                
        } catch (Exception $e) {
            error_log('Gmail OAuth callback error: ' . $e->getMessage());
            http_response_code(500);
            echo '<html><body style="font-family: Arial; padding: 50px; text-align: center;">
                <h1>❌ Server Error</h1>
                <p>Failed to complete authorization. Please try again.</p>
                <p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>
                </body></html>';
        }
    }

    /**
     * POST /api/parse/email/fetch
     * Fetch and parse emails from Gmail (CAMS/KFintech statements)
     * Body: { "query": "from:camsonline.com", "max_results": 10 }
     */
    public function fetchEmails(): void {
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        $input = json_decode(file_get_contents('php://input'), true);
        $searchQuery = $input['query'] ?? 'from:(camsonline.com OR kfintech.com) subject:(statement OR portfolio)';
        $maxResults = $input['max_results'] ?? 10;

        try {
            $client = $this->getGmailClient($userId);
            
            // Check if authenticated
            $tokenPath = __DIR__ . "/../data/gmail_token_$userId.json";
            if (!file_exists($tokenPath)) {
                Response::error('Gmail not authenticated. Call /api/parse/email/setup first.', 401);
                return;
            }

            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);

            // Refresh token if expired
            if ($client->isAccessTokenExpired()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                file_put_contents($tokenPath, json_encode($client->getAccessToken()));
            }

            $gmail = new \Google_Service_Gmail($client);

            // Search for emails
            $messagesResponse = $gmail->users_messages->listUsersMessages('me', [
                'q' => $searchQuery,
                'maxResults' => $maxResults
            ]);

            $messages = $messagesResponse->getMessages() ?? [];
            $parsedData = [];

            foreach ($messages as $messageRef) {
                $message = $gmail->users_messages->get('me', $messageRef->getId(), ['format' => 'full']);
                
                // Extract email content
                $subject = '';
                $headers = $message->getPayload()->getHeaders();
                foreach ($headers as $header) {
                    if ($header->getName() === 'Subject') {
                        $subject = $header->getValue();
                        break;
                    }
                }

                // Get email body (handle multipart)
                $body = $this->getEmailBody($message->getPayload());

                // Parse with AI
                $parsed = $this->ai->parseEmailContent($body, $subject);
                
                if ($parsed) {
                    $parsed['email_id'] = $messageRef->getId();
                    $parsed['subject'] = $subject;
                    $parsed['parsed_at'] = date('Y-m-d H:i:s');
                    $parsedData[] = $parsed;

                    // Save to database based on type
                    $this->saveEmailData($userId, $parsed);
                }
            }

            Response::success([
                'message' => 'Emails fetched and parsed',
                'total_emails' => count($messages),
                'parsed_count' => count($parsedData),
                'data' => $parsedData
            ]);

        } catch (Exception $e) {
            Response::error('Email fetch error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/parse/email/webhook
     * Webhook for Gmail push notifications (Cloud Pub/Sub)
     */
    public function gmailWebhook(): void {
        // Gmail push notifications use Cloud Pub/Sub
        // This endpoint receives base64-encoded messages
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['message']['data'])) {
            Response::error('Invalid webhook payload', 400);
            return;
        }

        $data = json_decode(base64_decode($input['message']['data']), true);
        $emailAddress = $data['emailAddress'] ?? null;
        $historyId = $data['historyId'] ?? null;

        error_log("Gmail webhook received for $emailAddress, historyId: $historyId");

        // TODO: Fetch new messages using historyId and process them
        // For now, just acknowledge the webhook
        
        Response::success([
            'message' => 'Webhook received',
            'processed' => true
        ]);
    }

    private function getGmailClient(int $userId): \Google_Client {
        if ($this->gmailClient) {
            return $this->gmailClient;
        }

        // Use constants from config
        $clientId = GMAIL_CLIENT_ID;
        $clientSecret = GMAIL_CLIENT_SECRET;
        $redirectUri = GMAIL_REDIRECT_URI;

        if (!$clientId || !$clientSecret) {
            throw new Exception('Gmail OAuth not configured. Set GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET in .env');
        }

        $client = new \Google_Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope(\Google_Service_Gmail::GMAIL_READONLY);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $this->gmailClient = $client;
        return $client;
    }

    private function getEmailBody($payload): string {
        $body = '';

        if ($payload->getBody()->getSize() > 0) {
            $body = base64_url_decode($payload->getBody()->getData());
        } elseif ($payload->getParts()) {
            foreach ($payload->getParts() as $part) {
                if ($part->getMimeType() === 'text/plain' || $part->getMimeType() === 'text/html') {
                    $body .= base64_url_decode($part->getBody()->getData());
                } elseif ($part->getParts()) {
                    // Recursive for nested parts
                    $body .= $this->getEmailBody($part);
                }
            }
        }

        return $body;
    }

    private function saveEmailData(int $userId, array $data): void {
        $type = $data['type'] ?? 'unknown';

        if ($type === 'mutual_fund' && isset($data['holdings'])) {
            // Save mutual fund holdings
            foreach ($data['holdings'] as $holding) {
                $query = "
                    INSERT INTO mutual_funds (
                        user_id, fund_name, units, current_value, 
                        purchase_value, gain_loss, last_updated
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        units = VALUES(units),
                        current_value = VALUES(current_value),
                        gain_loss = VALUES(gain_loss),
                        last_updated = NOW()
                ";

                $this->db->execute($query, [
                    $userId,
                    $holding['name'] ?? 'Unknown Fund',
                    $holding['units'] ?? 0,
                    $holding['current_value'] ?? 0,
                    $holding['purchase_value'] ?? 0,
                    $holding['gain_loss'] ?? 0
                ]);
            }
        } elseif ($type === 'stock' && isset($data['holdings'])) {
            // Save stock holdings
            foreach ($data['holdings'] as $holding) {
                $query = "
                    INSERT INTO stocks (
                        user_id, stock_name, quantity, current_price, 
                        purchase_price, gain_loss, last_updated
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity = VALUES(quantity),
                        current_price = VALUES(current_price),
                        gain_loss = VALUES(gain_loss),
                        last_updated = NOW()
                ";

                $this->db->execute($query, [
                    $userId,
                    $holding['name'] ?? 'Unknown Stock',
                    $holding['units'] ?? 0,
                    $holding['current_value'] ?? 0,
                    $holding['purchase_value'] ?? 0,
                    $holding['gain_loss'] ?? 0
                ]);
            }
        }
    }

    /**
     * GET /api/parse/email/gmail/status
     * Check Gmail authorization status
     */
    public function getGmailStatus(): void {
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        $tokenPath = __DIR__ . "/../data/gmail_token_$userId.json";
        $authorized = file_exists($tokenPath);

        Response::success([
            'authorized' => $authorized,
            'user_id' => $userId
        ]);
    }

    /**
     * GET /api/parse/email/gmail/setup
     * Get Gmail OAuth authorization URL
     */
    public function getGmailAuthUrl(): void {
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        try {
            // Use constants from config
            $clientId = GMAIL_CLIENT_ID;
            $clientSecret = GMAIL_CLIENT_SECRET;
            $redirectUri = GMAIL_REDIRECT_URI;

            if (!$clientId || !$clientSecret) {
                Response::error('Gmail OAuth not configured. Set GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET in .env', 500);
                return;
            }

            $client = new \Google_Client();
            $client->setClientId($clientId);
            $client->setClientSecret($clientSecret);
            $client->setRedirectUri($redirectUri);
            $client->addScope(\Google_Service_Gmail::GMAIL_READONLY);
            $client->setAccessType('offline');
            $client->setPrompt('consent');
            $client->setState((string)$userId); // Pass user ID in state parameter

            $authUrl = $client->createAuthUrl();

            Response::success([
                'authUrl' => $authUrl,
                'message' => 'Please visit this URL to authorize Gmail access'
            ]);
        } catch (Exception $e) {
            Response::error('Failed to generate auth URL: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/parse/email/gmail/fetch
     * Fetch and parse Gmail emails
     */
    public function fetchGmailEmails(): void {
        $tokenData = JWTHandler::requireAuth();
        $userId = $tokenData['userId'];

        $input = json_decode(file_get_contents('php://input'), true);
        $maxResults = $input['maxResults'] ?? 10;
        $query = $input['query'] ?? 'from:(camsonline.com OR kfintech.com)';

        try {
            $client = $this->getGmailClient($userId);
            $service = new \Google_Service_Gmail($client);

            $messages = $service->users_messages->listUsersMessages('me', [
                'q' => $query,
                'maxResults' => $maxResults
            ]);

            $emailsProcessed = 0;
            $transactionsSaved = 0;

            foreach ($messages->getMessages() as $message) {
                $msg = $service->users_messages->get('me', $message->getId());
                $emailsProcessed++;

                // Process email (simplified - you can expand this)
                $subject = '';
                $body = '';
                
                foreach ($msg->getPayload()->getHeaders() as $header) {
                    if ($header->getName() === 'Subject') {
                        $subject = $header->getValue();
                    }
                }

                if ($msg->getPayload()->getBody()->getData()) {
                    $body = base64_url_decode($msg->getPayload()->getBody()->getData());
                } elseif ($msg->getPayload()->getParts()) {
                    foreach ($msg->getPayload()->getParts() as $part) {
                        if ($part->getBody()->getData()) {
                            $body .= base64_url_decode($part->getBody()->getData());
                        }
                    }
                }

                // Parse email content using AI
                $parsedData = $this->ai->parseEmail($subject, $body);
                
                if ($parsedData && isset($parsedData['transactions'])) {
                    foreach ($parsedData['transactions'] as $transaction) {
                        // Save to database
                        $this->saveTransaction($userId, $transaction);
                        $transactionsSaved++;
                    }
                }
            }

            Response::success([
                'emailsProcessed' => $emailsProcessed,
                'transactionsSaved' => $transactionsSaved,
                'message' => "Processed $emailsProcessed emails, saved $transactionsSaved transactions"
            ]);
        } catch (Exception $e) {
            Response::error('Failed to fetch Gmail emails: ' . $e->getMessage(), 500);
        }
    }

    private function saveTransaction($userId, $transaction): void {
        $query = "INSERT INTO transactions (
            user_id, type, amount, category, description, 
            transaction_date, account_id, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($query, [
            $userId,
            $transaction['type'] ?? 'expense',
            $transaction['amount'] ?? 0,
            $transaction['category'] ?? 'Other',
            $transaction['description'] ?? '',
            $transaction['date'] ?? date('Y-m-d'),
            $transaction['account_id'] ?? null
        ]);
    }
}

function base64_url_decode($input): string {
    return base64_decode(strtr($input, '-_', '+/'));
}

