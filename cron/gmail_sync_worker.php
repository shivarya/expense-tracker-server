<?php
/**
 * Gmail Fetch Worker (cron-drained)
 *
 * Drains pending `gmail` rows from sync_jobs. Designed for cPanel: no daemon —
 * run it from cron every ~10 min and it processes a few jobs within a wall-clock
 * budget that stays under shared-hosting limits:
 *
 *   *\/10 * * * *  php /home/USER/.../server/cron/gmail_sync_worker.php >> /home/USER/gmail_worker.log 2>&1
 *
 * For each job it loads the user's Gmail client, fetches statement emails for the
 * requested date range, decrypts PDFs using the user's candidate-password pool,
 * extracts holdings/transactions, upserts them, and records progress + a
 * scrape_logs entry per source.
 *
 * This increment implements the CAMS/KFintech mutual-fund source end-to-end;
 * CC / CDSL / NPS are recognized and logged as not-yet-implemented (next
 * increment) so the framework and job tracking are already in place.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This worker runs from CLI/cron only.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/gmailService.php';
require_once __DIR__ . '/../utils/gmailFetcher.php';
require_once __DIR__ . '/../utils/statementPasswordVault.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../controllers/statementsController.php';

const WORKER_BUDGET_SECONDS = 50;   // stay under cPanel max_execution_time
const MAX_JOBS_PER_RUN = 5;
const MAX_MESSAGES_PER_SOURCE = 25;

// Gmail senders per source (mirrors scraper/src/config/senders.ts).
const SOURCES = [
    'mutual_funds' => [
        'source' => 'cams',
        'senders' => ['donotreply@camsonline.com', 'service@kfintech.com'],
        'implemented' => true,
    ],
    'stocks' => [
        'source' => 'cdsl',
        'senders' => ['eCAS@cdslstatement.com'],
        'implemented' => true, // CDSL eCAS (stocks + MF)
    ],
    'long_term' => [
        'source' => 'nps',
        'senders' => ['nps-statements@mailer.proteantech.in'],
        'implemented' => true, // NPS
    ],
    'transactions' => [
        'source' => 'credit_cards',
        // Only SBI/ICICI statement PDFs are parseable today (parseTransactionsByBank);
        // others are fetched but will log a parser error until their parsers land.
        // cbssbi.cas@alerts.sbi.bank.in and yonobysbi@alerts.sbi.bank.in are a
        // different format entirely (SBI's consolidated *savings-account*
        // e-statement -- same underlying report, just requested via netbanking
        // vs the YONO app -- not a credit-card statement) and are routed
        // separately in dispatchMessage() below.
        'senders' => [
            'statements@hdfcbank.net', 'statements@rbl.bank.in',
            'credit_cards@icicibank.com', 'credit_cards@icici.bank.in',
            'creditcardservices@sbicard.com', 'statements@axisbank.com',
            'cbssbi.cas@alerts.sbi.bank.in', 'yonobysbi@alerts.sbi.bank.in',
        ],
        'implemented' => true, // CC statements (reuses StatementController::ingestCreditCardPdf)
    ],
];

/** Senders for SBI's consolidated account-statement (CAS) email — a savings-account layout, not a CC statement. Same report, requested via netbanking or the YONO app. */
const SBI_CAS_SENDERS = ['cbssbi.cas@alerts.sbi.bank.in', 'yonobysbi@alerts.sbi.bank.in'];

// Map a credit-card statement sender address to a bank enum value.
const CC_SENDER_BANK = [
    'statements@hdfcbank.net' => 'hdfc',
    'statements@rbl.bank.in' => 'rbl',
    'rblcreditcard@rblbank.com' => 'rbl',
    'credit_cards@icicibank.com' => 'icici',
    'credit_cards@icici.bank.in' => 'icici',
    'creditcardservices@sbicard.com' => 'sbi',
    'statements@axisbank.com' => 'axis',
    'statements@kotak.com' => 'kotak',
];

$startTime = time();
$db = Database::getInstance();

$jobs = $db->fetchAll(
    "SELECT id, user_id, params FROM sync_jobs
     WHERE type = 'gmail' AND status = 'pending'
     ORDER BY created_at ASC
     LIMIT " . MAX_JOBS_PER_RUN
);

if (empty($jobs)) {
    echo "[gmail-worker] no pending jobs\n";
    exit(0);
}

foreach ($jobs as $job) {
    if (time() - $startTime > WORKER_BUDGET_SECONDS) {
        echo "[gmail-worker] time budget reached; remaining jobs left pending\n";
        break;
    }
    processJob($db, (int)$job['id'], (int)$job['user_id'], $job['params']);
}

exit(0);

// ─────────────────────────────────────────────────────────────────────────────

function processJob(Database $db, int $jobId, int $userId, $paramsRaw): void
{
    global $startTime; // the per-run wall-clock budget anchor (set in the main scope)

    // Atomically claim the job so overlapping cron runs don't double-process it.
    $claimed = $db->execute(
        "UPDATE sync_jobs SET status = 'processing', started_at = NOW() WHERE id = ? AND status = 'pending'",
        [$jobId]
    );
    if ($claimed < 1) {
        return;
    }

    try {
        $params = is_string($paramsRaw) ? (json_decode($paramsRaw, true) ?: []) : (is_array($paramsRaw) ? $paramsRaw : []);
        $range = (string)($params['range'] ?? '6m');
        $requestedTypes = isset($params['types']) && is_array($params['types']) && !empty($params['types'])
            ? $params['types']
            : array_keys(SOURCES);

        $client = GmailService::getClientForUser($userId);
        if ($client === null) {
            throw new Exception('Gmail not connected (or access was revoked). Reconnect Gmail and retry.');
        }

        $afterClause = gmailAfterClause($range);
        $passwords = gatherCandidatePasswords($db, $userId);
        $statementController = new StatementController();
        $ai = new AzureOpenAI();

        $totalProcessed = 0;
        $totalSaved = 0;
        $totalSkipped = 0;
        $sourceSummaries = [];

        foreach ($requestedTypes as $dataType) {
            if (!isset(SOURCES[$dataType])) {
                continue;
            }
            $cfg = SOURCES[$dataType];

            if (empty($cfg['implemented'])) {
                logScrape($db, $userId, $dataType, $cfg['source'], 'partial', 0, 0, 'Source recognized; processing not yet implemented in this build.');
                $sourceSummaries[] = "{$cfg['source']}: skipped (not yet implemented)";
                continue;
            }

            $fromQuery = implode(' OR ', array_map(fn($s) => "from:{$s}", $cfg['senders']));
            $query = "({$fromQuery}) has:attachment {$afterClause}";
            $messageIds = GmailFetcher::listMessageIds($client, trim($query), MAX_MESSAGES_PER_SOURCE);

            $srcSaved = 0;
            $srcProcessed = 0;
            $srcFailed = 0;
            $srcFailureReasons = [];

            foreach ($messageIds as $messageId) {
                if (time() - $startTime > WORKER_BUDGET_SECONDS) {
                    break 2;
                }

                $syncId = 'gmail:' . $messageId;
                if (alreadySynced($db, $userId, $dataType, $cfg['source'], $syncId)) {
                    $totalSkipped++;
                    continue;
                }

                try {
                    $saved = dispatchMessage($db, $client, $statementController, $ai, $userId, $dataType, $messageId, $passwords);
                    $srcSaved += $saved;
                    $totalSaved += $saved;
                    markSynced($db, $userId, $dataType, $cfg['source'], $syncId, ['saved' => $saved]);
                } catch (Throwable $e) {
                    $srcFailed++;
                    $reason = trim($e->getMessage());
                    if ($reason !== '' && !in_array($reason, $srcFailureReasons, true)) {
                        $srcFailureReasons[] = $reason;
                    }
                    error_log("[gmail-worker] message {$messageId} failed: " . $e->getMessage());
                }

                $srcProcessed++;
                $totalProcessed++;
                updateProgress($db, $jobId, $totalProcessed, $totalSaved, $totalSkipped);
            }

            // Surface *why* a message failed instead of only counting it — a
            // decrypt/parse failure never calls markSynced(), so it's retried
            // (and silently re-fails) on every future sync until this is visible
            // somewhere a person will actually see it.
            $summary = "{$cfg['source']}: {$srcSaved} saved / {$srcProcessed} emails";
            if ($srcFailed > 0) {
                $summary .= " ({$srcFailed} failed: " . implode(' | ', array_slice($srcFailureReasons, 0, 2)) . ")";
            }
            $sourceSummaries[] = $summary;

            $scrapeStatus = $srcFailed === 0 ? 'success' : ($srcSaved > 0 ? 'partial' : 'failed');
            $scrapeError = $srcFailed > 0 ? implode(' | ', array_slice($srcFailureReasons, 0, 5)) : null;
            logScrape($db, $userId, $dataType, $cfg['source'], $scrapeStatus, $srcProcessed, $srcSaved, $scrapeError);
        }

        $db->execute(
            "UPDATE sync_jobs
             SET status = 'completed', completed_at = NOW(), progress = 100,
                 processed_items = ?, saved_items = ?, skipped_items = ?, error_message = ?
             WHERE id = ?",
            [$totalProcessed, $totalSaved, $totalSkipped, implode('; ', $sourceSummaries) ?: null, $jobId]
        );
        echo "[gmail-worker] job {$jobId} completed: " . implode('; ', $sourceSummaries) . "\n";
    } catch (Throwable $e) {
        $db->execute(
            "UPDATE sync_jobs SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?",
            [substr($e->getMessage(), 0, 500), $jobId]
        );
        echo "[gmail-worker] job {$jobId} failed: " . $e->getMessage() . "\n";
    }
}

/** Route a single email to the processor for its data type. Returns rows saved. */
function dispatchMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $dataType,
    string $messageId,
    array $passwords
): int {
    switch ($dataType) {
        case 'mutual_funds':
            return processMutualFundMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'stocks':
            return processCdslMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'long_term':
            return processNpsMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'transactions':
            $message = GmailFetcher::getMessage($client, $messageId);
            $from = GmailFetcher::getHeader($message, 'From');
            foreach (SBI_CAS_SENDERS as $sbiCasSender) {
                if (stripos($from, $sbiCasSender) !== false) {
                    return processSbiCasMessage($client, $sc, $userId, $messageId, $message, $passwords);
                }
            }
            return processCreditCardMessage($db, $client, $sc, $ai, $userId, $messageId, $message, $passwords);
        default:
            return 0;
    }
}

/**
 * Download a message's PDF attachments and return decrypted text (tries the
 * email's password hint, then the user's candidate pool). Falls back to the
 * email body when there are no attachments.
 * @return array{text: string, subject: string, body: string}
 */
function fetchDecryptedText(
    \Google\Client $client,
    StatementController $sc,
    string $messageId,
    \Google\Service\Gmail\Message $message,
    array $passwords
): array {
    $subject = GmailFetcher::getHeader($message, 'Subject');
    $body = GmailFetcher::getPlainText($message);
    $hint = GmailFetcher::extractPasswordHint($body, $subject);
    $tryPasswords = $hint ? array_merge([$hint], $passwords) : $passwords;

    $attachments = GmailFetcher::downloadPdfAttachments($client, $messageId, $message);

    $text = '';
    if (empty($attachments)) {
        $text = $body;
    } else {
        foreach ($attachments as $att) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gmail_dl_' . uniqid() . '.pdf';
            file_put_contents($tmp, $att['bytes']);
            try {
                $t = extractTextTryingPasswords($sc, $tmp, $tryPasswords);
                if (trim($t) !== '') {
                    $text .= "\n" . $t;
                }
            } finally {
                @unlink($tmp);
            }
        }
    }

    return ['text' => $text, 'subject' => $subject, 'body' => $body];
}

/** CAMS/KFintech → mutual-fund holdings. */
function processMutualFundMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $messageId,
    array $passwords
): int {
    $message = GmailFetcher::getMessage($client, $messageId);
    $data = fetchDecryptedText($client, $sc, $messageId, $message, $passwords);
    if (trim($data['text']) === '') {
        throw new Exception('Could not extract text (PDF locked and no candidate password matched).');
    }

    $parsed = $ai->parseEmailContent($data['text'], $data['subject']);
    $holdings = (is_array($parsed) && isset($parsed['holdings']) && is_array($parsed['holdings'])) ? $parsed['holdings'] : [];
    $folio = (is_array($parsed) ? ($parsed['account_number'] ?? null) : null) ?: 'Unknown';

    $saved = 0;
    foreach ($holdings as $holding) {
        if (empty($holding['name'])) {
            continue;
        }
        saveMutualFundHolding($db, $userId, $holding, (string)$folio);
        $saved++;
    }
    return $saved;
}

/** CDSL eCAS → demat stocks + mutual-fund holdings. */
function processCdslMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $messageId,
    array $passwords
): int {
    $message = GmailFetcher::getMessage($client, $messageId);
    $data = fetchDecryptedText($client, $sc, $messageId, $message, $passwords);
    if (trim($data['text']) === '') {
        throw new Exception('Could not extract CDSL eCAS text (locked PDF / wrong password).');
    }

    $holdings = $ai->extractCdslHoldings($data['text']);
    $saved = 0;

    foreach (($holdings['stocks'] ?? []) as $stock) {
        if (empty($stock['isin']) && empty($stock['symbol']) && empty($stock['company_name'])) {
            continue;
        }
        saveStockHolding($db, $userId, $stock);
        $saved++;
    }

    foreach (($holdings['mutual_funds'] ?? []) as $mf) {
        $name = trim((string)($mf['fund_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        saveMutualFundHolding($db, $userId, [
            'name' => $name,
            'units' => $mf['units'] ?? 0,
            'current_value' => $mf['current_value'] ?? 0,
            'purchase_value' => $mf['invested_amount'] ?? 0,
        ], (string)($mf['folio_number'] ?? 'Unknown'));
        $saved++;
    }

    return $saved;
}

/** NPS statement → long-term fund summary. */
function processNpsMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $messageId,
    array $passwords
): int {
    $message = GmailFetcher::getMessage($client, $messageId);
    $data = fetchDecryptedText($client, $sc, $messageId, $message, $passwords);
    if (trim($data['text']) === '') {
        throw new Exception('Could not extract NPS statement text (locked PDF / wrong password).');
    }

    $nps = $ai->extractNpsStatement($data['text']);
    if (empty($nps) || ((float)($nps['current_value'] ?? 0) <= 0 && (float)($nps['invested_amount'] ?? 0) <= 0)) {
        return 0;
    }

    saveLongTermNps($db, $userId, $nps);
    return 1;
}

/** Credit-card statement email → transactions (reuses the upload pipeline). */
function processCreditCardMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $messageId,
    \Google\Service\Gmail\Message $message,
    array $passwords
): int {
    $from = GmailFetcher::getHeader($message, 'From');
    $bank = bankFromSender($from);
    if ($bank === null) {
        throw new Exception('Unrecognized credit-card sender: ' . $from);
    }

    $attachments = GmailFetcher::downloadPdfAttachments($client, $messageId, $message);
    if (empty($attachments)) {
        return 0;
    }

    $saved = 0;
    foreach ($attachments as $att) {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gmail_cc_' . uniqid() . '.pdf';
        file_put_contents($tmp, $att['bytes']);
        try {
            $result = $sc->ingestCreditCardPdf($userId, $bank, '', $tmp, $att['filename'], $passwords);
            $saved += (int)($result['saved_transactions'] ?? 0);
        } finally {
            @unlink($tmp);
        }
    }
    return $saved;
}

/** SBI consolidated account-statement (CAS) email → savings-account transactions. */
function processSbiCasMessage(
    \Google\Client $client,
    StatementController $sc,
    int $userId,
    string $messageId,
    \Google\Service\Gmail\Message $message,
    array $passwords
): int {
    $attachments = GmailFetcher::downloadPdfAttachments($client, $messageId, $message);
    if (empty($attachments)) {
        return 0;
    }

    $saved = 0;
    foreach ($attachments as $att) {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gmail_sbi_cas_' . uniqid() . '.pdf';
        file_put_contents($tmp, $att['bytes']);
        try {
            $result = $sc->ingestSbiCasStatement($userId, $tmp, $att['filename'], $passwords);
            $saved += (int)($result['saved_transactions'] ?? 0);
        } finally {
            @unlink($tmp);
        }
    }
    return $saved;
}

/** Map a credit-card statement From header to a bank enum, or null. */
function bankFromSender(string $from): ?string
{
    $needle = strtolower($from);
    foreach (CC_SENDER_BANK as $addr => $bank) {
        if (strpos($needle, strtolower($addr)) !== false) {
            return $bank;
        }
    }
    return null;
}

/** Try empty password (unencrypted) then each candidate until text is extracted. */
function extractTextTryingPasswords(StatementController $statementController, string $file, array $passwords): string
{
    foreach (array_merge([''], $passwords) as $pwd) {
        try {
            $text = $statementController->extractTextFromPdf($file, (string)$pwd);
            if (trim($text) !== '') {
                return $text;
            }
        } catch (Throwable $e) {
            // try next password
        }
    }
    return '';
}

function saveMutualFundHolding(Database $db, int $userId, array $holding, string $folio): void
{
    $fundName = (string)$holding['name'];
    $units = (float)($holding['units'] ?? 0);
    $currentValue = (float)($holding['current_value'] ?? 0);
    $invested = (float)($holding['purchase_value'] ?? $holding['invested_amount'] ?? 0);
    $nav = $units > 0 ? ($currentValue / $units) : 0;

    $existing = $db->fetchOne(
        "SELECT id FROM mutual_funds WHERE user_id = ? AND folio_number = ? AND fund_name = ?",
        [$userId, $folio, $fundName]
    );

    if ($existing) {
        $db->execute(
            "UPDATE mutual_funds SET units = ?, nav = ?, invested_amount = ?, current_value = ?, last_updated = NOW() WHERE id = ?",
            [$units, $nav, $invested, $currentValue, $existing['id']]
        );
    } else {
        $db->execute(
            "INSERT INTO mutual_funds (user_id, fund_name, folio_number, amc, units, nav, invested_amount, current_value, created_at, last_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$userId, $fundName, $folio, extractAmc($fundName), $units, $nav, $invested, $currentValue]
        );
    }
}

function saveStockHolding(Database $db, int $userId, array $stock): void
{
    $isin = trim((string)($stock['isin'] ?? ''));
    $symbol = trim((string)($stock['symbol'] ?? ''));
    $company = trim((string)($stock['company_name'] ?? ''));

    if ($symbol === '') {
        $symbol = $isin !== ''
            ? $isin
            : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $company) ?: '', 0, 12));
    }
    if ($symbol === '') {
        return;
    }

    $qty = (float)($stock['quantity'] ?? 0);
    $current = (float)($stock['current_value'] ?? 0);
    $invested = (float)($stock['invested_amount'] ?? 0);
    $avg = $qty > 0 ? ($invested / $qty) : 0;

    // Upsert keyed by unique_stock_identity (user_id, platform, dedupe_key),
    // where dedupe_key is generated from isin (or symbol) by the schema.
    $db->execute(
        "INSERT INTO stocks
            (user_id, platform, symbol, isin, company_name, quantity, average_price, invested_amount, current_value, created_at, last_updated)
         VALUES (?, 'cdsl', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            average_price = VALUES(average_price),
            invested_amount = VALUES(invested_amount),
            current_value = VALUES(current_value),
            company_name = VALUES(company_name),
            last_updated = NOW()",
        [$userId, $symbol, $isin !== '' ? $isin : null, $company !== '' ? $company : $symbol, $qty, $avg, $invested, $current]
    );
}

function saveLongTermNps(Database $db, int $userId, array $nps): void
{
    $pran = trim((string)($nps['pran'] ?? ''));
    $name = trim((string)($nps['account_name'] ?? '')) ?: 'NPS Account';
    $invested = (float)($nps['invested_amount'] ?? 0);
    $current = (float)($nps['current_value'] ?? 0);
    $interest = max(0.0, $current - $invested);

    $existing = $db->fetchOne(
        "SELECT id FROM long_term_funds
         WHERE user_id = ? AND fund_type = 'nps' AND ((? <> '' AND pran_number = ?) OR account_name = ?)
         LIMIT 1",
        [$userId, $pran, $pran, $name]
    );

    if ($existing) {
        $db->execute(
            "UPDATE long_term_funds
             SET account_name = ?, pran_number = ?, invested_amount = ?, current_value = ?, interest_earned = ?, last_updated = NOW()
             WHERE id = ?",
            [$name, $pran !== '' ? $pran : null, $invested, $current, $interest, $existing['id']]
        );
    } else {
        $db->execute(
            "INSERT INTO long_term_funds
                (user_id, fund_type, account_name, pran_number, invested_amount, current_value, interest_earned, status, created_at, last_updated)
             VALUES (?, 'nps', ?, ?, ?, ?, ?, 'active', NOW(), NOW())",
            [$userId, $name, $pran !== '' ? $pran : null, $invested, $current, $interest]
        );
    }
}

function extractAmc(string $fundName): string
{
    $map = [
        'HDFC' => 'HDFC', 'ICICI' => 'ICICI Prudential', 'SBI' => 'SBI', 'Axis' => 'Axis',
        'Kotak' => 'Kotak', 'Nippon' => 'Nippon India', 'UTI' => 'UTI', 'DSP' => 'DSP',
        'Aditya Birla' => 'Aditya Birla Sun Life', 'Franklin' => 'Franklin Templeton', 'Mirae' => 'Mirae Asset',
    ];
    foreach ($map as $needle => $amc) {
        if (stripos($fundName, $needle) !== false) {
            return $amc;
        }
    }
    return 'Other';
}

/** Decrypt the user's candidate + card-specific stored passwords to plaintext. */
function gatherCandidatePasswords(Database $db, int $userId): array
{
    $out = [];
    $sources = [
        "SELECT encrypted_password, iv, auth_tag FROM statement_password_candidates WHERE user_id = ?",
        "SELECT encrypted_password, iv, auth_tag FROM statement_passwords WHERE user_id = ?",
    ];
    foreach ($sources as $sql) {
        foreach ($db->fetchAll($sql, [$userId]) as $row) {
            try {
                $pwd = StatementPasswordVault::decrypt($row['encrypted_password'], $row['iv'], $row['auth_tag']);
                if ($pwd !== '') {
                    $out[] = $pwd;
                }
            } catch (Throwable $e) {
                // skip undecryptable rows
            }
        }
    }
    return array_values(array_unique($out));
}

function gmailAfterClause(string $range): string
{
    $map = ['1m' => '-1 month', '2m' => '-2 months', '6m' => '-6 months', '1y' => '-1 year'];
    if ($range === 'all' || !isset($map[$range])) {
        return $range === 'all' ? '' : 'after:' . date('Y/m/d', strtotime('-6 months'));
    }
    return 'after:' . date('Y/m/d', strtotime($map[$range]));
}

function alreadySynced(Database $db, int $userId, string $dataType, string $source, string $identifier): bool
{
    $row = $db->fetchOne(
        "SELECT id FROM scraper_sync_log WHERE user_id = ? AND data_type = ? AND source = ? AND source_identifier = ?",
        [$userId, $dataType, $source, $identifier]
    );
    return !empty($row);
}

function markSynced(Database $db, int $userId, string $dataType, string $source, string $identifier, array $metadata): void
{
    $db->execute(
        "INSERT INTO scraper_sync_log (user_id, data_type, source, source_identifier, metadata, synced_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE metadata = VALUES(metadata), synced_at = NOW()",
        [$userId, $dataType, $source, $identifier, json_encode($metadata)]
    );
}

function logScrape(Database $db, int $userId, string $dataType, string $source, string $status, int $processed, int $created, ?string $error): void
{
    $sourceType = in_array($dataType, ['mutual_funds', 'stocks', 'fixed_deposits'], true) ? $dataType
        : ($dataType === 'long_term' ? 'nps' : 'email');
    $db->execute(
        "INSERT INTO scrape_logs (user_id, source_type, source_name, status, records_processed, records_created, error_message, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$userId, $sourceType, $source, $status, $processed, $created, $error]
    );
}

function updateProgress(Database $db, int $jobId, int $processed, int $saved, int $skipped): void
{
    $db->execute(
        "UPDATE sync_jobs SET processed_items = ?, saved_items = ?, skipped_items = ? WHERE id = ?",
        [$processed, $saved, $skipped, $jobId]
    );
}
