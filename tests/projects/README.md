# Projects tests

```
php tests/projects/test_projects.php
```

Runs the Projects engine (`projects/lib`) against the local test database
`flowwork_test` (create it with `tests/db/setup.sh`). It uses company 1 and its
users 1 (admin), 3 (member) and 4 (admin), creates its own projects, boards and
items, and removes everything it created when it finishes — even when an
assertion fails.

What it covers:

- schema provisioning and the backfill of the items' own fields
- access levels (owner, manager, member, viewer, company-visible projects,
  board members, capped company roles) and cross-company isolation
- projects: create from a template, numbering, update, members, duplicate,
  archive, delete
- boards, groups and columns; validation of every column type; status label
  aliases; formulas (functions, timelines, renamed columns)
- mirrored fields and `board_item_people` (what Calendar, Home and My work read)
- moves, reorder, copy, archive / restore / delete, move to another board
- subitems and progress from subitems; updates with @mentions, replies and
  notifications; watchers; dependencies (no loops)
- automations, saved views, guests, money, search, My work and the
  `ProjectLink` API other modules use

The browser side was checked by hand and with Playwright walkthroughs (every
view and dialog, light and dark, desktop and phone, and the guest journey).
