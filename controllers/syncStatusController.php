<?php
/**
 * Sync Status Controller
 * 
 * Tracks what data has been scraped and synced to prevent duplicates.
 * Uses scraper_sync_log table to store non-PII identifiers.
 * 
 * Endpoints:
 * - GET /api/sync/status - Get sync status for a data type
 * - POST /api/sync/log - Record synced items
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';

class SyncStatusController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get sync status for a data type
     * GET /api/sync/status?type=stocks&source=zerodha
     * 
     * Response:
     * {
     *   "success": true,
     *   "synced_items": [
     *     {
     *       "source_identifier": "RELIANCE",
     *       "source_file_hash": "abc123...",
     *       "last_portal_date": "2026-02-01",
     *       "synced_at": "2026-02-02 10:30:00"
     *     },
     *     ...
     *   ],
     *   "total_count": 42,
     *   "last_sync": "2026-02-02 10:30:00"
     * }
     */
    public function getStatus() {
        try {
            // Verify JWT and get user_id
            $userId = verifyJWT();
            if (!$userId) {
                Response::unauthorized('Invalid or missing token');
                return;
            }
            
            // Get query params
            $dataType = $_GET['type'] ?? null;
            $source = $_GET['source'] ?? null;
            
            if (!$dataType) {
                Response::error('Missing required parameter: type', 400);
                return;
            }
            
            // Validate data type
            $validTypes = ['stocks', 'mutual_funds', 'fixed_deposits', 'long_term', 'transactions', 'emis', 'bank_accounts'];
            if (!in_array($dataType, $validTypes)) {
                Response::error('Invalid data type', 400);
                return;
            }
            
            // Build query
            $sql = "
                SELECT 
                    source_identifier,
                    source,
                    source_file_hash,
                    last_portal_date,
                    metadata,
                    synced_at
                FROM scraper_sync_log
                WHERE user_id = ? AND data_type = ?
            ";
            
            $params = [$userId, $dataType];
            
            if ($source) {
                $sql .= " AND source = ?";
                $params[] = $source;
            }
            
            $sql .= " ORDER BY synced_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get last sync time
            $lastSync = null;
            if (!empty($items)) {
                $lastSync = $items[0]['synced_at'];
            }
            
            Response::success([
                'synced_items' => $items,
                'total_count' => count($items),
                'last_sync' => $lastSync
            ]);
            
        } catch (Exception $e) {
            error_log("Sync status error: " . $e->getMessage());
            Response::error('Failed to get sync status: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Record synced items
     * POST /api/sync/log
     * 
     * Body:
     * {
     *   "data_type": "stocks",
     *   "source": "zerodha",
     *   "items": [
     *     {
     *       "source_identifier": "RELIANCE",
     *       "source_file_hash": "abc123...",
     *       "last_portal_date": "2026-02-01",
     *       "metadata": {"company_name": "Reliance Industries"}
     *     },
     *     ...
     *   ]
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "logged_count": 42,
     *   "updated_count": 5,
     *   "new_count": 37
     * }
     */
    public function logSync() {
        try {
            // Verify JWT and get user_id
            $userId = verifyJWT();
            if (!$userId) {
                Response::unauthorized('Invalid or missing token');
                return;
            }
            
            // Get request body
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['data_type']) || !isset($input['source']) || !isset($input['items'])) {
                Response::error('Missing required fields: data_type, source, items', 400);
                return;
            }
            
            $dataType = $input['data_type'];
            $source = $input['source'];
            $items = $input['items'];
            
            // Validate data type
            $validTypes = ['stocks', 'mutual_funds', 'fixed_deposits', 'long_term', 'transactions', 'emis', 'bank_accounts'];
            if (!in_array($dataType, $validTypes)) {
                Response::error('Invalid data type', 400);
                return;
            }
            
            $newCount = 0;
            $updatedCount = 0;
            
            // Insert or update each item
            $sql = "
                INSERT INTO scraper_sync_log 
                    (user_id, data_type, source, source_identifier, source_file_hash, last_portal_date, metadata, synced_at)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    source_file_hash = VALUES(source_file_hash),
                    last_portal_date = VALUES(last_portal_date),
                    metadata = VALUES(metadata),
                    synced_at = NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            
            foreach ($items as $item) {
                if (!isset($item['source_identifier'])) {
                    continue;
                }
                
                $sourceIdentifier = $item['source_identifier'];
                $sourceFileHash = $item['source_file_hash'] ?? null;
                $lastPortalDate = $item['last_portal_date'] ?? null;
                $metadata = isset($item['metadata']) ? json_encode($item['metadata']) : null;
                
                $stmt->execute([
                    $userId,
                    $dataType,
                    $source,
                    $sourceIdentifier,
                    $sourceFileHash,
                    $lastPortalDate,
                    $metadata
                ]);
                
                // Check if it was an insert or update
                if ($stmt->rowCount() == 1) {
                    $newCount++;
                } else if ($stmt->rowCount() == 2) {
                    $updatedCount++;
                }
            }
            
            Response::success([
                'logged_count' => count($items),
                'new_count' => $newCount,
                'updated_count' => $updatedCount
            ]);
            
        } catch (Exception $e) {
            error_log("Sync log error: " . $e->getMessage());
            Response::error('Failed to log sync: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Check if an item was already synced
     * POST /api/sync/check
     * 
     * Body:
     * {
     *   "data_type": "stocks",
     *   "source": "zerodha",
     *   "identifiers": ["RELIANCE", "TCS", "INFY"]
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "already_synced": ["RELIANCE", "TCS"],
     *   "not_synced": ["INFY"]
     * }
     */
    public function checkSync() {
        try {
            // Verify JWT and get user_id
            $userId = verifyJWT();
            if (!$userId) {
                Response::unauthorized('Invalid or missing token');
                return;
            }
            
            // Get request body
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['data_type']) || !isset($input['source']) || !isset($input['identifiers'])) {
                Response::error('Missing required fields: data_type, source, identifiers', 400);
                return;
            }
            
            $dataType = $input['data_type'];
            $source = $input['source'];
            $identifiers = $input['identifiers'];
            
            if (!is_array($identifiers) || empty($identifiers)) {
                Response::error('identifiers must be a non-empty array', 400);
                return;
            }
            
            // Query for existing items
            $placeholders = str_repeat('?,', count($identifiers) - 1) . '?';
            $sql = "
                SELECT DISTINCT source_identifier
                FROM scraper_sync_log
                WHERE user_id = ? AND data_type = ? AND source = ?
                AND source_identifier IN ($placeholders)
            ";
            
            $params = array_merge([$userId, $dataType, $source], $identifiers);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $alreadySynced = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $notSynced = array_diff($identifiers, $alreadySynced);
            
            Response::success([
                'already_synced' => array_values($alreadySynced),
                'not_synced' => array_values($notSynced)
            ]);
            
        } catch (Exception $e) {
            error_log("Sync check error: " . $e->getMessage());
            Response::error('Failed to check sync: ' . $e->getMessage(), 500);
        }
    }
}

// Route handler
$controller = new SyncStatusController();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $controller->getStatus();
        break;
        
    case 'POST':
        if (strpos($_SERVER['REQUEST_URI'], '/check') !== false) {
            $controller->checkSync();
        } else {
            $controller->logSync();
        }
        break;
        
    default:
        Response::error('Method not allowed', 405);
}
