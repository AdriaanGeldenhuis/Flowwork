# Flowwork Mail

Unified mail client inside Flowwork: IMAP/SMTP accounts, threaded inbox, rich
composer, labels, rules, templates, signatures, snooze, scheduled send and CRM
linking.

## Layout

```
mail/
  index.php            inbox app (list · reader · composer)
  settings.php         accounts, signatures, labels, rules, preferences
  templates.php        reusable messages
  compose.php          redirects old links into the composer
  css/mail.css         design system (light + dark, mobile-first)
  js/mail.js           app + shared helpers (window.FWMail)
  js/mail-settings.js  settings + templates pages
  api/*.php            JSON endpoints (fetch, CSRF header on POST)
  lib/*.php            engine: MailSchema, ImapClient, SmtpSender, MimeParser,
                       MimeBuilder, HtmlSanitizer, MailSyncService, MailStore,
                       MailOutbox, MailActions, MailRepo, RulesEngine, RateLimiter
  worker/, cron/       CLI jobs (denied over HTTP)
  ajax/.htaccess       blocks the retired endpoints still on the server
```

## Server setup

1. **Encryption key.** Add `define('MAIL_SECRET_KEY', '…32+ random characters…');`
   to the server's `config.php` (an environment variable also works, but cron
   jobs often do not see web-server environment variables, while both load
   `config.php`). Passwords saved before the key existed still open and are
   re-encrypted under the key the next time the account syncs or sends.
   Changing the key after that means users must re-enter their mailbox
   passwords.
2. **Schema.** Created/updated automatically on first use. You can also run
   `Migrations/2026-10-15-mail-overhaul.sql` — they produce the same schema.
3. **Storage.** `storage/mail/` must be writable by PHP. Attachments,
   the outgoing spool and rate-limit buckets live there. `storage/.htaccess`
   already blocks direct HTTP access.
4. **Cron.** Mail syncs while the app is open, but these keep it fresh and send
   scheduled mail when nobody is logged in. Where the host's cron is limited
   (xneelo: every two hours), the web tick `notifications/api/tick.php` runs the
   sync and reply reminders every minute instead; see `notifications/README.md`.

```
*/5  * * * *  php /path/to/mail/worker/sync_imap.php   >/dev/null 2>&1
*/15 * * * *  php /path/to/mail/worker/sla_tick.php    >/dev/null 2>&1
7    * * * *  php /path/to/mail/cron/auto_link_crm.php >/dev/null 2>&1
```

No PHP extensions beyond the defaults are needed (`openssl`, `mbstring`,
`dom`, `fileinfo`). IMAP is spoken directly over sockets, so `ext-imap` is not
required.

## How it works

- **Sync** (`MailSyncService`): per account, INBOX and the server's Sent
  folder. The first sync imports the last 30 days (max 200 messages, newest
  first); later syncs fetch only new UIDs. Flags and messages moved or deleted
  in other clients are reconciled. Work is time-boxed and locked per account.
- **Actions** (`MailActions`): read, star, archive, trash, spam and moves update
  the database immediately and queue the matching server change in
  `email_imap_ops`. The queue is applied at the start of the next sync.
- **Sending** (`MailOutbox`): Drafts → Outbox (undo window or scheduled time)
  → Sent. A copy is appended to the server's Sent folder, except for Gmail,
  which files its own.
- **Security**: every query is scoped to the user's own accounts. Email HTML
  is sanitised server-side (`HtmlSanitizer`) and shown in a sandboxed iframe
  with a strict CSP. Remote images are blocked until the user allows them.
  Attachments are only served through `api/attachment.php`; received mail can
  reach only its own inline parts, and a sent message only embeds images from
  its own conversation. IMAP/SMTP hosts must resolve (A and AAAA) to public
  addresses and the socket connects to the checked address (`MailNet`); define
  `MAIL_ALLOW_PRIVATE_HOSTS` to allow a mail server on a private network.
  Send, test, sync, upload and rule runs are rate-limited.

## Tests

See `tests/mail/README.md`.
