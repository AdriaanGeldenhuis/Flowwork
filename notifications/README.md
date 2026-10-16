# Notifications

In-app notifications (the bell) and phone notifications for the Flowwork
Android app (FlowWork / FlowMail / FlowDrive icons), sent through Firebase
Cloud Messaging (FCM).

## Layout

```
includes/notify.php                 fw_notify(): bell row + phone push in one call;
                                    creates/repairs the `notifications` table
includes/push.php                   devices, outbox, FCM HTTP v1 client, signed
                                    action tokens (Mark as read / Archive)
notifications/worker/send_push.php  cron: sends the queue and retries
notifications/api/tick.php          the same jobs over HTTP, for an outside
                                    scheduler (host cron limited to 2-hourly)
mail/api/push_action.php            the notification buttons (token-authorised)
Migrations/2026-10-16-push-notifications.sql   optional, same schema
tests/notifications/                test_push.php, test_push_http.php
```

## What sends a notification

| Event | Bell | Phone category | Opens |
|---|---|---|---|
| New email (per conversation, newest message) | one line per sync | FlowMail › New email | FlowMail, the conversation |
| Reply overdue (Mail settings › Reply reminders) | yes | FlowMail › Reply reminders | the conversation |
| Calendar reminder | yes | FlowWork › Event reminders | the event |
| @mention on a project board | yes | FlowWork › Mentions | the item's comments |
| "Send a test notification" (Signed-in devices) | no | FlowWork › Updates | Signed-in devices |

New-mail notifications respect Mail settings › "Flowwork notifications for new
mail". The first import of a newly connected mailbox does not notify. Reading,
archiving or deleting a conversation anywhere in Flowwork (or from the
notification itself) removes its notification from all of the user's phones.

## How a phone is registered

The app sets a first-party cookie `fw_push` (its FCM token) on its WebView.
On every request `init.php` calls `fw_push_sync_device()`, which ties that
token to the signed-in device row in `user_sessions`. Consequences:

- only signed-in phones receive anything; logging out, signing the device out
  in **Signed-in devices**, a password reset or a suspension stops it at once;
- another person signing in on the same phone takes the token over;
- nothing extra is posted, so there is no extra endpoint or CSRF token.

A device row stays signed in for `REMEMBER_ME_EXPIRY` without use. A phone that
is not opened for longer than that is signed out and stops getting
notifications. `config.example.php` uses 30 days, which suits the app.

## Server setup (once)

1. **Firebase key.** Firebase console › Project settings (project
   `flowwork-f26fe`) › Service accounts › *Generate new private key*. Upload
   the JSON file to the server as

   ```
   storage/firebase/service-account.json
   ```

   `storage/` is not web-readable, is not in git and is not touched by the
   deploy. Until the key is there, nothing is queued or sent. Elsewhere is fine too: `define('FCM_SERVICE_ACCOUNT_FILE', '/path/key.json');`
   in `config.php`. Make it readable by PHP only (`chmod 600`). The folder
   must be writable by PHP: the OAuth access token is cached next to the key.
   Also check Firebase console › Project settings › Cloud Messaging: *Firebase
   Cloud Messaging API (V1)* must show **Enabled**.

2. **Every-minute jobs (the tick).** xneelo's cron runs at most every two
   hours, which is too slow for new-mail notifications and calendar reminders
   (a reminder is only sent between its reminder time and the event start).
   So a free outside scheduler calls a secret URL every minute instead:

   a. Add a random key of 32+ characters to the server's `config.php`:

      ```php
      define('FW_CRON_KEY', '...64 random letters/digits...');
      ```

   b. At https://cron-job.org (free) create a cron job: URL
      `https://www.flowwork.app/notifications/api/tick.php?key=<the key>`,
      schedule *every minute*, request method GET.

   Each call sends queued phone notifications (and retries), sends due
   calendar reminders, sends due scheduled mail and syncs mailboxes not synced
   in the last 90 seconds, runs reply-overdue reminders every 15 minutes and
   housekeeping hourly. It answers at once with the previous run's figures
   (shown in cron-job.org's history) and works up to ~45 s after that. Runs
   never overlap and are at least 20 s apart. Without `FW_CRON_KEY` the URL
   answers 503 and does nothing.

   Optional safety net in xneelo's cron (every 2 hours):

   ```
   /usr/local/bin/php /usr/home/<user>/public_html/notifications/worker/send_push.php
   ```

   On a host whose cron allows every minute, the CLI jobs do the same work:
   `notifications/worker/send_push.php` (every minute),
   `calendar/cron/send_reminders.php` and `mail/worker/sync_imap.php` (every
   5 minutes), `mail/worker/sla_tick.php` (every 15 minutes).

3. **Schema.** Created automatically on first use. Running
   `Migrations/2026-10-16-push-notifications.sql` does the same up front.

4. **Check it.** Open Flowwork in the app › menu › *Signed-in devices*. The
   *Phone notifications* card says whether this phone is ready; *Send a test
   notification* sends one right away and reports Firebase's answer. Errors are
   logged with the prefix `[push]`.

## Payload

Data-only FCM messages (the app draws every notification itself, also when it
is closed); keys are documented in
`app files/app/src/main/java/app/flowwork/push/Notifier.java`.

## Tests

```
php tests/notifications/test_push.php        # queue, sending, retries, tokens (fake FCM)
php tests/notifications/test_push_http.php   # app sign-in → binding → buttons → sign-out
```

Both need the test database (`tests/db/setup.sh`).
