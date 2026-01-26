# Expense Tracker - PHP Backend

PHP REST API server for the Expense Tracker mobile app with AI-powered SMS/email parsing.

## Features

- **AI-Powered Parsing** - Azure OpenAI GPT-4 for SMS and email transaction extraction
- **Real-time Webhooks** - SMS forwarding and Gmail push notifications
- Portfolio management (Stocks, Mutual Funds, FDs, Long-term funds)
- Transaction tracking with categories
- Bank account management (HDFC, SBI, ICICI, IDFC, RBL, Axis, Kotak)
- EMI tracking
- Data sync endpoints for scraper integration
- JWT authentication with Google Sign-In
- Auto-creation of bank accounts and categories

## Tech Stack

- PHP 8.0+
- MySQL 8.0+
- PDO for database access
- Firebase JWT for authentication
- Guzzle HTTP client for API calls
- Google API Client for Gmail integration
- Azure OpenAI GPT-4 Turbo for AI parsing

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

Edit `.env` with your credentials:

```env
# Database
DB_HOST=localhost
DB_NAME=expense_tracker
DB_USER=root
DB_PASS=

# JWT
JWT_SECRET=your-secret-key

# Azure OpenAI (for SMS/email parsing)
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=your-api-key
AZURE_OPENAI_DEPLOYMENT=gpt-4-turbo
```

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

### SMS Parsing (NEW - Phase 7)
- `POST /parse/sms` - Parse batch SMS messages
- `POST /parse/sms/webhook` - Real-time SMS webhook

**Example Request:**
```json
{
  "messages": [
    {
      "sender": "VK-HDFCBK",
      "body": "Rs. 500.00 debited from A/c XX1234 on 20-Jan-26 at SWIGGY",
      "date": "2026-01-20 14:30:00"
    }
  ]
}
```

**Supported Banks:** HDFC, SBI, ICICI, IDFC, RBL, Axis, Kotak

### Email Parsing (NEW - Phase 7)
- `POST /parse/email/setup` - Initialize Gmail OAuth2
- `POST /parse/email/callback` - Complete OAuth2 flow
- `POST /parse/email/fetch` - Fetch and parse CAMS/KFintech emails
- `POST /parse/email/webhook` - Gmail push notification handler

**See [SMS_EMAIL_PARSER_API.md](SMS_EMAIL_PARSER_API.md) for complete documentation**

### Auth
- `POST /auth/google` - Google Sign-In authentication
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

- JWT authentication with Google Sign-In for multi-user support
- All endpoints require valid JWT token (except `/health`, `/auth/*`)
- User-specific data isolation via `user_id` from token
- Auto-creation of bank accounts and categories from parsed SMS
- Duplicate transaction prevention (±60 minute window)
- All timestamps use `Asia/Kolkata` timezone
- Gain/loss percentages calculated with stored generated columns
- SMS parsing: ~95% accuracy on major Indian banks
- Email parsing: Supports CAMS and KFintech mutual fund statements

## Additional Documentation

- **[SMS_EMAIL_PARSER_API.md](SMS_EMAIL_PARSER_API.md)** - Complete API documentation for parsing endpoints
- **[QUICK_START_PARSING.md](QUICK_START_PARSING.md)** - Quick setup guide for SMS/email parsing
- **[PHASE_7_COMPLETE.md](../PHASE_7_COMPLETE.md)** - Phase 7 implementation summary

## Troubleshooting

### "Azure OpenAI credentials not configured"
Add `AZURE_OPENAI_*` variables to `.env` and restart server

### "Gmail not authenticated"
Complete OAuth2 flow: `/parse/email/setup` → visit auth URL → `/parse/email/callback`

### "Unauthorized: Invalid token"
Include JWT in header: `Authorization: Bearer <token>`

