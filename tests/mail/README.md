# Mail tests

## Unit tests (no database or servers)

```
php tests/mail/test_mail_units.php
```

Covers MIME parsing (charsets, RFC 2047/2231, nested multipart, flowed text),
the HTML sanitizer, message building (header injection, Bcc), rule matching,
search parsing and the IMAP response tokenizer.

## End-to-end test (real IMAP + SMTP)

Exercises sync, threading, rules, server-side moves/flags/deletes, undo send,
scheduled send, Sent-folder filing and UIDVALIDITY resets against the test
database.

1. Test database: `tests/db/setup.sh`.
2. A Dovecot IMAP server on `127.0.0.1:1993` (IMAPS, self-signed is fine).
   It needs user `test@example.com` / `secret`, special-use folders
   Sent/Trash/Junk/Archive/Drafts, and an **empty** mailbox.
3. The SMTP sink:
   `python3 tests/mail/fake_smtp.py 2525 /tmp/fake_smtp &`
4. Run:

```
MAIL_TEST_SMTP_OUT=/tmp/fake_smtp php tests/mail/test_mail_integration.php
```

The test prints `SKIP` (and passes) when the IMAP or SMTP server is not
running.
