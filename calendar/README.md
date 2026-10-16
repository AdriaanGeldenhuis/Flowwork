# Flowwork Calendar

The company calendar: personal, team and shared calendars, repeating events,
invitations with replies, reminders (bell, phone, email), files and links to
Flowwork records, plus live dates from the other modules (projects, tasks,
invoices, quotes, deals, pay days). Built on the Mail design system
(`/mail/css/mail.css`), light and dark, mobile first.

## Layout

```
calendar/
  index.php              the app (day · week · month · year · schedule, event panel, editor)
  settings.php           General · Calendars · From Flowwork · Import & export
  ics.php                private subscription feed (?token=…), no session
  event_view.php         old links → /calendar/?event=ID
  event_new.php          old links → /calendar/?new=1 (or ?event=ID&edit=1)
  css/calendar.css       calendar layer on top of mail.css (scoped .fwc)
  js/calendar-core.js    shared helpers + calendar dialogs (window.FWCal)
  js/calendar.js         the app
  js/calendar-settings.js
  partials/              head (assets), topbar, extra icons
  api/*.php              JSON endpoints (fetch; CSRF header on every POST)
  lib/                   engine (below)
  cron/send_reminders.php  CLI reminder job
  ajax/, assets/         retired; .htaccess denies them
```

`lib/`:

| File | What it does |
| --- | --- |
| `CalendarSchema.php` | Creates / upgrades the tables once per version (marker in `storage/calendar/`) |
| `CalendarRepo.php` | Access rules, feed, create / update / move / delete with scopes, replies, search, import, free/busy, notifications |
| `Recurrence.php` | Repeat rules (DAILY / WEEKLY / MONTHLY / YEARLY, INTERVAL, COUNT, UNTIL, BYDAY incl. "2TU" / "-1FR", BYMONTHDAY) |
| `CalendarSources.php` | Live items from other modules |
| `CalendarLinks.php` | Linking events to records, permission-checked |
| `CalendarSettings.php` | Per-user settings over the company defaults |
| `Ics.php` | iCalendar export and import (RFC 5545) |
| `CalendarLink.php` | The API other modules use |
| `reminders.php` | Sends due reminders |

## Setup

1. **Database.** Nothing to do: the first request to the calendar (or the
   reminder job) converts the tables to utf8mb4, adds the new columns and
   indexes, and extends the link types. To apply it by hand instead, run
   `Migrations/2026-10-17-calendar-overhaul.sql` (safe to run twice). Its
   commented-out tail removes the copies the old syncs wrote; review first.
2. **Reminders.** Already running if the web tick is set up
   (`notifications/api/tick.php`, every minute; see `notifications/README.md`).
   On a host with a normal cron you can use
   `*/5 * * * * php /path/to/calendar/cron/send_reminders.php` instead.
3. **Storage.** `storage/calendar/` must be writable (attachments, schema
   marker, rate-limit buckets). It is ignored by git and denied over HTTP by
   `storage/.htaccess`.
4. **Company defaults** (admins): `/admin/calendar.php` — time zone, week start,
   working hours, default view and reminder, and which Flowwork dates appear.

## How it behaves

- **Times** are wall-clock times in the company's calendar time zone
  (`calendar_timezone`, default Africa/Johannesburg). Reminders and "today" use
  that zone, not the server's. Exports are in UTC.
- **Who sees what.** Personal calendars: the owner, plus people it is shared
  with (view, or edit = may add events). Team calendars: everyone in the
  company; members add events, owners and admins manage the calendar. Project
  calendars: people on the project. The organiser and invitees always see an
  event; others see it in full, as **Busy**, or not at all (**Private**).
- **Repeating events** can be changed or deleted for *this event*, *this and
  following* (the series is split) or *all events*.
- **Invitations** notify invitees (bell + phone); replies notify the
  organiser; time/place changes and cancellations notify attendees. Declining
  removes that person's reminders.
- **Flowwork dates** (project start/due, my tasks, unpaid invoices, sent
  quotes, my open deals, pay days for admins/bookkeepers) are read live and
  never copied. Copies written by the retired syncs are hidden and removed as
  documents are saved.
- **Subscriptions:** each calendar has a private `.ics` link (Settings →
  Calendars) that can be reset; private events are never published.

## Deep links

| URL | Opens |
| --- | --- |
| `/calendar/?event=12&date=2026-10-21` | an event (the occurrence on that date) |
| `/calendar/?event=12&edit=1` | the editor for an event |
| `/calendar/?new=1&title=…&date=…&start=09:00&end=10:00&all_day=0&location=…&description=…&link=invoice:44&calendar_id=3` | the editor, filled in |
| `/calendar/?view=month&date=2026-10-21&focus=invoice:44` | a day with a live item highlighted |

## Integrations

```php
require_once __DIR__ . '/../../calendar/lib/autoload.php';

// A follow-up on the user's own calendar, linked to a record, with reminders
CalendarLink::followUp($DB, $companyId, $userId, $role, 'crm_account', 12, 'Call back', '2026-10-21 10:00', 60, ['in_app', 'email']);

// Upcoming events linked to a record that this user may see
CalendarLink::upcomingFor($DB, $companyId, $userId, $role, 'project', 5);

// A project's events + start/due/task dates
CalendarLink::projectTimeline($DB, $companyId, $userId, $role, 5, '2026-10-01', '2026-12-31');

// Link into the calendar
CalendarLink::url('2026-10-21', 'invoice:44');
```

Used by: Home (today tile), CRM (follow-ups on accounts and deals), Quotes &
Invoices ("View in Calendar", "Schedule in Calendar…", old-copy clean-up),
Projects (project calendar, add event, .ics), Mail ("Add to calendar" on a
message), Admin (company defaults) and the notification bell.

## Security

- Every endpoint goes through `auth_gate.php`; every POST needs the CSRF header.
- All queries are prepared and scoped to the company; access is checked per
  calendar and per event on the server.
- Writes that touch several tables run in a transaction.
- Attachments: 15 MB each, 20 per event, stored under `storage/calendar/`
  (denied over HTTP) with random names, served only through the API with
  `nosniff` and a sandbox CSP.
- Rate limits per user: event writes (300 / 10 min), uploads and calendar
  changes (60 / 10 min), search (120 / min), import (10 / 10 min); the
  subscription feed allows 120 requests an hour per link.

## Tests

`php tests/calendar/test_calendar.php` — see `tests/calendar/README.md`.
