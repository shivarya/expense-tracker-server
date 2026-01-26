# 500 Error Debugging Guide

## What I Fixed:

1. ✅ **Updated index.php** - Fixed routing to handle `/expense_tracker/` subfolder
2. ✅ **Added Azure OpenAI constants** to config.php
3. ✅ **Created debug files** for testing

---

## Step-by-Step Debugging:

### Test 1: Basic PHP Test
Visit: `https://shivarya.dev/expense_tracker/test.php`

**Expected:** JSON response showing PHP is working
**If fails:** PHP is not executing - check cPanel PHP version

### Test 2: Debug Information
Visit: `https://shivarya.dev/expense_tracker/debug.php`

**Expected:** Shows all paths, environment info, and phpinfo
**Check for:**
- Is vendor/autoload.php found?
- Is .env file found?
- Is config.php loading?

### Test 3: Health Endpoint
Visit: `https://shivarya.dev/expense_tracker/health`

**Expected:** JSON health check response

---

## Common Issues & Fixes:

### Issue 1: Vendor folder missing
**Symptom:** debug.php shows "vendor/autoload.php NOT FOUND"
**Fix:**
```bash
cd public_html/expense_tracker
composer install --no-dev --optimize-autoloader
```

### Issue 2: .env file missing
**Symptom:** debug.php shows ".env file NOT FOUND"
**Fix:** Create .env file with database credentials (see cPanel deployment guide)

### Issue 3: .htaccess not working
**Symptom:** 404 errors or routes don't work
**Fix:** Ensure .htaccess has:
```apache
RewriteEngine On
RewriteBase /expense_tracker/
RewriteRule ^(.*)$ /expense_tracker/index.php [QSA,L]
```

### Issue 4: PHP errors are hidden
**Symptom:** Blank page or generic 500
**Fix in cPanel:**
1. File Manager → expense_tracker folder
2. Create/edit `php.ini`:
```ini
display_errors = On
error_reporting = E_ALL
log_errors = On
error_log = error_log
```

### Issue 5: Database connection fails
**Symptom:** Works on test.php but fails on health endpoint
**Check:**
- .env has correct DB credentials
- Database exists in cPanel → MySQL Databases
- User has privileges

### Issue 6: Permissions
**Check file permissions:**
- Folders: 755
- PHP files: 644
- .env: 600

---

## Quick Checks in cPanel:

1. **Check Error Log:**
   - cPanel → "Errors" (under Metrics)
   - Or File Manager → `expense_tracker/error_log`

2. **Check PHP Version:**
   - cPanel → "Select PHP Version"
   - Should be PHP 8.1 or 8.2

3. **Check PHP Extensions:**
   - cPanel → "Select PHP Version" → "Extensions"
   - Required: curl, json, mbstring, mysqli, pdo, pdo_mysql

---

## Files to Upload/Check:

Upload these updated files to server:
1. ✅ `index.php` (fixed routing)
2. ✅ `config/config.php` (added Azure constants)
3. ✅ `test.php` (for testing)
4. ✅ `debug.php` (for debugging)
5. ⚠️ `.env` (with your credentials)
6. ⚠️ `vendor/` folder (after composer install)

---

## Next Steps:

1. **Test basic PHP:** Visit test.php
2. **Check debug info:** Visit debug.php
3. **Check Apache error log:** cPanel → Errors
4. **Upload fixed files** if not already done
5. **Run composer install** if vendor missing
6. **Test health endpoint** again

---

## If Still Getting 500 Error:

**Check Apache error log** (not PHP error log):
- cPanel → "Errors"
- Look for the most recent error when you access /health
- Common errors:
  - "Premature end of script headers" = PHP crash/syntax error
  - "File does not exist" = .htaccess routing issue
  - "Permission denied" = File permission issue

**Enable detailed errors temporarily:**
In `index.php`, change line 4:
```php
ini_set('display_errors', '1');  // Change from '0' to '1'
```

Then visit health endpoint - error will show on screen.

---

**Status:** Fixed routing and config. Test with debug files first!
