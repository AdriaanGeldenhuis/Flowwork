\# Calendar Cron Jobs Setup



\## Email Reminders



Run every 5 minutes:



```bash

\*/5 \* \* \* \* /usr/bin/php /path/to/your/app/calendar/cron/send\_reminders.php >> /var/log/calendar\_reminders.log 2>\&1

