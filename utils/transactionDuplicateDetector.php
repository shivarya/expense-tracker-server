<?php

require_once __DIR__ . '/azureOpenAI.php';

class TransactionDuplicateDetector
{
    private PDO $pdo;
    private ?AzureOpenAI $ai;

    public function __construct(PDO $pdo, ?AzureOpenAI $ai = null)
    {
        $this->pdo = $pdo;
        $this->ai = $ai;
    }

    /**
     * Evaluate whether a transaction is duplicate using hybrid deterministic + AI scoring.
     *
     * Result fields:
     * - confidence: 0-100
     * - should_skip: true if confidence >= skip_threshold
     * - possible_duplicate: true if confidence in [duplicate_threshold, skip_threshold)
     * - ai_used/fallback_used for observability
     */
    public function evaluate(int $userId, array $transaction, array $options = []): array
    {
        $skipThreshold = (int)($options['skip_threshold'] ?? 76);
        $duplicateThreshold = (int)($options['duplicate_threshold'] ?? 51);
        $maxCandidates = max(1, (int)($options['max_candidates'] ?? 20));
        $aiEnabled = (bool)($options['ai_enabled'] ?? true);
        $excludeTransactionId = isset($options['exclude_transaction_id']) ? (int)$options['exclude_transaction_id'] : null;
        $accountId = isset($options['account_id']) ? (int)$options['account_id'] : null;
        $expandLinkedAccounts = (bool)($options['expand_linked_accounts'] ?? false);
        $sourceHint = strtolower(trim((string)($options['source_hint'] ?? '')));

        $normalized = $this->normalizeIncoming($transaction);

        if ($normalized['amount'] <= 0) {
            return $this->buildResult(false, false, 0, 'invalid_amount', null, [], false, true);
        }

        $referenceMatch = $this->findReferenceMatch($userId, $normalized['reference_number'], $excludeTransactionId);
        if ($referenceMatch !== null) {
            $match = $this->decorateMatchWithScore($referenceMatch, 100, 'reference_match');
            return $this->buildResult(true, true, 100, 'reference_match', (int)$referenceMatch['id'], [$match], false, false);
        }

        // Cross-card guard: the same statement line already imported under a DIFFERENT
        // card account is the same physical transaction mis-attributed (the reference
        // number differs only because the card last4 was wrong). Catch it by
        // (calendar date, amount, type, normalized raw description) instead of the ref.
        $crossCardMatch = $this->findStatementLineDuplicate($userId, $normalized, $accountId, $excludeTransactionId, $sourceHint);
        if ($crossCardMatch !== null) {
            $match = $this->decorateMatchWithScore($crossCardMatch, 100, 'cross_card_statement_match');
            return $this->buildResult(true, true, 100, 'cross_card_statement_match', (int)$crossCardMatch['id'], [$match], false, false);
        }

        $accountIds = [];
        if ($accountId !== null && $accountId > 0) {
            $accountIds[] = $accountId;
        }

        if ($expandLinkedAccounts || empty($accountIds)) {
            $linkedAccountIds = $this->resolveAccountIds($userId, $normalized['account_last4']);
            if (!empty($linkedAccountIds)) {
                $accountIds = array_values(array_unique(array_merge($accountIds, $linkedAccountIds)));
            }
        }

        $candidates = $this->fetchCandidateTransactions(
            $userId,
            $normalized,
            $accountIds,
            $maxCandidates,
            $excludeTransactionId
        );

        if (empty($candidates)) {
            return $this->buildResult(false, false, 0, 'no_candidates', null, [], false, false);
        }

        [$deterministicScore, $bestCandidate, $scoredMatches] = $this->scoreDeterministic(
            $normalized,
            $candidates,
            $accountIds,
            $sourceHint
        );

        $aiUsed = false;
        $fallbackUsed = false;
        $finalScore = $deterministicScore;
        $reason = 'deterministic';

        if ($aiEnabled && $this->ai !== null && $deterministicScore >= 35) {
            $aiUsed = true;
            $aiScore = $this->ai->scoreTransactionDuplicate(
                $this->buildAiTransactionPayload($normalized),
                array_slice($candidates, 0, 15)
            );

            if ($aiScore !== null) {
                $finalScore = $this->clampScore($aiScore);
                $reason = 'ai_score';
            } else {
                // Conservative fallback: skip only if deterministic evidence is very strong.
                $fallbackUsed = true;
                if ($deterministicScore >= 90) {
                    $finalScore = 80;
                    $reason = 'deterministic_strong_fallback';
                } else {
                    $finalScore = min(75, $deterministicScore);
                    $reason = 'deterministic_conservative_fallback';
                }
            }
        }

        $isDuplicate = $finalScore >= $duplicateThreshold;
        $shouldSkip = $finalScore >= $skipThreshold;

        return $this->buildResult(
            $isDuplicate,
            $shouldSkip,
            $finalScore,
            $reason,
            $bestCandidate !== null ? (int)$bestCandidate['id'] : null,
            $scoredMatches,
            $aiUsed,
            $fallbackUsed
        );
    }

    private function normalizeIncoming(array $transaction): array
    {
        $accountRaw = (string)($transaction['account_number'] ?? $transaction['account_last4'] ?? '');
        $accountDigits = preg_replace('/\D+/', '', $accountRaw);
        $accountLast4 = $accountDigits !== '' ? substr($accountDigits, -4) : '';

        return [
            'amount' => round((float)($transaction['amount'] ?? 0), 2),
            'transaction_type' => $this->normalizeTransactionType($transaction['transaction_type'] ?? null),
            'transaction_date' => $this->normalizeDateTime($transaction['date'] ?? $transaction['transaction_date'] ?? null),
            'merchant' => trim((string)($transaction['merchant'] ?? '')),
            'description' => trim((string)($transaction['description'] ?? '')),
            'reference_number' => trim((string)($transaction['reference_number'] ?? '')),
            'account_last4' => $accountLast4,
            'raw_description' => $this->extractRawDescription($transaction),
        ];
    }

    /**
     * Pull the original statement/SMS line out of source_data (accepts either an
     * already-decoded array or a JSON string).
     */
    private function extractRawDescription(array $transaction): string
    {
        $sourceData = $transaction['source_data'] ?? null;
        if (is_string($sourceData)) {
            $decoded = json_decode($sourceData, true);
            $sourceData = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($sourceData)) {
            $sourceData = [];
        }

        $raw = $sourceData['raw_description'] ?? $sourceData['raw_text'] ?? '';
        return trim((string)$raw);
    }

    private function normalizeTransactionType(?string $raw): string
    {
        $value = strtolower(trim((string)$raw));
        if ($value === 'expense') {
            return 'debit';
        }
        if ($value === 'income') {
            return 'credit';
        }
        if (in_array($value, ['debit', 'credit', 'transfer'], true)) {
            return $value;
        }
        return 'debit';
    }

    private function normalizeDateTime(?string $raw): string
    {
        if (!$raw) {
            return date('Y-m-d H:i:s');
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function hasMidnightTime(string $dateTime): bool
    {
        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return false;
        }

        return date('H:i:s', $timestamp) === '00:00:00';
    }

    private function findReferenceMatch(int $userId, string $referenceNumber, ?int $excludeTransactionId = null): ?array
    {
        if ($referenceNumber === '') {
            return null;
        }

        $sql = "SELECT id, account_id, transaction_type, amount, merchant, description, transaction_date, reference_number
                FROM transactions
                WHERE user_id = ?
                  AND deleted_at IS NULL
                  AND reference_number = ?";
        $params = [$userId, $referenceNumber];

        if ($excludeTransactionId !== null) {
            $sql .= " AND id <> ?";
            $params[] = $excludeTransactionId;
        }

        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Detect the same statement line already imported under another card account.
     *
     * Credit-card statements carry an exact raw line and an exact calendar date. If a
     * non-deleted transaction with the same (date, amount, type, normalized raw
     * description) already exists under a DIFFERENT account, the incoming row is that
     * same transaction re-filed onto the wrong card — skip it. This is independent of
     * reference_number, which differs when the card last4 was mis-detected.
     *
     * Same-account matches are deliberately ignored here: legitimate same-day repeats
     * on one card are left to the normal deterministic/AI scoring. When the resolved
     * account is unknown (e.g. preview), we require an exact raw-line match to stay
     * conservative.
     */
    private function findStatementLineDuplicate(int $userId, array $normalized, ?int $accountId, ?int $excludeTransactionId, string $sourceHint = ''): ?array
    {
        $incomingRaw = $this->normalizeText($normalized['raw_description']);
        $incomingMerchant = $this->normalizeText($normalized['merchant']);
        $incomingDescription = $this->normalizeText($normalized['description']);

        // Need at least one textual signal to avoid matching unrelated same-amount rows.
        if ($incomingRaw === '' && $incomingMerchant === '' && $incomingDescription === '') {
            return null;
        }

        $day = date('Y-m-d', strtotime($normalized['transaction_date']));
        $amount = $normalized['amount'];

        $sql = "SELECT id, account_id, transaction_type, amount, merchant, description, transaction_date, reference_number, source,
                       JSON_UNQUOTE(JSON_EXTRACT(source_data, '\$.raw_description')) AS raw_description
                FROM transactions
                WHERE user_id = ?
                  AND deleted_at IS NULL
                  AND transaction_type = ?
                  AND amount BETWEEN ? AND ?
                  AND DATE(transaction_date) = ?";
        $params = [$userId, $normalized['transaction_type'], $amount - 0.01, $amount + 0.01, $day];

        if ($excludeTransactionId !== null) {
            $sql .= " AND id <> ?";
            $params[] = $excludeTransactionId;
        }

        $sql .= " ORDER BY id DESC LIMIT 25";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }

        // When we know the incoming account, a different-account match can rely on the
        // merchant+description agreeing. With an unknown account, demand the raw line.
        $requireRawLineMatch = ($accountId === null);

        $incomingIsStatement = in_array($sourceHint, ['statement_pdf', 'email'], true);

        foreach ($rows as $row) {
            $sameAccount = ($accountId !== null && (int)$row['account_id'] === $accountId);
            $candIsSms = in_array(strtolower((string)($row['source'] ?? '')), ['sms', 'sms_webhook'], true);
            // Same card is normally left to deterministic/AI scoring, EXCEPT a statement
            // line re-importing a transaction already captured from SMS on the same card.
            if ($sameAccount && !($incomingIsStatement && $candIsSms)) {
                continue;
            }

            $candRaw = $this->normalizeText((string)($row['raw_description'] ?? ''));
            if ($incomingRaw !== '' && $candRaw !== '' && $incomingRaw === $candRaw) {
                return $row;
            }

            if (!$requireRawLineMatch) {
                // Same date+amount+type on another card with a recognisably similar
                // merchant/description is the same physical transaction captured from a
                // different source (e.g. a statement PDF vs the SMS). Fuzzy-match so
                // "Lifestyle" vs "Life Style Internati" / "Swiggy" vs "Swiggy Instamart"
                // are caught even though the text isn't identical.
                $candMerchant = (string)($row['merchant'] ?? '');
                $candDescription = (string)($row['description'] ?? '');
                if ($this->textsLikelySame($normalized['merchant'], $candMerchant)
                    || $this->textsLikelySame($normalized['description'], $candDescription)
                    || $this->textsLikelySame($normalized['merchant'], $candDescription)
                    || $this->textsLikelySame($normalized['description'], $candMerchant)) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function resolveAccountIds(int $userId, string $accountLast4): array
    {
        if ($accountLast4 === '') {
            return [];
        }

        $sql = "SELECT id
                FROM bank_accounts
                WHERE user_id = ?
                  AND (
                    account_number LIKE ?
                    OR card_last_four = ?
                  )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, '%' . $accountLast4, $accountLast4]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    private function fetchCandidateTransactions(
        int $userId,
        array $normalized,
        array $accountIds,
        int $maxCandidates,
        ?int $excludeTransactionId
    ): array {
        $date = $normalized['transaction_date'];
        $amount = $normalized['amount'];
        $txnType = $normalized['transaction_type'];

        $dateFrom = date('Y-m-d H:i:s', strtotime($date . ' -2 days'));
        $dateTo = date('Y-m-d H:i:s', strtotime($date . ' +2 days'));

        $sql = "SELECT id, account_id, transaction_type, amount, merchant, description, transaction_date, reference_number, source
                FROM transactions
                WHERE user_id = ?
                  AND deleted_at IS NULL
                  AND amount BETWEEN ? AND ?
                  AND transaction_date BETWEEN ? AND ?
                  AND transaction_type = ?";

        $params = [
            $userId,
            $amount - 0.01,
            $amount + 0.01,
            $dateFrom,
            $dateTo,
            $txnType,
        ];

        if (!empty($accountIds)) {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $sql .= " AND account_id IN ($placeholders)";
            foreach ($accountIds as $id) {
                $params[] = $id;
            }
        }

        if ($excludeTransactionId !== null) {
            $sql .= " AND id <> ?";
            $params[] = $excludeTransactionId;
        }

        $sql .= " ORDER BY ABS(TIMESTAMPDIFF(MINUTE, transaction_date, ?)) ASC, id DESC LIMIT " . (int)$maxCandidates;
        $params[] = $date;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    private function scoreDeterministic(array $normalized, array $candidates, array $accountIds, string $sourceHint = ''): array
    {
        $bestScore = 0;
        $bestCandidate = null;
        $scoredMatches = [];
        $isCreditCardScraper = $sourceHint === 'credit_card_scraper_ai';
        $incomingIsMidnight = $this->hasMidnightTime($normalized['transaction_date']);

        foreach ($candidates as $candidate) {
            $score = 0;
            $reasons = [];
            $candidateSource = strtolower(trim((string)($candidate['source'] ?? '')));
            $isSmsCandidate = in_array($candidateSource, ['sms', 'sms_webhook'], true);

            $amountDiff = abs((float)$candidate['amount'] - (float)$normalized['amount']);
            if ($amountDiff <= 0.01) {
                $score += 35;
                $reasons[] = 'amount';
            }

            $candidateType = strtolower((string)$candidate['transaction_type']);
            if ($candidateType === $normalized['transaction_type']) {
                $score += 15;
                $reasons[] = 'type';
            } else {
                $score -= 20;
            }

            if (!empty($accountIds) && in_array((int)$candidate['account_id'], $accountIds, true)) {
                $score += 20;
                $reasons[] = 'account';
            }

            $minutesDiff = abs((int)((strtotime($candidate['transaction_date']) - strtotime($normalized['transaction_date'])) / 60));
            if ($minutesDiff <= 5) {
                $score += 20;
                $reasons[] = 'time_5m';
            } elseif ($minutesDiff <= 30) {
                $score += 15;
                $reasons[] = 'time_30m';
            } elseif ($minutesDiff <= 120) {
                $score += 8;
                $reasons[] = 'time_2h';
            } elseif ($minutesDiff <= 1440) {
                $score += 4;
                $reasons[] = 'time_1d';
            }

            $merchantSimilarity = $this->textSimilarity($normalized['merchant'], (string)($candidate['merchant'] ?? ''));
            if ($merchantSimilarity >= 0.95) {
                $score += 25;
                $reasons[] = 'merchant_exact';
            } elseif ($merchantSimilarity >= 0.70) {
                $score += 15;
                $reasons[] = 'merchant_similar';
            } elseif ($merchantSimilarity >= 0.45) {
                $score += 7;
                $reasons[] = 'merchant_related';
            } elseif ($normalized['merchant'] !== '' && (string)($candidate['merchant'] ?? '') !== '') {
                $score -= 10;
            }

            $descriptionSimilarity = $this->textSimilarity($normalized['description'], (string)($candidate['description'] ?? ''));
            if ($descriptionSimilarity >= 0.80) {
                $score += 5;
                $reasons[] = 'description';
            }

            // Statement imports often have date-only midnight timestamps.
            // Boost likely CC statement-to-SMS matches when amount/type/merchant align within the same day.
            if (
                $isCreditCardScraper
                && $incomingIsMidnight
                && $minutesDiff <= 1440
                && $merchantSimilarity >= 0.70
                && $amountDiff <= 0.01
            ) {
                $score += 12;
                $reasons[] = 'cc_statement_day_match';
            }

            // For statement imports, same-day same-amount on the same linked card account is usually an SMS+statement duplicate.
            if (
                $isCreditCardScraper
                && $incomingIsMidnight
                && $isSmsCandidate
                && $minutesDiff <= 1440
                && $amountDiff <= 0.01
                && !empty($accountIds)
                && in_array((int)$candidate['account_id'], $accountIds, true)
            ) {
                $score += 16;
                $reasons[] = 'cc_statement_same_day_account';
            }

            $score = $this->clampScore($score);
            $matchReason = empty($reasons) ? 'amount_window' : implode('+', $reasons);
            $decoratedMatch = $this->decorateMatchWithScore($candidate, $score, $matchReason);
            $scoredMatches[] = $decoratedMatch;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        usort($scoredMatches, static function ($a, $b) {
            return ($b['score'] <=> $a['score']);
        });

        return [$bestScore, $bestCandidate, array_slice($scoredMatches, 0, 5)];
    }

    private function textSimilarity(string $a, string $b): float
    {
        $left = $this->normalizeText($a);
        $right = $this->normalizeText($b);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        if (str_contains($left, $right) || str_contains($right, $left)) {
            return 0.85;
        }

        $tokensA = array_values(array_filter(explode(' ', $left), static fn($t) => $t !== ''));
        $tokensB = array_values(array_filter(explode(' ', $right), static fn($t) => $t !== ''));

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $uniqueA = array_unique($tokensA);
        $uniqueB = array_unique($tokensB);

        $intersection = array_intersect($uniqueA, $uniqueB);
        $union = array_unique(array_merge($uniqueA, $uniqueB));

        if (empty($union)) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string)$value);
    }

    /**
     * Loose merchant/description equivalence for cross-source duplicate matching:
     * identical after normalisation, one space-stripped string contains the other,
     * or they share a significant (>=4 char) token.
     */
    private function textsLikelySame(string $a, string $b): bool
    {
        $a = $this->normalizeText($a);
        $b = $this->normalizeText($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $sa = str_replace(' ', '', $a);
        $sb = str_replace(' ', '', $b);
        if (strlen($sa) >= 4 && strlen($sb) >= 4 && (str_contains($sa, $sb) || str_contains($sb, $sa))) {
            return true;
        }

        $ta = array_filter(explode(' ', $a), static fn($t) => strlen($t) >= 4);
        $tb = array_filter(explode(' ', $b), static fn($t) => strlen($t) >= 4);
        return !empty(array_intersect($ta, $tb));
    }

    private function buildAiTransactionPayload(array $normalized): array
    {
        return [
            'amount' => $normalized['amount'],
            'transaction_type' => $normalized['transaction_type'],
            'transaction_date' => $normalized['transaction_date'],
            'merchant' => $normalized['merchant'],
            'description' => $normalized['description'],
            'reference_number' => $normalized['reference_number'],
        ];
    }

    private function decorateMatchWithScore(array $row, int $score, string $reason): array
    {
        return [
            'id' => (int)$row['id'],
            'amount' => (float)$row['amount'],
            'merchant' => $row['merchant'] ?? null,
            'description' => $row['description'] ?? null,
            'transaction_date' => $row['transaction_date'],
            'transaction_type' => $row['transaction_type'] ?? null,
            'reference_number' => $row['reference_number'] ?? null,
            'source' => $row['source'] ?? null,
            'score' => $score,
            'reason' => $reason,
        ];
    }

    private function clampScore(int $score): int
    {
        if ($score < 0) {
            return 0;
        }
        if ($score > 100) {
            return 100;
        }
        return $score;
    }

    private function buildResult(
        bool $isDuplicate,
        bool $shouldSkip,
        int $confidence,
        string $reason,
        ?int $matchedTransactionId,
        array $matches,
        bool $aiUsed,
        bool $fallbackUsed
    ): array {
        return [
            'is_duplicate' => $isDuplicate,
            'should_skip' => $shouldSkip,
            'possible_duplicate' => $isDuplicate && !$shouldSkip,
            'confidence' => $this->clampScore($confidence),
            'reason' => $reason,
            'matched_transaction_id' => $matchedTransactionId,
            'matches' => $matches,
            'ai_used' => $aiUsed,
            'fallback_used' => $fallbackUsed,
        ];
    }
}
