<?php
/**
 * Background SMS Sync Worker
 * Usage: php background_sms_sync.php {jobId} {payloadPath}
 */

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
$payloadPath = $argv[2] ?? null;
$db = null;

try {
    if ($jobId <= 0 || !$payloadPath) {
        throw new Exception('Missing required jobId or payloadPath');
    }

    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/smsParserController.php';

    $db = Database::getInstance();

    if (!is_file($payloadPath)) {
        throw new Exception('SMS sync payload file not found');
    }

    $rawPayload = file_get_contents($payloadPath);
    if ($rawPayload === false) {
        throw new Exception('Failed to read SMS sync payload file');
    }

    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        throw new Exception('Invalid SMS sync payload JSON');
    }

    $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
    $messages = isset($payload['messages']) && is_array($payload['messages']) ? $payload['messages'] : [];

    if ($userId <= 0) {
        throw new Exception('Invalid user_id in SMS sync payload');
    }

    $db->execute(
        "UPDATE sync_jobs SET status = 'processing', started_at = COALESCE(started_at, NOW()), error_message = NULL WHERE id = ?",
        [$jobId]
    );

    $controller = new SMSParserController();
    $controller->processBankMessagesForUser($userId, $messages, [
        'job_id' => $jobId,
        'summary_tag' => 'SMS_ASYNC',
        'chunk_size' => 10,
        'sleep_ms' => 150,
    ]);

    @unlink($payloadPath);
} catch (Throwable $e) {
    error_log('Background SMS sync failed: ' . $e->getMessage());

    try {
        if ($db === null) {
            require_once __DIR__ . '/../config/config.php';
            require_once __DIR__ . '/../config/database.php';
            $db = Database::getInstance();
        }

        if ($jobId > 0) {
            $db->execute(
                "UPDATE sync_jobs SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 500), $jobId]
            );
        }
    } catch (Throwable $inner) {
        error_log('Failed to mark SMS sync job as failed: ' . $inner->getMessage());
    }

    if ($payloadPath && is_file($payloadPath)) {
        @unlink($payloadPath);
    }

    exit(1);
}
