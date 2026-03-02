-- Migration 003: Cleanup rogue categories created by SMS sync
-- Run via cPanel phpMyAdmin or SSH mysql client
-- Safe to run multiple times (UPDATE/DELETE only affect matching rows)
--
-- Step 1: Fix icons and colors on canonical categories to match app design
UPDATE categories SET icon = 'restaurant-outline',               color = '#FF4757' WHERE id = 1;
UPDATE categories SET icon = 'car-outline',                      color = '#FFA502' WHERE id = 2;
UPDATE categories SET icon = 'bag-handle-outline',               color = '#2B7BE5' WHERE id = 3;
UPDATE categories SET icon = 'tv-outline',                       color = '#9C27B0' WHERE id = 4;
UPDATE categories SET icon = 'flash-outline',                    color = '#FF6B6B' WHERE id = 5;
UPDATE categories SET icon = 'medical-outline',                  color = '#00C48C' WHERE id = 6;
UPDATE categories SET icon = 'school-outline',                   color = '#3F51B5' WHERE id = 7;
UPDATE categories SET icon = 'airplane-outline',                 color = '#FF9800' WHERE id = 8;
UPDATE categories SET icon = 'cart-outline',                     color = '#4CAF50' WHERE id = 9;
UPDATE categories SET icon = 'shield-checkmark-outline',         color = '#607D8B' WHERE id = 10;
UPDATE categories SET icon = 'home-outline',                     color = '#795548' WHERE id = 11;
UPDATE categories SET icon = 'person-outline',                   color = '#E91E63' WHERE id = 12;
UPDATE categories SET icon = 'trending-up-outline',              color = '#00BCD4' WHERE id = 13;
UPDATE categories SET icon = 'cash-outline',                     color = '#4CAF50' WHERE id = 14;
UPDATE categories SET icon = 'return-down-back-outline',         color = '#8BC34A' WHERE id = 15;
UPDATE categories SET icon = 'wallet-outline',                   color = '#CDDC39' WHERE id = 16;
UPDATE categories SET icon = 'swap-horizontal-outline',          color = '#9E9E9E' WHERE id = 17;
UPDATE categories SET icon = 'help-circle-outline',              color = '#BDBDBD' WHERE id = 18;
UPDATE categories SET icon = 'ellipsis-horizontal-circle-outline', color = '#FF5722' WHERE id = 51;

-- Step 2: Reassign transactions from rogue categories to canonical ones
--   Food-related rogues → 1
UPDATE transactions SET category_id = 1
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('food','Food','Food & Beverage','food_and_beverage','food_delivery','dining','Dining','Restaurant','Cafe') AND user_id IS NOT NULL);

--   Transport rogues → 2
UPDATE transactions SET category_id = 2
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('Transport','fuel','Fuel','Cab') AND user_id IS NOT NULL);

--   Entertainment → 4
UPDATE transactions SET category_id = 4
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('Streaming','subscription','OTT','Tata Play') AND user_id IS NOT NULL);

--   Bills rogues → 5
UPDATE transactions SET category_id = 5
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('Bill Payment','Bills','utilities','Utilities','Recharge','Mobile Recharge','Internet','broadband','Electricity','Bill Pay') AND user_id IS NOT NULL);

--   Healthcare rogues → 6
UPDATE transactions SET category_id = 6
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('Health','Medical','Pharmacy','Hospital') AND user_id IS NOT NULL);

--   EMI rogues → 11
UPDATE transactions SET category_id = 11
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('EMI','EMI Principal/Amortization','loan_payment','Amortization','Loan Installment') AND user_id IS NOT NULL);

--   Miscellaneous catch-all → 51
UPDATE transactions SET category_id = 51
WHERE category_id IN (SELECT id FROM categories WHERE name IN (
  'Other','other','Tax','Tax (IGST)','Tax component','interest','Fees',
  'Online Services','UPI','UPI Payment','UPI Transfer','Card','card_spend',
  'purchase','Purchase (tax/fee)','reversal','ATM','ATM Withdrawal','Cash Withdrawal'
) AND user_id IS NOT NULL);

--   Income catch-all → 16
UPDATE transactions SET category_id = 16
WHERE category_id IN (SELECT id FROM categories WHERE name IN ('Income') AND user_id IS NOT NULL);

-- Step 3: Delete the rogue user-created categories (only delete ones with no remaining transactions)
DELETE FROM categories
WHERE user_id IS NOT NULL
AND id NOT IN (SELECT DISTINCT category_id FROM transactions)
AND name IN (
  'food','Food','Food & Beverage','food_and_beverage','food_delivery',
  'dining','Dining','Restaurant','Cafe',
  'Transport','fuel','Fuel','Cab',
  'Streaming','subscription','OTT','Tata Play',
  'Bill Payment','Bills','utilities','Utilities','Recharge','Mobile Recharge',
  'Internet','broadband','Electricity','Bill Pay',
  'Health','Medical','Pharmacy','Hospital',
  'EMI','EMI Principal/Amortization','loan_payment','Amortization','Loan Installment',
  'Other','other','Tax','Tax (IGST)','Tax component','interest','Fees',
  'Online Services','UPI','UPI Payment','UPI Transfer','Card','card_spend',
  'purchase','Purchase (tax/fee)','reversal','ATM','ATM Withdrawal','Cash Withdrawal',
  'Income'
);

-- Step 4: Safety check — show any remaining non-canonical categories
SELECT id, name, type, icon, color, user_id,
       (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id) AS txn_count
FROM categories c
WHERE id NOT IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,51)
ORDER BY txn_count DESC, id;
