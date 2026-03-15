<?php
/**
 * Duplicate Detection Controller
 * 
 * Detects potential duplicates across all data types:
 * - Transactions (same amount + date + account within 1 hour)
 * - Stocks (same symbol + user)
 * - Mutual Funds (same scheme_name + folio)
 * - Fixed Deposits (same bank + principal + maturity)
 * - EMIs (same name + amount + start_date)
 * - Bank Accounts (same account_number)
 * - Long Term Funds (same type + account_number)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';

class DuplicateController {
    private $db;
    private TransactionDuplicateDetector $duplicateDetector;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->duplicateDetector = new TransactionDuplicateDetector($this->db, null);
    }
    
    /**
     * Detect duplicates across all data types
     * POST /api/duplicates/detect
     * 
     * Query params:
     * - types: comma-separated list (transactions,stocks,mutual_funds,etc) or 'all'
     * 
     * Response:
     * {
     *   "success": true,
     *   "duplicates": {
     *     "transactions": [...],
     *     "stocks": [...],
     *     "mutual_funds": [...],
     *     ...
     *   },
     *   "summary": {
     *     "total_duplicates": 15,
     *     "by_type": {...}
     *   }
     * }
     */
    public function detect() {
        try {
            // Verify JWT and get user_id
            $tokenData = JWTHandler::requireAuth();
            $userId = $tokenData['userId'];
            
            // Get types to check
            $requestedTypes = $_GET['types'] ?? 'all';
            $types = $requestedTypes === 'all' 
                ? ['transactions', 'stocks', 'mutual_funds', 'fixed_deposits', 'emis', 'bank_accounts', 'long_term_funds']
                : array_map('trim', explode(',', $requestedTypes));
            
            $duplicates = [];
            $summary = ['total_duplicates' => 0, 'by_type' => []];
            
            // Check each type
            foreach ($types as $type) {
                $method = 'find' . str_replace('_', '', ucwords($type, '_')) . 'Duplicates';
                if (method_exists($this, $method)) {
                    $found = $this->$method($userId);
                    if (!empty($found)) {
                        $duplicates[$type] = $found;
                        $count = count($found);
                        $summary['by_type'][$type] = $count;
                        $summary['total_duplicates'] += $count;
                    }
                }
            }
            
            Response::success([
                'duplicates' => $duplicates,
                'summary' => $summary
            ]);
            
        } catch (Exception $e) {
            error_log("Duplicate detection error: " . $e->getMessage());
            Response::error('Failed to detect duplicates: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Preview duplicates between incoming payload items and existing DB records.
     * POST /api/duplicates/preview
     *
     * Request body:
     * {
     *   "type": "transactions|stocks|mutual_funds|emis",
     *   "items": [...]
     * }
     */
    public function preview() {
        try {
            $tokenData = JWTHandler::requireAuth();
            $userId = $tokenData['userId'];

            $input = getJsonInput();
            $type = $input['type'] ?? null;
            $items = $input['items'] ?? null;

            if (!$type || !is_array($items)) {
                Response::error('Invalid payload. Expected {type, items[]}', 400);
                return;
            }

            $validTypes = ['transactions', 'stocks', 'mutual_funds', 'emis'];
            if (!in_array($type, $validTypes, true)) {
                Response::error('Invalid type. Allowed: transactions, stocks, mutual_funds, emis', 400);
                return;
            }

            $results = [];
            $duplicateCount = 0;

            foreach ($items as $index => $item) {
                $matches = [];
                $isDuplicate = false;
                $duplicateConfidence = 0;
                $duplicateReason = null;

                if ($type === 'transactions') {
                    $evaluation = $this->previewTransactionEvaluation($userId, $item);
                    $matches = $evaluation['matches'];
                    $isDuplicate = (bool)$evaluation['is_duplicate'];
                    $duplicateConfidence = (int)$evaluation['confidence'];
                    $duplicateReason = $evaluation['reason'];
                } elseif ($type === 'stocks') {
                    $matches = $this->previewStockMatches($userId, $item);
                    $isDuplicate = !empty($matches);
                } elseif ($type === 'mutual_funds') {
                    $matches = $this->previewMutualFundMatches($userId, $item);
                    $isDuplicate = !empty($matches);
                } elseif ($type === 'emis') {
                    $matches = $this->previewEmiMatches($userId, $item);
                    $isDuplicate = !empty($matches);
                }

                if ($isDuplicate) {
                    $duplicateCount++;
                }

                $results[] = [
                    'index' => $index,
                    'is_duplicate' => $isDuplicate,
                    'match_count' => count($matches),
                    'duplicate_confidence' => $duplicateConfidence,
                    'duplicate_reason' => $duplicateReason,
                    'incoming' => $item,
                    'matches' => $matches
                ];
            }

            Response::success([
                'type' => $type,
                'total_items' => count($items),
                'duplicate_items' => $duplicateCount,
                'new_items' => count($items) - $duplicateCount,
                'results' => $results
            ], 'Duplicate preview completed');

        } catch (Exception $e) {
            error_log("Duplicate preview error: " . $e->getMessage());
            Response::error('Failed to preview duplicates: ' . $e->getMessage(), 500);
        }
    }

    private function previewTransactionMatches($userId, $item) {
        $evaluation = $this->previewTransactionEvaluation($userId, $item);
        return $evaluation['matches'];
    }

    private function previewTransactionEvaluation($userId, $item) {
        $evaluation = $this->duplicateDetector->evaluate((int)$userId, $item, [
            'ai_enabled' => false,
            'skip_threshold' => 76,
            'duplicate_threshold' => 51,
        ]);

        return [
            'is_duplicate' => (bool)($evaluation['is_duplicate'] ?? false),
            'confidence' => (int)($evaluation['confidence'] ?? 0),
            'reason' => $evaluation['reason'] ?? null,
            'matches' => $evaluation['matches'] ?? [],
        ];
    }

    private function previewStockMatches($userId, $item) {
        $symbol = trim((string)($item['symbol'] ?? ''));
        $isin = trim((string)($item['isin'] ?? ''));

        if ($symbol === '' && $isin === '') {
            return [];
        }

        try {
            $sql = "SELECT id, platform, symbol, company_name, quantity, current_value
                FROM stocks
                WHERE user_id = ?
                  AND ((? <> '' AND symbol = ?) OR (? <> '' AND isin = ?))
                ORDER BY id DESC
                LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $symbol, $symbol, $isin, $isin]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Fallback for deployments where stocks table does not yet have `isin` column
            $sql = "SELECT id, platform, symbol, company_name, quantity, current_value
                FROM stocks
                WHERE user_id = ?
                  AND (? <> '' AND symbol = ?)
                ORDER BY id DESC
                LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $symbol, $symbol]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    private function previewMutualFundMatches($userId, $item) {
        $folio = trim((string)($item['folio'] ?? ($item['folio_number'] ?? '')));
        $fundName = trim((string)($item['fund_name'] ?? ($item['name'] ?? '')));
        // Normalize folio: strip "/XX" suffix for matching
        $cleanFolio = preg_replace('/\/\d+$/', '', $folio);

        if ($cleanFolio === '' && $fundName === '') {
            return [];
        }

        $sql = "SELECT id, amc, fund_name, folio_number, units, current_value
                FROM mutual_funds
                WHERE user_id = ?
                  AND (
                    (? <> '' AND (folio_number = ? OR folio_number = ? OR REGEXP_REPLACE(folio_number, '/[0-9]+$', '') = ?))
                    OR (? <> '' AND fund_name = ?)
                  )
                ORDER BY id DESC
                LIMIT 10";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $cleanFolio, $folio, $cleanFolio, $cleanFolio, $fundName, $fundName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function previewEmiMatches($userId, $item) {
        $loanName = trim((string)($item['loan_name'] ?? ($item['name'] ?? '')));
        $startDate = $item['start_date'] ?? null;
        $principal = $item['principal_amount'] ?? ($item['amount'] ?? null);

        if ($loanName === '' || !$startDate || $principal === null) {
            return [];
        }

        $sql = "SELECT id, loan_name, principal_amount, start_date, tenure_months, status
                FROM emis
                WHERE user_id = ?
                  AND loan_name = ?
                  AND principal_amount = ?
                  AND start_date = ?
                ORDER BY id DESC
                LIMIT 10";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $loanName, $principal, $startDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate transactions
     * Criteria: Same amount, same account, within 1 hour
     */
    private function findTransactionsDuplicates($userId) {
        $sql = "
            SELECT 
                t1.id,
                t1.amount,
                t1.transaction_date,
                t1.transaction_type,
                t1.merchant,
                t1.reference_number,
                ba.bank,
                ba.account_number,
                GROUP_CONCAT(t2.id ORDER BY t2.id) as duplicate_ids,
                COUNT(t2.id) as duplicate_count,
                MAX(
                    CASE
                        WHEN t1.reference_number IS NOT NULL
                             AND t1.reference_number <> ''
                             AND t1.reference_number = t2.reference_number THEN 100
                        ELSE 80
                    END
                ) as duplicate_confidence
            FROM transactions t1
            JOIN bank_accounts ba ON t1.account_id = ba.id
            JOIN transactions t2 ON 
                t1.user_id = t2.user_id
                AND t1.account_id = t2.account_id
                AND t1.transaction_type = t2.transaction_type
                AND t1.amount = t2.amount
                AND ABS(TIMESTAMPDIFF(MINUTE, t1.transaction_date, t2.transaction_date)) <= 60
                AND (
                    (
                        t1.reference_number IS NOT NULL
                        AND t1.reference_number <> ''
                        AND t1.reference_number = t2.reference_number
                    )
                    OR (
                        NULLIF(TRIM(COALESCE(t1.merchant, '')), '') IS NOT NULL
                        AND LOWER(TRIM(COALESCE(t1.merchant, ''))) = LOWER(TRIM(COALESCE(t2.merchant, '')))
                    )
                )
                AND t1.id < t2.id
            WHERE t1.user_id = ?
              AND t1.deleted_at IS NULL
              AND t2.deleted_at IS NULL
            GROUP BY t1.id
            HAVING duplicate_count > 0
            ORDER BY t1.transaction_date DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate stocks
     * Criteria: Same symbol
     */
    private function findStocksDuplicates($userId) {
        $sql = "
            SELECT 
                s1.id, s1.symbol, s1.company_name, s1.platform, s1.quantity,
                GROUP_CONCAT(s2.id ORDER BY s2.id) as duplicate_ids,
                COUNT(s2.id) as duplicate_count
            FROM stocks s1
            JOIN stocks s2 ON 
                s1.user_id = s2.user_id 
                AND s1.symbol = s2.symbol 
                AND s1.id < s2.id
            WHERE s1.user_id = ?
            GROUP BY s1.id
            HAVING duplicate_count > 0
            ORDER BY s1.symbol
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate mutual funds
     * Criteria: Same scheme name and folio number (with /XX suffix normalization)
     */
    private function findMutualfundsDuplicates($userId) {
        $sql = "
            SELECT 
                m1.id, m1.fund_name, m1.folio_number, m1.amc, m1.units,
                GROUP_CONCAT(m2.id ORDER BY m2.id) as duplicate_ids,
                COUNT(m2.id) as duplicate_count
            FROM mutual_funds m1
            JOIN mutual_funds m2 ON 
                m1.user_id = m2.user_id 
                AND m1.fund_name = m2.fund_name 
                AND (
                    m1.folio_number = m2.folio_number
                    OR REGEXP_REPLACE(m1.folio_number, '/[0-9]+$', '') = REGEXP_REPLACE(m2.folio_number, '/[0-9]+$', '')
                )
                AND m1.id < m2.id
            WHERE m1.user_id = ?
            GROUP BY m1.id
            HAVING duplicate_count > 0
            ORDER BY m1.fund_name
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate fixed deposits
     * Criteria: Same bank, same principal amount, same maturity date
     */
    private function findFixeddepositsDuplicates($userId) {
        $sql = "
            SELECT 
                f1.id, f1.bank_name, f1.account_number, f1.principal_amount, 
                f1.maturity_date, f1.interest_rate,
                GROUP_CONCAT(f2.id ORDER BY f2.id) as duplicate_ids,
                COUNT(f2.id) as duplicate_count
            FROM fixed_deposits f1
            JOIN fixed_deposits f2 ON 
                f1.user_id = f2.user_id 
                AND f1.bank_name = f2.bank_name 
                AND f1.principal_amount = f2.principal_amount
                AND f1.maturity_date = f2.maturity_date
                AND f1.id < f2.id
            WHERE f1.user_id = ?
            GROUP BY f1.id
            HAVING duplicate_count > 0
            ORDER BY f1.maturity_date DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate EMIs
     * Criteria: Same name, same amount, same start date
     */
    private function findEmisDuplicates($userId) {
        $sql = "
            SELECT 
                e1.id, e1.name, e1.amount, e1.start_date, e1.end_date, e1.bank_name,
                GROUP_CONCAT(e2.id ORDER BY e2.id) as duplicate_ids,
                COUNT(e2.id) as duplicate_count
            FROM emis e1
            JOIN emis e2 ON 
                e1.user_id = e2.user_id 
                AND e1.name = e2.name 
                AND e1.amount = e2.amount
                AND e1.start_date = e2.start_date
                AND e1.id < e2.id
            WHERE e1.user_id = ?
            GROUP BY e1.id
            HAVING duplicate_count > 0
            ORDER BY e1.start_date DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate bank accounts
     * Criteria: Same account number
     */
    private function findBankaccountsDuplicates($userId) {
        $sql = "
            SELECT 
                b1.id, b1.bank_name, b1.account_number, b1.account_type, b1.balance,
                GROUP_CONCAT(b2.id ORDER BY b2.id) as duplicate_ids,
                COUNT(b2.id) as duplicate_count
            FROM bank_accounts b1
            JOIN bank_accounts b2 ON 
                b1.user_id = b2.user_id 
                AND b1.account_number = b2.account_number
                AND b1.id < b2.id
            WHERE b1.user_id = ?
            GROUP BY b1.id
            HAVING duplicate_count > 0
            ORDER BY b1.bank_name
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find duplicate long term funds
     * Criteria: Same type and account number
     */
    private function findLongtermfundsDuplicates($userId) {
        $sql = "
            SELECT 
                l1.id, l1.fund_type, l1.account_number, l1.current_value, 
                l1.organization_name,
                GROUP_CONCAT(l2.id ORDER BY l2.id) as duplicate_ids,
                COUNT(l2.id) as duplicate_count
            FROM long_term_funds l1
            JOIN long_term_funds l2 ON 
                l1.user_id = l2.user_id 
                AND l1.fund_type = l2.fund_type 
                AND l1.account_number = l2.account_number
                AND l1.id < l2.id
            WHERE l1.user_id = ?
            GROUP BY l1.id
            HAVING duplicate_count > 0
            ORDER BY l1.fund_type
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Route handler
$controller = new DuplicateController();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        if (strpos($_SERVER['REQUEST_URI'], '/detect') !== false) {
            $controller->detect();
        } elseif (strpos($_SERVER['REQUEST_URI'], '/preview') !== false) {
            $controller->preview();
        } else {
            Response::error('Invalid endpoint', 404);
        }
        break;
        
    default:
        Response::error('Method not allowed', 405);
}
