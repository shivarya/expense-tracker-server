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
require_once __DIR__ . '/../utils/bankAlertIngestor.php';
require_once __DIR__ . '/../utils/accountBalance.php';
require_once __DIR__ . '/../utils/statementBalanceExtractor.php';
require_once __DIR__ . '/../utils/subscriptionService.php';
require_once __DIR__ . '/../utils/npsAccounts.php';

const WORKER_BUDGET_SECONDS = 50;   // stay under cPanel max_execution_time
const MAX_JOBS_PER_RUN = 5;
const MAX_MESSAGES_PER_SOURCE = 25;
const MAX_REQUEUES = 5;              // times one job may hand its remaining work to the next cron run
const AUTO_SYNC_EVERY_SECONDS = 6 * 3600; // a recent-mail sync per connected user, so alerts land the same day

// Gmail senders per source (mirrors scraper/src/config/senders.ts). The key is what a job's `types` names;
// 'data_type' (default: the key) is the scraper_sync_log / scrape_logs bucket; 'attachments' => false lists
// body-only mail; 'sender_filters' narrows one sender with extra Gmail search terms.
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
    // 'transactions' comes before 'long_term' deliberately: NPS statements
    // routinely fail with locked-PDF/wrong-password errors that burn through
    // several password-candidate attempts per message, and a slow-failing
    // source ahead of this one can exhaust WORKER_BUDGET_SECONDS via the
    // `break 2` below before this source's loop is ever entered -- silently
    // skipping (not failing -- just never attempted) credit-card and SBI CAS
    // statement processing for that run. Keep this the highest-priority
    // implemented source since it's the most-used pipeline.
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
    // Body-only alert emails, parsed without AI (utils/alertEmailParsers.php): HDFC UPI debits/credits — kept
    // only when no SMS/notification already recorded that UPI reference (HDFC sends no SMS for UPI debits
    // <= ₹100 / credits <= ₹500) — HDFC's daily available balance, and Pluxee meal-card spends/loads/balance.
    // HDFC's ATM / e-mandate "Account update" mails are left alone: no UPI reference to dedupe on, and the
    // bank SMS already covers them.
    'bank_alerts' => [
        'source' => 'bank_alerts',
        'data_type' => 'transactions',
        'senders' => ['alerts@hdfcbank.bank.in', 'alerts@hdfcbank.net', 'noreply-cardinfo@services.pluxee.in'],
        'attachments' => false,
        'subject' => 'subject:("UPI txn" OR "Account update" OR "Transaction confirmation" OR "credited")',
        'max_messages' => 200,
        'implemented' => true,
    ],
    'long_term' => [
        'source' => 'nps',
        // Protean CRA, and KFintech CRA (the employer-paid corporate NPS); both PDFs open with the 12-digit PRAN.
        'senders' => ['nps-statements@mailer.proteantech.in', 'kcra@kfintech.com'],
        'sender_filters' => ['kcra@kfintech.com' => 'subject:"Statement"'],
        'implemented' => true, // NPS
    ],
    // Statements read only for the account's closing balance (savings balance for net worth).
    'balances' => [
        'source' => 'balance_statements',
        'data_type' => 'bank_accounts',
        'senders' => ['statement@idfcfirst.bank.in'],
        'implemented' => true,
    ],
];

/** Balance-only statement senders → bank enum. */
const BALANCE_SENDER_BANK = ['statement@idfcfirst.bank.in' => 'idfc'];

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

enqueueAutoSyncJobs($db);

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
        $undecryptablePasswords = 0;
        $passwords = gatherCandidatePasswords($db, $userId, $undecryptablePasswords);
        $statementController = new StatementController();
        $ai = new AzureOpenAI();

        // A job that ran out of time budget is handed back to the queue (below) with
        // its running totals in params.carry, so a long sync finishes across several
        // cron runs instead of being marked "completed" with later sources skipped.
        $requeues = (int)($params['requeues'] ?? 0);
        $carry = is_array($params['carry'] ?? null) ? $params['carry'] : [];
        $totalProcessed = (int)($carry['processed'] ?? 0);
        $totalSaved = (int)($carry['saved'] ?? 0);
        $totalSkipped = 0; // re-counted every run (already-synced mail is re-listed each time)
        $sourceSummaries = is_array($carry['summaries'] ?? null) ? $carry['summaries'] : [];
        $budgetHit = false;

        if ($undecryptablePasswords > 0 && $requeues === 0) {
            $warning = "WARNING: {$undecryptablePasswords} saved statement password(s) cannot be decrypted with the current server key "
                . '(' . count($passwords) . ' usable) -- re-add them under Statement Passwords, otherwise the PDFs they protect will keep failing';
            error_log("[gmail-worker] user {$userId}: {$warning}");
            $sourceSummaries[] = $warning;
        }

        foreach ($requestedTypes as $sourceKey) {
            if (!isset(SOURCES[$sourceKey])) {
                continue;
            }
            $cfg = SOURCES[$sourceKey];
            $dataType = $cfg['data_type'] ?? $sourceKey;

            if (time() - $startTime > WORKER_BUDGET_SECONDS) {
                $budgetHit = true;
                break;
            }

            if (empty($cfg['implemented'])) {
                logScrape($db, $userId, $dataType, $cfg['source'], 'partial', 0, 0, 'Source recognized; processing not yet implemented in this build.');
                $sourceSummaries[] = "{$cfg['source']}: skipped (not yet implemented)";
                continue;
            }

            $messageIds = GmailFetcher::listMessageIds($client, sourceQuery($cfg, $afterClause), (int)($cfg['max_messages'] ?? MAX_MESSAGES_PER_SOURCE));

            $srcSaved = 0;
            $srcProcessed = 0;
            $srcFailed = 0;
            $srcFailureReasons = [];

            foreach ($messageIds as $messageId) {
                $syncId = 'gmail:' . $messageId;
                // params.reprocess names sources whose already-synced mail should be read again (e.g. to pick up
                // the closing balance of a statement imported before balances were tracked). Safe: statement
                // imports dedupe on file hash, alerts on UPI reference / message hash.
                $reprocess = in_array($sourceKey, (array)($params['reprocess'] ?? []), true);
                if (!$reprocess && alreadySynced($db, $userId, $dataType, $cfg['source'], $syncId)) {
                    $totalSkipped++;
                    continue;
                }

                // Only out of time if there is real work left (checked after the cheap
                // already-synced skip). Stop this source, but still write its summary
                // and scrape log below -- a bare `break 2` used to drop both.
                if (time() - $startTime > WORKER_BUDGET_SECONDS) {
                    $budgetHit = true;
                    break;
                }

                try {
                    $saved = dispatchMessage($db, $client, $statementController, $ai, $userId, $sourceKey, $messageId, $passwords);
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
            if ($budgetHit) {
                $summary .= ' [time budget reached; more to do]';
            }
            // On continuation runs every source is re-listed; don't repeat a "0 emails" line for each.
            if (!($requeues > 0 && $srcProcessed === 0 && $srcFailed === 0)) {
                $sourceSummaries[] = $summary;
            }

            $scrapeStatus = $srcFailed === 0 ? 'success' : ($srcSaved > 0 ? 'partial' : 'failed');
            $scrapeError = $srcFailed > 0 ? implode(' | ', array_slice($srcFailureReasons, 0, 5)) : null;
            logScrape($db, $userId, $dataType, $cfg['source'], $scrapeStatus, $srcProcessed, $srcSaved, $scrapeError);

            if ($budgetHit) {
                break;
            }
        }

        if ($budgetHit) {
            if ($requeues < MAX_REQUEUES) {
                // Not finished: hand the rest to the next cron run. Everything already
                // processed is in scraper_sync_log, so it won't be redone.
                $params['requeues'] = $requeues + 1;
                $params['carry'] = ['processed' => $totalProcessed, 'saved' => $totalSaved, 'summaries' => $sourceSummaries];
                $db->execute(
                    "UPDATE sync_jobs
                     SET status = 'pending', started_at = NULL, params = ?,
                         processed_items = ?, saved_items = ?, skipped_items = ?, error_message = ?
                     WHERE id = ?",
                    [json_encode($params), $totalProcessed, $totalSaved, $totalSkipped, implode('; ', $sourceSummaries) . ' [continuing in next run]', $jobId]
                );
                echo "[gmail-worker] job {$jobId} out of time budget; re-queued (run " . ($requeues + 1) . '/' . MAX_REQUEUES . "): " . implode('; ', $sourceSummaries) . "\n";
                return;
            }
            $sourceSummaries[] = 'stopped early: hit the time budget on every retry -- run Sync again to continue';
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

/** Gmail search for one source: its senders (each optionally narrowed), subject filter, attachment rule, date. */
function sourceQuery(array $cfg, string $afterClause): string
{
    $filters = $cfg['sender_filters'] ?? [];
    $from = implode(' OR ', array_map(
        fn($s) => isset($filters[$s]) ? "(from:{$s} {$filters[$s]})" : "from:{$s}",
        $cfg['senders']
    ));
    $parts = ["({$from})"];
    if (!empty($cfg['subject'])) {
        $parts[] = $cfg['subject'];
    }
    if ($cfg['attachments'] ?? true) {
        $parts[] = 'has:attachment';
    }
    $parts[] = $afterClause;
    return trim(implode(' ', $parts));
}

/** Route a single email to the processor for its source. Returns rows saved. */
function dispatchMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $sourceKey,
    string $messageId,
    array $passwords
): int {
    switch ($sourceKey) {
        case 'mutual_funds':
            return processMutualFundMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'stocks':
            return processCdslMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'long_term':
            return processNpsMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'bank_alerts':
            return processBankAlertMessage($db, $client, $userId, $messageId);
        case 'balances':
            return processBalanceStatementMessage($db, $client, $sc, $ai, $userId, $messageId, $passwords);
        case 'transactions':
            $message = GmailFetcher::getMessage($client, $messageId);
            $from = GmailFetcher::getHeader($message, 'From');
            foreach (SBI_CAS_SENDERS as $sbiCasSender) {
                if (stripos($from, $sbiCasSender) !== false) {
                    return processSbiCasMessage($db, $client, $sc, $userId, $messageId, $message, $passwords);
                }
            }
            return processCreditCardMessage($db, $client, $sc, $ai, $userId, $messageId, $message, $passwords);
        default:
            return 0;
    }
}

/**
 * HDFC / Pluxee alert email → balances + only-missing transactions (utils/bankAlertIngestor.php). Returns the
 * number of transactions created plus balances updated.
 */
function processBankAlertMessage(Database $db, \Google\Client $client, int $userId, string $messageId): int
{
    static $ingestor = null;
    $ingestor ??= new BankAlertIngestor($db);

    $message = GmailFetcher::getMessage($client, $messageId);
    // internalDate is when Gmail received the alert (ms since epoch) — the payment time to within seconds.
    $receivedAt = date('Y-m-d H:i:s', (int)floor(((int)$message->getInternalDate()) / 1000));
    $result = $ingestor->ingest(
        $userId,
        GmailFetcher::getHeader($message, 'From'),
        GmailFetcher::getHeader($message, 'Subject'),
        GmailFetcher::getReadableText($message),
        $receivedAt,
        $messageId
    );
    return $result['transactions'] + $result['balances'];
}

/** IDFC FIRST monthly statement → that savings account's closing balance. */
function processBalanceStatementMessage(
    Database $db,
    \Google\Client $client,
    StatementController $sc,
    AzureOpenAI $ai,
    int $userId,
    string $messageId,
    array $passwords
): int {
    $message = GmailFetcher::getMessage($client, $messageId);
    $from = strtolower(GmailFetcher::getHeader($message, 'From'));
    $bank = null;
    foreach (BALANCE_SENDER_BANK as $addr => $enum) {
        if (str_contains($from, $addr)) {
            $bank = $enum;
        }
    }
    if ($bank === null) {
        throw new Exception('Unrecognized balance-statement sender: ' . $from);
    }

    $data = fetchDecryptedText($client, $sc, $messageId, $message, $passwords);
    if (trim($data['text']) === '' || $data['text'] === $data['body']) {
        throw new Exception('Could not open the statement PDF (locked and no saved password matched -- add it under Statement Passwords).');
    }

    $found = StatementBalanceExtractor::closingBalance($data['text'], $ai);
    if ($found === null || $found['account_last4'] === null) {
        throw new Exception('Statement opened but no closing balance / account number was found in it.');
    }
    $asOf = $found['as_of'] ?? date('Y-m-d', (int)floor(((int)$message->getInternalDate()) / 1000));
    return AccountBalance::updateBankAccount($db, $userId, $bank, $found['account_last4'], $found['balance'], $asOf) ? 1 : 0;
}

/**
 * Every AUTO_SYNC_EVERY_SECONDS, queue a recent-mail (3-day) sync for each user with Gmail connected, so alert
 * emails (missing UPI payments, balances) and new statements arrive without anyone tapping Sync.
 */
function enqueueAutoSyncJobs(Database $db): void
{
    try {
        $users = $db->fetchAll("SELECT id FROM users WHERE gmail_token IS NOT NULL AND gmail_token <> ''");
        foreach ($users as $user) {
            $userId = (int)$user['id'];
            if (!SubscriptionService::isPremium($userId)) {
                continue;
            }
            $busy = $db->fetchOne(
                "SELECT id FROM sync_jobs WHERE user_id = ? AND type = 'gmail'
                   AND (status IN ('pending', 'processing') OR created_at > ?)
                 LIMIT 1",
                [$userId, date('Y-m-d H:i:s', time() - AUTO_SYNC_EVERY_SECONDS)]
            );
            if ($busy) {
                continue; // a sync is queued/running, or one (manual or auto) ran recently
            }
            $db->insert(
                "INSERT INTO sync_jobs (user_id, type, status, progress, params, created_at) VALUES (?, 'gmail', 'pending', 0, ?, NOW())",
                [$userId, json_encode(['range' => '3d', 'auto' => true])]
            );
            echo "[gmail-worker] queued auto sync for user {$userId}\n";
        }
    } catch (Throwable $e) {
        error_log('[gmail-worker] auto-sync enqueue failed: ' . $e->getMessage());
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

    $from = strtolower(GmailFetcher::getHeader($message, 'From'));
    saveLongTermNps($db, $userId, $nps, str_contains($from, 'kfintech') ? 'KFintech CRA' : 'Protean CRA');
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

/** SBI consolidated account-statement (CAS) email → savings-account transactions + closing balance. */
function processSbiCasMessage(
    Database $db,
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
            foreach (($result['balances'] ?? []) as $last4 => $closing) {
                AccountBalance::updateBankAccount($db, $userId, 'sbi', (string)$last4, (float)$closing['balance'], (string)$closing['date']);
            }
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

/**
 * Decrypt the user's candidate + card-specific stored passwords to plaintext.
 *
 * Rows that no longer decrypt (typically because STATEMENT_PASSWORD_KEY -- or
 * the JWT_SECRET it falls back to -- changed after they were saved) are skipped
 * but counted in $undecryptable, so the caller can say so. Skipping them
 * silently made every statement they protected fail as a plain "wrong
 * password" for months.
 */
function gatherCandidatePasswords(Database $db, int $userId, int &$undecryptable = 0): array
{
    $out = [];
    $undecryptable = 0;
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
                $undecryptable++;
            }
        }
    }
    return array_values(array_unique($out));
}

function gmailAfterClause(string $range): string
{
    // '3d' is the automatic recent-mail sync (enqueueAutoSyncJobs); the app offers the month ranges.
    $map = ['3d' => '-3 days', '1m' => '-1 month', '2m' => '-2 months', '3m' => '-3 months', '6m' => '-6 months', '1y' => '-1 year'];
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
