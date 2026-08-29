<?php

/**
 * CategoryResolver — canonical category logic, single source of truth.
 *
 * - Used by smsParserController to assign categories during SMS sync
 * - Used by /categories/auto-fix to heal rogue categories created by old SMS syncs
 * - Never creates new DB rows; always maps to one of the canonical IDs
 *
 * To add new merchant/category patterns, edit $NAME_ALIASES and $KEYWORD_MAP below.
 */
class CategoryResolver
{
    /** Canonical category definitions: id → [name, icon, color, type] */
    public const CANONICAL = [
        1  => ['name' => 'Food & Dining',    'icon' => 'restaurant-outline',                     'color' => '#FF4757', 'type' => 'expense'],
        2  => ['name' => 'Transportation',   'icon' => 'car-outline',                             'color' => '#FFA502', 'type' => 'expense'],
        3  => ['name' => 'Shopping',         'icon' => 'bag-handle-outline',                      'color' => '#2B7BE5', 'type' => 'expense'],
        4  => ['name' => 'Entertainment',    'icon' => 'tv-outline',                              'color' => '#9C27B0', 'type' => 'expense'],
        5  => ['name' => 'Bills & Utilities','icon' => 'flash-outline',                           'color' => '#FF6B6B', 'type' => 'expense'],
        6  => ['name' => 'Healthcare',       'icon' => 'medical-outline',                         'color' => '#00C48C', 'type' => 'expense'],
        7  => ['name' => 'Education',        'icon' => 'school-outline',                          'color' => '#3F51B5', 'type' => 'expense'],
        8  => ['name' => 'Travel',           'icon' => 'airplane-outline',                        'color' => '#FF9800', 'type' => 'expense'],
        9  => ['name' => 'Groceries',        'icon' => 'cart-outline',                            'color' => '#4CAF50', 'type' => 'expense'],
        10 => ['name' => 'Insurance',        'icon' => 'shield-checkmark-outline',                'color' => '#607D8B', 'type' => 'expense'],
        11 => ['name' => 'Rent/EMI',         'icon' => 'home-outline',                            'color' => '#795548', 'type' => 'expense'],
        12 => ['name' => 'Personal Care',    'icon' => 'person-outline',                          'color' => '#E91E63', 'type' => 'expense'],
        13 => ['name' => 'Investments',      'icon' => 'trending-up-outline',                     'color' => '#00BCD4', 'type' => 'investment'],
        14 => ['name' => 'Salary',           'icon' => 'cash-outline',                            'color' => '#4CAF50', 'type' => 'income'],
        15 => ['name' => 'Refund',           'icon' => 'return-down-back-outline',                'color' => '#8BC34A', 'type' => 'income'],
        16 => ['name' => 'Other Income',     'icon' => 'wallet-outline',                          'color' => '#CDDC39', 'type' => 'income'],
        17 => ['name' => 'Transfer',         'icon' => 'swap-horizontal-outline',                 'color' => '#9E9E9E', 'type' => 'transfer'],
        18 => ['name' => 'Uncategorized',    'icon' => 'help-circle-outline',                     'color' => '#BDBDBD', 'type' => 'expense'],
        51 => ['name' => 'Miscellaneous',    'icon' => 'ellipsis-horizontal-circle-outline',      'color' => '#FF5722', 'type' => 'expense'],
        52 => ['name' => 'Household Help',    'icon' => 'people-outline',                          'color' => '#8D6E63', 'type' => 'expense'],
        53 => ['name' => 'Kids Activities',   'icon' => 'trophy-outline',                          'color' => '#FF7043', 'type' => 'expense'],
        54 => ['name' => 'Software & Tools',  'icon' => 'laptop-outline',                          'color' => '#5C6BC0', 'type' => 'expense'],
        55 => ['name' => 'Donation',          'icon' => 'heart-outline',                           'color' => '#E74C3C', 'type' => 'expense'],
        56 => ['name' => 'Home Improvement',  'icon' => 'hammer-outline',                          'color' => '#8D6E63', 'type' => 'expense'],
    ];

    /**
     * Exact & alias name map → canonical ID (normalised lowercase → ID).
     *
     * ADD NEW PATTERNS HERE when SMS sync creates rogue categories.
     * Key: lowercase + underscores/hyphens/spaces all normalised.
     */
    public const NAME_ALIASES = [
        // ── Food & Dining (1) ───────────────────────────────────────────
        'food & dining'              => 1, 'food and dining'       => 1,
        'food'                       => 1, 'food & beverage'       => 1,
        'food_and_beverage'          => 1, 'food_delivery'         => 1,
        'dining'                     => 1, 'restaurant'            => 1,
        'cafe'                       => 1, 'swiggy'                => 1,
        'zomato'                     => 1,

        // ── Transportation (2) ──────────────────────────────────────────
        'transportation'             => 2, 'transport'             => 2,
        'fuel'                       => 2, 'cab'                   => 2,
        'petrol'                     => 2, 'taxi'                  => 2,
        'petrol fee'                 => 2, 'fuel fee'              => 2,
        'parking'                    => 2, 'parking fee'           => 2,
        'parking charges'            => 2,

        // ── Shopping (3) ────────────────────────────────────────────────
        'shopping'                   => 3, 'retail'                => 3,
        'e-commerce'                 => 3, 'ecommerce'             => 3,
        'purchase'                   => 3, 'card_purchase'         => 3,
        'card purchase'              => 3,
        'pooja material'             => 3, 'pooja materials'       => 3,
        'puja material'              => 3, 'puja materials'        => 3,

        // ── Entertainment (4) ───────────────────────────────────────────
        'entertainment'              => 4, 'streaming'             => 4,
        'subscription'               => 4, 'tata play'             => 4,
        'ott'                        => 4, 'movies'                => 4,
        'gaming'                     => 4,

        // ── Bills & Utilities (5) ───────────────────────────────────────
        'bills & utilities'          => 5, 'bills and utilities'   => 5,
        'bills'                      => 5, 'utilities'             => 5,
        'bill payment'               => 5, 'recharge'              => 5,
        'mobile recharge'            => 5, 'internet'              => 5,
        'broadband'                  => 5, 'electricity'           => 5,
        'bill pay'                   => 5, 'utility'               => 5,
        // Standing instruction / mandate patterns (auto-created by bank SMS)
        'standing_instruction_setup' => 5, 'standing instruction setup' => 5,
        'standing_instruction'       => 5, 'standing instruction'  => 5,
        'net_banking_si'             => 5, 'net banking si'        => 5,
        'si_setup'                   => 5, 'si setup'              => 5,
        'auto_pay'                   => 5, 'auto pay'              => 5,
        'autopay'                    => 5,

        // ── Healthcare (6) ──────────────────────────────────────────────
        'healthcare'                 => 6, 'health'                => 6,
        'medical'                    => 6, 'pharmacy'              => 6,
        'hospital'                   => 6,

        // ── Education (7) ───────────────────────────────────────────────
        'education'                  => 7,

        // ── Travel (8) ──────────────────────────────────────────────────
        'travel'                     => 8, 'flight'                => 8,
        'hotel'                      => 8,

        // ── Groceries (9) ───────────────────────────────────────────────
        'groceries'                  => 9, 'grocery'               => 9,
        'supermarket'                => 9, 'kirana'                => 9,

        // ── Insurance (10) ──────────────────────────────────────────────
        'insurance'                  => 10, 'premium'              => 10,

        // ── Rent/EMI (11) ───────────────────────────────────────────────
        'rent/emi'                   => 11, 'rent'                 => 11,
        'emi'                        => 11, 'loan_payment'         => 11,
        'emi principal/amortization' => 11, 'amortization'         => 11,
        'loan installment'           => 11,
        // e-mandate is a bank auto-debit mechanism, mostly used for EMI
        'e_mandate'                  => 11, 'e mandate'            => 11,
        'emandate'                   => 11, 'nach'                 => 11,
        'nach_debit'                 => 11, 'nach debit'           => 11,
        'auto_debit'                 => 11, 'auto debit'           => 11,
        'emi_conversion'             => 11, 'emi conversion'       => 11,

        // ── Personal Care (12) ──────────────────────────────────────────
        'personal care'              => 12, 'grooming'             => 12,
        'salon'                      => 12, 'spa'                  => 12,

        // ── Household Help (52) ─────────────────────────────────────────
        'household help'             => 52, 'household'            => 52,
        'maid'                       => 52, 'cook'                 => 52,
        'driver'                     => 52, 'domestic help'        => 52,
        'maid salary'                => 52, 'cook salary'          => 52,
        'driver salary'              => 52, 'bai'                  => 52,
        'sweeper'                    => 52, 'watchman'             => 52,
        'househelp'                  => 52, 'house help'           => 52,

        // ── Kids Activities (53) ─────────────────────────────────────────
        'kids activities'            => 53, 'kids activity'        => 53,
        'karate'                     => 53, 'dance'                => 53,
        'dance class'                => 53, 'karate class'         => 53,
        'sports'                     => 53, 'sport fee'            => 53,
        'extracurricular'            => 53, 'extra curricular'     => 53,
        'swimming'                   => 53, 'cricket'              => 53,
        'football'                   => 53, 'badminton'            => 53,
        'tennis'                     => 53, 'hobby class'          => 53,
        'kids class'                 => 53, 'abacus'               => 53,
        'drawing class'              => 53, 'music class'          => 53,

        // ── Software & Tools (54) ─────────────────────────────────────────
        'software & tools'           => 54, 'software and tools'   => 54,
        'software'                   => 54, 'saas'                 => 54,
        'hosting'                    => 54, 'domain'               => 54,
        'github'                     => 54, 'figma'                => 54,
        'notion'                     => 54, 'aws'                  => 54,
        'azure'                      => 54, 'gcp'                  => 54,
        'digitalocean'               => 54, 'vercel'               => 54,
        'netlify'                    => 54, 'jetbrains'            => 54,
        'cloudflare'                 => 54, 'openai'               => 54,
        'developer tools'            => 54, 'dev tools'            => 54,
        'cloud storage'              => 54, 'google workspace'     => 54,
        'microsoft 365'              => 54, 'dropbox'              => 54,
        'slack'                      => 54, 'zoom'                 => 54,

        // ── Donation (55) ──────────────────────────────────────────────
        'donation'                   => 55, 'charity'              => 55,
        'charitable'                 => 55, 'donate'               => 55,
        'charity donation'           => 55, 'charitable donation'  => 55,
        'ngo donation'               => 55, 'contribution'         => 55,
        'relief fund'                => 55, 'donation fund'        => 55,
        'temple donation'            => 55, 'mosque donation'      => 55,
        'church donation'            => 55,

        // ── Home Improvement (56) ──────────────────────────────────────
        'home improvement'           => 56, 'house repair'         => 56,
        'home repair'                => 56, 'home renovation'      => 56,
        'house renovation'           => 56, 'renovation'           => 56,
        'rennovation'                => 56, 'home maintenance'     => 56,
        'home decor'                 => 56, 'home decoration'      => 56,
        'home furnishing'            => 56, 'interior design'      => 56,
        'interior'                   => 56, 'carpenter'            => 56,
        'plumber'                    => 56, 'electrician'          => 56,
        'painting'                   => 56, 'paint work'           => 56,
        'hardware store'             => 56, 'tiles'                => 56,
        'civil work'                 => 56,

        // ── Investments (13) ────────────────────────────────────────────
        'investments'                => 13, 'investment'           => 13,
        'sip'                        => 13, 'mutual fund'          => 13,
        'stocks'                     => 13, 'nps'                  => 13,
        'ppf'                        => 13,

        // ── Salary (14) ─────────────────────────────────────────────────
        'salary'                     => 14, 'payroll'              => 14,        'neft_salary'                => 14, 'neft salary'          => 14,
        // ── Refund (15) ─────────────────────────────────────────────────
        'refund'                     => 15, 'reversal'             => 15,
        'cashback'                   => 15,

        // ── Other Income (16) ───────────────────────────────────────────
        'other income'               => 16, 'income'               => 16,
        'account_credit'             => 16, 'account credit'       => 16,
        'credit'                     => 16,
        'card_payment_received'      => 16, 'card payment received' => 16,

        // ── Transfer (17) ───────────────────────────────────────────────
        'transfer'                   => 17, 'self transfer'        => 17,
        'internal transfer'          => 17, 'fund_transfer'        => 17,
        'fund transfer'              => 17, 'neft transfer'        => 17,
        // Credit card BILL payments settle debt already counted when the
        // card's own line items were recorded -- counting the payment too
        // would double-count the same spend. Not the same as a plain card
        // purchase ('card'/'card_spend' below), which stays Miscellaneous.
        'card_payment'               => 17, 'card payment'         => 17,
        'credit card payment'        => 17, 'cc payment'           => 17,
        'card bill payment'          => 17, 'cc bill payment'      => 17,
        'credit card bill'           => 17, 'credit card bill payment' => 17,

        // ── Uncategorized (18) ──────────────────────────────────────────
        'uncategorized'              => 18, 'unknown'              => 18,

        // ── Miscellaneous (51) ──────────────────────────────────────────
        'miscellaneous'              => 51, 'other'                => 51,
        'upi'                        => 51, 'upi payment'          => 51,
        'upi_transfer'               => 51, 'upi transfer'         => 51,
        'card'                       => 51, 'card_spend'           => 51,
        'atm'                        => 51, 'atm_withdrawal'       => 51,
        'atm withdrawal'             => 51, 'cash withdrawal'      => 51,
        'tax'                        => 51, 'tax (igst)'           => 51,
        'tax component'              => 51,
        // interest credited → Other Income (not Miscellaneous)
        'interest'                   => 16, 'interest credit'      => 16,
        'interest credited'          => 16, 'fd interest'          => 16,
        'savings interest'           => 16, 'interest income'      => 16,
        'fees'                       => 51, 'online services'      => 51,
        'purchase (tax/fee)'         => 51, 'bank charge'          => 51,
        'bank charges'               => 51, 'service charge'       => 51,
        'service charges'            => 51, 'charges'              => 51,
    ];

    /**
     * Normalise a category name for alias lookup.
     * Converts to lowercase, collapses whitespace, keeps underscores for matching.
     */
    public static function normalise(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * Resolve a name string to a canonical category ID.
     * Returns null if no match found (caller decides fallback).
     */
    public static function resolveByName(string $name): ?int
    {
        $key = self::normalise($name);

        // Exact match
        if (isset(self::NAME_ALIASES[$key])) {
            return self::NAME_ALIASES[$key];
        }

        // Partial substring match (both directions)
        foreach (self::NAME_ALIASES as $alias => $id) {
            if (str_contains($key, $alias) || str_contains($alias, $key)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Resolve a transaction array (from AI) to a canonical category ID.
     * Priority: AI integer ID → AI name string → transaction_type fallback → 51
     */
    public static function resolveTransaction(array $transaction): int
    {
        // 1. AI returned a valid canonical integer ID
        if (!empty($transaction['category_id']) && isset(self::CANONICAL[(int)$transaction['category_id']])) {
            return (int)$transaction['category_id'];
        }

        // 2. AI returned a category name string
        if (!empty($transaction['category'])) {
            $resolved = self::resolveByName((string)$transaction['category']);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 3. Credit transactions default to Other Income
        if (($transaction['transaction_type'] ?? '') === 'credit') {
            return 16;
        }

        // 4. Final fallback
        return 51;
    }

    /**
     * Scan the DB for non-canonical categories belonging to a user,
     * reassign their transactions, and delete the now-empty rows.
     *
     * @param Database $db
     * @param int      $userId   Pass 0 to process ALL users (admin use)
     * @return array             ['fixed' => int, 'deleted' => int, 'details' => array]
     */
    public static function autoFix(object $db, int $userId = 0): array
    {
        $canonicalIds = array_keys(self::CANONICAL);
        $placeholders = implode(',', array_fill(0, count($canonicalIds), '?'));

        // Fetch all rogue (non-canonical) categories
        if ($userId > 0) {
            $rogues = $db->fetchAll(
                "SELECT id, name, type FROM categories WHERE id NOT IN ($placeholders) AND user_id = ?",
                [...$canonicalIds, $userId]
            );
        } else {
            $rogues = $db->fetchAll(
                "SELECT id, name, type FROM categories WHERE id NOT IN ($placeholders)",
                $canonicalIds
            );
        }

        $fixedCount   = 0;
        $deletedCount = 0;
        $details      = [];

        foreach ($rogues as $rogue) {
            // Resolve name → canonical ID
            $targetId = self::resolveByName($rogue['name']);

            // Fallback: type-based default
            if ($targetId === null) {
                $targetId = match($rogue['type'] ?? 'expense') {
                    'income'     => 16,
                    'investment' => 13,
                    'transfer'   => 17,
                    default      => 51, // Miscellaneous — not 52/53 as those need explicit mapping
                };
            }

            // Reassign transactions
            $affected = $db->execute(
                "UPDATE transactions SET category_id = ? WHERE category_id = ?",
                [$targetId, $rogue['id']]
            );

            // Delete the rogue category row
            $db->execute("DELETE FROM categories WHERE id = ?", [$rogue['id']]);
            $deletedCount++;

            if ($affected > 0) {
                $fixedCount += $affected;
            }

            $details[] = [
                'rogue_id'     => $rogue['id'],
                'rogue_name'   => $rogue['name'],
                'mapped_to_id' => $targetId,
                'mapped_to'    => self::CANONICAL[$targetId]['name'],
                'txn_moved'    => $affected,
            ];

            error_log("[CategoryResolver] Rogue '{$rogue['name']}' (id={$rogue['id']}) → {$targetId} ({$affected} txns moved)");
        }

        return [
            'fixed'   => $fixedCount,
            'deleted' => $deletedCount,
            'details' => $details,
        ];
    }
}
