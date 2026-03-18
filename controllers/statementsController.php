<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../utils/categoryResolver.php';
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

        if (!$this->isSupportedBank($bank)) {
            Response::error('Only SBI and ICICI statement password setup is currently supported.', 400);
        }

        if ($accountType !== 'credit_card') {
            Response::error('Only credit card statements are supported in this release.', 400);
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

        if (!$this->isSupportedBank($bank)) {
            Response::error('Only SBI and ICICI statement uploads are currently supported.', 400);
        }

        if ($accountType !== 'credit_card') {
            Response::error('Only credit card statement uploads are supported in this release.', 400);
        }

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
                    $parsedResult = $this->parseTransactionsByBank($bank, $text, $candidateCardContext);
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

            if ($effectiveCardLastFour !== ($cardLastFourFilter ?? '')) {
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

            $accountId = $this->getOrCreateCreditCardAccount($userId, $bank, $effectiveCardLastFour);
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

                    $transactionPayload = [
                        'bank' => strtoupper($bank),
                        'account_number' => $cardLastFour,
                        'transaction_type' => $normalizedType,
                        'amount' => $txn['amount'],
                        'merchant' => $normalizedMerchant,
                        'description' => $normalizedDescription,
                        'date' => $txn['transaction_date'],
                        'reference_number' => $txn['reference_number'],
                        'payment_method' => $paymentMethod,
                    ];

                    $duplicateCheck = $this->duplicateDetector->evaluate($userId, $transactionPayload, [
                        'account_id' => $accountId,
                        'ai_enabled' => true,
                        'skip_threshold' => 76,
                        'duplicate_threshold' => 51,
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

                    $this->db->insert(
                        "INSERT INTO transactions
                         (user_id, account_id, category_id, transaction_type, amount, merchant, description,
                          transaction_date, reference_number, source, payment_method, source_data, duplicate_score)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'statement_pdf', ?, ?, ?)",
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
        return in_array($bank, ['icici', 'sbi'], true);
    }

    private function getOrCreateCreditCardAccount(int $userId, string $bank, string $cardLastFour): int
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM bank_accounts
             WHERE user_id = ? AND bank = ? AND account_type = 'credit_card'
               AND (card_last_four = ? OR account_number LIKE ?)
             ORDER BY id DESC
             LIMIT 1",
            [$userId, $bank, $cardLastFour, '%' . $cardLastFour]
        );

        if ($existing && isset($existing['id'])) {
            return (int)$existing['id'];
        }

        return (int)$this->db->insert(
            "INSERT INTO bank_accounts
             (user_id, bank, account_type, account_number, account_name, card_last_four, status)
             VALUES (?, ?, 'credit_card', ?, ?, ?, 'active')",
            [
                $userId,
                $bank,
                'XXXX' . $cardLastFour,
                strtoupper($bank) . ' Card',
                $cardLastFour,
            ]
        );
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

    if ($uri === '/statements/upload' && $method === 'POST') {
        $controller->uploadStatement();
        return;
    }

    Response::error('Invalid statements route', 404);
}
