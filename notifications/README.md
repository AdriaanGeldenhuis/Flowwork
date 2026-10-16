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
   deploy. Elsewhere is fine too: `define('FCM_SERVICE_ACCOUNT_FILE', '/path/key.json');`
   in `config.php`. Make it readable by PHP only (`chmod 600`). The folder
   must be writable by PHP: the OAuth access token is cached next to the key.
   Also check Firebase console › Project settings › Cloud Messaging: *Firebase
   Cloud Messaging API (V1)* must show **Enabled**.

2. **Cron.** Add next to the mail jobs:

   ```
   *    * * * *  php /path/to/notifications/worker/send_push.php  >/dev/null 2>&1
   ```

   Calendar reminders also need `calendar/cron/send_reminders.php` every
   5 minutes (see `calendar/cron/README.md`) if that job is not set up yet.

   Most pushes leave at the end of the request that created them; the worker
   is the safety net and the retry loop. New-mail notifications are as fresh as
   the mail sync (`mail/worker/sync_imap.php`, every 5 minutes, or sooner while
   Mail is open somewhere).

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
