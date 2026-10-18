# Calendar reminder job

Reminders are sent by the web tick (`notifications/api/tick.php`, every
minute; see `notifications/README.md`). On a host with a normal cron you can
run the job directly instead:

```bash
*/5 * * * * /usr/bin/php /path/to/app/calendar/cron/send_reminders.php >> /path/to/logs/calendar_reminders.log 2>&1
```

It is CLI only (403 over HTTP). See `calendar/README.md`.
