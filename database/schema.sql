-- Expense Tracker Database Schema
-- Created: January 19, 2026
-- Database: expense_tracker

CREATE DATABASE IF NOT EXISTS expense_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE expense_tracker;

-- ============================================
-- USERS TABLE (for future multi-user support)
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    google_id VARCHAR(255) UNIQUE,
    phone VARCHAR(20),
    profile_picture TEXT,
    gmail_token JSON COMMENT 'Gmail OAuth access token and refresh token',
    gmail_authorized_at TIMESTAMP NULL COMMENT 'When user authorized Gmail access',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SYNC JOBS TABLE (for background sync tracking)
-- ============================================
CREATE TABLE IF NOT EXISTS sync_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('sms', 'gmail', 'stocks', 'mutual_funds', 'all') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    progress INT DEFAULT 0 COMMENT 'Progress percentage (0-100)',
    total_items INT DEFAULT 0,
    processed_items INT DEFAULT 0,
    saved_items INT DEFAULT 0,
    skipped_items INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STOCKS TABLE (Zerodha, Groww)
-- ============================================
CREATE TABLE IF NOT EXISTS stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    platform ENUM('zerodha', 'groww', 'other') NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    company_name VARCHAR(255),
    quantity DECIMAL(15, 4) DEFAULT 0,
    average_price DECIMAL(15, 2) DEFAULT 0,
    invested_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    current_value DECIMAL(15, 2) NOT NULL DEFAULT 0,
    current_price DECIMAL(15, 2),
    gain_loss_amount DECIMAL(15, 2) GENERATED ALWAYS AS (current_value - invested_amount) STORED,
    gain_loss_percent DECIMAL(10, 2) GENERATED ALWAYS AS (
        CASE WHEN invested_amount > 0 
        THEN ((current_value - invested_amount) / invested_amount * 100) 
        ELSE 0 END
    ) STORED,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_platform (user_id, platform),
    INDEX idx_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MUTUAL FUNDS TABLE (CAMS/KFintech)
-- ============================================
CREATE TABLE IF NOT EXISTS mutual_funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fund_name VARCHAR(500) NOT NULL,
    folio_number VARCHAR(100) NOT NULL,
    amc VARCHAR(100) NOT NULL COMMENT 'Asset Management Company (HDFC, ICICI, Nippon, etc)',
    scheme_code VARCHAR(50),
    isin VARCHAR(50),
    portal_url TEXT COMMENT 'AMC portal URL for manual checking',
    units DECIMAL(15, 4) DEFAULT 0,
    nav DECIMAL(15, 4) DEFAULT 0 COMMENT 'Net Asset Value per unit',
    invested_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    current_value DECIMAL(15, 2) NOT NULL DEFAULT 0,
    gain_loss_amount DECIMAL(15, 2) GENERATED ALWAYS AS (current_value - invested_amount) STORED,
    gain_loss_percent DECIMAL(10, 2) GENERATED ALWAYS AS (
        CASE WHEN invested_amount > 0 
        THEN ((current_value - invested_amount) / invested_amount * 100) 
        ELSE 0 END
    ) STORED,
    plan_type ENUM('direct', 'regular') DEFAULT 'direct',
    option_type ENUM('growth', 'dividend', 'idcw') DEFAULT 'growth',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_amc (user_id, amc),
    INDEX idx_folio (folio_number),
    UNIQUE KEY unique_folio_fund (user_id, folio_number, fund_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- FIXED DEPOSITS TABLE (Bank FDs)
-- ============================================
CREATE TABLE IF NOT EXISTS fixed_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank ENUM('hdfc', 'sbi', 'icici', 'idfc', 'rbl', 'axis', 'kotak', 'other') NOT NULL,
    fd_number VARCHAR(100),
    account_number VARCHAR(50),
    principal_amount DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL COMMENT 'Annual interest rate percentage',
    tenure_months INT NOT NULL,
    start_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    maturity_value DECIMAL(15, 2) NOT NULL,
    amount_percent DECIMAL(10, 2) GENERATED ALWAYS AS (
        CASE WHEN maturity_value > 0 
        THEN ((maturity_value - principal_amount) / principal_amount * 100) 
        ELSE 0 END
    ) STORED,
    status ENUM('active', 'matured', 'premature_withdrawal') DEFAULT 'active',
    auto_renewal BOOLEAN DEFAULT FALSE,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_bank (user_id, bank),
    INDEX idx_maturity_date (maturity_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LONG TERM FUNDS TABLE (PF, NPS, Sukanya, PPF)
-- ============================================
CREATE TABLE IF NOT EXISTS long_term_funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fund_type ENUM('pf', 'nps', 'sukanya', 'ppf', 'vpf') NOT NULL,
    account_name VARCHAR(255) NOT NULL COMMENT 'Employer name for PF, Account holder for others',
    account_number VARCHAR(100),
    pran_number VARCHAR(50) COMMENT 'For NPS',
    uan_number VARCHAR(50) COMMENT 'For PF/UAN',
    invested_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    current_value DECIMAL(15, 2) NOT NULL DEFAULT 0,
    employer_contribution DECIMAL(15, 2) DEFAULT 0 COMMENT 'For PF',
    interest_earned DECIMAL(15, 2) DEFAULT 0,
    maturity_date DATE COMMENT 'Expected maturity date',
    maturity_value DECIMAL(15, 2),
    lock_in_period_years INT COMMENT 'Lock-in period in years',
    start_date DATE,
    last_contribution_date DATE,
    status ENUM('active', 'matured', 'closed') DEFAULT 'active',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_type (user_id, fund_type),
    INDEX idx_maturity_date (maturity_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- BANK ACCOUNTS TABLE (Savings & Credit Cards)
-- ============================================
CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank ENUM('hdfc', 'sbi', 'icici', 'idfc', 'rbl', 'axis', 'kotak', 'other') NOT NULL,
    account_type ENUM('savings', 'current', 'credit_card') NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    account_name VARCHAR(255),
    ifsc_code VARCHAR(20),
    branch VARCHAR(255),
    balance DECIMAL(15, 2) DEFAULT 0 COMMENT 'Current balance for savings/current',
    credit_limit DECIMAL(15, 2) DEFAULT 0 COMMENT 'For credit cards',
    available_credit DECIMAL(15, 2) DEFAULT 0 COMMENT 'For credit cards',
    billing_date INT COMMENT 'Day of month for credit card billing',
    due_date INT COMMENT 'Day of month for credit card payment',
    card_last_four VARCHAR(4) COMMENT 'Last 4 digits of card number',
    is_primary BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'closed', 'frozen') DEFAULT 'active',
    last_synced TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_bank (user_id, bank),
    INDEX idx_account_type (account_type),
    INDEX idx_status (status),
    UNIQUE KEY unique_account (user_id, bank, account_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CATEGORIES TABLE (Expense categories)
-- ============================================
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'NULL for system categories',
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'shopping-outline',
    color VARCHAR(20) DEFAULT '#2196F3',
    type ENUM('expense', 'income', 'investment', 'transfer') DEFAULT 'expense',
    monthly_budget DECIMAL(15, 2) DEFAULT 0,
    is_system BOOLEAN DEFAULT FALSE COMMENT 'System categories cannot be deleted',
    parent_category_id INT NULL COMMENT 'For sub-categories',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_user_type (user_id, type),
    INDEX idx_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TRANSACTIONS TABLE (All expenses/income)
-- ============================================
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    category_id INT NOT NULL,
    transaction_type ENUM('debit', 'credit', 'transfer') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    merchant VARCHAR(500),
    description TEXT,
    transaction_date DATETIME NOT NULL,
    reference_number VARCHAR(100),
    source ENUM('sms', 'email', 'web_scrape', 'manual') NOT NULL,
    source_data JSON COMMENT 'Original SMS/email content',
    is_emi BOOLEAN DEFAULT FALSE,
    emi_id INT NULL COMMENT 'Link to EMI if this is an EMI payment',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_user_date (user_id, transaction_date),
    INDEX idx_account_date (account_id, transaction_date),
    INDEX idx_category (category_id),
    INDEX idx_type_date (transaction_type, transaction_date),
    INDEX idx_merchant (merchant),
    INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EMIS TABLE (Loan EMI tracking)
-- ============================================
CREATE TABLE IF NOT EXISTS emis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL COMMENT 'Bank account from which EMI is deducted',
    loan_name VARCHAR(255) NOT NULL,
    loan_type ENUM('home', 'car', 'personal', 'education', 'credit_card', 'other') NOT NULL,
    bank ENUM('hdfc', 'sbi', 'icici', 'idfc', 'rbl', 'axis', 'kotak', 'other') NOT NULL,
    principal_amount DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL,
    tenure_months INT NOT NULL,
    emi_amount DECIMAL(15, 2) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    remaining_months INT NOT NULL,
    remaining_principal DECIMAL(15, 2),
    due_date INT NOT NULL COMMENT 'Day of month when EMI is due',
    status ENUM('active', 'paid', 'foreclosed') DEFAULT 'active',
    auto_debit BOOLEAN DEFAULT TRUE,
    last_payment_date DATE,
    next_payment_date DATE,
    total_paid DECIMAL(15, 2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT,
    INDEX idx_user_status (user_id, status),
    INDEX idx_next_payment (next_payment_date),
    INDEX idx_bank (bank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SCRAPE LOGS TABLE (Track scraping activities)
-- ============================================
CREATE TABLE IF NOT EXISTS scrape_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    source_type ENUM('stocks', 'mutual_funds', 'fixed_deposits', 'pf', 'nps', 'bank_sms', 'email', 'web_portal') NOT NULL,
    source_name VARCHAR(255) NOT NULL COMMENT 'zerodha, hdfc, cams, etc',
    status ENUM('success', 'partial', 'failed', 'in_progress') NOT NULL,
    records_processed INT DEFAULT 0,
    records_created INT DEFAULT 0,
    records_updated INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    error_message TEXT,
    execution_time_seconds INT,
    metadata JSON COMMENT 'Additional details like date range, filters used',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_source (user_id, source_type),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SCRAPER SYNC LOG TABLE (Track synced items to detect duplicates)
-- ============================================
CREATE TABLE IF NOT EXISTS scraper_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    data_type ENUM('stocks', 'mutual_funds', 'fixed_deposits', 'long_term', 'transactions', 'emis', 'bank_accounts') NOT NULL,
    source VARCHAR(100) NOT NULL COMMENT 'zerodha, groww, cams, kfintech, hdfc-cc, icici-cc, epfo, nps, etc',
    source_identifier VARCHAR(500) NOT NULL COMMENT 'symbol, folio, reference_number - non-PII identifiers',
    source_file_hash VARCHAR(64) COMMENT 'SHA-256 hash of source file content',
    last_portal_date DATE COMMENT 'Last transaction/update date visible on source portal',
    metadata JSON COMMENT 'Additional non-PII data: statement_date, item_count, etc',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sync_item (user_id, data_type, source, source_identifier),
    INDEX idx_user_type_source (user_id, data_type, source),
    INDEX idx_synced_at (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ASSET SUMMARY VIEW (Portfolio overview)
-- ============================================
CREATE OR REPLACE VIEW v_asset_summary AS
SELECT 
    u.id AS user_id,
    'Stocks' AS category,
    COALESCE(SUM(s.invested_amount), 0) AS invested_amount,
    COALESCE(SUM(s.current_value), 0) AS current_value,
    CASE 
        WHEN SUM(s.invested_amount) > 0 
        THEN ((SUM(s.current_value) - SUM(s.invested_amount)) / SUM(s.invested_amount) * 100)
        ELSE 0 
    END AS gain_loss_percent,
    COUNT(s.id) AS item_count
FROM users u
LEFT JOIN stocks s ON u.id = s.user_id
GROUP BY u.id

UNION ALL

SELECT 
    u.id AS user_id,
    'Mutual Funds' AS category,
    COALESCE(SUM(mf.invested_amount), 0) AS invested_amount,
    COALESCE(SUM(mf.current_value), 0) AS current_value,
    CASE 
        WHEN SUM(mf.invested_amount) > 0 
        THEN ((SUM(mf.current_value) - SUM(mf.invested_amount)) / SUM(mf.invested_amount) * 100)
        ELSE 0 
    END AS gain_loss_percent,
    COUNT(mf.id) AS item_count
FROM users u
LEFT JOIN mutual_funds mf ON u.id = mf.user_id
GROUP BY u.id

UNION ALL

SELECT 
    u.id AS user_id,
    'Fixed Deposits' AS category,
    COALESCE(SUM(fd.principal_amount), 0) AS invested_amount,
    COALESCE(SUM(fd.maturity_value), 0) AS current_value,
    CASE 
        WHEN SUM(fd.principal_amount) > 0 
        THEN ((SUM(fd.maturity_value) - SUM(fd.principal_amount)) / SUM(fd.principal_amount) * 100)
        ELSE 0 
    END AS gain_loss_percent,
    COUNT(fd.id) AS item_count
FROM users u
LEFT JOIN fixed_deposits fd ON u.id = fd.user_id AND fd.status = 'active'
GROUP BY u.id

UNION ALL

SELECT 
    u.id AS user_id,
    'Long Term Funds' AS category,
    COALESCE(SUM(ltf.invested_amount), 0) AS invested_amount,
    COALESCE(SUM(ltf.current_value), 0) AS current_value,
    CASE 
        WHEN SUM(ltf.invested_amount) > 0 
        THEN ((SUM(ltf.current_value) - SUM(ltf.invested_amount)) / SUM(ltf.invested_amount) * 100)
        ELSE 0 
    END AS gain_loss_percent,
    COUNT(ltf.id) AS item_count
FROM users u
LEFT JOIN long_term_funds ltf ON u.id = ltf.user_id AND ltf.status = 'active'
GROUP BY u.id;

-- ============================================
-- INSERT DEFAULT CATEGORIES
-- ============================================
INSERT INTO categories (user_id, name, icon, color, type, is_system, display_order) VALUES
(NULL, 'Food & Dining', 'restaurant-outline', '#FF5722', 'expense', TRUE, 1),
(NULL, 'Transportation', 'car-outline', '#2196F3', 'expense', TRUE, 2),
(NULL, 'Shopping', 'cart-outline', '#E91E63', 'expense', TRUE, 3),
(NULL, 'Entertainment', 'film-outline', '#9C27B0', 'expense', TRUE, 4),
(NULL, 'Bills & Utilities', 'receipt-outline', '#FF9800', 'expense', TRUE, 5),
(NULL, 'Healthcare', 'medkit-outline', '#4CAF50', 'expense', TRUE, 6),
(NULL, 'Education', 'school-outline', '#00BCD4', 'expense', TRUE, 7),
(NULL, 'Travel', 'airplane-outline', '#3F51B5', 'expense', TRUE, 8),
(NULL, 'Groceries', 'basket-outline', '#8BC34A', 'expense', TRUE, 9),
(NULL, 'Insurance', 'shield-checkmark-outline', '#795548', 'expense', TRUE, 10),
(NULL, 'Rent/EMI', 'home-outline', '#607D8B', 'expense', TRUE, 11),
(NULL, 'Personal Care', 'sparkles-outline', '#F06292', 'expense', TRUE, 12),
(NULL, 'Investments', 'trending-up-outline', '#4CAF50', 'investment', TRUE, 13),
(NULL, 'Salary', 'cash-outline', '#4CAF50', 'income', TRUE, 14),
(NULL, 'Refund', 'arrow-undo-outline', '#00BCD4', 'income', TRUE, 15),
(NULL, 'Other Income', 'wallet-outline', '#8BC34A', 'income', TRUE, 16),
(NULL, 'Transfer', 'swap-horizontal-outline', '#9E9E9E', 'transfer', TRUE, 17),
(NULL, 'Uncategorized', 'help-circle-outline', '#9E9E9E', 'expense', TRUE, 18);

-- ============================================
-- STORED PROCEDURES
-- ============================================

-- Calculate portfolio total
DELIMITER //
CREATE PROCEDURE sp_calculate_portfolio_total(IN p_user_id INT)
BEGIN
    SELECT 
        SUM(invested_amount) AS total_invested,
        SUM(current_value) AS total_current_value,
        CASE 
            WHEN SUM(invested_amount) > 0 
            THEN ((SUM(current_value) - SUM(invested_amount)) / SUM(invested_amount) * 100)
            ELSE 0 
        END AS overall_gain_loss_percent
    FROM (
        SELECT invested_amount, current_value FROM stocks WHERE user_id = p_user_id
        UNION ALL
        SELECT invested_amount, current_value FROM mutual_funds WHERE user_id = p_user_id
        UNION ALL
        SELECT principal_amount AS invested_amount, maturity_value AS current_value 
        FROM fixed_deposits WHERE user_id = p_user_id AND status = 'active'
        UNION ALL
        SELECT invested_amount, current_value FROM long_term_funds WHERE user_id = p_user_id AND status = 'active'
    ) AS all_investments;
END //
DELIMITER ;

-- Calculate monthly expenses by category
DELIMITER //
CREATE PROCEDURE sp_monthly_expenses_by_category(
    IN p_user_id INT, 
    IN p_start_date DATE, 
    IN p_end_date DATE
)
BEGIN
    SELECT 
        c.name AS category_name,
        c.color AS category_color,
        c.icon AS category_icon,
        COUNT(t.id) AS transaction_count,
        SUM(t.amount) AS total_amount,
        c.monthly_budget
    FROM transactions t
    INNER JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = p_user_id 
        AND t.transaction_type = 'debit'
        AND t.transaction_date BETWEEN p_start_date AND p_end_date
    GROUP BY c.id, c.name, c.color, c.icon, c.monthly_budget
    ORDER BY total_amount DESC;
END //
DELIMITER ;

-- Get upcoming EMI payments
DELIMITER //
CREATE PROCEDURE sp_upcoming_emis(IN p_user_id INT, IN p_days_ahead INT)
BEGIN
    SELECT 
        e.*,
        ba.bank AS bank_name,
        ba.account_number
    FROM emis e
    INNER JOIN bank_accounts ba ON e.account_id = ba.id
    WHERE e.user_id = p_user_id 
        AND e.status = 'active'
        AND e.next_payment_date <= DATE_ADD(CURDATE(), INTERVAL p_days_ahead DAY)
    ORDER BY e.next_payment_date ASC;
END //
DELIMITER ;

-- ============================================
-- END OF SCHEMA
-- ============================================
