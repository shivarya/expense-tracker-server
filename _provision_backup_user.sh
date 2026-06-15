#!/bin/bash
# One-off: provision a least-privilege MySQL backup user via cPanel UAPI and
# write its credentials to a chmod-600 defaults file. Password is generated here
# on the server and is NEVER printed to stdout. Delete this script after running.
set -uo pipefail

CNF="$HOME/.mysql-backup.cnf"
USER="backup_ro"
DBS="budgetTracker citypata expense_tracker medimention omersamaj sanjivaniherb shivarya_splitcash"
PW="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32)"
TMP="$(mktemp)"

ok() { grep -q '"status" : 1' "$TMP"; }

# 1) Create the user (or reset its password if it already exists). No echo of PW.
if uapi Mysql list_users 2>/dev/null | grep -q "\"$USER\""; then
  uapi Mysql set_password user="$USER" password="$PW" >"$TMP" 2>&1
  ok && echo "user '$USER': password reset OK" || { echo "user '$USER': password reset FAILED"; grep -i error "$TMP" | head -1; }
else
  uapi Mysql create_user name="$USER" password="$PW" >"$TMP" 2>&1
  ok && echo "user '$USER': created OK" || { echo "user '$USER': create FAILED"; grep -i error "$TMP" | head -1; }
fi

# 2) Grant least-priv on each DB; fall back to ALL PRIVILEGES if the host rejects the list.
for d in $DBS; do
  uapi Mysql set_privileges_on_database user="$USER" database="$d" \
       privileges='SELECT,SHOW VIEW,LOCK TABLES,TRIGGER,EVENT' >"$TMP" 2>&1
  if ok; then echo "  grant $d: OK (read-only set)"; continue; fi
  uapi Mysql set_privileges_on_database user="$USER" database="$d" privileges='ALL PRIVILEGES' >"$TMP" 2>&1
  if ok; then echo "  grant $d: OK (all-priv fallback)"; else echo "  grant $d: FAILED"; grep -i error "$TMP" | head -1; fi
done

# 3) Write the protected defaults file (creds only here, mode 600).
umask 077
cat > "$CNF" <<CNF
[client]
user=$USER
password=$PW
[mysqldump]
user=$USER
password=$PW
CNF
chmod 600 "$CNF"
echo "wrote $CNF (chmod 600)"

# 4) Smoke test: can the backup user dump the schema of every DB?
echo "--- smoke test: schema-only dump per DB ---"
for d in $DBS; do
  if mysqldump --defaults-extra-file="$CNF" --single-transaction --no-data --no-tablespaces --skip-comments "$d" >/dev/null 2>"$TMP"; then
    echo "  dump-test $d: OK"
  else
    echo "  dump-test $d: FAILED -> $(head -1 "$TMP")"
  fi
done

rm -f "$TMP"
echo "DONE"
