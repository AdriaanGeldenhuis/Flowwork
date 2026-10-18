# Flowwork Projects

Projects and their boards — a construction-friendly take on monday.com:
portfolio, My work, project overview with schedule, money, files and team, and
boards with table, kanban, calendar, timeline (Gantt), workload and chart
views, an item panel with updates, subitems and files, automations, saved
views and read-only guests. Built on the Mail design system
(`/mail/css/mail.css`, the "Maison" look shared with Calendar), light and
dark, mobile first.

## Layout

```
projects/
  index.php              portfolio · My work · archive · search (#portfolio, #mywork, #archive, #search?q=)
  view.php               a project: overview · schedule · money · files · team · activity · settings (?tab=)
  board.php              a board (?board_id=&item=&open=&view=&view_id=)
  guest-confirm.php      guest accepts an invitation (chooses a password)
  guest-login.php        guest sign in (rate limited)          guest-logout.php   POST sign out
  guest-view.php         the read-only board for a guest        guest-file.php     a file, for a guest
  guest-changes.php      "this board was updated" probe for the guest page
  css/projects.css       Projects layer on top of mail.css (scoped .fwp)
  js/projects-core.js    shared helpers: api, toasts, popovers, dialogs, people, dates, money,
                         pickers, activity sentences, project sidebar (window.FWP)
  js/projects-home.js    index.php                  js/project.js       view.php
  js/board.js            board state, filters, toolbar, table view, bulk actions, live updates (window.FWB)
  js/board-cells.js      how each column type shows and is edited (window.FWBCells)
  js/board-panel.js      the item panel                js/board-views.js   kanban · calendar · timeline · workload · chart
  js/board-dialogs.js    column settings, filters, sort, hide, saved views, share, automations, import …
  partials/              head (assets), topbar, icons, boot data, guest page shell
  api/*.php              JSON endpoints (fetch; X-CSRF-TOKEN on every POST)
  lib/                   the engine (below)
```

`lib/`:

| File | What it does |
| --- | --- |
| `ProjectSchema.php` | Creates / upgrades the tables once per version (marker in `storage/projects/`) and backfills the new fields |
| `ProjectRepo.php` | Access levels, projects (list, stats, create, update, duplicate, delete), members, stars, recents, preferences, rate limits |
| `BoardRepo.php` | Boards from templates, groups, columns, formulas, the items' mirrored fields, saved views, copy / move / delete |
| `ItemRepo.php` | Items, cell values, moves, archive, subitems, updates (@mentions, replies), watchers, dependencies, files |
| `Columns.php` | The 21 column types: config shapes, validation, decoding, text, status labels, progress |
| `Formula.php` | The formula language (parsed, never `eval`'d) |
| `Automations.php` | "When … then …" recipes, run after the change that triggers them (3 levels deep at most), logged |
| `Activity.php` | The audit log and the activity feeds; the change cursor for live updates |
| `ProjectNotify.php` | Bell + phone notifications (assigned, @mentioned, updates on followed items, added to a project, automations, due dates) |
| `Templates.php` | Board and project templates (tasks, programme, costing, procurement, snags, safety …) |
| `Guests.php` | Guest invitations, sign in, the read-only payload |
| `ProjectLink.php` | **The API other modules use** (URLs, access checks, pickers, My work, money, mail links) |

## Setup

1. **Database.** Nothing to do: the first Projects request creates the missing
   tables (`board_item_people`, `project_user_state`), adds the new columns and
   indexes, widens the column types and fills the items' own fields from the
   boards that already exist. To apply the DDL by hand first, run
   `Migrations/2026-10-18-projects-overhaul.sql` (safe to run twice), then open
   any Projects page once for the backfill.
2. **Storage.** `storage/projects/` must be writable (uploads, schema marker,
   rate-limit buckets). It is ignored by git and denied over HTTP by
   `storage/.htaccess`. Files uploaded by the old boards (under `uploads/…`)
   keep working: the file endpoint finds them where they are.
3. **Reminders.** Due-date reminders go out with the minute tick
   (`notifications/api/tick.php`; see `notifications/README.md`), or with
   `php notifications/worker/project_reminders.php` from a real cron.
4. **Guest emails** use `APP_BASE_URL` (config.php) for the invitation link and
   the company mailer; when sending fails, the manager gets the link to pass on.

## Access

| Level | Who | Can |
| --- | --- | --- |
| 5 admin | company admins | everything in the company |
| 4 owner | the project's owner / creator, members with role owner | + delete the project |
| 3 manager | the project manager, members with role manager | + settings, team, boards, automations, guests |
| 2 member | project members | edit items, groups and columns; post updates; upload |
| 1 viewer | viewers; everyone, on projects shared with the company | read, follow, post updates |

Board members (`board_members`) can open that one board. Company roles
viewer, pos and bookkeeper never get more than viewer. Every query is scoped to
the session's company; every id that arrives is checked against it.

## Boards

- **Columns:** status, people, date, timeline, priority, text, long text,
  numbers (number / money / percent / whole, unit, group summary), formula,
  progress (typed, from status columns or from subitems), dropdown (single or
  several), tags, checkbox, rating, link, email, phone, supplier (CRM), files,
  created, last updated.
- **Formulas:** `{Column}` or `[Column]`, `.start` / `.end` on timelines,
  `+ - * / ^ %`, comparisons, and SUM AVG MIN MAX COUNT ABS ROUND ROUNDUP
  ROUNDDOWN SQRT POWER MOD IF AND OR NOT TODAY DAYS WORKDAYS CONCAT. Renaming a
  column rewrites the formulas that use it.
- **Mirrored fields:** each item keeps `status_label`, `assigned_to`,
  `priority`, `progress`, `start_date`, `end_date`, `due_date`, and
  `board_item_people` lists everyone on it — what Calendar, Home, My work and
  the reminders read with plain SQL.
- **Views:** table (sticky name column, keyboard, drag and drop, resizable
  columns, group summaries, subitems), kanban (by status, priority, person,
  dropdown or group), calendar (month, drag to reschedule), timeline (drag to
  move, drag the ends, dependencies, days / weeks / months), workload (people ×
  weeks, from an Hours / Effort column when there is one), chart. Filters,
  sort, hidden columns and the person filter are per person; saved views can be
  shared with the board.
- **Live:** the board polls `api/changes.php` (4 s while active, slower when
  quiet, 45 s in the background) and merges other people's changes.

## The API for other modules

```php
require_once __DIR__ . '/../projects/lib/autoload.php';
ProjectLink::itemUrl($boardId, $itemId);                     // deep link (optionally &open=updates|files|activity)
ProjectLink::visibleProjectIds($DB, $co, $userId, $role);    // null = every project (admins)
ProjectLink::belongs($DB, $co, $projectId);                  // validate a posted project id
ProjectLink::boardBelongs($DB, $co, $boardId, $itemId);      // validate posted board / item ids
ProjectLink::pickerProjects($DB, $co, $userId, $role, [$keep]);  // + pickerBoards / pickerItems
ProjectLink::myWorkSummary($DB, $co, $userId, $role);        // Home tile
ProjectLink::describe($DB, $co, 'project'|'board'|'item', $id);  // label + URL for a link chip
ProjectLink::linkEmail($repo, 'project'|'item', $id, $emailId);
ProjectLink::financials($repo, $projectId);                  // budget, quotes, invoices, costs, hours
```

Over HTTP: `api/pickers.php?type=projects|boards|items|groups|clients`,
`api/items.php` (`create` with `email_id`, `link_email`), `api/project.list.php`
(the shape Finance reports expect).

## Security notes

- CSRF: `auth_gate.php` checks `X-CSRF-TOKEN` on every API POST; the guest
  forms carry the session token; guest sign out is a POST.
- Uploads: extension allow-list, 25 MB, 200 per item, random names under
  `storage/projects/`, served only through `api/files.php` / `guest-file.php`
  after an access check, with `nosniff` and a sandbox CSP; only images, PDFs and
  plain text open inline.
- Rate limits (per person, file buckets): writes, uploads, imports, guest
  invitations, search; guest sign in by address and by email.
- Guests: tokens are 256-bit, invitations expire, passwords at least 10
  characters, the board needs the guest's session (not just the link), and the
  payload carries no emails, members, money or other boards.
- Exports neutralise spreadsheet formulas in cells.

## Tests

`php tests/projects/test_projects.php` — the engine against the test database
(see `tests/projects/README.md`).
