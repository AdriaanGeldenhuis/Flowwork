# Calendar tests

```
php tests/calendar/test_calendar.php
```

Needs the test database (`tests/db/setup.sh`). Runs the calendar engine
(`calendar/lib`) directly, no web server:

- schema provisioning (utf8mb4, link types, new columns)
- repeat rules: weekly by day, skipped dates, the 31st and 29 February,
  "second Tuesday" / "last Friday", every other week, UNTIL, far-off windows
- access: private, shared (view / edit), team calendars, viewers, Busy and
  Private events, chronological feed
- create / update validation, partial updates keeping length, emoji titles
- invitations, replies and their notifications, free/busy, declining
- repeating events: change one, change this and following, change all from
  an occurrence, delete one / following / all
- reminders in the company time zone: due, not yet due, missed, snoozed,
  declined attendee, repeating event, no double sends
- live Flowwork dates (invoices, projects, tasks) with company and per-user
  switches and role / project access — projects and tasks by the Projects
  engine's rules (members, company-shared projects, board members, everyone
  on an item, timeline spans, done / overdue, archived and completed hidden)
- old sync copies hidden and removed on save; links back to records
- CRM follow-ups and project timelines (events linked to the project, its
  boards and tasks; board members see only their boards' tasks)
- links to projects, boards and tasks (who may link them, search) and
  project calendars
- iCalendar export (escaping, UTC, all-day, folding) and import (time zones,
  DURATION, CANCELLED, PRIVATE, changed occurrences, re-import without
  duplicates)
- search, settings, rate limiting, deep links

It creates its own calendars, events, invoices, customer, projects, boards and
items (with their people and board members), and removes them (and restores
the calendar settings it changes) when it ends.
