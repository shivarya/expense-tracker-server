# Expense Tracker - PHP Backend

PHP REST API server for the Expense Tracker mobile app.

## Features

- Portfolio management (Stocks, Mutual Funds, FDs, Long-term funds)
- Transaction tracking with categories
- Bank account management (HDFC, SBI, ICICI, IDFC, RBL)
- EMI tracking
- Data sync endpoints for scraper integration
- JWT authentication (ready for multi-user support)

## Tech Stack

- PHP 8.0+
- MySQL 8.0+
- PDO for database access
- Firebase JWT for authentication

## Setup

### 1. Install Dependencies

```bash
cd server
composer install
```

> Tip: Composer prefers the PHP `zip` extension for faster installs. If you see warnings about `zip` missing, enable `extension=zip` in your `php.ini` (restart Apache/PHP) or install `unzip` on your system — Composer will fall back to cloning packages from source if `zip` is unavailable.


### 2. Configure Environment

Copy `.env.example` to `.env` and update:

```bash
cp .env.example .env
```

Edit `.env` with your database credentials.

### 3. Create Database

```bash
mysql -u root -p < database/schema.sql
```

This will create the `expense_tracker` database with all tables, views, and stored procedures.

### 4. Start PHP Server

```bash
php -S localhost:8000
```

Or use XAMPP/WAMP/MAMP and point to this directory.

## API Endpoints

### Health Check
- `GET /health` - API status

### Dashboard
- `GET /dashboard` - Complete dashboard summary

### Investments
- `GET /investments` - All investments
- `GET /investments/stocks` - All stocks
- `POST /investments/stocks` - Create/update stock
- `GET /investments/mutual-funds` - All mutual funds
- `POST /investments/mutual-funds` - Create/update MF
- `GET /investments/fixed-deposits` - All FDs
- `POST /investments/fixed-deposits` - Create/update FD
- `GET /investments/long-term` - PF, NPS, Sukanya, PPF
- `POST /investments/long-term` - Create/update long-term fund
- `DELETE /investments/{type}/{id}` - Delete investment

### Transactions
- `GET /transactions` - Get transactions (with filters)
- `POST /transactions` - Create transaction
- `DELETE /transactions/{id}` - Delete transaction

Query params: `start_date`, `end_date`, `account_id`, `category_id`, `type`, `limit`

### Bank Accounts
- `GET /accounts` - All accounts
- `GET /accounts/{id}` - Account details with transactions
- `POST /accounts` - Create account
- `PUT /accounts/{id}` - Update account
- `DELETE /accounts/{id}` - Delete account

### EMIs
- `GET /emis` - All EMIs
- `POST /emis` - Create EMI
- `PUT /emis/{id}` - Update EMI
- `DELETE /emis/{id}` - Delete EMI

### Categories
- `GET /categories` - All categories
- `POST /categories` - Create category
- `PUT /categories/{id}` - Update category
- `DELETE /categories/{id}` - Delete category

### Sync (for scraper)
- `POST /sync/stocks` - Bulk sync stocks
- `POST /sync/mutual-funds` - Bulk sync MFs from CAMS
- `POST /sync/transactions` - Bulk sync SMS/email transactions
- `POST /sync/fixed-deposits` - Bulk sync FDs
- `GET /sync/logs` - Get sync history

### Auth
- `POST /auth/login` - Login (simple auth for now)
- `GET /auth/me` - Get current user

## Database Schema

### Tables
- `users` - User accounts
- `stocks` - Stock holdings (Zerodha, Groww)
- `mutual_funds` - MF portfolio (CAMS data)
- `fixed_deposits` - Bank FDs
- `long_term_funds` - PF, NPS, Sukanya, PPF
- `bank_accounts` - Bank accounts & credit cards
- `transactions` - All expenses/income
- `emis` - Loan EMIs
- `categories` - Expense categories
- `scrape_logs` - Sync activity logs

### Views
- `v_asset_summary` - Portfolio overview by category

### Stored Procedures
- `sp_calculate_portfolio_total` - Calculate total portfolio value
- `sp_monthly_expenses_by_category` - Monthly expense breakdown
- `sp_upcoming_emis` - EMIs due in next N days

## Response Format

Success:
```json
{
  "success": true,
  "data": {...},
  "message": "Success message"
}
```

Error:
```json
{
  "success": false,
  "error": "Error message",
  "errors": {...}  // Optional validation errors
}
```

## CORS

Configured for:
- `http://localhost:19006` (Expo web)
- `http://localhost:8081` (Metro bundler)
- `exp://*` (Expo Go)

## Error Logging

Errors are logged to `php_errors.log` in the server directory.

## Development Notes

- Currently using `user_id = 1` for single-user mode
- JWT auth is ready for multi-user expansion
- All timestamps use `Asia/Kolkata` timezone
- Gain/loss percentages calculated with stored generated columns

