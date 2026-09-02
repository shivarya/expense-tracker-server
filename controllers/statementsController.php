<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/fxConverter.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../utils/categoryResolver.php';
require_once __DIR__ . '/../utils/categoryLearning.php';
require_once __DIR__ . '/../utils/merchantSubscriptionDetector.php';
require_once __DIR__ . '/../utils/statementPasswordVault.php';

if (!class_exists('\Smalot\PdfParser\Parser')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class StatementController
{
    private Database $db;
    private TransactionDuplicateDetector $duplicateDetector;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->duplicateDetector = new TransactionDuplicateDetector($this->db->getConnection(), new AzureOpenAI());
    }

    public function savePassword(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();
        $bank = $this->normalizeBank($input['bank'] ?? '');
        $accountType = $this->normalizeAccountType($input['account_type'] ?? 'credit_card');
        $rawCardLastFour = (string)($input['card_last_four'] ?? '');
        $cardLastFour = $this->normalizeCardLastFour($rawCardLastFour);
        $password = (string)($input['password'] ?? '');

        if (!$this->isValidBankAccountTypeCombo($bank, $accountType)) {
            Response::error('Only SBI, ICICI, RBL credit card statements, or HDFC savings statements are currently supported.', 400);
        }

        if (trim($rawCardLastFour) !== '' && strlen($cardLastFour) !== 4) {
            Response::error('card_last_four must contain 4 digits when provided.', 400);
        }

        if (trim($password) === '') {
            Response::error('password is required.', 400);
        }

        $encrypted = StatementPasswordVault::encrypt($password);

        $cardLastFourValue = $cardLastFour !== '' ? $cardLastFour : null;

        if ($cardLastFourValue === null) {
            $existing = $this->db->fetchOne(
                "SELECT id FROM statement_passwords
                 WHERE user_id = ? AND bank = ? AND account_type = ? AND card_last_four IS NULL
                 ORDER BY id DESC
                 LIMIT 1",
                [$userId, $bank, $accountType]
            );

            if ($existing && isset($existing['id'])) {
                $this->db->execute(
                    "UPDATE statement_passwords
                     SET encrypted_password = ?, iv = ?, auth_tag = ?, encryption_version = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?",
                    [
                        $encrypted['encrypted_password'],
                        $encrypted['iv'],
                        $encrypted['auth_tag'],
                        $encrypted['encryption_version'],
                        (int)$existing['id'],
                    ]
                );
            } else {
                $this->db->insert(
                    "INSERT INTO statement_passwords
                     (user_id, bank, account_type, card_last_four, encrypted_password, iv, auth_tag, encryption_version)
                     VALUES (?, ?, ?, NULL, ?, ?, ?, ?)",
                    [
                        $userId,
                        $bank,
                        $accountType,
                        $encrypted['encrypted_password'],
                        $encrypted['iv'],
                        $encrypted['auth_tag'],
                        $encrypted['encryption_version'],
                    ]
                );
            }
        } else {
            $sql = "INSERT INTO statement_passwords
                    (user_id, bank, account_type, card_last_four, encrypted_password, iv, auth_tag, encryption_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        encrypted_password = VALUES(encrypted_password),
                        iv = VALUES(iv),
                        auth_tag = VALUES(auth_tag),
                        encryption_version = VALUES(encryption_version),
                        updated_at = CURRENT_TIMESTAMP";

            $this->db->execute($sql, [
                $userId,
                $bank,
                $accountType,
                $cardLastFourValue,
                $encrypted['encrypted_password'],
                $encrypted['iv'],
                $encrypted['auth_tag'],
                $encrypted['encryption_version'],
            ]);
        }

        Response::success([
            'bank' => $bank,
            'account_type' => $accountType,
            'card_last_four' => $cardLastFourValue,
            'stored' => true,
        ], 'Statement password saved securely.');
    }

    public function deletePassword(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();
        $bank = $this->normalizeBank($input['bank'] ?? '');
        $accountType = $this->normalizeAccountType($input['account_type'] ?? 'credit_card');
        $rawCardLastFour = (string)($input['card_last_four'] ?? '');
        $cardLastFour = $this->normalizeCardLastFour($rawCardLastFour);

        if ($bank === '') {
            Response::error('bank is required.', 400);
        }

        if (trim($rawCardLastFour) !== '' && strlen($cardLastFour) !== 4) {
            Response::error('card_last_four must contain 4 digits when provided.', 400);
        }

        if ($cardLastFour !== '') {
            $deleted = $this->db->execute(
                "DELETE FROM statement_passwords WHERE user_id = ? AND bank = ? AND account_type = ? AND card_last_four = ?",
                [$userId, $bank, $accountType, $cardLastFour]
            );
        } else {
            $deleted = $this->db->execute(
                "DELETE FROM statement_passwords WHERE user_id = ? AND bank = ? AND account_type = ?",
                [$userId, $bank, $accountType]
            );
        }

        Response::success([
            'deleted' => $deleted > 0,
        ], $deleted > 0 ? 'Statement password removed.' : 'No matching statement password found.');
    }

    /**
     * GET /statements/password-candidates
     * List the user's saved candidate passwords (never returns plaintext).
     */
    public function getPasswordCandidates(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $rows = $this->db->fetchAll(
            "SELECT id, label, created_at, updated_at
             FROM statement_password_candidates
             WHERE user_id = ?
             ORDER BY updated_at DESC, id DESC",
            [$userId]
        );

        Response::success([
            'candidates' => array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'label' => $row['label'] !== null ? (string)$row['label'] : null,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }, $rows),
            'count' => count($rows),
        ], 'Password candidates retrieved.');
    }

    /**
     * POST /statements/password-candidates
     * Body: { "passwords": [ "..", { "password": "..", "label": ".." } ] }
     *   or  { "password": "..", "label": ".." }
     * Adds each password to the user's pool (encrypted, de-duplicated).
     */
    public function savePasswordCandidates(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();

        // Accept a single password or an array of strings / {password,label} objects.
        $entries = [];
        if (isset($input['passwords']) && is_array($input['passwords'])) {
            $entries = $input['passwords'];
        } elseif (isset($input['password'])) {
            $entries = [['password' => $input['password'], 'label' => $input['label'] ?? null]];
        }

        if (empty($entries)) {
            Response::error('Provide "password" or a non-empty "passwords" array.', 400);
        }

        $added = 0;
        $skipped = 0;
        $invalid = 0;

        foreach ($entries as $entry) {
            $password = is_array($entry) ? (string)($entry['password'] ?? '') : (string)$entry;
            $label = is_array($entry) && isset($entry['label']) && trim((string)$entry['label']) !== ''
                ? mb_substr(trim((string)$entry['label']), 0, 100)
                : null;

            $password = trim($password);
            if ($password === '' || mb_strlen($password) > 256) {
                $invalid++;
                continue;
            }

            $hash = $this->candidatePasswordHash($password);
            $encrypted = StatementPasswordVault::encrypt($password);

            // Upsert: keep first occurrence; refresh label/updated_at on repeat.
            // MySQL rowCount(): 1 = inserted (new), 2 = updated (duplicate).
            $affected = $this->db->execute(
                "INSERT INTO statement_password_candidates
                    (user_id, label, password_hash, encrypted_password, iv, auth_tag, encryption_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    label = COALESCE(VALUES(label), label),
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $userId,
                    $label,
                    $hash,
                    $encrypted['encrypted_password'],
                    $encrypted['iv'],
                    $encrypted['auth_tag'],
                    $encrypted['encryption_version'],
                ]
            );

            if ((int)$affected >= 2) {
                $skipped++;
            } else {
                $added++;
            }
        }

        Response::success([
            'added' => $added,
            'skipped_duplicates' => $skipped,
            'invalid' => $invalid,
        ], 'Password candidates saved.');
    }

    /**
     * DELETE /statements/password-candidates
     * Body: { "id": 123 } to delete one, or { "all": true } to clear the pool.
     */
    public function deletePasswordCandidate(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();

        if (!empty($input['all'])) {
            $deleted = $this->db->execute(
                "DELETE FROM statement_password_candidates WHERE user_id = ?",
                [$userId]
            );
            Response::success(['deleted' => (int)$deleted], 'All password candidates removed.');
        }

        $id = isset($input['id']) ? (int)$input['id'] : 0;
        if ($id <= 0) {
            Response::error('Provide "id" of the candidate to delete, or "all": true.', 400);
        }

        $deleted = $this->db->execute(
            "DELETE FROM statement_password_candidates WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );

        Response::success([
            'deleted' => $deleted > 0,
        ], $deleted > 0 ? 'Password candidate removed.' : 'No matching candidate found.');
    }

    /**
     * POST /statements/password-candidates/reveal
     * Body: { "id": 123 }
     * Returns the decrypted plaintext for ONE of the authenticated user's own
     * candidates, so they can confirm what's stored. Owner-scoped (id + user_id).
     */
    public function revealPasswordCandidate(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        $input = getJsonInput();
        $id = isset($input['id']) ? (int)$input['id'] : 0;
        if ($id <= 0) {
            Response::error('Provide "id" of the candidate to reveal.', 400);
        }

        $row = $this->db->fetchOne(
            "SELECT encrypted_password, iv, auth_tag
             FROM statement_password_candidates
             WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        if (!$row) {
            Response::error('Password candidate not found.', 404);
        }

        try {
            $plain = StatementPasswordVault::decrypt($row['encrypted_password'], $row['iv'], $row['auth_tag']);
        } catch (Throwable $e) {
            Response::error('Could not decrypt this password.', 500);
        }

        Response::success(['id' => $id, 'password' => $plain]);
    }

    /** HMAC of a candidate password — used only to de-duplicate, never to decrypt. */
    private function candidatePasswordHash(string $password): string
    {
        $key = defined('STATEMENT_PASSWORD_KEY') ? (string)STATEMENT_PASSWORD_KEY : '';
        return hash_hmac('sha256', $password, $key);
    }

    /**
     * Return the user's candidate passwords as decryptable rows shaped like
     * statement_passwords rows, so they can be appended to the decryption loop.
     */
    private function loadCandidatePasswordRows(int $userId): array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT encrypted_password, iv, auth_tag
                 FROM statement_password_candidates
                 WHERE user_id = ?
                 ORDER BY updated_at DESC, id DESC",
                [$userId]
            );
        } catch (Exception $e) {
            // Tolerate the table not existing yet (migration 015 not applied) so
            // statement uploads keep working regardless of deploy order.
            error_log('loadCandidatePasswordRows skipped: ' . $e->getMessage());
            return [];
        }

        return array_map(static function (array $row): array {
            return [
                'card_last_four' => null,
                'encrypted_password' => (string)$row['encrypted_password'],
                'iv' => (string)$row['iv'],
                'auth_tag' => (string)$row['auth_tag'],
            ];
        }, $rows);
    }

    public function uploadStatement(): void
    {
        $tokenData = JWTHandler::requireAuth();
        $userId = (int)$tokenData['userId'];

        if (!isset($_FILES['statement_pdf'])) {
            Response::error('Missing statement_pdf file field.', 400);
        }

        $bank = $this->normalizeBank($_POST['bank'] ?? '');
        $accountType = $this->normalizeAccountType($_POST['account_type'] ?? 'credit_card');
        $rawCardLastFour = (string)($_POST['card_last_four'] ?? '');
        $cardLastFour = $this->normalizeCardLastFour($rawCardLastFour);

        if (!$this->isValidBankAccountTypeCombo($bank, $accountType)) {
            Response::error('Only SBI, ICICI, RBL credit card statements, or HDFC savings statements are currently supported.', 400);
        }

        $isHdfcSavings = ($bank === 'hdfc' && $accountType === 'savings');

        if (trim($rawCardLastFour) !== '' && strlen($cardLastFour) !== 4) {
            Response::error('card_last_four must contain 4 digits when provided.', 400);
        }

        $file = $_FILES['statement_pdf'];
        $this->validateUploadedPdf($file);

        if ($cardLastFour !== '') {
            // When a card is provided, try both card-specific and generic (NULL) passwords.
            $passwordRows = $this->db->fetchAll(
                "SELECT card_last_four, encrypted_password, iv, auth_tag
                 FROM statement_passwords
                 WHERE user_id = ? AND bank = ? AND account_type = ?
                   AND (card_last_four = ? OR card_last_four IS NULL)
                 ORDER BY (card_last_four = ?) DESC, updated_at DESC, id DESC",
                [$userId, $bank, $accountType, $cardLastFour, $cardLastFour]
            );
        } else {
            $passwordRows = $this->db->fetchAll(
                "SELECT card_last_four, encrypted_password, iv, auth_tag
                 FROM statement_passwords
                 WHERE user_id = ? AND bank = ? AND account_type = ?
                 ORDER BY updated_at DESC, id DESC",
                [$userId, $bank, $accountType]
            );
        }

        // Also try the user's generic candidate-password pool (after any
        // bank/card-specific passwords) so a shared list of common passwords
        // (DOB, PAN, etc.) can decrypt statements without per-card setup.
        $passwordRows = array_merge($passwordRows, $this->loadCandidatePasswordRows($userId));

        if (empty($passwordRows)) {
            $passwordRows = [[
                'card_last_four' => $cardLastFour !== '' ? $cardLastFour : null,
                'plain_password' => '',
            ]];
        }

        $fileHash = hash_file('sha256', $file['tmp_name']);
        $fileName = basename((string)$file['name']);
        $cardLastFourFilter = $cardLastFour !== '' ? $cardLastFour : null;

        $existingUpload = $this->db->fetchOne(
            "SELECT id, card_last_four, extracted_count, saved_count, skipped_high_confidence, flagged_possible_duplicates,
                    ai_checked_transactions, duplicate_fallback_used
             FROM statement_uploads
             WHERE user_id = ? AND bank = ? AND account_type = ?
               AND (? IS NULL OR card_last_four <=> ?)
               AND file_hash = ? AND status IN ('success', 'duplicate_upload')
             ORDER BY id DESC
             LIMIT 1",
            [$userId, $bank, $accountType, $cardLastFourFilter, $cardLastFourFilter, $fileHash]
        );

        if ($existingUpload) {
            $duplicateUploadId = $this->db->insert(
                "INSERT INTO statement_uploads
                 (user_id, bank, account_type, card_last_four, file_name, file_hash, status,
                  extracted_count, saved_count, skipped_high_confidence, flagged_possible_duplicates,
                  ai_checked_transactions, duplicate_fallback_used)
                 VALUES (?, ?, ?, ?, ?, ?, 'duplicate_upload', ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $bank,
                    $accountType,
                    $existingUpload['card_last_four'] ?? $cardLastFourFilter,
                    $fileName,
                    $fileHash,
                    (int)$existingUpload['extracted_count'],
                    (int)$existingUpload['saved_count'],
                    (int)$existingUpload['skipped_high_confidence'],
                    (int)$existingUpload['flagged_possible_duplicates'],
                    (int)$existingUpload['ai_checked_transactions'],
                    (int)$existingUpload['duplicate_fallback_used'],
                ]
            );

            Response::success([
                'upload_id' => (int)$duplicateUploadId,
                'duplicate_upload' => true,
                'extracted_transactions' => (int)$existingUpload['extracted_count'],
                'saved_transactions' => (int)$existingUpload['saved_count'],
                'skipped_high_confidence' => (int)$existingUpload['skipped_high_confidence'],
                'flagged_possible_duplicates' => (int)$existingUpload['flagged_possible_duplicates'],
                'ai_checked_transactions' => (int)$existingUpload['ai_checked_transactions'],
                'duplicate_fallback_used' => (int)$existingUpload['duplicate_fallback_used'],
            ], 'This statement file was already processed earlier.');
        }

        $uploadId = (int)$this->db->insert(
            "INSERT INTO statement_uploads
             (user_id, bank, account_type, card_last_four, file_name, file_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, 'processing')",
            [$userId, $bank, $accountType, $cardLastFourFilter, $fileName, $fileHash]
        );

        $workingFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statement_' . uniqid() . '.pdf';
        $cleanupFiles = [$workingFile];

        try {
            if (!move_uploaded_file($file['tmp_name'], $workingFile)) {
                throw new Exception('Failed to persist uploaded statement file.');
            }

            $text = '';
            $parsedResult = ['transactions' => [], 'parser' => ''];
            $resolvedCardLastFour = $cardLastFour;
            $lastPasswordError = '';
            $attemptedPasswordCandidates = [];
            $lastParserUsed = '';
            $lastExtractedPreview = '';

            foreach ($passwordRows as $passwordRow) {
                try {
                    $candidateCardLastFour = $this->normalizeCardLastFour((string)($passwordRow['card_last_four'] ?? ''));
                    $attemptedPasswordCandidates[] = $candidateCardLastFour !== '' ? $candidateCardLastFour : 'generic';

                    $password = '';
                    if (array_key_exists('plain_password', $passwordRow)) {
                        $password = (string)$passwordRow['plain_password'];
                    } else {
                        $password = StatementPasswordVault::decrypt(
                            (string)$passwordRow['encrypted_password'],
                            (string)$passwordRow['iv'],
                            (string)$passwordRow['auth_tag']
                        );
                    }

                    $candidateCardContext = $cardLastFour !== '' ? $cardLastFour : $candidateCardLastFour;

                    $text = $this->extractPdfText($workingFile, $password, $cleanupFiles);
                    $parsedResult = $isHdfcSavings
                        ? array_merge($this->parseHdfcSavingsStatement($text), ['parser' => 'hdfc_savings_v1'])
                        : $this->parseTransactionsByBank($bank, $text, $candidateCardContext);
                    $lastParserUsed = (string)($parsedResult['parser'] ?? '');
                    $lastExtractedPreview = $this->buildTextPreview($text);
                    $resolvedCardLastFour = $candidateCardContext;
                    $lastPasswordError = '';
                    break;
                } catch (Exception $passwordError) {
                    $lastPasswordError = $passwordError->getMessage();
                }
            }

            if (empty($parsedResult['transactions'])) {
                throw new Exception(
                    'Could not parse statement using available password(s). Save the correct statement password and retry.'
                        . (!empty($attemptedPasswordCandidates)
                            ? ' Tried: ' . implode(', ', array_unique($attemptedPasswordCandidates)) . '.'
                            : '')
                        . ($lastParserUsed !== '' ? ' Parser: ' . $lastParserUsed . '.' : '')
                        . ($lastExtractedPreview !== '' ? ' Text preview: ' . $lastExtractedPreview . '.' : '')
                        . ($lastPasswordError !== '' ? ' Last error: ' . $lastPasswordError : '')
                );
            }

            $effectiveCardLastFour = $resolvedCardLastFour !== '' ? $resolvedCardLastFour : '0000';

            if (!$isHdfcSavings && $effectiveCardLastFour !== ($cardLastFourFilter ?? '')) {
                $this->db->execute(
                    "UPDATE statement_uploads SET card_last_four = ? WHERE id = ?",
                    [$effectiveCardLastFour, $uploadId]
                );
            }

            $parsedTransactions = $parsedResult['transactions'];
            $parserName = $parsedResult['parser'];

            if (empty($parsedTransactions)) {
                throw new Exception('No transactions detected in the uploaded ' . strtoupper($bank) . ' statement.');
            }

            if ($isHdfcSavings) {
                $accountLastFour = (string)($parsedResult['account_last_four'] ?? '');
                $accountLastFour = $accountLastFour !== '' ? $accountLastFour : '0000';
                $accountId = $this->getOrCreateSavingsAccount($userId, $bank, $accountLastFour);
                $stats = $this->persistParsedSavingsTransactions(
                    $userId,
                    $bank,
                    $accountId,
                    $accountLastFour,
                    $parsedTransactions,
                    $parserName,
                    $uploadId,
                    $fileHash
                );
            } else {
                $cardType = $this->extractCardTypeForBank($bank, (string)$text);
                $accountId = $this->getOrCreateCreditCardAccount($userId, $bank, $effectiveCardLastFour, $cardType !== '' ? $cardType : null);
                $stats = $this->persistParsedCardTransactions(
                    $userId,
                    $bank,
                    $effectiveCardLastFour,
                    $accountId,
                    $parsedTransactions,
                    $parserName,
                    $uploadId,
                    $fileHash
                );
            }

            $savedCount = $stats['saved'];
            $skippedHighConfidence = $stats['skipped_high_confidence'];
            $flaggedPossibleDuplicates = $stats['flagged'];
            $aiChecked = $stats['ai_checked'];
            $fallbackUsed = $stats['fallback_used'];
            $savedDebitCount = $stats['debit_count'];
            $savedCreditCount = $stats['credit_count'];
            $savedDebitAmount = $stats['debit_amount'];
            $savedCreditAmount = $stats['credit_amount'];
            $savedDateMin = $stats['date_min'];
            $savedDateMax = $stats['date_max'];
            $errors = $stats['errors'];

            $this->db->execute(
                "UPDATE statement_uploads
                 SET status = 'success', extracted_count = ?, saved_count = ?, skipped_high_confidence = ?,
                     flagged_possible_duplicates = ?, ai_checked_transactions = ?, duplicate_fallback_used = ?,
                     error_message = ?
                 WHERE id = ?",
                [
                    count($parsedTransactions),
                    $savedCount,
                    $skippedHighConfidence,
                    $flaggedPossibleDuplicates,
                    $aiChecked,
                    $fallbackUsed,
                    !empty($errors) ? implode(' | ', array_slice($errors, 0, 5)) : null,
                    $uploadId,
                ]
            );

            Response::success([
                'upload_id' => $uploadId,
                'duplicate_upload' => false,
                'extracted_transactions' => count($parsedTransactions),
                'saved_transactions' => $savedCount,
                'skipped_high_confidence' => $skippedHighConfidence,
                'flagged_possible_duplicates' => $flaggedPossibleDuplicates,
                'ai_checked_transactions' => $aiChecked,
                'duplicate_fallback_used' => $fallbackUsed,
                'saved_debit_count' => $savedDebitCount,
                'saved_credit_count' => $savedCreditCount,
                'saved_debit_amount' => round($savedDebitAmount, 2),
                'saved_credit_amount' => round($savedCreditAmount, 2),
                'saved_date_min' => $savedDateMin,
                'saved_date_max' => $savedDateMax,
                'errors' => $errors,
            ], 'Statement parsed and synced successfully.');
        } catch (Exception $e) {
            $this->db->execute(
                "UPDATE statement_uploads
                 SET status = 'failed', error_message = ?
                 WHERE id = ?",
                [substr($e->getMessage(), 0, 1000), $uploadId]
            );

            Response::error('Statement upload failed: ' . $e->getMessage(), 500);
        } finally {
            foreach ($cleanupFiles as $cleanupPath) {
                if (is_string($cleanupPath) && file_exists($cleanupPath)) {
                    @unlink($cleanupPath);
                }
            }
        }
    }

    /**
     * Public wrapper so server-side workers (e.g. the Gmail fetch worker) can
     * reuse the exact multi-tool PDF decryption/extraction used for uploads
     * (qpdf -> pdftotext -> mutool -> python/pypdf). Returns extracted text, or
     * throws if the password/tools could not open the PDF.
     */
    public function extractTextFromPdf(string $inputPath, string $password): string
    {
        $cleanupFiles = [];
        try {
            return $this->extractPdfText($inputPath, $password, $cleanupFiles);
        } finally {
            foreach ($cleanupFiles as $cleanupPath) {
                if (is_string($cleanupPath) && file_exists($cleanupPath)) {
                    @unlink($cleanupPath);
                }
            }
        }
    }

    /**
     * AI-refine, rules-categorize, dedupe and insert parsed credit-card
     * transactions. Shared by uploadStatement() and the Gmail worker
     * (ingestCreditCardPdf). Returns counts; does not touch statement_uploads.
     */
    private function persistParsedCardTransactions(
        int $userId,
        string $bank,
        string $effectiveCardLastFour,
        int $accountId,
        array $parsedTransactions,
        string $parserName,
        int $uploadId,
        string $fileHash
    ): array {
        // Best-effort AI cleanup for merchant/description/category before dedupe + insert.
        // Must still succeed if AI is unavailable or returns partial output.
        $statementAi = new AzureOpenAI();
        $aiRefinements = $statementAi->refineStatementTransactions($parsedTransactions, strtoupper($bank));
        if (!empty($aiRefinements)) {
            foreach ($parsedTransactions as $idx => $parsedTxn) {
                if (!isset($aiRefinements[$idx]) || !is_array($aiRefinements[$idx])) {
                    continue;
                }
                $refined = $aiRefinements[$idx];
                if (!empty($refined['merchant'])) {
                    $parsedTransactions[$idx]['merchant'] = (string)$refined['merchant'];
                }
                if (!empty($refined['description'])) {
                    $parsedTransactions[$idx]['description'] = (string)$refined['description'];
                }
                if (!empty($refined['category_id'])) {
                    $parsedTransactions[$idx]['category_id'] = (int)$refined['category_id'];
                }
            }
        }

        $paymentMethod = strtoupper($bank) . ' Card *' . $effectiveCardLastFour;

        $savedCount = 0;
        $skippedHighConfidence = 0;
        $flaggedPossibleDuplicates = 0;
        $aiChecked = 0;
        $fallbackUsed = 0;
        $savedDebitCount = 0;
        $savedCreditCount = 0;
        $savedDebitAmount = 0.0;
        $savedCreditAmount = 0.0;
        $savedDateMin = null;
        $savedDateMax = null;
        $errors = [];

        foreach ($parsedTransactions as $txn) {
            try {
                $normalizedType = $this->normalizeStatementTransactionType(
                    (string)($txn['transaction_type'] ?? ''),
                    (string)($txn['description'] ?? '')
                );
                $normalizedDescription = $this->normalizeStatementDescription(
                    (string)($txn['description'] ?? ''),
                    (string)($txn['merchant'] ?? ''),
                    strtoupper($bank)
                );
                $normalizedMerchant = $this->normalizeStatementMerchant(
                    (string)($txn['merchant'] ?? ''),
                    $normalizedDescription,
                    strtoupper($bank) . ' Card Transaction'
                );

                // Rules-first: a user's learned merchant→category correction
                // beats the AI guess (mirrors the SMS parsing path).
                $learnedCategoryId = CategoryLearning::resolveFromTransaction($this->db, $userId, [
                    'merchant' => $normalizedMerchant,
                    'description' => $normalizedDescription,
                ]);
                $resolvedCategoryId = $learnedCategoryId !== null
                    ? $learnedCategoryId
                    : (isset($txn['category_id']) ? (int)$txn['category_id'] : null);

                $transactionPayload = [
                    'bank' => strtoupper($bank),
                    'account_number' => $effectiveCardLastFour,
                    'transaction_type' => $normalizedType,
                    'amount' => $txn['amount'],
                    'merchant' => $normalizedMerchant,
                    'description' => $normalizedDescription,
                    'category_id' => $resolvedCategoryId,
                    'date' => $txn['transaction_date'],
                    'reference_number' => $txn['reference_number'],
                    'payment_method' => $paymentMethod,
                ];

                $duplicateCheck = $this->duplicateDetector->evaluate($userId, $transactionPayload, [
                    'account_id' => $accountId,
                    'ai_enabled' => true,
                    'skip_threshold' => 76,
                    'duplicate_threshold' => 51,
                    'source_hint' => 'statement_pdf',
                ]);

                if (!empty($duplicateCheck['ai_used'])) {
                    $aiChecked++;
                }
                if (!empty($duplicateCheck['fallback_used'])) {
                    $fallbackUsed++;
                }
                if (!empty($duplicateCheck['should_skip'])) {
                    $skippedHighConfidence++;
                    continue;
                }
                if (!empty($duplicateCheck['possible_duplicate'])) {
                    $flaggedPossibleDuplicates++;
                }

                $categoryId = CategoryResolver::resolveTransaction($transactionPayload);
                $sourceData = [
                    'source' => 'statement_pdf',
                    'parser' => $parserName,
                    'bank' => $bank,
                    'card_last_four' => $effectiveCardLastFour,
                    'upload_id' => $uploadId,
                    'file_hash' => $fileHash,
                    'raw_line' => $txn['raw_line'],
                ];

                // Card statements bill in INR (amount already converted). If the
                // raw line carries the original foreign figure, preserve it.
                $foreign = fxExtractForeign((string)($txn['raw_line'] ?? '') . ' ' . (string)($txn['description'] ?? ''));
                $origAmount = $foreign['amount'] ?? null;
                $origCurrency = $foreign['currency'] ?? null;

                $newTxnId = $this->db->insert(
                    "INSERT INTO transactions
                     (user_id, account_id, category_id, transaction_type, amount, currency, original_amount, original_currency,
                      merchant, description, transaction_date, reference_number, source, payment_method, source_data, duplicate_score)
                     VALUES (?, ?, ?, ?, ?, 'INR', ?, ?, ?, ?, ?, ?, 'statement_pdf', ?, ?, ?)",
                    [
                        $userId,
                        $accountId,
                        $categoryId,
                        $normalizedType,
                        $txn['amount'],
                        $origAmount,
                        $origCurrency,
                        $normalizedMerchant,
                        $normalizedDescription,
                        $txn['transaction_date'],
                        $txn['reference_number'],
                        $paymentMethod,
                        json_encode($sourceData),
                        (int)($duplicateCheck['confidence'] ?? 0),
                    ]
                );

                MerchantSubscriptionDetector::evaluateTransaction($this->db, $userId, (int)$newTxnId);

                $savedCount++;

                $txnAmount = (float)$txn['amount'];
                if ($normalizedType === 'credit') {
                    $savedCreditCount++;
                    $savedCreditAmount += $txnAmount;
                } else {
                    $savedDebitCount++;
                    $savedDebitAmount += $txnAmount;
                }

                $txnDate = (string)($txn['transaction_date'] ?? '');
                if ($txnDate !== '') {
                    if ($savedDateMin === null || strtotime($txnDate) < strtotime($savedDateMin)) {
                        $savedDateMin = $txnDate;
                    }
                    if ($savedDateMax === null || strtotime($txnDate) > strtotime($savedDateMax)) {
                        $savedDateMax = $txnDate;
                    }
                }
            } catch (Exception $recordError) {
                $errors[] = $recordError->getMessage();
            }
        }

        return [
            'saved' => $savedCount,
            'skipped_high_confidence' => $skippedHighConfidence,
            'flagged' => $flaggedPossibleDuplicates,
            'ai_checked' => $aiChecked,
            'fallback_used' => $fallbackUsed,
            'debit_count' => $savedDebitCount,
            'credit_count' => $savedCreditCount,
            'debit_amount' => $savedDebitAmount,
            'credit_amount' => $savedCreditAmount,
            'date_min' => $savedDateMin,
            'date_max' => $savedDateMax,
            'errors' => $errors,
        ];
    }

    /**
     * Worker-facing: ingest a credit-card statement PDF already on disk (e.g.
     * downloaded from Gmail), trying the given plaintext passwords. Reuses the
     * same parse/normalize/dedupe/insert path as uploads. Returns a summary;
     * throws on hard failure.
     */
    public function ingestCreditCardPdf(
        int $userId,
        string $bank,
        string $cardLastFour,
        string $workingFile,
        string $fileName,
        array $passwordPlaintexts = []
    ): array {
        $bank = $this->normalizeBank($bank);
        if (!$this->isSupportedBank($bank)) {
            throw new Exception("Unsupported bank for statement parsing: {$bank}");
        }

        $cardLastFour = $this->normalizeCardLastFour($cardLastFour);
        $cardFilter = $cardLastFour !== '' ? $cardLastFour : null;
        $fileHash = hash_file('sha256', $workingFile);

        // Skip if this exact statement file was already processed.
        $existing = $this->db->fetchOne(
            "SELECT id FROM statement_uploads
             WHERE user_id = ? AND bank = ? AND account_type = 'credit_card'
               AND file_hash = ? AND status IN ('success', 'duplicate_upload')
             ORDER BY id DESC LIMIT 1",
            [$userId, $bank, $fileHash]
        );
        if ($existing) {
            return ['duplicate_upload' => true, 'extracted_transactions' => 0, 'saved_transactions' => 0];
        }

        $uploadId = (int)$this->db->insert(
            "INSERT INTO statement_uploads (user_id, bank, account_type, card_last_four, file_name, file_hash, status)
             VALUES (?, ?, 'credit_card', ?, ?, ?, 'processing')",
            [$userId, $bank, $cardFilter, $fileName, $fileHash]
        );

        try {
            $parsedResult = ['transactions' => [], 'parser' => ''];
            foreach (array_merge([''], $passwordPlaintexts) as $pwd) {
                try {
                    $text = $this->extractTextFromPdf($workingFile, (string)$pwd);
                    $candidate = $this->parseTransactionsByBank($bank, $text, $cardLastFour);
                    if (!empty($candidate['transactions'])) {
                        $parsedResult = $candidate;
                        break;
                    }
                } catch (Exception $e) {
                    // try next password
                }
            }

            $parsedTransactions = $parsedResult['transactions'];
            if (empty($parsedTransactions)) {
                throw new Exception('Could not parse statement with available passwords.');
            }

            // Statement PDFs have no card number passed in; detect the masked last
            // digits from the decrypted text so transactions file under the real card
            // (and dedupe against SMS) instead of a synthetic XXXX0000 account.
            $detectedLast4 = ($cardLastFour === '' && isset($text)) ? $this->extractCardLast4ForBank($bank, (string)$text) : '';
            $effectiveCardLastFour = $cardLastFour !== '' ? $cardLastFour : ($detectedLast4 !== '' ? $detectedLast4 : '0000');
            $cardType = isset($text) ? $this->extractCardTypeForBank($bank, (string)$text) : '';
            $accountId = $this->getOrCreateCreditCardAccount($userId, $bank, $effectiveCardLastFour, $cardType !== '' ? $cardType : null);
            $stats = $this->persistParsedCardTransactions(
                $userId,
                $bank,
                $effectiveCardLastFour,
                $accountId,
                $parsedTransactions,
                (string)($parsedResult['parser'] ?? ''),
                $uploadId,
                $fileHash
            );

            $this->db->execute(
                "UPDATE statement_uploads
                 SET status = 'success', extracted_count = ?, saved_count = ?, skipped_high_confidence = ?,
                     flagged_possible_duplicates = ?, ai_checked_transactions = ?, duplicate_fallback_used = ?,
                     error_message = ?
                 WHERE id = ?",
                [
                    count($parsedTransactions),
                    $stats['saved'],
                    $stats['skipped_high_confidence'],
                    $stats['flagged'],
                    $stats['ai_checked'],
                    $stats['fallback_used'],
                    !empty($stats['errors']) ? implode(' | ', array_slice($stats['errors'], 0, 5)) : null,
                    $uploadId,
                ]
            );

            return [
                'duplicate_upload' => false,
                'extracted_transactions' => count($parsedTransactions),
                'saved_transactions' => $stats['saved'],
            ];
        } catch (Exception $e) {
            $this->db->execute(
                "UPDATE statement_uploads SET status = 'failed', error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 1000), $uploadId]
            );
            throw $e;
        }
    }

    /**
     * Worker-facing: ingest an SBI consolidated account-statement (CAS) PDF
     * already on disk (e.g. downloaded from Gmail). Same duplicate-file-skip /
     * dedupe / insert pattern as ingestCreditCardPdf, but for the CAS
     * savings-account layout, which is structurally unrelated to a credit-card
     * statement (multi-page: account summary, then a "TRANSACTION DETAILS"
     * block per linked account with a Date/Description/Credit/Debit/Balance
     * table) so it gets its own parser rather than reusing parseTransactionsByBank.
     */
    public function ingestSbiCasStatement(
        int $userId,
        string $workingFile,
        string $fileName,
        array $passwordPlaintexts = []
    ): array {
        $fileHash = hash_file('sha256', $workingFile);

        $existing = $this->db->fetchOne(
            "SELECT id FROM statement_uploads
             WHERE user_id = ? AND bank = 'sbi' AND account_type = 'savings'
               AND file_hash = ? AND status IN ('success', 'duplicate_upload')
             ORDER BY id DESC LIMIT 1",
            [$userId, $fileHash]
        );
        if ($existing) {
            return ['duplicate_upload' => true, 'extracted_transactions' => 0, 'saved_transactions' => 0];
        }

        $uploadId = (int)$this->db->insert(
            "INSERT INTO statement_uploads (user_id, bank, account_type, file_name, file_hash, status)
             VALUES (?, 'sbi', 'savings', ?, ?, 'processing')",
            [$userId, $fileName, $fileHash]
        );

        try {
            $text = '';
            foreach (array_merge([''], $passwordPlaintexts) as $pwd) {
                try {
                    $candidate = $this->extractTextFromPdf($workingFile, (string)$pwd);
                    if (trim($candidate) !== '') {
                        $text = $candidate;
                        break;
                    }
                } catch (Exception $e) {
                    // try next password
                }
            }

            if (trim($text) === '') {
                throw new Exception('Could not decrypt/parse SBI e-statement with available passwords.');
            }

            // Two distinct e-statement layouts share this same sender pool:
            // the netbanking-requested one has a "TRANSACTION DETAILS" block
            // per account with one line per transaction; the YONO-app one is
            // titled "STATEMENT OF ACCOUNT" with no such marker and spreads
            // each transaction across several lines instead (see
            // parseSbiYonoStatement's docblock for the exact layout).
            $parsed = str_contains($text, 'TRANSACTION DETAILS')
                ? $this->parseSbiCasStatement($text)
                : $this->parseSbiYonoStatement($text);
            $transactions = $parsed['transactions'];
            if (empty($transactions)) {
                throw new Exception('No transactions found in SBI e-statement.');
            }

            $accountLastFour = $parsed['account_last_four'] !== '' ? $parsed['account_last_four'] : '0000';
            $accountId = $this->getOrCreateSavingsAccount($userId, 'sbi', $accountLastFour);
            $stats = $this->persistParsedSavingsTransactions($userId, 'sbi', $accountId, $accountLastFour, $transactions, 'sbi_cas', $uploadId, $fileHash);

            $this->db->execute(
                "UPDATE statement_uploads
                 SET status = 'success', extracted_count = ?, saved_count = ?, skipped_high_confidence = ?,
                     flagged_possible_duplicates = ?, ai_checked_transactions = ?, duplicate_fallback_used = ?,
                     error_message = ?
                 WHERE id = ?",
                [
                    count($transactions),
                    $stats['saved'],
                    $stats['skipped_high_confidence'],
                    $stats['flagged'],
                    $stats['ai_checked'],
                    $stats['fallback_used'],
                    !empty($stats['errors']) ? implode(' | ', array_slice($stats['errors'], 0, 5)) : null,
                    $uploadId,
                ]
            );

            return [
                'duplicate_upload' => false,
                'extracted_transactions' => count($transactions),
                'saved_transactions' => $stats['saved'],
            ];
        } catch (Exception $e) {
            $this->db->execute(
                "UPDATE statement_uploads SET status = 'failed', error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 1000), $uploadId]
            );
            throw $e;
        }
    }

    /**
     * Parse an SBI CAS statement's plain text into transaction rows. The
     * statement lists one "TRANSACTION DETAILS" block per linked account
     * (savings/current), each carrying its own masked account number, so
     * blocks are parsed independently and transactions never bleed across
     * accounts. Transaction lines look like:
     *   "01-07-26 UPI/DR/345394731537/ASHISH T/HDFC/shivarya3@/Payme - 0 50000.00 111933.38"
     *   DD-MM-YY <description> - <credit> <debit> <balance-after>
     * Every line's balance-after is contiguous with the next line's, which is
     * a strong internal check that the regex isn't silently mis-parsing.
     */
    private function parseSbiCasStatement(string $text): array
    {
        $blocks = preg_split('/(?=TRANSACTION DETAILS)/', $text);
        $transactions = [];
        $accountLastFour = '';
        $lineRe = '/^(\d{2}-\d{2}-\d{2})\s+(.+?)\s+-\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)\s+([\d,]+(?:\.\d{2})?)$/';

        foreach ($blocks as $block) {
            if (strpos($block, 'TRANSACTION DETAILS') === false) {
                continue; // preamble before the first account block
            }
            if (!preg_match('/X{4,}(\d{4})/', $block, $accMatch)) {
                continue;
            }
            $blockLastFour = $accMatch[1];
            if ($accountLastFour === '') {
                $accountLastFour = $blockLastFour;
            }

            foreach (explode("\n", $block) as $line) {
                $line = trim($line);
                if ($line === '' || !preg_match($lineRe, $line, $m)) {
                    continue;
                }
                [, $date, $desc, $creditStr, $debitStr] = $m;
                $credit = (float)str_replace(',', '', $creditStr);
                $debit = (float)str_replace(',', '', $debitStr);
                $isCredit = $credit > 0;
                $amount = $isCredit ? $credit : $debit;
                if ($amount <= 0) {
                    continue;
                }

                $desc = trim($desc);
                $transactions[] = [
                    'transaction_type' => $isCredit ? 'credit' : 'debit',
                    'amount' => $amount,
                    'merchant' => $this->cleanSbiCasMerchant($desc),
                    'description' => $desc,
                    'transaction_date' => $this->toIsoDateDdMmYy($date),
                    'reference_number' => $this->extractSbiCasReference($desc),
                    'raw_line' => $line,
                ];
            }
        }

        return ['account_last_four' => $accountLastFour, 'transactions' => $transactions];
    }

    /**
     * Parse the YONO-app-requested SBI e-statement -- a structurally
     * different layout from parseSbiCasStatement() above: titled "STATEMENT
     * OF ACCOUNT" (no "TRANSACTION DETAILS" marker), account number shown in
     * full rather than masked, and each transaction spread across several
     * lines instead of one:
     *   "02/08/2026 02/08/2026"           transaction date, value date (DD/MM/YYYY)
     *   "WDL TFR"                          transaction mode/type code
     *   "UPI/DR/774803736478/ASHISH"       description, wraps across an
     *   "T/HDFC/shivarya3@/Paym"             arbitrary number of lines
     *   "0097695162091 AT 00219 SME"
     *   "BRANCH, INDUSTRIAL ESTATE,"
     *   "KANPUR"
     *   "- 25,000.00 - 1,45,375.50"        Cheque/RefNo, Debit, Credit, Balance
     * A credit line has the debit/credit slots swapped:
     * "- - 1,000.00 96,160.68". A stray page-break pair (a "<N>Page no."
     * line followed by a lone "Balance" header line) can land mid-
     * description on a page boundary and is filtered out rather than folded
     * into the description text.
     */
    private function parseSbiYonoStatement(string $text): array
    {
        $accountLastFour = $this->extractYonoAccountLastFour($text);

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $dateRe = '/^(\d{2}\/\d{2}\/\d{4})\s+\d{2}\/\d{2}\/\d{4}$/';
        $amountsRe = '/^(-|[\d,]+\.\d{2})\s+(-|[\d,]+\.\d{2})\s+(-|[\d,]+\.\d{2})\s+([\d,]+\.\d{2})$/';

        $transactions = [];
        $currentDate = '';
        $descriptionParts = [];

        foreach ($lines as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\d*Page no\.?$/i', $line) || strcasecmp($line, 'Balance') === 0) {
                continue; // page-break furniture, not description text
            }

            if (preg_match($dateRe, $line, $dm)) {
                $currentDate = $dm[1];
                $descriptionParts = [];
                continue;
            }

            if ($currentDate === '') {
                continue; // still in the header, before the first transaction
            }

            if (preg_match($amountsRe, $line, $am)) {
                [, , $debitStr, $creditStr] = $am;
                $debit = $debitStr === '-' ? 0.0 : (float)str_replace(',', '', $debitStr);
                $credit = $creditStr === '-' ? 0.0 : (float)str_replace(',', '', $creditStr);
                $isCredit = $credit > 0;
                $amount = $isCredit ? $credit : $debit;

                if ($amount > 0) {
                    $desc = $this->stripYonoTransactionModePrefix(trim(implode(' ', $descriptionParts)));
                    $transactions[] = [
                        'transaction_type' => $isCredit ? 'credit' : 'debit',
                        'amount' => $amount,
                        'merchant' => $this->cleanSbiCasMerchant($desc),
                        'description' => $desc,
                        'transaction_date' => $this->toIsoDateDdMmYyyy($currentDate),
                        'reference_number' => $this->extractSbiCasReference($desc),
                        'raw_line' => $line,
                    ];
                }

                $currentDate = '';
                $descriptionParts = [];
                continue;
            }

            $descriptionParts[] = $line;
        }

        return ['account_last_four' => $accountLastFour, 'transactions' => $transactions];
    }

    /**
     * The YONO statement's header lists two 11-digit numbers close together
     * with no distinguishing mask -- CIF Number first, Account Number second
     * (the PDF's text layer extracts labels and values as separate groups,
     * not interleaved 1:1 with each other, so matching by label adjacency
     * isn't reliable). Scoped to the header only (before the statement body
     * begins) to avoid an accidental match against a later UPI reference
     * number.
     */
    private function extractYonoAccountLastFour(string $text): string
    {
        $bodyStart = strpos($text, 'STATEMENT OF ACCOUNT');
        $header = $bodyStart !== false ? substr($text, 0, $bodyStart) : substr($text, 0, 2000);

        if (preg_match_all('/\b\d{11}\b/', $header, $matches) && count($matches[0]) >= 2) {
            return substr($matches[0][1], -4);
        }
        return '';
    }

    /** "02/08/2026" -> "2026-08-02" */
    private function toIsoDateDdMmYyyy(string $ddmmyyyy): string
    {
        [$d, $m, $y] = explode('/', $ddmmyyyy);
        return $y . '-' . $m . '-' . $d;
    }

    /**
     * The YONO layout prefixes every description with a transaction-mode code
     * ("WDL TFR", "DEP TFR", "DEBIT", "CREDIT") that the netbanking-format
     * cleanSbiCasMerchant() below doesn't expect -- e.g. "WDL TFR UPI/DR/..."
     * never matches its str_starts_with($desc, 'UPI/') check. Strip it first
     * so both parsers can share the same merchant-cleaning logic.
     */
    private function stripYonoTransactionModePrefix(string $desc): string
    {
        return trim((string)preg_replace('/^(WDL\s+TFR|DEP\s+TFR|DEBIT|CREDIT)\s+/i', '', $desc));
    }

    /** e.g. "UPI/DR/345394731537/ASHISH T/HDFC/shivarya3@/Payme" -> "ASHISH T" */
    private function cleanSbiCasMerchant(string $desc): string
    {
        if (str_starts_with($desc, 'UPI/')) {
            $parts = explode('/', $desc);
            if (isset($parts[3]) && trim($parts[3]) !== '') {
                return trim($parts[3]);
            }
        }
        if (str_starts_with($desc, 'ACHDr') || str_starts_with($desc, 'ACHCr')) {
            return trim((string)preg_replace('/^ACH(Dr|Cr)\s*/', '', $desc));
        }
        if (str_starts_with($desc, 'NEFT')) {
            $segs = explode('*', $desc);
            return trim((string)end($segs));
        }
        return $desc;
    }

    private function extractSbiCasReference(string $desc): ?string
    {
        if (preg_match('#UPI/(?:DR|CR)/(\d+)/#', $desc, $m)) {
            return 'SBI_' . $m[1];
        }
        if (preg_match('/NEFT\*[^*]+\*([^*]+)\*/', $desc, $m)) {
            return 'SBI_' . $m[1];
        }
        return null;
    }

    /** "31-07-26" -> "2026-07-31" */
    private function toIsoDateDdMmYy(string $ddmmyy): string
    {
        [$d, $m, $y] = explode('-', $ddmmyy);
        return '20' . $y . '-' . $m . '-' . $d;
    }

    /**
     * Resolve (or create) the savings/current account for an SBI CAS
     * statement. Mirrors getOrCreateCreditCardAccount's collision handling,
     * and matches on a last-four LIKE fallback so it lands on the same
     * account the SMS parser and bulk scraper sync already created (which
     * store account_number as literal 'XXXX<last4>').
     */
    private function getOrCreateSavingsAccount(int $userId, string $bank, string $accountLastFour): int
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM bank_accounts
             WHERE user_id = ? AND bank = ? AND account_type = 'savings' AND account_number LIKE ?
             ORDER BY id DESC
             LIMIT 1",
            [$userId, $bank, '%' . $accountLastFour]
        );

        if ($existing && isset($existing['id'])) {
            return (int)$existing['id'];
        }

        $accountNumber = 'XXXX' . $accountLastFour;
        try {
            return (int)$this->db->insert(
                "INSERT INTO bank_accounts
                 (user_id, bank, account_type, account_number, account_name, status)
                 VALUES (?, ?, 'savings', ?, ?, 'active')",
                [$userId, $bank, $accountNumber, strtoupper($bank) . ' Account']
            );
        } catch (Exception $e) {
            $row = $this->db->fetchOne(
                "SELECT id FROM bank_accounts WHERE user_id = ? AND bank = ? AND account_number = ? LIMIT 1",
                [$userId, $bank, $accountNumber]
            );
            if ($row && isset($row['id'])) {
                return (int)$row['id'];
            }
            throw new Exception("Cannot create savings account: account_number '{$accountNumber}' collision. Original error: " . $e->getMessage());
        }
    }

    /**
     * Parse an HDFC savings-account "SmartStatement" PDF (the emailed monthly
     * statement, unlike SBI's CAS/YONO layouts). Table columns are Date /
     * Narration / Chq.-Ref No. / Value Date / Withdrawal / Deposit / Closing
     * Balance, but the text layer wraps narration across an arbitrary number
     * of lines before the trailing Ref-No/Value-Date/amounts land together on
     * one line, e.g.:
     *   "01/08/2026"
     *   "UPI-NIRMAL BEHERA-NB2137247@OKICICI-BKID"
     *   "0008434-515065211295-PAYMENT FROM PHONE"
     *   "515065211295	01/08/2026	2,000.00 0.00	56,543.83"
     * Short rows fit on one line instead:
     *   "05/08/2026 ACH D- HDFC BANK LTD-471795462	002864704217	05/08/2026	12,506.00 0.00	29,693.33"
     * Verified against a real statement by cross-summing parsed debit/credit
     * counts and totals against the statement's own printed summary block.
     */
    private function parseHdfcSavingsStatement(string $text): array
    {
        $accountLastFour = $this->extractHdfcSavingsAccountLastFour($text);

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $fullRowRe = '/^(\d{2}\/\d{2}\/\d{4})\s+(.*?)(?:\s+(\d+))?\s+(\d{2}\/\d{2}\/\d{4})\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})$/';
        $dateStartRe = '/^(\d{2}\/\d{2}\/\d{4})\b/';
        $trailerRe = '/^(.*?)(?:(\d+)\s+)?(\d{2}\/\d{2}\/\d{4})\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})$/';

        $transactions = [];
        $currentDate = '';
        $descriptionParts = [];

        foreach ($lines as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/\s+/', ' ', $line) ?? $line;

            if ($currentDate === '') {
                if (preg_match($dateStartRe, $line)) {
                    if (preg_match($fullRowRe, $line, $m)) {
                        $this->addHdfcSavingsTransaction($transactions, $m[1], $m[2], (string)($m[3] ?? ''), $m[5], $m[6], $line);
                        continue;
                    }
                    preg_match($dateStartRe, $line, $dm);
                    $currentDate = $dm[1];
                    $remainder = trim(substr($line, strlen($dm[1])));
                    $descriptionParts = $remainder !== '' ? [$remainder] : [];
                }
                continue;
            }

            // Mid-accumulation of a wrapped narration: bail out defensively if
            // boilerplate (repeated per-page header/footer) leaks in before a
            // trailer line is found, rather than folding it into a description.
            if (count($descriptionParts) > 15 || $this->looksLikeHdfcBoilerplate($line)) {
                $currentDate = '';
                $descriptionParts = [];
                continue;
            }

            if (preg_match($trailerRe, $line, $m)) {
                if (trim($m[1]) !== '') {
                    $descriptionParts[] = trim($m[1]);
                }
                $this->addHdfcSavingsTransaction($transactions, $currentDate, implode(' ', $descriptionParts), (string)($m[2] ?? ''), $m[4], $m[5], $line);
                $currentDate = '';
                $descriptionParts = [];
                continue;
            }

            $descriptionParts[] = $line;
        }

        return ['account_last_four' => $accountLastFour, 'transactions' => $transactions];
    }

    private function addHdfcSavingsTransaction(
        array &$transactions,
        string $date,
        string $narration,
        string $refNo,
        string $withdrawalRaw,
        string $depositRaw,
        string $rawLine
    ): void {
        $withdrawal = (float)str_replace(',', '', $withdrawalRaw);
        $deposit = (float)str_replace(',', '', $depositRaw);
        $isCredit = $deposit > 0;
        $amount = $isCredit ? $deposit : $withdrawal;
        if ($amount <= 0) {
            return;
        }

        $narration = trim((string)preg_replace('/\s+/', ' ', $narration));
        $transactions[] = [
            'transaction_type' => $isCredit ? 'credit' : 'debit',
            'amount' => round($amount, 2),
            'merchant' => $this->cleanHdfcSavingsMerchant($narration),
            'description' => $narration,
            'transaction_date' => $this->toIsoDateDdMmYyyy($date),
            'reference_number' => $this->extractHdfcSavingsReference($narration, $refNo),
            'raw_line' => $rawLine,
        ];
    }

    /** e.g. "UPI-NIRMAL BEHERA-NB2137247@OKICICI-BKID..." -> "NIRMAL BEHERA" */
    private function cleanHdfcSavingsMerchant(string $desc): string
    {
        if (preg_match('/^UPI-AUTOPAY-([^-]+)-/i', $desc, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^REV-UPI-/i', $desc)) {
            return 'UPI Reversal';
        }
        if (preg_match('/^UPI-([^-]+)-/i', $desc, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^ACH\s?[DC]-\s*(.+)$/i', $desc, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^EAW-/i', $desc)) {
            return 'Cash Withdrawal';
        }
        return $this->extractMerchant($desc, 'HDFC Account Transaction');
    }

    private function extractHdfcSavingsReference(string $desc, string $refNo): ?string
    {
        if ($refNo !== '') {
            return 'HDFC_' . $refNo;
        }
        if (preg_match('/-(\d{6,})-/', $desc, $m)) {
            return 'HDFC_' . $m[1];
        }
        if (preg_match('/\b(\d{6,})\b/', $desc, $m)) {
            return 'HDFC_' . $m[1];
        }
        return null;
    }

    /**
     * The account number ("Account number : 50100124361453") is the only
     * colon-prefixed 13-16 digit field in the header — every other numeric
     * field there (Cust ID, Pr.Code, Br.Code, MICR, phone numbers) is
     * shorter, so a length-scoped match is enough without needing to solve
     * this PDF's jumbled label/value column ordering.
     */
    private function extractHdfcSavingsAccountLastFour(string $text): string
    {
        if (preg_match('/:\s*(\d{13,16})\b/', $text, $m)) {
            return substr($m[1], -4);
        }
        return '';
    }

    private function looksLikeHdfcBoilerplate(string $line): bool
    {
        return (bool)preg_match(
            '/^(HDFC BANK LIMITED|Generation Date|Requesting Branch code|Generated by|Contents of this statement|State account branch GSTIN|HDFC Bank GSTIN|Registered Office Address|Page \d+ of \d+|Statement From|Account Branch|A\/C Open Date|Cust ID|Account Status|Account number|RTGS\/NEFT IFSC|JOINT HOLDERS|Nomination|Expected AMB|\*\*END OF STATEMENT\*\*|STATEMENT SUMMARY|Cr Count|Dr Count|Opening Balance|Closing Balance|Debits|Credits)\b/i',
            $line
        );
    }

    /**
     * AI-refine, dedupe and insert parsed savings-account statement
     * transactions (SBI CAS/YONO, HDFC). Mirrors persistParsedCardTransactions
     * but for a savings-account statement (no card-specific fields, no
     * foreign-currency handling needed — these statements are INR-only).
     */
    private function persistParsedSavingsTransactions(
        int $userId,
        string $bank,
        int $accountId,
        string $accountLastFour,
        array $parsedTransactions,
        string $parserName,
        int $uploadId,
        string $fileHash
    ): array {
        $bankLabel = strtoupper($bank);
        $statementAi = new AzureOpenAI();
        $aiRefinements = $statementAi->refineStatementTransactions($parsedTransactions, $bankLabel);
        if (!empty($aiRefinements)) {
            foreach ($parsedTransactions as $idx => $parsedTxn) {
                if (!isset($aiRefinements[$idx]) || !is_array($aiRefinements[$idx])) {
                    continue;
                }
                $refined = $aiRefinements[$idx];
                if (!empty($refined['merchant'])) {
                    $parsedTransactions[$idx]['merchant'] = (string)$refined['merchant'];
                }
                if (!empty($refined['description'])) {
                    $parsedTransactions[$idx]['description'] = (string)$refined['description'];
                }
                if (!empty($refined['category_id'])) {
                    $parsedTransactions[$idx]['category_id'] = (int)$refined['category_id'];
                }
            }
        }

        $paymentMethod = $bankLabel . ' Savings *' . $accountLastFour;

        $savedCount = 0;
        $skippedHighConfidence = 0;
        $flaggedPossibleDuplicates = 0;
        $aiChecked = 0;
        $fallbackUsed = 0;
        $savedDebitCount = 0;
        $savedCreditCount = 0;
        $savedDebitAmount = 0.0;
        $savedCreditAmount = 0.0;
        $savedDateMin = null;
        $savedDateMax = null;
        $errors = [];

        foreach ($parsedTransactions as $txn) {
            try {
                $normalizedType = ($txn['transaction_type'] ?? 'debit') === 'credit' ? 'credit' : 'debit';
                $normalizedDescription = trim((string)($txn['description'] ?? ''));
                $normalizedMerchant = trim((string)($txn['merchant'] ?? '')) ?: ($bankLabel . ' Transaction');

                // Rules-first: a user's learned merchant->category correction
                // beats the AI guess (mirrors the credit-card statement path).
                $learnedCategoryId = CategoryLearning::resolveFromTransaction($this->db, $userId, [
                    'merchant' => $normalizedMerchant,
                    'description' => $normalizedDescription,
                ]);
                $resolvedCategoryId = $learnedCategoryId !== null
                    ? $learnedCategoryId
                    : (isset($txn['category_id']) ? (int)$txn['category_id'] : null);

                $transactionPayload = [
                    'bank' => $bankLabel,
                    'account_number' => $accountLastFour,
                    'transaction_type' => $normalizedType,
                    'amount' => $txn['amount'],
                    'merchant' => $normalizedMerchant,
                    'description' => $normalizedDescription,
                    'category_id' => $resolvedCategoryId,
                    'date' => $txn['transaction_date'],
                    'reference_number' => $txn['reference_number'],
                    'payment_method' => $paymentMethod,
                ];

                $duplicateCheck = $this->duplicateDetector->evaluate($userId, $transactionPayload, [
                    'account_id' => $accountId,
                    'expand_linked_accounts' => true,
                    'ai_enabled' => true,
                    'skip_threshold' => 76,
                    'duplicate_threshold' => 51,
                    'source_hint' => 'statement_pdf',
                ]);

                if (!empty($duplicateCheck['ai_used'])) {
                    $aiChecked++;
                }
                if (!empty($duplicateCheck['fallback_used'])) {
                    $fallbackUsed++;
                }
                if (!empty($duplicateCheck['should_skip'])) {
                    $skippedHighConfidence++;
                    continue;
                }
                if (!empty($duplicateCheck['possible_duplicate'])) {
                    $flaggedPossibleDuplicates++;
                }

                $categoryId = CategoryResolver::resolveTransaction($transactionPayload);
                $sourceData = [
                    'source' => 'statement_pdf',
                    'parser' => $parserName,
                    'bank' => $bank,
                    'account_last_four' => $accountLastFour,
                    'upload_id' => $uploadId,
                    'file_hash' => $fileHash,
                    'raw_line' => $txn['raw_line'],
                ];

                $newTxnId = $this->db->insert(
                    "INSERT INTO transactions
                     (user_id, account_id, category_id, transaction_type, amount, currency,
                      merchant, description, transaction_date, reference_number, source, payment_method, source_data, duplicate_score)
                     VALUES (?, ?, ?, ?, ?, 'INR', ?, ?, ?, ?, 'statement_pdf', ?, ?, ?)",
                    [
                        $userId,
                        $accountId,
                        $categoryId,
                        $normalizedType,
                        $txn['amount'],
                        $normalizedMerchant,
                        $normalizedDescription,
                        $txn['transaction_date'],
                        $txn['reference_number'],
                        $paymentMethod,
                        json_encode($sourceData),
                        (int)($duplicateCheck['confidence'] ?? 0),
                    ]
                );

                MerchantSubscriptionDetector::evaluateTransaction($this->db, $userId, (int)$newTxnId);

                $savedCount++;

                $txnAmount = (float)$txn['amount'];
                if ($normalizedType === 'credit') {
                    $savedCreditCount++;
                    $savedCreditAmount += $txnAmount;
                } else {
                    $savedDebitCount++;
                    $savedDebitAmount += $txnAmount;
                }

                $txnDate = (string)($txn['transaction_date'] ?? '');
                if ($txnDate !== '') {
                    if ($savedDateMin === null || strtotime($txnDate) < strtotime($savedDateMin)) {
                        $savedDateMin = $txnDate;
                    }
                    if ($savedDateMax === null || strtotime($txnDate) > strtotime($savedDateMax)) {
                        $savedDateMax = $txnDate;
                    }
                }
            } catch (Exception $recordError) {
                $errors[] = $recordError->getMessage();
            }
        }

        return [
            'saved' => $savedCount,
            'skipped_high_confidence' => $skippedHighConfidence,
            'flagged' => $flaggedPossibleDuplicates,
            'ai_checked' => $aiChecked,
            'fallback_used' => $fallbackUsed,
            'debit_count' => $savedDebitCount,
            'credit_count' => $savedCreditCount,
            'debit_amount' => $savedDebitAmount,
            'credit_amount' => $savedCreditAmount,
            'date_min' => $savedDateMin,
            'date_max' => $savedDateMax,
            'errors' => $errors,
        ];
    }

    private function validateUploadedPdf(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('File upload failed with error code: ' . (int)$file['error'], 400);
        }

        $maxBytes = 20 * 1024 * 1024;
        if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxBytes) {
            Response::error('Statement PDF size must be between 1 byte and 20 MB.', 400);
        }

        $name = strtolower((string)($file['name'] ?? ''));
        if (!str_ends_with($name, '.pdf')) {
            Response::error('Only PDF files are supported.', 400);
        }

        $fp = fopen($file['tmp_name'], 'rb');
        if (!$fp) {
            Response::error('Unable to read uploaded file.', 400);
        }

        $header = fread($fp, 4);
        fclose($fp);

        if ($header !== '%PDF') {
            Response::error('Invalid PDF file header.', 400);
        }
    }

    private function extractPdfText(string $inputPath, string $password, array &$cleanupFiles): string
    {
        $parser = new \Smalot\PdfParser\Parser();

        try {
            $pdf = $parser->parseFile($inputPath);
            $text = $pdf->getText();
            if (trim($text) !== '') {
                return $text;
            }
        } catch (Exception $ignored) {
            // Fall through to qpdf decryption path.
        }

        if (!function_exists('shell_exec')) {
            throw new Exception('Encrypted statement detected but qpdf is unavailable on server.');
        }

        $diagnostics = [];

        $homeDir = (string)(getenv('HOME') ?: '');

        $qpdfBinary = $this->resolveCliBinary('qpdf', 'STATEMENT_QPDF_BIN', [
            '/usr/bin/qpdf',
            '/usr/local/bin/qpdf',
            $homeDir !== '' ? $homeDir . '/bin/qpdf' : '',
        ]);

        $pdftotextBinary = $this->resolveCliBinary('pdftotext', 'STATEMENT_PDFTOTEXT_BIN', [
            '/usr/bin/pdftotext',
            '/usr/local/bin/pdftotext',
            $homeDir !== '' ? $homeDir . '/bin/pdftotext' : '',
        ]);

        $mutoolBinary = $this->resolveCliBinary('mutool', 'STATEMENT_MUTOOL_BIN', [
            '/usr/bin/mutool',
            '/usr/local/bin/mutool',
            $homeDir !== '' ? $homeDir . '/bin/mutool' : '',
        ]);

        $pythonBinary = $this->resolveCliBinary('python3', 'STATEMENT_PYTHON_BIN', [
            '/usr/bin/python3',
            '/usr/local/bin/python3',
            $homeDir !== '' ? $homeDir . '/bin/python3' : '',
        ]);

        $decryptedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statement_decrypted_' . uniqid() . '.pdf';
        $cleanupFiles[] = $decryptedPath;

        if ($qpdfBinary !== null) {
            $command = escapeshellarg($qpdfBinary)
                . ' --password=' . escapeshellarg($password)
                . ' --decrypt ' . escapeshellarg($inputPath)
                . ' ' . escapeshellarg($decryptedPath)
                . ' 2>&1';

            $commandOutput = shell_exec($command);
            $qpdfOutput = trim((string)$commandOutput);
            if (file_exists($decryptedPath) && filesize($decryptedPath) > 0) {
                try {
                    $pdf = $parser->parseFile($decryptedPath);
                    $text = $pdf->getText();
                    if (trim($text) !== '') {
                        return $text;
                    }
                    $diagnostics[] = 'qpdf decrypted but parser returned empty text';
                } catch (Exception $e) {
                    $diagnostics[] = 'qpdf parse failed: ' . substr($e->getMessage(), 0, 180);
                }
            } else {
                $diagnostics[] = 'qpdf failed: ' . $this->summarizeCliOutput($qpdfOutput);
            }
        } else {
            $diagnostics[] = 'qpdf not found (set STATEMENT_QPDF_BIN to absolute path if installed)';
        }

        // Fallback 1: pdftotext can decrypt directly with password in many hosts.
        if ($pdftotextBinary !== null) {
            $pdfToTextCommand = escapeshellarg($pdftotextBinary)
                . ' -upw ' . escapeshellarg($password)
                . ' ' . escapeshellarg($inputPath)
                . ' - 2>&1';
            $pdfToTextOutput = trim((string)shell_exec($pdfToTextCommand));

            if ($pdfToTextOutput !== '' && !$this->looksLikeCliFailure($pdfToTextOutput)) {
                return $pdfToTextOutput;
            }

            $diagnostics[] = 'pdftotext failed: ' . $this->summarizeCliOutput($pdfToTextOutput);
        } else {
            $diagnostics[] = 'pdftotext not found (set STATEMENT_PDFTOTEXT_BIN to absolute path if installed)';
        }

        // Fallback 2: mutool text extraction with password.
        if ($mutoolBinary !== null) {
            $mutoolCommand = escapeshellarg($mutoolBinary)
                . ' draw -F txt -p ' . escapeshellarg($password)
                . ' ' . escapeshellarg($inputPath)
                . ' 2>&1';
            $mutoolOutput = trim((string)shell_exec($mutoolCommand));

            if ($mutoolOutput !== '' && !$this->looksLikeCliFailure($mutoolOutput)) {
                return $mutoolOutput;
            }

            $diagnostics[] = 'mutool failed: ' . $this->summarizeCliOutput($mutoolOutput);
        } else {
            $diagnostics[] = 'mutool not found (set STATEMENT_MUTOOL_BIN to absolute path if installed)';
        }

        // Fallback 3: python3 + pypdf (installable in user home on cPanel without root).
        if ($pythonBinary !== null) {
            $pythonResult = $this->extractWithPythonPypdf($pythonBinary, $inputPath, $password, $cleanupFiles);
            if (!empty($pythonResult['ok'])) {
                return (string)$pythonResult['text'];
            }

            $diagnostics[] = 'python+pypdf failed: ' . (string)($pythonResult['error'] ?? 'unknown error');
        } else {
            $diagnostics[] = 'python3 not found (set STATEMENT_PYTHON_BIN to absolute path if installed)';
        }

        throw new Exception(
            'Failed to decrypt statement PDF. Verify stored password and PDF tools availability. '
            . implode(' | ', $diagnostics)
        );
    }

    private function extractWithPythonPypdf(string $pythonBinary, string $inputPath, string $password, array &$cleanupFiles): array
    {
        $scriptPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statement_extract_' . uniqid() . '.py';
        $cleanupFiles[] = $scriptPath;

        $script = <<<'PY'
import sys

reader_ctor = None
legacy_reader = False

try:
    from pypdf import PdfReader as _PdfReader
    reader_ctor = _PdfReader
except Exception as pypdf_import_error:
    try:
        from PyPDF2 import PdfReader as _PdfReader
        reader_ctor = _PdfReader
    except Exception as pypdf2_import_error:
        try:
            from PyPDF2 import PdfFileReader as _PdfFileReader
            reader_ctor = _PdfFileReader
            legacy_reader = True
        except Exception as legacy_import_error:
            print("__PYPDF_MISSING__" + str(pypdf_import_error) + " || " + str(pypdf2_import_error) + " || " + str(legacy_import_error))
            sys.exit(0)

path = sys.argv[1]
password = sys.argv[2] if len(sys.argv) > 2 else ""
file_handle = None

try:
    if legacy_reader:
        file_handle = open(path, "rb")
        reader = reader_ctor(file_handle)
        is_encrypted = bool(getattr(reader, "isEncrypted", False))
    else:
        reader = reader_ctor(path)
        is_encrypted = bool(getattr(reader, "is_encrypted", False))

    if is_encrypted:
        decrypt_result = reader.decrypt(password)
        if decrypt_result in (0, False, None):
            print("__PYPDF_BAD_PASSWORD__")
            sys.exit(0)

    chunks = []
    for page in reader.pages:
        if hasattr(page, "extract_text"):
            text = page.extract_text() or ""
        elif hasattr(page, "extractText"):
            text = page.extractText() or ""
        else:
            text = ""

        if text:
            chunks.append(text)

    output = "\n".join(chunks).strip()
    if output:
        print(output)
    else:
        print("__PYPDF_EMPTY__")
except Exception as ex:
    print("__PYPDF_ERROR__" + str(ex))
finally:
    if file_handle is not None:
        try:
            file_handle.close()
        except Exception:
            pass
PY;

        if (@file_put_contents($scriptPath, $script) === false) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'unable to write temporary Python script',
            ];
        }

        $command = escapeshellarg($pythonBinary)
            . ' ' . escapeshellarg($scriptPath)
            . ' ' . escapeshellarg($inputPath)
            . ' ' . escapeshellarg($password)
            . ' 2>&1';

        $output = trim((string)shell_exec($command));

        if (str_starts_with($output, '__PYPDF_MISSING__')) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'Python PDF library missing/incompatible (Python 3.6 usually needs: python3 -m pip install --user "PyPDF2<3")',
            ];
        }

        if ($output === '__PYPDF_BAD_PASSWORD__') {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'incorrect PDF password',
            ];
        }

        if ($output === '__PYPDF_EMPTY__') {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'decrypted but extracted text is empty',
            ];
        }

        if (str_starts_with($output, '__PYPDF_ERROR__')) {
            return [
                'ok' => false,
                'text' => '',
                'error' => substr($output, strlen('__PYPDF_ERROR__')),
            ];
        }

        if ($this->looksLikeCliFailure($output)) {
            return [
                'ok' => false,
                'text' => '',
                'error' => $this->summarizeCliOutput($output),
            ];
        }

        return [
            'ok' => trim($output) !== '',
            'text' => $output,
            'error' => trim($output) !== '' ? '' : 'no output',
        ];
    }

    private function resolveCliBinary(string $binaryName, string $envVar, array $commonPaths): ?string
    {
        $envPath = trim((string)(getenv($envVar) ?: ''));
        if ($envPath !== '' && is_file($envPath) && is_executable($envPath)) {
            return $envPath;
        }

        $whichOutput = trim((string)shell_exec('command -v ' . escapeshellarg($binaryName) . ' 2>/dev/null'));
        if ($whichOutput !== '' && is_file($whichOutput) && is_executable($whichOutput)) {
            return $whichOutput;
        }

        foreach ($commonPaths as $path) {
            $path = trim((string)$path);
            if ($path === '') {
                continue;
            }

            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function looksLikeCliFailure(string $output): bool
    {
        if ($output === '') {
            return true;
        }

        return preg_match(
            '/command not found|not recognized as an internal or external command|cannot authenticate password|incorrect password|command line error|unable to open|failed to open|no such file/i',
            $output
        ) === 1;
    }

    private function summarizeCliOutput(string $output): string
    {
        if (trim($output) === '') {
            return 'no output';
        }

        return substr(trim($output), 0, 220);
    }

    private function parseTransactionsByBank(string $bank, string $text, string $cardLastFour): array
    {
        if ($bank === 'sbi') {
            $transactions = $this->parseSbiTransactions($text, $cardLastFour);
            if (!empty($transactions)) {
                return [
                    'transactions' => $transactions,
                    'parser' => 'sbi_v1',
                ];
            }

            return [
                'transactions' => $this->parseGenericStatementTransactions($text, $cardLastFour, 'SBI'),
                'parser' => 'sbi_generic_v1',
            ];
        }

        if ($bank === 'rbl') {
            $transactions = $this->parseRblTransactions($text, $cardLastFour);
            if (!empty($transactions)) {
                return [
                    'transactions' => $transactions,
                    'parser' => 'rbl_v1',
                ];
            }

            return [
                'transactions' => $this->parseGenericStatementTransactions($text, $cardLastFour, 'RBL'),
                'parser' => 'rbl_generic_v1',
            ];
        }

        $transactions = $this->parseIciciTransactions($text, $cardLastFour);
        if (!empty($transactions)) {
            return [
                'transactions' => $transactions,
                'parser' => 'icici_v1',
            ];
        }

        return [
            'transactions' => $this->parseGenericStatementTransactions($text, $cardLastFour, 'ICICI'),
            'parser' => 'icici_generic_v1',
        ];
    }

    private function parseGenericStatementTransactions(string $text, string $cardLastFour, string $bankLabel): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $records = [];
        $current = '';

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\s+/', ' ', $line) ?? $line;

            if ($this->looksLikeStatementDatePrefix($line)) {
                if ($current !== '') {
                    $records[] = $current;
                }
                $current = $line;
                continue;
            }

            if ($current !== '') {
                $current .= ' ' . $line;
            }
        }

        if ($current !== '') {
            $records[] = $current;
        }

        $transactions = [];
        $index = 0;

        foreach ($records as $record) {
            $index++;

            $dateInfo = $this->parseStatementDatePrefix($record);
            if ($dateInfo === null) {
                continue;
            }

            $dateRaw = $dateInfo['raw_date'];
            $transactionDate = $dateInfo['normalized_date'];
            $rest = $dateInfo['rest'];
            $restLower = strtolower($rest);

            if (preg_match('/statement|opening balance|closing balance|minimum amount due|total amount due|payment due date|credit limit|available limit|reward/i', $restLower)) {
                continue;
            }

            if (!preg_match_all('/-?\d[\d,]*\.\d{2}/', $rest, $amountMatches, PREG_OFFSET_CAPTURE) || empty($amountMatches[0])) {
                continue;
            }

            $candidateAmounts = $amountMatches[0];
            $selectedAmountIndex = count($candidateAmounts) >= 2 ? count($candidateAmounts) - 2 : 0;

            $amountRaw = (string)$candidateAmounts[$selectedAmountIndex][0];
            $amountOffset = (int)$candidateAmounts[$selectedAmountIndex][1];
            $amount = (float)str_replace(',', '', str_replace('-', '', $amountRaw));

            if ($amount <= 0) {
                continue;
            }

            $description = trim(substr($rest, 0, $amountOffset));
            if ($description === '') {
                $description = trim($rest);
            }

            $tail = strtoupper(trim(substr($rest, $amountOffset + strlen($amountRaw))));
            $drCr = '';
            if (preg_match('/\b(CR|DR)\b/i', $tail, $drCrMatch)) {
                $drCr = strtoupper((string)$drCrMatch[1]);
            }

            if ($description === '') {
                continue;
            }

            $transactionType = $this->inferTransactionType($description, $drCr);
            $merchant = $this->extractMerchant($description, $bankLabel . ' Card Transaction');
            $reference = $this->extractReferenceFromDescription($description);

            if ($reference === '') {
                $reference = strtoupper(substr(sha1($dateRaw . '|' . $description . '|' . $amount . '|' . $cardLastFour . '|' . $index), 0, 12));
            }

            $transactions[] = [
                'transaction_type' => $transactionType,
                'amount' => round($amount, 2),
                'merchant' => $merchant,
                'description' => $description,
                'transaction_date' => $transactionDate,
                'reference_number' => $bankLabel . '_' . $reference,
                'raw_line' => $record,
                'card_last_four' => $cardLastFour,
            ];
        }

        return $transactions;
    }

    private function looksLikeStatementDatePrefix(string $line): bool
    {
        return preg_match(
            '/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}[\-\s](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*[\-\s]\d{2,4})\b/i',
            $line
        ) === 1;
    }

    private function parseStatementDatePrefix(string $record): ?array
    {
        if (!preg_match(
            '/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}[\-\s](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*[\-\s]\d{2,4})\s+(.+)$/i',
            trim($record),
            $match
        )) {
            return null;
        }

        $rawDate = trim((string)$match[1]);
        $normalizedDate = $this->normalizeStatementDateFlexible($rawDate);

        return [
            'raw_date' => $rawDate,
            'normalized_date' => $normalizedDate,
            'rest' => trim((string)$match[2]),
        ];
    }

    private function normalizeStatementDateFlexible(string $dateRaw): string
    {
        $clean = trim($dateRaw);

        if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/', $clean)) {
            return $this->normalizeStatementDate($clean);
        }

        $normalizedText = preg_replace('/\s+/', ' ', str_replace('-', ' ', $clean)) ?? $clean;
        $parsedTs = strtotime($normalizedText);
        if ($parsedTs !== false) {
            return date('Y-m-d 12:00:00', $parsedTs);
        }

        return date('Y-m-d H:i:s');
    }

    private function buildTextPreview(string $text): string
    {
        $preview = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($preview === '') {
            return '';
        }

        return substr($preview, 0, 220);
    }

    private function parseIciciTransactions(string $text, string $cardLastFour): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $combinedLines = [];
        $currentLine = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{8,}/', $line)) {
                if ($currentLine !== '') {
                    $combinedLines[] = $currentLine;
                }
                $currentLine = $line;
                continue;
            }

            if ($currentLine !== '') {
                $currentLine .= ' ' . $line;
            }
        }

        if ($currentLine !== '') {
            $combinedLines[] = $currentLine;
        }

        $transactions = [];

        foreach ($combinedLines as $line) {
            if (!preg_match('/^(\d{2}\/\d{2}\/\d{4})\s+(\d{8,})\s+(.+?)\s+([\d,]+\.\d{2})\s*(CR)?\s*$/i', $line, $match)) {
                continue;
            }

            $dateRaw = $match[1];
            $serialNo = $match[2];
            $middlePart = trim($match[3]);
            $amountRaw = $match[4];
            $isCredit = !empty($match[5]);

            $amount = (float)str_replace(',', '', $amountRaw);
            if ($amount <= 0) {
                continue;
            }

            if (str_contains(strtolower($middlePart), 'transaction details')) {
                continue;
            }

            $description = $middlePart;
            if (preg_match('/^(.+?)\s+(-?\d+)(?:\s+[\d,]+\.\d{2})?\s*$/', $middlePart, $descMatch)) {
                $description = trim($descMatch[1]);
            }

            $normalizedDate = $this->normalizeIciciDate($dateRaw);
            $merchant = $this->extractMerchant($description, 'ICICI Card Transaction');

            $transactions[] = [
                'transaction_type' => $isCredit ? 'credit' : 'debit',
                'amount' => round($amount, 2),
                'merchant' => $merchant,
                'description' => $description,
                'transaction_date' => $normalizedDate,
                'reference_number' => 'ICICI_' . $serialNo,
                'raw_line' => $line,
                'card_last_four' => $cardLastFour,
            ];
        }

        return $transactions;
    }

    /**
     * RBL credit-card statement parser. Transaction lines look like:
     *   "23 May 2026 LIFE STYLE INTERNATIO BANGALORE KAR 299.00"
     *   "29 May 2026 PAYMENT RECEIVED - BBPS 12,522.00"  (a credit)
     * Skips the EMI/fee schedule rows (which carry the mangled "/uni20B9" rupee
     * glyph and dd-Mon-yy 2-digit dates) and zero-amount lines.
     */
    private function parseRblTransactions(string $text, string $cardLastFour): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $transactions = [];
        $creditPattern = '/payment received|payment credit|reversal|refund|cashback|received\s*-/i';

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', (string)$line) ?? '');
            if ($line === '' || stripos($line, 'uni20B9') !== false) {
                continue; // skip EMI/fee-schedule noise (rupee-glyph rows)
            }

            // <D Mon YYYY> <merchant ...> <amount>[ Cr]
            if (!preg_match('/^(\d{1,2}) ([A-Za-z]{3}) (\d{4}) (.+?) ([\d,]+\.\d{2})(?:\s*(Cr|CR))?$/', $line, $m)) {
                continue;
            }

            $monthNum = $this->monthAbbrToNum($m[2]);
            if ($monthNum === null) {
                continue;
            }

            $amount = (float)str_replace(',', '', $m[5]);
            if ($amount <= 0) {
                continue;
            }

            $description = trim($m[4]);
            if (stripos($description, 'opening balance') !== false || stripos($description, 'total amount due') !== false) {
                continue;
            }

            $isCredit = !empty($m[6]) || preg_match($creditPattern, $description) === 1;
            $normalizedDate = sprintf('%04d-%02d-%02d 12:00:00', (int)$m[3], $monthNum, (int)$m[1]);
            $merchant = $this->extractMerchant($description, 'RBL Card Transaction');

            $transactions[] = [
                'transaction_type' => $isCredit ? 'credit' : 'debit',
                'amount' => round($amount, 2),
                'merchant' => $merchant,
                'description' => $description,
                'transaction_date' => $normalizedDate,
                // No serial in the PDF text; derive a stable ref so re-syncs dedupe.
                'reference_number' => 'RBL_' . substr(md5($normalizedDate . '|' . $description . '|' . $amount), 0, 16),
                'raw_line' => $line,
                'card_last_four' => $cardLastFour,
            ];
        }

        return $transactions;
    }

    /** Three-letter month abbreviation to 1-12, or null. */
    private function monthAbbrToNum(string $mon): ?int
    {
        static $map = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $key = strtolower(substr(trim($mon), 0, 3));
        return $map[$key] ?? null;
    }

    /**
     * Pull the masked card's trailing digits (2-4) from decrypted statement text,
     * e.g. ICICI "XXXXXXXX7003" -> 7003, RBL "Card Number XXXXXXXXXXXXXX89" -> 89.
     * Returned digits are matched against existing cards via account_number LIKE,
     * so even a 2-digit tail maps to the right card (…6089).
     */
    private function extractCardLast4ForBank(string $bank, string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        $flat = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        // Masked digits then 2-4 revealed trailing digits (not followed by more digits).
        if (preg_match('/(?:[xX*]\s?){4,}(\d{2,4})(?!\d)/', $flat, $m)) {
            return $m[1];
        }
        // "card ending 1234" / "card no: ....1234"
        if (preg_match('/card\s*(?:no\.?|number|ending(?:\s*in)?)?\s*[:#]?\s*(?:[xX*\d]{2,}\D)?(\d{4})(?!\d)/i', $flat, $m)) {
            return $m[1];
        }
        return '';
    }

    private function parseSbiTransactions(string $text, string $cardLastFour): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $transactions = [];

        $currentDateRaw = '';
        $descriptionParts = [];

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\s+/', ' ', $line) ?? $line;

            if (preg_match('/page no\.|statement of account|brought forward|opening balance|closing balance/i', $line)) {
                continue;
            }

            if (preg_match('/^(\d{2}\/\d{2}\/\d{4})\d{2}\/\d{2}\/\d{4}$/', $line, $dateMatch)
                || preg_match('/^(\d{2}\/\d{2}\/\d{4})$/', $line, $dateMatch)) {
                $currentDateRaw = $dateMatch[1];
                $descriptionParts = [];
                continue;
            }

            if ($currentDateRaw === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $line) ?: [];
            if (count($tokens) >= 4 && $tokens[0] === '-') {
                $debitRaw = $tokens[1] ?? '-';
                $creditRaw = $tokens[2] ?? '-';
                $balanceRaw = $tokens[3] ?? '';

                $debitAmount = $this->parseSbiAmountToken($debitRaw);
                $creditAmount = $this->parseSbiAmountToken($creditRaw);
                $balanceAmount = $this->parseSbiAmountToken($balanceRaw);

                if ($balanceAmount === null || ($debitAmount === null && $creditAmount === null)) {
                    continue;
                }

                $description = trim(implode(' ', $descriptionParts));
                if ($description === '') {
                    $description = 'SBI account statement transaction';
                }

                $type = 'debit';
                $amount = $debitAmount;
                if ($debitAmount === null && $creditAmount !== null) {
                    $type = 'credit';
                    $amount = $creditAmount;
                } elseif ($debitAmount !== null && $creditAmount !== null) {
                    $type = $creditAmount >= $debitAmount ? 'credit' : 'debit';
                    $amount = $type === 'credit' ? $creditAmount : $debitAmount;
                }

                if ($amount === null || $amount <= 0) {
                    $currentDateRaw = '';
                    $descriptionParts = [];
                    continue;
                }

                $normalizedDate = $this->normalizeStatementDate($currentDateRaw);
                $merchant = $this->extractMerchant($description, 'SBI Account Transaction');
                $referenceValue = $this->extractReferenceFromDescription($description);
                if ($referenceValue === '') {
                    $referenceValue = strtoupper(substr(sha1($currentDateRaw . '|' . $description . '|' . $amount . '|' . $cardLastFour), 0, 12));
                }

                $rawLine = $currentDateRaw . ' | ' . $description . ' | ' . $line;
                $transactions[] = [
                    'transaction_type' => $type,
                    'amount' => round($amount, 2),
                    'merchant' => $merchant,
                    'description' => $description,
                    'transaction_date' => $normalizedDate,
                    'reference_number' => 'SBI_' . $referenceValue,
                    'raw_line' => $rawLine,
                    'card_last_four' => $cardLastFour,
                ];

                $currentDateRaw = '';
                $descriptionParts = [];
                continue;
            }

            if (preg_match('/^balance$/i', $line)) {
                continue;
            }

            $descriptionParts[] = $line;
        }

        return $transactions;
    }

    private function parseSbiAmountToken(string $token): ?float
    {
        $value = trim($token);
        if ($value === '-' || $value === '--' || $value === '') {
            return null;
        }

        if (!preg_match('/^[\d,]+\.\d{2}$/', $value)) {
            return null;
        }

        $amount = (float)str_replace(',', '', $value);
        return $amount > 0 ? $amount : null;
    }

    private function normalizeIciciDate(string $dateRaw): string
    {
        return $this->normalizeStatementDate($dateRaw);
    }

    private function normalizeStatementDate(string $dateRaw): string
    {
        $normalized = str_replace('-', '/', trim($dateRaw));
        $parts = explode('/', $normalized);
        if (count($parts) !== 3) {
            return date('Y-m-d H:i:s');
        }

        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $year = $parts[2];

        if (strlen($year) === 2) {
            $year = '20' . $year;
        }

        return $year . '-' . $month . '-' . $day . ' 12:00:00';
    }

    private function extractMerchant(string $description, string $fallbackLabel = 'Card Transaction'): string
    {
        $merchant = trim($description);

        if (preg_match('/UPI-\d+-(.+?)(?:\sIN\s\d+)?$/i', $merchant, $upiMatch)) {
            $merchant = trim($upiMatch[1]);
        }

        $merchant = preg_replace('/\sIN\s\d+$/i', '', $merchant) ?? $merchant;
        $merchant = preg_replace('/\s+(BANGALORE|BENGALURU|MUMBAI|DELHI|CHENNAI|KOLKATA|PUNE|HYDERABAD|IND|MH|DL|TN)$/i', '', $merchant) ?? $merchant;
        $merchant = preg_replace('/\s+/', ' ', trim($merchant)) ?? $merchant;

        if ($merchant === '') {
            $merchant = $fallbackLabel;
        }

        return mb_substr($merchant, 0, 500);
    }

    private function extractReferenceFromDescription(string $description): string
    {
        if (preg_match('/(?:ref(?:erence)?|rrn|utr|txn(?:\s*id)?)\s*[:\-]?\s*([A-Z0-9]{6,})/i', $description, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/\b([A-Z0-9]{8,})\b/', strtoupper($description), $match)) {
            return $match[1];
        }

        return '';
    }

    private function inferTransactionType(string $description, string $drCr): string
    {
        if ($drCr === 'CR') {
            return 'credit';
        }

        if ($drCr === 'DR') {
            return 'debit';
        }

        if (preg_match('/\b(refund|reversal|cashback|payment\s+received|credited|credit\s+interest|interest\s+credited|salary\s+credit|amount\s+received|received)\b/i', $description)) {
            return 'credit';
        }

        if (preg_match('/\b(debit(?:ed)?|purchase|spent|withdrawal|atm|bill\s+paid|charged)\b/i', $description)) {
            return 'debit';
        }

        return 'debit';
    }

    private function normalizeStatementTransactionType(string $rawType, string $description = ''): string
    {
        $value = strtolower(trim($rawType));
        if ($value === 'expense') {
            return 'debit';
        }
        if ($value === 'income') {
            return 'credit';
        }
        if (in_array($value, ['debit', 'credit', 'transfer'], true)) {
            return $value;
        }

        return $this->inferTransactionType($description, '');
    }

    private function normalizeStatementMerchant(string $merchant, string $description, string $fallbackLabel): string
    {
        $value = trim($merchant);
        if ($value === '') {
            $value = $this->extractMerchant($description, $fallbackLabel);
        }

        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        if ($value !== '' && strtoupper($value) === $value) {
            $value = ucwords(strtolower($value));
        }

        if ($value === '') {
            $value = $fallbackLabel;
        }

        return mb_substr($value, 0, 500);
    }

    private function normalizeStatementDescription(string $description, string $merchant, string $bankLabel): string
    {
        $value = preg_replace('/\s+/', ' ', trim($description)) ?? trim($description);
        if ($value === '') {
            $merchantValue = trim($merchant);
            if ($merchantValue !== '') {
                $value = 'Transaction at ' . $merchantValue;
            } else {
                $value = $bankLabel . ' card transaction';
            }
        }

        return mb_substr($value, 0, 1000);
    }

    private function isSupportedBank(string $bank): bool
    {
        return in_array($bank, ['icici', 'sbi', 'rbl'], true);
    }

    /**
     * Gate for the manual password-setup/upload entry points: SBI/ICICI/RBL
     * are credit-card-only there, while HDFC is savings-account-only (its
     * "SmartStatement" PDF). Kept separate from isSupportedBank(), which
     * additionally gates the credit-card-specific ingest path used by the
     * Gmail worker and must not start accepting 'hdfc' as a credit-card bank.
     */
    private function isValidBankAccountTypeCombo(string $bank, string $accountType): bool
    {
        if ($bank === 'hdfc') {
            return $accountType === 'savings';
        }
        return $this->isSupportedBank($bank) && $accountType === 'credit_card';
    }

    /**
     * Detect the card product name from decrypted statement text (e.g. ICICI
     * "Rubyx"/"Amazon Pay"/"Coral"/"Platinum", RBL "Play") so two cards from the
     * same bank get distinct account names instead of both being "{BANK} Card".
     * Mirrors scraper/src/transactions/credit-cards.ts's detectBankAndCardType.
     */
    private function extractCardTypeForBank(string $bank, string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        $upper = strtoupper($text);

        if ($bank === 'icici') {
            if (str_contains($upper, 'RUBYX') || str_contains($upper, 'RUBY')) return 'Rubyx';
            if (str_contains($upper, 'AMAZON PAY')) return 'Amazon Pay';
            if (str_contains($upper, 'CORAL')) return 'Coral';
            if (str_contains($upper, 'PLATINUM')) return 'Platinum';
        } elseif ($bank === 'rbl') {
            if (str_contains($upper, 'PLAY')) return 'Play';
        }

        return '';
    }

    private function getOrCreateCreditCardAccount(int $userId, string $bank, string $cardLastFour, ?string $cardType = null): int
    {
        $existing = $this->db->fetchOne(
            "SELECT id, card_type FROM bank_accounts
             WHERE user_id = ? AND bank = ? AND account_type = 'credit_card'
               AND (card_last_four = ? OR account_number LIKE ?)
             ORDER BY id DESC
             LIMIT 1",
            [$userId, $bank, $cardLastFour, '%' . $cardLastFour]
        );

        if ($existing && isset($existing['id'])) {
            if ($cardType && empty($existing['card_type'])) {
                $this->db->execute(
                    "UPDATE bank_accounts SET card_type = ?, account_name = ? WHERE id = ?",
                    [$cardType, strtoupper($bank) . ' ' . $cardType, $existing['id']]
                );
            }
            return (int)$existing['id'];
        }

        $accountNumber = 'XXXX' . $cardLastFour;
        $accountName = $cardType ? (strtoupper($bank) . ' ' . $cardType) : (strtoupper($bank) . ' Card');
        try {
            return (int)$this->db->insert(
                "INSERT INTO bank_accounts
                 (user_id, bank, account_type, account_number, account_name, card_last_four, card_type, status)
                 VALUES (?, ?, 'credit_card', ?, ?, ?, ?, 'active')",
                [
                    $userId,
                    $bank,
                    $accountNumber,
                    $accountName,
                    $cardLastFour,
                    $cardType,
                ]
            );
        } catch (Exception $e) {
            // unique_account (user_id, bank, account_number) collision — either we lost
            // a race against another credit-card insert, or account_number happens to
            // match a DIFFERENT account_type (e.g. a savings account whose masked number
            // coincidentally equals 'XXXX<last4>'). Must stay scoped to credit_card here:
            // silently reusing a non-credit-card row would misfile transactions into the
            // wrong account with no error at all.
            $row = $this->db->fetchOne(
                "SELECT id FROM bank_accounts WHERE user_id = ? AND bank = ? AND account_number = ? AND account_type = 'credit_card' LIMIT 1",
                [$userId, $bank, $accountNumber]
            );
            if ($row && isset($row['id'])) {
                return (int)$row['id'];
            }
            throw new Exception("Cannot create credit card account: account_number '{$accountNumber}' is already used by a non-credit-card account for this bank. Original error: " . $e->getMessage());
        }
    }

    private function normalizeBank(string $bank): string
    {
        $value = strtolower(trim($bank));
        $map = [
            'hdfc' => 'hdfc',
            'hdfc bank' => 'hdfc',
            'sbi' => 'sbi',
            'state bank of india' => 'sbi',
            'icici' => 'icici',
            'icici bank' => 'icici',
            'idfc' => 'idfc',
            'idfc first bank' => 'idfc',
            'rbl' => 'rbl',
            'rbl bank' => 'rbl',
            'axis' => 'axis',
            'axis bank' => 'axis',
            'kotak' => 'kotak',
            'kotak mahindra bank' => 'kotak',
        ];

        return $map[$value] ?? 'other';
    }

    private function normalizeAccountType(string $accountType): string
    {
        $value = strtolower(trim($accountType));
        if (in_array($value, ['credit_card', 'credit card', 'card'], true)) {
            return 'credit_card';
        }

        if ($value === 'current') {
            return 'current';
        }

        return 'savings';
    }

    private function normalizeCardLastFour(string $cardLastFour): string
    {
        $digits = preg_replace('/\D+/', '', $cardLastFour);
        if (!$digits) {
            return '';
        }

        return substr($digits, -4);
    }
}

function handleStatementRoutes(string $uri, string $method): void
{
    $controller = new StatementController();

    if ($uri === '/statements/password' && $method === 'POST') {
        $controller->savePassword();
        return;
    }

    if ($uri === '/statements/password' && $method === 'DELETE') {
        $controller->deletePassword();
        return;
    }

    if ($uri === '/statements/password-candidates' && $method === 'GET') {
        $controller->getPasswordCandidates();
        return;
    }

    if ($uri === '/statements/password-candidates' && $method === 'POST') {
        $controller->savePasswordCandidates();
        return;
    }

    if ($uri === '/statements/password-candidates' && $method === 'DELETE') {
        $controller->deletePasswordCandidate();
        return;
    }

    if ($uri === '/statements/password-candidates/reveal' && $method === 'POST') {
        $controller->revealPasswordCandidate();
        return;
    }

    if ($uri === '/statements/upload' && $method === 'POST') {
        $controller->uploadStatement();
        return;
    }

    Response::error('Invalid statements route', 404);
}
