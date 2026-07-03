# Security remediation runbook

This branch applies the code fixes from the technical due diligence. Some steps
**cannot be done in code** — they require rotating live credentials and rewriting
git history on your side. Do the P0 items **today**; treat the committed secrets
as already compromised.

---

## P0 — do this first (incident response)

### 1. Rotate every credential that was ever committed
Assume all of these are public. Rotate/replace, then update the values via
environment variables or an un-committed `config.php`:

| Secret | Where it leaked | Action |
|---|---|---|
| Production DB password (`3CLkvJsAM52…`) | `config.php`, `mail/config.php`, `config.php.20251106.php` | Change the MySQL user's password at the host; update `DB_PASS`. |
| `MAIL_SECRET_KEY` | defaults to `CHANGE_ME_IN_PRODUCTION` in `mail/config.php` | Set a real 32-byte random key in the environment. Re-encrypt any stored mail credentials. |
| Android signing key + password (`Adrianus1234!`) | `app files/flowwork-release.keystore`, `keystore.properties` | Generate a new upload key; enrol in Google **Play App Signing**. Rotate that password anywhere it is reused. |
| Any SMTP / API keys | environment / `shared/email_config.php` (already uses `getenv`) | Rotate if they were ever hard-coded. |

### 2. Purge the secrets from git history
Removing files in a new commit does **not** erase them from history. After
rotating, scrub history on a clone and force-push (coordinate with anyone who has
the repo):

```bash
# Using git-filter-repo (recommended)
pip install git-filter-repo
git filter-repo --invert-paths \
  --path config.php.20251106.php \
  --path mail/config.php.20251106.php \
  --path "app files/app/flowwork-release.keystore" \
  --path "app files/keystore.properties" \
  --path "app files/local.properties" \
  --path Migrations/flowwwqmnt_db1.sql \
  --path Migrations/flowwwqmnt_db1.txt \
  --path php-error.log \
  --path logs/email.log \
  --path logs/php_errors.log \
  --path-glob 'uploads/company/*/compliance/*' \
  --path-glob 'uploads/receipts/*'
# then: git push --force --all  (and --tags)
```
Also rotate the DB password **again** if the dump (which contains bcrypt hashes)
was ever cloned by a third party, and force a password reset for all users.

### 3. Payment webhooks — already fixed in code
`webhooks/yoco.php` and `qi/ajax/yoco_webhook.php` now **require** a valid HMAC
signature (with replay protection) before recording a payment. **Action:** make
sure each company has its Yoco `yoco_webhook_secret` configured — the endpoints
now fail closed, so an unconfigured secret means payments won't auto-reconcile.
Decide which of the two endpoints is registered with Yoco and retire the other.

### 4. Financial documents — deny-by-default is now on
`storage/.htaccess` blocks direct web access to payslips and invoice PDFs (they
exposed ID numbers and bank details at guessable URLs). A new authenticated
proxy, `download.php`, serves them after checking session + company + role.

> **Action required (functional):** any link that pointed directly at
> `/storage/...` will now 403. Repoint them at the proxy, e.g. in
> `payroll/payslips.php` change
> `href="<?= $psPath ?>"` → `href="/download.php?file=<?= urlencode(ltrim($psPath,'/')) ?>"`,
> and do the same for invoice-PDF links in the `qi` views. If you need invoices
> working before rewiring, you can temporarily remove `storage/.htaccess`, but
> the PII exposure returns until the links are moved.

---

## What the code changes on this branch already cover

- **Yoco webhook forgery** (both endpoints) — mandatory signature + replay check;
  stopped leaking exception messages / `display_errors`.
- **Projects authorization** — `require_board_role` / `require_project_role`
  added to the previously-unguarded endpoints: `bulk-*`, `cell/update`,
  `column/{create,update,delete,reorder}`, `column.visibility`,
  `subitem/{create,update,delete}`, `board.member.add`, `board.member.remove`.
  `board.member.add` also caps the granted role at the caller's own level, and
  the bulk writes are now scoped to the authorized board.
- **Repo hygiene** — real `.gitignore`; committed dumps, logs, keystore, backup
  configs and PII uploads removed from tracking; `config.example.php` added.

## Still open (see the due-diligence report) — recommended next

- **Stored XSS (~30 sinks)** — add one shared `escapeHtml()` in the board UI and
  a Content-Security-Policy. Highest remaining risk after this branch.
- **PAYE tax engine** (`payroll/calc_engine.php`) — bracket math is wrong; fix
  and cover with unit tests against SARS tables.
- **Auth hardening** — hash reset tokens, pin the reset/invite `Host`, rate-limit
  login, validate the login `redirect` param.
- **File-upload allow-lists**, **error-message suppression**, **FX on AP postings**,
  **CI with secret scanning**, and **foreign keys on the board domain**.
