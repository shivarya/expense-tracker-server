# cPanel Deployment Guide - Quick Start

**Server Setup Time:** ~30 minutes  
**Skill Level:** Beginner-friendly

---

## Prerequisites Checklist

- [ ] cPanel hosting account with PHP 8.1+
- [ ] Domain/subdomain: `api.expensetracker.com`
- [ ] Azure OpenAI account credentials
- [ ] Access to cPanel dashboard

---

## Step-by-Step Deployment

### 1. Setup Domain & SSL (5 mins)

1. **Setup Folder:**
   - Files will be in: `public_html/expense_tracker`
   - URL will be: `http://shivarya.dev/expense_tracker`
   - No domain setup needed (using existing domain)

2. **Enable SSL (Recommended):**
   - cPanel → "SSL/TLS Status"
   - Find `shivarya.dev`
   - Click "Run AutoSSL" (if available)
   - API will be accessible at: `https://shivarya.dev/expense_tracker`

3. **Note:** Using HTTPS is strongly recommended for production

---

### 2. Create MySQL Database (3 mins)

1. **cPanel → MySQL Databases**

2. **Create Database:**
   - Name: `expense_tracker`
   - Click "Create Database"
   - Note full name: `cpanel_username_expense_tracker`

3. **Create User:**
   - Username: `expense_app`
   - Click "Password Generator" → Copy password
   - Click "Create User"
   - Note full username: `cpanel_username_expense_app`

4. **Link User to Database:**
   - "Add User To Database" section
   - User: `cpanel_username_expense_app`
   - Database: `cpanel_username_expense_tracker`
   - Check "ALL PRIVILEGES"
   - "Make Changes"

---

### 3. Upload Backend Files (5 mins)

1. **Prepare Files Locally:**
   ```bash
   cd expense-tracker/server
   composer install --no-dev --optimize-autoloader
   ```

2. **Create ZIP:**
   - Zip entire `server/` folder contents
   - Name: `server.zip`

3. **Upload to cPanel:**
   - cPanel → File Manager
   - Navigate to: `public_html/expense_tracker`
   - Click "Upload" → Select `server.zip`
   - Right-click `server.zip` → "Extract"
   - Delete `server.zip` after extraction

---

### 4. Configure Environment (5 mins)

1. **Create .env File:**
   - File Manager → `public_html/expense_tracker`
   - Click "+ File" → Name: `.env`
   - Right-click `.env` → "Edit"
   - Paste this content:

```env
# Database Configuration
DB_HOST=localhost
DB_NAME=cpanel_username_expense_tracker
DB_USER=cpanel_username_expense_app
DB_PASS=your-database-password-here

# JWT Secret (generate with: openssl rand -base64 32)
JWT_SECRET=generate-a-random-32-character-string-here

# Azure OpenAI Configuration
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=your-azure-openai-api-key
AZURE_OPENAI_DEPLOYMENT=gpt-4-turbo

# Server Configuration
SERVER_ENV=production
ALLOWED_ORIGINS=*

# Timezone
TIMEZONE=Asia/Kolkata
```

2. **Save and set permissions:**
   - Save file
   - Right-click `.env` → "Permissions" → `600`

---

### 5. Setup .htaccess (3 mins)

1. **Create .htaccess:**
   - File Manager → `public_html/expense_tracker`
   - Click "+ File" → Name: `.htaccess`
   - Edit and paste:

```apache
# Enable Rewrite Engine
RewriteEngine On

# Set base for subfolder routing
RewriteBase /expense_tracker/

# HTTPS Redirect (optional, recommended)
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# CORS Headers
<IfModule mod_headers.c>
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
    Header set Access-Control-Allow-Headers "Authorization, Content-Type, X-Requested-With"
    Header set Access-Control-Allow-Credentials "true"
    
    # Security Headers
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-Content-Type-Options "nosniff"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

# Handle OPTIONS Requests
RewriteCond %{REQUEST_METHOD} OPTIONS
RewriteRule ^(.*)$ $1 [R=204,L]

# Route all requests to index.php (subfolder aware)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /expense_tracker/index.php [QSA,L]

# Protect sensitive files
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# Disable directory listing
Options -Indexes
```

2. **Save with permissions: `644`**

---

### 6. Configure PHP (2 mins)
shivarya.dev` (main domain)
   - Choose: PHP 8.1 or 8.2
   - Click "Apply"
   - Note: This affects entire domain including subfolderect PHP Version" or "MultiPHP Manager"
   - Select domain: `api.expensetracker.com`
   - Choose: PHP 8.1 or 8.2
   - Click "Apply"

2. **Configure PHP Settings:**
   - "Select PHP Version" → "Options"
   - Set:
     - `upload_max_filesize`: 10M
     - `post_max_size`: 10M
     - `memory_limit`: 256M
     - `max_execution_time`: 300
   - Save

3. **Enable Extensions:**
   - "Extensions" tab
   - Enable: curl, json, mbstring, mysqli, pdo, pdo_mysql, xml, zip, openssl
   - Save

---

### 7. Import Database Schema (3 mins)

1. **Access phpMyAdmin:**
   - cPanel → "phpMyAdmin"
   - Click database: `cpanel_username_expense_tracker`

2. **Import Schema:**
   - Click "Import" tab
   - Choose File: `database/schema.sql` (from your local files)
   - Scroll down → Click "Go"
   - Success! Should create 11 tables

3. **Verify:**
   - Check left sidebar shows 11 tables:
     - users, bank_accounts, categories, transactions
     - investments, mutual_funds, fixed_deposits
     - budgets, recurring_transactions, notifications, sms_logs

---
shivarya.dev/expense_tracker/health`
   - Or: `http://shivarya.dev/expense_tracker/health` (if SSL not enabled)
### 8. Test API (2 mins)

1. **Open Browser:**
   - Visit: `https://api.expensetracker.com/health`

2. **Expected Response:**
```json
{
  "success": true,
  "message": "Expense Tracker API is running",
  "data": {
    "status": "healthy",
    "timestamp": "2026-01-26T12:00:00Z",
    "version": "1.0.0"
  }
}
```

3. **If Error:**
   - cPanel → "Errors" (check error log)
   - Verify `.env` credentials
   - Check database connection
   - Ensure `.htaccess` is working

---

## Quick Troubleshooting

### ❌ "500 Internal Server Error"
**Fix:**
- Check `.htaccess` syntax
- cPanel → Errors → View error details
- Ensure PHP 8.1+ is selected
- Check `.env` file exists and has correct values

### ❌ "Database connection failed"
**Fix:**
- Verify database credentials in `.env`
- Check database user privileges in cPanel → MySQL Databases
- Ensure `DB_HOST=localhost` (not IP address)

### ❌ "CORS policy error"
**Fix:**
- Check `.htaccess` has CORS headers
- Ensure `mod_headers` is enabled (contact host if not)
- Clear browser cache

### ❌ "File not found" for API routes
**Fix:**
- Verify `.htaccess` RewriteEngine is On
- Check `index.php` exists in root
- Ensure Apache `mod_rewrite` enabled (usually is on cPanel)

### ❌ "Vendor autoload not found"
**Fix:**
- Re-upload with `vendor/` folder included
- Or run `composer install` via SSH if available

---

## Security Checklist

After deployment:

- [ ] `.env` permissions set to 600
- [ ] `.htaccess` protecting `.env` file
- [ ] HTTPS redirect working
- [ ] SSL certificate installed and valid
- [ ] Database user has only necessary privileges
- [ ] File permissions: folders 755, files 644
- [ ] cPanel → "ModSecurity" enabled
- [ ] Error display disabled in production (check `php.ini`)

---

## File Permissions Reference

```
.env                  600 (rw-------)
.htaccess             644 (rw-r--r--)
index.php             644 (rw-r--r--)
config/               755 (rwxr-xr-x)
controllers/          755 (rwxr-xr-x)
database/             755 (rwxr-xr-x)
utils/                755 (rwxr-xr-x)
vendor/               755 (rwxr-xr-x)
data/                 755 (rwxr-xr-x)
```

---

## Backup Your Database

**Setup Automated Backup:**

1. **cPanel → Cron Jobs**
2. **Add Cron:**
   - Common Settings: "Once Per Day (0 0 * * *)"
   - Command:
```bash
mysqldump -u cpanel_username_expense_app -p'your-password' cpanel_username_expense_tracker > /home/cpanel_username/backups/db_$(date +\%Y\%m\%d).sql
```
3. **Create backups folder:**
   - File Manager → Create folder: `/home/cpanel_username/backups`
   - Set permissions: 755

---

## Update .env for Production

**Before going live, update:**

```env
# Change from * to your actual mobile app domain
ALLOWED_ORIGINS=https://yourdomain.com

# Ensure production Azure OpenAI
AZURE_OPENAI_ENDPOINT=https://your-production-resource.openai.azure.com
AZURE_OPENAI_API_KEY=your-production-api-key

# Verify timezone
TIMEZONE=Asia/Kolkata  # or your timezone
```

---

## Next Steps

1. ✅ Server deployed and tested
2. ⏭️ Update mobile app API_URL to: `https://api.expensetracker.com`
3. ⏭️ Build production APK/AAB
4. ⏭️ Submit to Google Play Store

---

## Support
expense_tracker/`
- Logs: cPanel → "Errors" or check `error_log` file
- Database: cPanel → "phpMyAdmin"
- Cron Jobs: cPanel → "Cron Jobs"
- SSL: cPanel → "SSL/TLS Status"

**Need Help?**
- Check cPanel error logs first
- Verify `.env` credentials match database
- Test in Postman before mobile app
- Contact your hosting support for server-specific issues

---

**Deployment Complete! 🎉**

Your API is now live at: `https://shivarya.dev/expense_tracker

Your API is now live at: `https://api.expensetracker.com`

*Last Updated: January 26, 2026*
