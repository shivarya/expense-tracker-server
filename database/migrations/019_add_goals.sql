-- Migration 019: Personal financial goals
--
-- Adds a standalone "goals" concept, distinct from category_budgets (014).
-- category_budgets caps ONE category per month; a goal can span a single
-- linked loan (debt_payoff), a free-standing savings pot with no backing
-- table yet (savings), the whole portfolio (net_worth), or an aggregate cap
-- across MANY categories (spend_cap) -- none of which category_budgets can
-- express. Progress for every type is computed on read from existing data
-- (emis / stocks+mutual_funds+fixed_deposits+long_term_funds / transactions);
-- goals never duplicates a balance, it only stores the target + assumptions.
--
-- goal_contributions is a manual ledger, used only by goal_type='savings'
-- (a savings pot with no backing bank_account/long_term_funds row yet --
-- logged by hand until a real RD/fund account exists).

CREATE TABLE IF NOT EXISTS goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_type ENUM('debt_payoff', 'savings', 'net_worth', 'spend_cap') NOT NULL,
    name VARCHAR(255) NOT NULL,

    -- debt_payoff only: which loan this goal tracks
    emi_id INT NULL COMMENT 'debt_payoff only; FK to emis.id',

    -- savings / net_worth: target corpus. spend_cap: the monthly cap amount.
    -- NULL for debt_payoff (its "target" is target_date, not an amount).
    target_amount DECIMAL(15, 2) NULL,

    -- savings only: opening balance before any goal_contributions row exists
    start_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,

    -- savings / net_worth / debt_payoff: deadline. NULL for spend_cap (ongoing, resets monthly).
    target_date DATE NULL,

    -- net_worth only: compounding assumptions for the forward projection
    assumed_annual_return_percent DECIMAL(5, 2) NULL COMMENT 'net_worth only; default 10.00 applied by controller if omitted',
    assumed_monthly_contribution DECIMAL(15, 2) NULL COMMENT 'net_worth only; optional, defaults to 0',

    -- spend_cap only: JSON array of categories.id to sum. NULL = default
    -- discretionary set (all type='expense' categories except 'Rent/EMI').
    linked_category_ids JSON NULL,

    status ENUM('active', 'achieved', 'abandoned') NOT NULL DEFAULT 'active',
    notes VARCHAR(500) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    -- SET NULL (not RESTRICT, unlike emis.account_id): a goal is a loose
    -- reference to a loan, not a structural dependency. If the linked EMI
    -- is ever deleted, the goal degrades to "linked loan missing" rather
    -- than blocking the EMI delete elsewhere in the app.
    FOREIGN KEY (emi_id) REFERENCES emis(id) ON DELETE SET NULL,

    INDEX idx_goals_user_type (user_id, goal_type),
    INDEX idx_goals_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goal_contributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    goal_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    contributed_at DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_goal_contributions_goal (goal_id),
    INDEX idx_goal_contributions_date (contributed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
