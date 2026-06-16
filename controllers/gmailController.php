<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/gmailService.php';

/**
 * Gmail integration routes (/gmail/*).
 *
 * Namespaced under /gmail (not /sync) because syncController fully claims the
 * /sync prefix. Handles connecting/disconnecting a user's read-only Gmail
 * authorization. Manual fetch (/gmail/sync) and job history (/gmail/jobs) are
 * added in later Phase 2 sub-tasks.
 */
function handleGmailRoutes(string $uri, string $method): void
{
    if ($uri === '/gmail/connect' && $method === 'POST') {
        gmailConnect();
        return;
    }

    if ($uri === '/gmail/status' && $method === 'GET') {
        gmailStatus();
        return;
    }

    if ($uri === '/gmail/disconnect' && $method === 'POST') {
        gmailDisconnect();
        return;
    }

    if ($uri === '/gmail/sync' && $method === 'POST') {
        gmailSync();
        return;
    }

    if ($uri === '/gmail/jobs' && $method === 'GET') {
        gmailJobs();
        return;
    }

    Response::error('Invalid Gmail route', 404);
}

/** Allowed Gmail fetch ranges and their cron-worker meaning. */
const GMAIL_SYNC_RANGES = ['all', '1y', '6m', '2m', '1m'];
const GMAIL_SYNC_TYPES = ['mutual_funds', 'stocks', 'long_term', 'transactions'];

/**
 * POST /gmail/sync  body: { range: all|1y|6m|2m|1m, types?: string[] }
 * Enqueues a Gmail fetch job for the cron worker to drain. Returns the job.
 */
function gmailSync(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    if (!GmailService::isConnected($userId)) {
        Response::error('Gmail is not connected. Connect Gmail before syncing.', 400);
        return;
    }

    $input = getJsonInput();
    $range = strtolower(trim((string)($input['range'] ?? '6m')));
    if (!in_array($range, GMAIL_SYNC_RANGES, true)) {
        Response::error('Invalid range. Use one of: ' . implode(', ', GMAIL_SYNC_RANGES), 400);
        return;
    }

    $types = [];
    if (isset($input['types']) && is_array($input['types'])) {
        $types = array_values(array_intersect($input['types'], GMAIL_SYNC_TYPES));
    }

    $db = getDB();

    // Don't pile up jobs: if one is already queued/running, return it.
    $existing = $db->fetchOne(
        "SELECT id, status, progress FROM sync_jobs
         WHERE user_id = ? AND type = 'gmail' AND status IN ('pending', 'processing')
         ORDER BY created_at DESC LIMIT 1",
        [$userId]
    );
    if ($existing) {
        Response::success([
            'job_id' => (int)$existing['id'],
            'status' => $existing['status'],
            'already_queued' => true,
        ], 'A Gmail sync is already in progress.');
        return;
    }

    $params = json_encode(['range' => $range, 'types' => $types]);
    $jobId = $db->insert(
        "INSERT INTO sync_jobs (user_id, type, params, status, created_at) VALUES (?, 'gmail', ?, 'pending', NOW())",
        [$userId, $params]
    );

    Response::success([
        'job_id' => (int)$jobId,
        'status' => 'pending',
        'range' => $range,
        'types' => $types,
        'already_queued' => false,
    ], 'Gmail sync queued. It will run shortly.');
}

/**
 * GET /gmail/jobs  — recent Gmail sync jobs for the user, with status + fail reason.
 */
function gmailJobs(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    $db = getDB();
    $rows = $db->fetchAll(
        "SELECT id, type, params, status, progress, total_items, processed_items, saved_items,
                skipped_items, error_message, started_at, completed_at, created_at
         FROM sync_jobs
         WHERE user_id = ? AND type = 'gmail'
         ORDER BY created_at DESC
         LIMIT 25",
        [$userId]
    );

    $jobs = array_map(static function (array $r): array {
        return [
            'id' => (int)$r['id'],
            'status' => $r['status'],
            'params' => $r['params'] ? json_decode($r['params'], true) : null,
            'progress' => (int)$r['progress'],
            'processed_items' => (int)$r['processed_items'],
            'saved_items' => (int)$r['saved_items'],
            'skipped_items' => (int)$r['skipped_items'],
            'error_message' => $r['error_message'],
            'started_at' => $r['started_at'],
            'completed_at' => $r['completed_at'],
            'created_at' => $r['created_at'],
        ];
    }, $rows);

    Response::success(['jobs' => $jobs, 'count' => count($jobs)], 'Gmail sync jobs retrieved.');
}

function gmailConnect(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    $input = getJsonInput();
    $code = $input['server_auth_code'] ?? $input['serverAuthCode'] ?? $input['code'] ?? null;

    if (!$code || trim((string)$code) === '') {
        Response::error('server_auth_code is required', 400);
        return;
    }

    try {
        $result = GmailService::connectFromAuthCode($userId, (string)$code);
        Response::success($result, 'Gmail connected successfully');
    } catch (Throwable $e) {
        error_log('Gmail connect error: ' . $e->getMessage());
        Response::error('Failed to connect Gmail: ' . $e->getMessage(), 400);
    }
}

function gmailStatus(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    try {
        Response::success(GmailService::getStatus($userId), 'Gmail status retrieved');
    } catch (Throwable $e) {
        error_log('Gmail status error: ' . $e->getMessage());
        Response::error('Failed to get Gmail status', 500);
    }
}

function gmailDisconnect(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    try {
        GmailService::disconnect($userId);
        Response::success(['connected' => false], 'Gmail disconnected');
    } catch (Throwable $e) {
        error_log('Gmail disconnect error: ' . $e->getMessage());
        Response::error('Failed to disconnect Gmail', 500);
    }
}
