<?php

/**
 * Upsert one NPS account. There can be two (Protean CRA, and the employer-paid one at KFintech CRA), and both
 * statements carry the same subscriber name — so match on PRAN, then on the CRA-labelled name, never on the
 * subscriber name (which made one account's statement overwrite the other's row). A Protean statement adopts
 * the pre-existing row that predates PRAN/labels, so the account the app already shows keeps its history.
 */
function saveLongTermNps(Database $db, int $userId, array $nps, string $cra = 'Protean CRA'): void
{
    $pran = preg_replace('/\D/', '', (string)($nps['pran'] ?? '')) ?? '';
    $pran = strlen($pran) === 12 ? $pran : '';
    $name = 'NPS · ' . $cra;
    $invested = (float)($nps['invested_amount'] ?? 0);
    $current = (float)($nps['current_value'] ?? 0);
    $interest = max(0.0, $current - $invested);

    $existing = $pran !== ''
        ? $db->fetchOne("SELECT id FROM long_term_funds WHERE user_id = ? AND fund_type = 'nps' AND pran_number = ? LIMIT 1", [$userId, $pran])
        : null;
    $existing ??= $db->fetchOne("SELECT id FROM long_term_funds WHERE user_id = ? AND fund_type = 'nps' AND account_name = ? LIMIT 1", [$userId, $name]);
    if (!$existing && $cra === 'Protean CRA') {
        $existing = $db->fetchOne(
            "SELECT id FROM long_term_funds
             WHERE user_id = ? AND fund_type = 'nps' AND account_name NOT LIKE 'NPS · %'
             ORDER BY id ASC LIMIT 1",
            [$userId]
        );
    }

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
