<?php
/**
 * Re-import one credit-card statement PDF through the normal ingest path (same parser, dedupe and
 * insert as the Gmail worker), trying the user's saved statement passwords:
 *   php scripts/reimport_card_statement.php <user_id> <bank> <card_last4> <pdf> [--dry-run]
 *
 * A failed earlier attempt for the same file does not block it (only `success` / `duplicate_upload`
 * uploads are skipped as already processed). --dry-run only opens and parses the PDF and prints the
 * rows and whether they reconcile; it writes nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/statementPasswordVault.php';
require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../controllers/statementsController.php';

[$script, $userId, $bank, $last4, $pdf] = array_pad($argv, 5, null);
if (!$pdf || !is_file($pdf)) {
    fwrite(STDERR, "usage: php scripts/reimport_card_statement.php <user_id> <bank> <card_last4> <pdf> [--dry-run]\n");
    exit(2);
}
$dryRun = in_array('--dry-run', $argv, true);

$db = Database::getInstance();
$passwords = [];
foreach ([
    'SELECT encrypted_password, iv, auth_tag FROM statement_password_candidates WHERE user_id = ?',
    'SELECT encrypted_password, iv, auth_tag FROM statement_passwords WHERE user_id = ?',
] as $sql) {
    foreach ($db->fetchAll($sql, [(int)$userId]) as $row) {
        try {
            $p = StatementPasswordVault::decrypt($row['encrypted_password'], $row['iv'], $row['auth_tag']);
            if ($p !== '') {
                $passwords[] = $p;
            }
        } catch (Throwable $e) {
            // a row that no longer decrypts is skipped, as in the worker
        }
    }
}
$passwords = array_values(array_unique($passwords));

$ctl = new StatementController();

if ($dryRun) {
    $parse = new ReflectionMethod($ctl, 'parseTransactionsByBank');
    $parse->setAccessible(true);
    $text = null;
    foreach (array_merge([''], $passwords) as $p) {
        try {
            $candidate = $ctl->extractTextFromPdf($pdf, (string)$p);
            if (trim($candidate) !== '') {
                $text = $candidate;
                break;
            }
        } catch (Throwable $e) {
        }
    }
    if ($text === null) {
        exit("Could not open the PDF with the saved passwords.\n");
    }
    $r = $parse->invoke($ctl, $bank, $text, $last4);
    printf("rows=%d reconciled=%s notes=%s\n", count($r['transactions']), var_export($r['reconciled'] ?? null, true), json_encode($r['notes'] ?? []));
    exit(($r['reconciled'] ?? null) === false ? 1 : 0);
}

$result = $ctl->ingestCreditCardPdf((int)$userId, $bank, $last4, $pdf, basename($pdf), $passwords);
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
