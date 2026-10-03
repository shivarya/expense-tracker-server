<?php

/**
 * AccountBalance
 *
 * Sets bank_accounts.balance from a bank source (daily HDFC balance alert, closing balance of an SBI/IDFC
 * statement, Pluxee wallet balance) — the "cash" part of net worth. bank_accounts.last_synced records the
 * as-of time of the stored balance, so an older source (last month's statement) never overwrites a newer one
 * (yesterday's alert).
 */
class AccountBalance
{
    /**
     * @param string $asOf 'Y-m-d' or 'Y-m-d H:i:s' (IST)
     * @return bool true when the stored balance was replaced
     */
    public static function updateBankAccount(Database $db, int $userId, string $bank, string $last4, float $balance, string $asOf): bool
    {
        $last4 = substr(preg_replace('/\D/', '', $last4) ?? '', -4);
        if (strlen($last4) !== 4) {
            return false;
        }
        $row = $db->fetchOne(
            "SELECT id, last_synced FROM bank_accounts
             WHERE user_id = ? AND bank = ? AND account_type IN ('savings', 'current') AND account_number LIKE ?
             ORDER BY (status = 'active') DESC, id ASC LIMIT 1",
            [$userId, $bank, '%' . $last4]
        );
        $accountId = $row ? (int)$row['id'] : (int)$db->insert(
            "INSERT INTO bank_accounts (user_id, bank, account_number, account_type, balance) VALUES (?, ?, ?, 'savings', 0)",
            [$userId, $bank, 'XXXX' . $last4]
        );

        return self::apply($db, $accountId, $row['last_synced'] ?? null, $balance, $asOf);
    }

    /** Balance of a synthetic wallet account (bank 'other', account_number 'WALLET-<KEY>'). */
    public static function updateWallet(Database $db, int $userId, string $walletKey, string $name, float $balance, string $asOf): bool
    {
        $number = 'WALLET-' . strtoupper($walletKey);
        $row = $db->fetchOne(
            "SELECT id, last_synced FROM bank_accounts WHERE user_id = ? AND bank = 'other' AND account_number = ? LIMIT 1",
            [$userId, $number]
        );
        $accountId = $row ? (int)$row['id'] : (int)$db->insert(
            "INSERT INTO bank_accounts (user_id, bank, account_number, account_type, account_name, balance) VALUES (?, 'other', ?, 'savings', ?, 0)",
            [$userId, $number, $name]
        );

        return self::apply($db, $accountId, $row['last_synced'] ?? null, $balance, $asOf);
    }

    private static function apply(Database $db, int $accountId, ?string $storedAsOf, float $balance, string $asOf): bool
    {
        $asOfTs = strtotime(strlen($asOf) === 10 ? $asOf . ' 23:59:59' : $asOf);
        if ($asOfTs === false) {
            return false;
        }
        if ($storedAsOf !== null && $storedAsOf !== '' && strtotime($storedAsOf) > $asOfTs) {
            return false; // already holds a newer balance
        }
        $db->execute(
            "UPDATE bank_accounts SET balance = ?, last_synced = ? WHERE id = ?",
            [round($balance, 2), date('Y-m-d H:i:s', $asOfTs), $accountId]
        );
        return true;
    }
}
