# cPanel Deployment Guide — Expense Tracker API

How the Expense Tracker PHP backend is deployed and updated on cPanel shared hosting.

> For the day-to-day **update** flow, prefer the `expense-deploy-api` skill (`.claude/skills/expense-deploy-api/`) — it's the operational checklist. This doc is the fuller reference (host facts, first-time setup, env, troubleshooting).

---

## Live facts

| | |
|---|---|
| **URL** | `https://shivarya.dev/expense_tracker/` |
| **Prod path** | `~/public_html/expense_tracker` (top-level subfolder — **not** under `~/public_html/shivarya.dev/`, unlike the newer `diet_plan` / `split_cash` apps) |
| **Host** | GoDaddy cPanel shared hosting; PHP 8.4, Composer 2.9 on PATH |
| **SSH** | `ssh cpanel` (configured alias) — or `ssh -i C:\Users\Ash\.ssh\cpanel_key hm5pno1wummg@184.168.101.66` (also the root helper `connect_ssh.ps1`) |
| **cPanel user** | `hm5pno1wummg` → MySQL DBs/users are prefixed `hm5pno1wummg_` |
| **Deploy model** | Prod is a **git checkout**. Updates are a `git pull` on the server, **not** a tarball/ZIP upload. |

---

## Updating an existing deployment (the normal case)

1. **Local**: commit and push the server change to `origin/master`. Optionally smoke-test: `cd "c:\Users\Ash\Documents\Projects\apps\expense-tracker\server" ; php -S localhost:8000` → `curl http://localhost:8000/health`.

2. **On prod** (`ssh cpanel` → `cd ~/public_html/expense_tracker`):
   - `git status` first. Prod commonly shows CRLF-only noise plus **runtime-mirror files edited live** — notably `CATEGORY_INSTRUCTIONS.md`. Preserve those before pulling:
     ```bash
     cp CATEGORY_INSTRUCTIONS.md ~/category_instructions.prod.bak
     ```
   - If there are genuine local modifications, `git stash` (or commit to a prod branch) so the pull can fast-forward.
   - `git fetch origin && git pull --ff-only origin master`
   - Restore any preserved files; `git stash pop` if you stashed.

3. **Composer** (only if PHP deps changed): `composer install --no-dev --optimize-autoloader`.

4. **Apply new DB migrations** — see [Database](#database) below. Never re-import `schema.sql` on a live DB.

5. **Verify** — see [Verify](#verify) below.

> **Env-only changes don't need a pull.** Adding an email to `PREMIUM_ALLOWLIST`, flipping `PREMIUM_ENFORCED`, or swapping an AI key takes effect on the next request — just edit `~/public_html/expense_tracker/.env` on the host.

---

## First-time setup

### 1. Database
- cPanel → **MySQL Databases** → create DB `hm5pno1wummg_expense_tracker` + a user, grant ALL PRIVILEGES.
- Import the base schema once: `mysql -u hm5pno1wummg_<user> -p'<pass>' hm5pno1wummg_<db> < database/schema.sql`.

### 2. Code
- Clone the repo into `~/public_html/expense_tracker` (or push and pull, per the update flow).
- `composer install --no-dev --optimize-autoloader`.

### 3. `.env`
Create `~/public_html/expense_tracker/.env` (perms **600**) — never upload your local `.env`. Current variables:

```env
# Database
DB_HOST=localhost
DB_NAME=hm5pno1wummg_expense_tracker
DB_USER=hm5pno1wummg_<user>
DB_PASS=<password>

# Auth
JWT_SECRET=<long random secret>
ALLOW_DEV_LOGIN=false                       # /auth/login user-1 backdoor returns 410 unless true; keep false in prod

# Server AI (provider-agnostic — utils/aiClient.php)
AI_PROVIDER=gemini
AI_MODEL=gemini-2.0-flash-lite
GEMINI_API_KEY=<key>
#   alt providers: OPENAI_API_KEY | GROQ_API_KEY | AZURE_OPENAI_* | AI_BASE_URL + AI_API_KEY (openai_compatible)

# Google Sign-In + Gmail auto-fetch
GOOGLE_CLIENT_ID=<Web OAuth client id>      # ID tokens verified against this; must match mobile app.json extra.googleClientId
GOOGLE_CLIENT_SECRET=<secret>               # required for the Gmail serverAuthCode → token exchange
GOOGLE_ALLOWED_AUDIENCES=                    # optional extra native client IDs (comma-separated)

# Premium gating
PREMIUM_ENFORCED=false                        # OFF ⇒ everyone treated as premium (non-breaking). true ⇒ real gating.
PREMIUM_ALLOWLIST=                            # comma-separated emails and/or numeric user IDs comped to premium (honored even when enforced)

# Play Billing verification (only needed once PREMIUM_ENFORCED=true with real purchases)
GOOGLE_PLAY_PACKAGE_NAME=dev.shivarya.expensetracker
GOOGLE_PLAY_SERVICE_ACCOUNT_JSON=            # absolute path to a service-account key file OUTSIDE the web root

# Misc
TIMEZONE=Asia/Kolkata
```

### 4. `.htaccess`
The repo ships the correct `.htaccess` — it uses `RewriteBase /expense_tracker/`, routes non-file requests to the absolute `/expense_tracker/index.php`, protects `.env`/dotfiles, sets CORS, and disables directory listing. Keep it intact; without it the parent docroot can shadow the API.

### 5. Gmail-sync cron (premium server-side auto-fetch)
The worker `cron/gmail_sync_worker.php` drains the `sync_jobs` queue in ~50s batches under the cPanel time caps. Add in cPanel → Cron Jobs:
```
*/10 * * * * php ~/public_html/expense_tracker/cron/gmail_sync_worker.php
```

---

## Database

Schema changes after the initial import ship as numbered migrations in `database/migrations/NNN_*.sql` (currently up to `017_add_subscriptions.sql`). On the live DB, **back up first, then apply only the new migrations in order** — do **not** re-import `schema.sql` (it won't ALTER existing tables):

```bash
mysqldump -u hm5pno1wummg_<user> -p'<pass>' hm5pno1wummg_<db> > ~/backup_$(date +%F).sql
mysql    -u hm5pno1wummg_<user> -p'<pass>' hm5pno1wummg_<db> < database/migrations/017_add_subscriptions.sql
```

(The host also runs daily GFS `mysqldump` backups under a `backup_ro` user — but always take your own before a migration.)

---

## Static pages (Google Play requirements)

- `server/delete.html` → served at `/expense_tracker/delete.html` (Data Safety account-deletion URL). Must be published.
- `server/privacy.html` is **STALE / pre-distribution** — replace its content with `docs/privacy.html` before public launch; do not assume the live file is current.
- Static files are served directly via the `.htaccess` `!-f` rule.

---

## Verify

```powershell
Invoke-RestMethod https://shivarya.dev/expense_tracker/health        # → { "success": true, ... } (no DB needed)
```
Protected routes must return **401 JSON** when unauthenticated — this proves routing reached `index.php` and there's no 500 fatal:
- `GET /expense_tracker/billing/status`
- `GET /expense_tracker/gmail/jobs`
- `POST /expense_tracker/parse/sms/structured`

With a valid `Authorization: Bearer <jwt>`, spot-check the specific endpoint you changed.

---

## Troubleshooting

| Symptom | Check |
|---|---|
| **500 Internal Server Error** | cPanel → Errors / `error_log`; `.htaccess` syntax; `.env` exists with valid DB creds; PHP 8.x selected |
| **Database connection failed** | `.env` creds; user privileges in cPanel → MySQL Databases; `DB_HOST=localhost` (not an IP) |
| **API route returns the portfolio HTML / 404** | `.htaccess` `RewriteEngine`/`RewriteBase` intact; `index.php` present; deployed to `~/public_html/expense_tracker` (not the wrong folder) |
| **Vendor autoload not found** | run `composer install --no-dev --optimize-autoloader` on the host |
| **`git pull` refuses (diverged/dirty)** | resolve the local prod modifications via `git stash` first (preserve `CATEGORY_INSTRUCTIONS.md`); never force-overwrite blindly |
| **Premium not gating / everyone premium** | expected when `PREMIUM_ENFORCED=false`; set `true` and confirm `PREMIUM_ALLOWLIST` |

---

## Permissions & hygiene

```
.env          600       index.php / *.php   644
.htaccess     644       config/ controllers/ utils/ vendor/ database/   755
```
- Never commit or upload `.env`. Set it `600` on the host.
- Keep secrets out of git; verify them on the host with **masked** checks (length / first+last chars), never by echoing full values.
- Keep error display off in production.
