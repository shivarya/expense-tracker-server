<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/database.php';

$db = getDB();

// 1. Category summary
$categories = $db->fetchAll(
    "SELECT id, name, icon, color, type FROM categories WHERE user_id = 1 ORDER BY name"
);
echo "=== CATEGORIES ===\n";
echo json_encode($categories, JSON_PRETTY_PRINT) . "\n\n";

// 2. Top merchant-to-category mappings (grouped)
$mappings = $db->fetchAll(
    "SELECT c.name as category, t.merchant, COUNT(*) as txn_count, 
            ROUND(SUM(t.amount),0) as total_amount,
            ROUND(AVG(t.amount),0) as avg_amount
     FROM transactions t 
     JOIN categories c ON t.category_id = c.id 
     WHERE t.user_id = 1 AND t.merchant IS NOT NULL AND t.merchant != ''
     GROUP BY c.name, t.merchant 
     ORDER BY c.name, txn_count DESC"
);
echo "=== MERCHANT-CATEGORY MAPPINGS ===\n";
echo json_encode($mappings, JSON_PRETTY_PRINT) . "\n\n";

// 3. Description keyword patterns per category
$descPatterns = $db->fetchAll(
    "SELECT c.name as category, 
            SUBSTRING_INDEX(t.description, ' ', 3) as desc_prefix,
            COUNT(*) as cnt
     FROM transactions t 
     JOIN categories c ON t.category_id = c.id 
     WHERE t.user_id = 1 AND t.description IS NOT NULL AND t.description != ''
     GROUP BY c.name, desc_prefix
     HAVING cnt >= 2
     ORDER BY c.name, cnt DESC"
);
echo "=== DESCRIPTION PATTERNS ===\n";
echo json_encode($descPatterns, JSON_PRETTY_PRINT) . "\n\n";

// 4. Source data patterns (merchant_category from parsed SMS/scrape)
$sourcePatterns = $db->fetchAll(
    "SELECT c.name as category,
            JSON_UNQUOTE(JSON_EXTRACT(t.source_data, '$.merchant_category')) as merchant_category,
            JSON_UNQUOTE(JSON_EXTRACT(t.source_data, '$.purpose')) as purpose,
            COUNT(*) as cnt
     FROM transactions t
     JOIN categories c ON t.category_id = c.id
     WHERE t.user_id = 1 AND t.source_data IS NOT NULL
     GROUP BY c.name, merchant_category, purpose
     HAVING cnt >= 2
     ORDER BY c.name, cnt DESC"
);
echo "=== SOURCE DATA PATTERNS ===\n";
echo json_encode($sourcePatterns, JSON_PRETTY_PRINT) . "\n\n";

// 5. Stats
$stats = $db->fetchOne(
    "SELECT COUNT(*) as total_txns, 
            COUNT(DISTINCT category_id) as categories_used,
            MIN(transaction_date) as earliest,
            MAX(transaction_date) as latest
     FROM transactions WHERE user_id = 1"
);
echo "=== STATS ===\n";
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
