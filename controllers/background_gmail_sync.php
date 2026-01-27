<?php
/**
 * Background Gmail Sync Worker
 * Usage: php background_gmail_sync.php {jobId} {userId} {maxResults} {query}
 */

// Get arguments first
$jobId = $argv[1] ?? null;
$userId = $argv[2] ?? null;
$maxResults = $argv[3] ?? 10;
$query = $argv[4] ?? 'from:(camsonline.com OR kfintech.com)';

$db = null;

try {
    if (!$jobId || !$userId) {
        throw new Exception("Missing jobId or userId");
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../utils/azureOpenAI.php';

    $db = Database::getInstance();
    $ai = new AzureOpenAI();

    // Update job status to processing
    $db->execute("UPDATE sync_jobs SET status = 'processing', started_at = NOW() WHERE id = ?", [$jobId]);

    // Load Gmail client
    require_once __DIR__ . '/../controllers/emailParserController.php';
    $controller = new EmailParserController();
    $client = $controller->getGmailClientForBackground($userId);
    
    $service = new \Google_Service_Gmail($client);
    $messagesList = $service->users_messages->listUsersMessages('me', [
        'q' => $query,
        'maxResults' => $maxResults
    ]);

    $messages = $messagesList->getMessages();
    $total = count($messages ?? []);
    
    $db->execute("UPDATE sync_jobs SET total_items = ? WHERE id = ?", [$total, $jobId]);

    if (empty($messages)) {
        $db->execute(
            "UPDATE sync_jobs SET status = 'completed', completed_at = NOW(), progress = 100 WHERE id = ?",
            [$jobId]
        );
        exit(0);
    }

    $processed = 0;
    $saved = 0;
    $errors = [];

    foreach ($messages as $message) {
        try {
            $msg = $service->users_messages->get('me', $message->getId());
            $processed++;

            // Extract subject and body
            $subject = '';
            $body = '';
            
            foreach ($msg->getPayload()->getHeaders() as $header) {
                if ($header->getName() === 'Subject') {
                    $subject = $header->getValue();
                }
            }

            // Decode email body
            if ($msg->getPayload()->getBody()->getData()) {
                $body = decodeBase64Url($msg->getPayload()->getBody()->getData());
            } elseif ($msg->getPayload()->getParts()) {
                foreach ($msg->getPayload()->getParts() as $part) {
                    if ($part->getBody()->getData()) {
                        $body .= decodeBase64Url($part->getBody()->getData());
                    }
                }
            }

            // Parse email content using AI (with retry logic)
            $parsedData = $ai->parseEmailContent($body, $subject);
            
            if ($parsedData && isset($parsedData['holdings']) && is_array($parsedData['holdings'])) {
                foreach ($parsedData['holdings'] as $holding) {
                    saveMutualFund($db, $userId, $holding, $parsedData);
                    $saved++;
                }
            }

            // Update progress
            $progress = round(($processed / $total) * 100);
            $db->execute(
                "UPDATE sync_jobs SET processed_items = ?, saved_items = ?, progress = ? WHERE id = ?",
                [$processed, $saved, $progress, $jobId]
            );

        } catch (Exception $e) {
            error_log("Error processing email {$message->getId()}: " . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }

    // Mark as completed
    $skipped = $processed - $saved;
    $errorMsg = empty($errors) ? null : implode("; ", array_slice($errors, 0, 3));
    
    $db->execute(
        "UPDATE sync_jobs SET status = 'completed', completed_at = NOW(), progress = 100, processed_items = ?, saved_items = ?, skipped_items = ?, error_message = ? WHERE id = ?",
        [$processed, $saved, $skipped, $errorMsg, $jobId]
    );

} catch (Exception $e) {
    error_log("Background Gmail sync failed: " . $e->getMessage());
    
    // Try to update database even if something went wrong
    try {
        if ($db !== null) {
            $db->execute(
                "UPDATE sync_jobs SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 500), $jobId]
            );
        } else {
            // Database not initialized, try to initialize it just for error logging
            require_once __DIR__ . '/../vendor/autoload.php';
            require_once __DIR__ . '/../config/config.php';
            require_once __DIR__ . '/../config/database.php';
            $db = Database::getInstance();
            $db->execute(
                "UPDATE sync_jobs SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 500), $jobId]
            );
        }
    } catch (Exception $dbError) {
        error_log("Failed to update database with error status: " . $dbError->getMessage());
    }
    
    exit(1);
} catch (Throwable $e) {
    // Catch any fatal errors (PHP 7+)
    error_log("Background Gmail sync fatal error: " . $e->getMessage());
    
    try {
        if ($db !== null) {
            $db->execute(
                "UPDATE sync_jobs SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?",
                [substr("Fatal: " . $e->getMessage(), 0, 500), $jobId]
            );
        }
    } catch (Exception $dbError) {
        error_log("Failed to update database with fatal error status: " . $dbError->getMessage());
    }
    
    exit(1);
}

function decodeBase64Url(string $input): string {
    return base64_decode(strtr($input, '-_', '+/'));
}

function saveMutualFund(Database $db, int $userId, array $holding, array $statementData): void {
    $folioNumber = $statementData['account_number'] ?? 'Unknown';
    $fundName = $holding['name'];
    
    // Check if fund already exists (by folio + fund name for better accuracy)
    $checkQuery = "SELECT id FROM mutual_funds WHERE user_id = ? AND folio_number = ? AND fund_name = ?";
    $existing = $db->fetchAll($checkQuery, [$userId, $folioNumber, $fundName]);

    $investedAmount = $holding['purchase_value'] ?? 0;
    $currentValue = $holding['current_value'] ?? 0;
    $units = $holding['units'] ?? 0;
    $nav = $units > 0 ? ($currentValue / $units) : 0;

    if (empty($existing)) {
        $insertQuery = "INSERT INTO mutual_funds (
            user_id, fund_name, folio_number, amc, units, nav,
            invested_amount, current_value, created_at, last_updated
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $db->execute($insertQuery, [
            $userId,
            $fundName,
            $folioNumber,
            extractAMC($fundName),
            $units,
            $nav,
            $investedAmount,
            $currentValue
        ]);
    } else {
        $updateQuery = "UPDATE mutual_funds SET 
            units = ?, nav = ?, invested_amount = ?, current_value = ?, last_updated = NOW()
            WHERE id = ?";

        $db->execute($updateQuery, [
            $units,
            $nav,
            $investedAmount,
            $currentValue,
            $existing[0]['id']
        ]);
    }
}

function extractAMC(string $fundName): string {
    $amcPatterns = [
        '/HDFC/i' => 'HDFC',
        '/ICICI/i' => 'ICICI Prudential',
        '/SBI/i' => 'SBI',
        '/Axis/i' => 'Axis',
        '/Kotak/i' => 'Kotak',
        '/Nippon/i' => 'Nippon India',
        '/UTI/i' => 'UTI',
        '/Aditya Birla/i' => 'Aditya Birla Sun Life',
        '/DSP/i' => 'DSP',
        '/Franklin/i' => 'Franklin Templeton',
    ];

    foreach ($amcPatterns as $pattern => $amc) {
        if (preg_match($pattern, $fundName)) {
            return $amc;
        }
    }

    return 'Other';
}
