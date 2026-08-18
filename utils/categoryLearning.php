<?php

require_once __DIR__ . '/merchantPattern.php';

/**
 * CategoryLearning
 *
 * Learns merchant-pattern to category mappings from manual recategorization
 * and reuses them for future SMS parsing for the same user.
 *
 * Runtime source of truth is DB table `category_learning_rules`.
 * A generated markdown section in CATEGORY_INSTRUCTIONS.md is also updated
 * so learned rules are visible and reviewable.
 */
class CategoryLearning
{
    private const TABLE_NAME = 'category_learning_rules';
    private const START_MARKER = '<!-- AUTO_LEARNED_MAPPINGS_START -->';
    private const END_MARKER = '<!-- AUTO_LEARNED_MAPPINGS_END -->';
    private const MIN_PATTERN_LENGTH = 3;

    private static bool $tableEnsured = false;
    private static bool $tableUnavailable = false;

    /**
     * Learn one mapping from an already-reviewed transaction.
     */
    public static function learnFromTransaction(
        object $db,
        int $userId,
        array $transaction,
        int $categoryId,
        ?int $sourceTransactionId = null
    ): array {
        try {
            self::ensureTable($db);

            $merchant = (string)($transaction['merchant'] ?? '');
            $description = (string)($transaction['description'] ?? '');

            $pattern = self::buildPattern($merchant);
            if ($pattern === '') {
                $pattern = self::buildPattern($description);
            }

            if ($pattern === '' || self::isGenericPattern($pattern)) {
                return [
                    'learned' => false,
                    'reason' => 'pattern_not_usable',
                ];
            }

            $category = $db->fetchOne(
                "SELECT id, name FROM categories WHERE id = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1",
                [$categoryId, $userId]
            );

            if (!$category) {
                return [
                    'learned' => false,
                    'reason' => 'category_not_found',
                ];
            }

            $db->execute(
                "INSERT INTO " . self::TABLE_NAME . " (
                    user_id,
                    merchant_pattern,
                    category_id,
                    category_name_snapshot,
                    source_transaction_id,
                    learned_count,
                    last_used_at,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    category_id = VALUES(category_id),
                    category_name_snapshot = VALUES(category_name_snapshot),
                    source_transaction_id = VALUES(source_transaction_id),
                    learned_count = learned_count + 1,
                    last_used_at = NOW(),
                    updated_at = NOW()",
                [
                    $userId,
                    $pattern,
                    (int)$category['id'],
                    (string)$category['name'],
                    $sourceTransactionId,
                ]
            );

            self::syncInstructionFile($db);

            return [
                'learned' => true,
                'pattern' => $pattern,
                'category_id' => (int)$category['id'],
                'category_name' => (string)$category['name'],
            ];
        } catch (Exception $e) {
            error_log('[CategoryLearning] learnFromTransaction failed: ' . $e->getMessage());
            return [
                'learned' => false,
                'reason' => 'exception',
            ];
        }
    }

    /**
     * Resolve category from learned merchant patterns for this user.
     */
    public static function resolveFromTransaction(object $db, int $userId, array $transaction): ?int
    {
        try {
            self::ensureTable($db);

            $candidates = self::buildCandidateTexts($transaction);
            if (empty($candidates)) {
                return null;
            }

            $rules = $db->fetchAll(
                "SELECT r.category_id, r.merchant_pattern, r.learned_count
                 FROM " . self::TABLE_NAME . " r
                 JOIN categories c ON c.id = r.category_id
                 WHERE r.user_id = ? AND (c.user_id IS NULL OR c.user_id = ?)
                 ORDER BY r.learned_count DESC, CHAR_LENGTH(r.merchant_pattern) DESC",
                [$userId, $userId]
            );

            foreach ($candidates as $candidate) {
                foreach ($rules as $rule) {
                    $pattern = (string)($rule['merchant_pattern'] ?? '');
                    if (strlen($pattern) < self::MIN_PATTERN_LENGTH) {
                        continue;
                    }

                    if (str_contains($candidate, $pattern) || str_contains($pattern, $candidate)) {
                        self::touchRule($db, $userId, $pattern);
                        return (int)$rule['category_id'];
                    }
                }
            }

            return null;
        } catch (Exception $e) {
            error_log('[CategoryLearning] resolveFromTransaction failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function touchRule(object $db, int $userId, string $pattern): void
    {
        $db->execute(
            "UPDATE " . self::TABLE_NAME . " SET last_used_at = NOW() WHERE user_id = ? AND merchant_pattern = ? LIMIT 1",
            [$userId, $pattern]
        );
    }

    private static function ensureTable(object $db): void
    {
        if (self::$tableEnsured || self::$tableUnavailable) {
            return;
        }

        try {
            $db->execute(
                "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    merchant_pattern VARCHAR(255) NOT NULL,
                    category_id INT NOT NULL,
                    category_name_snapshot VARCHAR(100) NOT NULL,
                    source_transaction_id INT NULL,
                    learned_count INT NOT NULL DEFAULT 1,
                    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
                    FOREIGN KEY (source_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                    UNIQUE KEY uniq_user_pattern (user_id, merchant_pattern),
                    INDEX idx_user_category (user_id, category_id),
                    INDEX idx_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            self::$tableEnsured = true;
        } catch (Exception $e) {
            self::$tableUnavailable = true;
            throw $e;
        }
    }

    private static function buildCandidateTexts(array $transaction): array
    {
        $candidates = [];

        $merchant = self::buildPattern((string)($transaction['merchant'] ?? ''));
        if ($merchant !== '') {
            $candidates[] = $merchant;
        }

        $description = self::buildPattern((string)($transaction['description'] ?? ''));
        if ($description !== '' && !in_array($description, $candidates, true)) {
            $candidates[] = $description;
        }

        return $candidates;
    }

    private static function buildPattern(string $text): string
    {
        return MerchantPattern::normalize($text);
    }

    private static function isGenericPattern(string $pattern): bool
    {
        return MerchantPattern::isGeneric($pattern);
    }

    private static function syncInstructionFile(object $db): void
    {
        $filePath = __DIR__ . '/../CATEGORY_INSTRUCTIONS.md';
        if (!file_exists($filePath)) {
            return;
        }

        $rows = $db->fetchAll(
            "SELECT user_id, merchant_pattern, category_id, category_name_snapshot, learned_count,
                    DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
             FROM " . self::TABLE_NAME . "
             ORDER BY updated_at DESC
             LIMIT 100"
        );

        $tableLines = [
            '| User | Pattern | Category ID | Category Name | Uses | Last Updated |',
            '|---|---|---:|---|---:|---|',
        ];

        if (empty($rows)) {
            $tableLines[] = '| - | - | - | - | - | - |';
        } else {
            foreach ($rows as $row) {
                $userId = (int)($row['user_id'] ?? 0);
                $pattern = str_replace('|', '\\|', (string)($row['merchant_pattern'] ?? ''));
                $categoryId = (int)($row['category_id'] ?? 0);
                $categoryName = str_replace('|', '\\|', (string)($row['category_name_snapshot'] ?? ''));
                $uses = (int)($row['learned_count'] ?? 0);
                $updatedAt = (string)($row['updated_at'] ?? '');

                $tableLines[] = "| {$userId} | {$pattern} | {$categoryId} | {$categoryName} | {$uses} | {$updatedAt} |";
            }
        }

        $markerBlock = self::START_MARKER . "\n" . implode("\n", $tableLines) . "\n" . self::END_MARKER;

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $updatedContent = $content;
        $hasStart = str_contains($content, self::START_MARKER);
        $hasEnd = str_contains($content, self::END_MARKER);

        if ($hasStart && $hasEnd) {
            $pattern = '/' . preg_quote(self::START_MARKER, '/') . '.*?' . preg_quote(self::END_MARKER, '/') . '/s';
            $replaced = preg_replace($pattern, $markerBlock, $content, 1);
            if ($replaced !== null) {
                $updatedContent = $replaced;
            }
        } else {
            $section = "## Auto-Learned Merchant Overrides (Generated)\n\n" .
                "This section is auto-generated from manual category changes in the mobile app.\n" .
                "Runtime matching uses DB table `category_learning_rules` (per user) and this markdown is a review mirror.\n\n" .
                $markerBlock . "\n";

            $updatedContent = rtrim($content) . "\n\n---\n\n" . $section;
        }

        if ($updatedContent !== $content) {
            file_put_contents($filePath, $updatedContent, LOCK_EX);
        }
    }
}
