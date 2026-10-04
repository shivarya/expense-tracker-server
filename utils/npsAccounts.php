<?php

/**
 * Upsert one NPS account. There can be two (Protean CRA, and the employer-paid one at KFintech CRA), and both
 * statements carry the same subscriber name — so match on PRAN, then on the CRA-labelled name, never on the
 * subscriber name (which made one account's statement overwrite the other's row). A Protean statement adopts
 * the pre-existing row that predates PRAN/labels, so the account the app already shows keeps its history.
 *
 * $fallbackAsOf ('Y-m-d', the statement email's date) is used when the statement's own "valuation as on" date
 * wasn't extracted. A statement older than the stored valuation_date is ignored: one sync can read several
 * months of statements, and one whose password was added later arrives after newer ones were applied.
 *
 * @return bool true when the account was inserted or updated
 */
function saveLongTermNps(Database $db, int $userId, array $nps, string $cra = 'Protean CRA', ?string $fallbackAsOf = null): bool
{
    $pran = preg_replace('/\D/', '', (string)($nps['pran'] ?? '')) ?? '';
    $pran = strlen($pran) === 12 ? $pran : '';
    $name = 'NPS · ' . $cra;
    $invested = (float)($nps['invested_amount'] ?? 0);
    $current = (float)($nps['current_value'] ?? 0);
    $interest = max(0.0, $current - $invested);
    $asOf = (string)($nps['as_of_date'] ?? '');
    $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) && strtotime($asOf) !== false ? $asOf : $fallbackAsOf;

    $select = "SELECT id, valuation_date FROM long_term_funds WHERE user_id = ? AND fund_type = 'nps'";
    $existing = $pran !== '' ? $db->fetchOne("$select AND pran_number = ? LIMIT 1", [$userId, $pran]) : null;
    $existing ??= $db->fetchOne("$select AND account_name = ? LIMIT 1", [$userId, $name]);
    if (!$existing && $cra === 'Protean CRA') {
        $existing = $db->fetchOne("$select AND account_name NOT LIKE 'NPS · %' ORDER BY id ASC LIMIT 1", [$userId]);
    }

    if ($existing) {
        $stored = $existing['valuation_date'] ?? null;
        if ($stored !== null && $asOf !== null && strtotime($asOf) < strtotime($stored)) {
            return false; // an older statement — the stored valuation is newer
        }
        $db->execute(
            "UPDATE long_term_funds
             SET account_name = ?, pran_number = ?, invested_amount = ?, current_value = ?, valuation_date = ?,
                 interest_earned = ?, last_updated = NOW()
             WHERE id = ?",
            [$name, $pran !== '' ? $pran : null, $invested, $current, $asOf, $interest, $existing['id']]
        );
        return true;
    }

    $db->execute(
        "INSERT INTO long_term_funds
            (user_id, fund_type, account_name, pran_number, invested_amount, current_value, valuation_date, interest_earned, status, created_at, last_updated)
         VALUES (?, 'nps', ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())",
        [$userId, $name, $pran !== '' ? $pran : null, $invested, $current, $asOf, $interest]
    );
    return true;
}
