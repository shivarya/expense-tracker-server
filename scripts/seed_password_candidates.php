<?php
/**
 * One-off CLI seeder for a user's statement-password candidate pool.
 *
 * Reuses the real StatementPasswordVault + the same HMAC dedupe hash the API
 * uses (controllers/statementsController::candidatePasswordHash), so rows are
 * identical to what the in-app "Add Password" flow writes. Must run on the host
 * where STATEMENT_PASSWORD_KEY is defined.
 *
 *   php seed_password_candidates.php list
 *   php seed_password_candidates.php seed <email> <absolute-path-to-passwords.json> [label]
 *
 * passwords.json: a JSON array of strings, or of {"password":"..","label":".."}.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/statementPasswordVault.php';

$mode = $argv[1] ?? '';
$db = Database::getInstance();
$keySet = defined('STATEMENT_PASSWORD_KEY') && trim((string)STATEMENT_PASSWORD_KEY) !== '';

if ($mode === 'list') {
    echo 'STATEMENT_PASSWORD_KEY: ' . ($keySet ? 'set' : 'MISSING') . "\n";
    $users = $db->fetchAll(
        "SELECT u.id, u.email,
                (SELECT COUNT(*) FROM statement_password_candidates c WHERE c.user_id = u.id) AS candidates
         FROM users u ORDER BY u.id"
    );
    foreach ($users as $u) {
        echo "id={$u['id']}  candidates={$u['candidates']}  {$u['email']}\n";
    }
    exit(0);
}

if ($mode === 'seed') {
    $email = trim((string)($argv[2] ?? ''));
    $jsonPath = (string)($argv[3] ?? '');
    $defaultLabel = trim((string)($argv[4] ?? 'Imported')) ?: null;

    if ($email === '' || $jsonPath === '') {
        fwrite(STDERR, "usage: php seed_password_candidates.php seed <email> <passwords.json> [label]\n");
        exit(2);
    }
    if (!$keySet) {
        fwrite(STDERR, "STATEMENT_PASSWORD_KEY is not configured on this host; cannot encrypt.\n");
        exit(3);
    }

    $user = $db->fetchOne("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
    if (!$user) {
        fwrite(STDERR, "No user found for email: {$email}\n");
        exit(4);
    }
    $userId = (int)$user['id'];

    $raw = @file_get_contents($jsonPath);
    if ($raw === false) {
        fwrite(STDERR, "Cannot read {$jsonPath}\n");
        exit(5);
    }
    $entries = json_decode($raw, true);
    if (!is_array($entries)) {
        fwrite(STDERR, "passwords file is not a JSON array\n");
        exit(6);
    }

    $added = 0; $skipped = 0; $invalid = 0;
    foreach ($entries as $entry) {
        $password = is_array($entry) ? (string)($entry['password'] ?? '') : (string)$entry;
        $label = (is_array($entry) && isset($entry['label']) && trim((string)$entry['label']) !== '')
            ? mb_substr(trim((string)$entry['label']), 0, 100)
            : $defaultLabel;

        $password = trim($password);
        if ($password === '' || mb_strlen($password) > 256) {
            $invalid++;
            continue;
        }

        $hash = hash_hmac('sha256', $password, (string)STATEMENT_PASSWORD_KEY);
        $encrypted = StatementPasswordVault::encrypt($password);

        $affected = $db->execute(
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

        if ((int)$affected >= 2) { $skipped++; } else { $added++; }
    }

    echo "user_id={$userId} email={$email}: added={$added} skipped_duplicates={$skipped} invalid={$invalid}\n";
    exit(0);
}

fwrite(STDERR, "usage: php seed_password_candidates.php list | seed <email> <passwords.json> [label]\n");
exit(1);
