# Admin console

The company admin console at `/admin/`, built on the Maison design system
(`/mail/css/mail.css` tokens, then `admin/css/admin.css`). Admins of the
active company only; everyone else gets a polite "admins only" page, and every
API answers 403.

## Layout

```
admin/
  index.php          Overview: people, invitations, projects, plan; set-up checklist; needs attention; recent changes
  company.php        Company profile (name, type, registration & tax, contact, address, logo, bank summary)
  companies.php      Your companies (switch, add) — company_new.php redirects here
  users.php          People & roles (add, edit role/seat, suspend, sign out everywhere, remove)
  invites.php        Invitations (send, resend, copy link, withdraw)
  boards.php         Projects & access (who is on each project, visibility, single-board access, export, archive)
  finance.php        Financial year, VAT basis, two-person journals (+ links into Finance)
  quotes.php         Redirects to /qi/settings.php (Quotes & Invoices owns its settings)
  payroll.php        Pay schedule and posting to Finance
  suppliers.php      Supplier search and compliance-document rules
  mail.php           Connected mailboxes and who sends what
  calendar.php       Company calendar defaults
  automations.php    What runs on its own, and whether the background runner is alive
  integrations.php   Yoco card payments
  security.php       Sign-in protection, password rules, signed-in devices, sign-in activity
  data.php           Exports and how long sign-in records are kept
  billing.php        Plan, seats, companies, change plan, cancel/resume
  audit.php          Audit trail (filters, paging, CSV export of the same filter)
  lib/               AdminBoot (page bootstrap), AdminKit (helpers, nav, labels), AdminSettings (the registry),
                     AdminAudit (filters, counts), AdminMail (invitation email), AdminSchema (invites columns)
  api/               JSON endpoints called with fetch (CSRF header): settings, people, invites, company,
                     companies, projects, security, billing, export (GET, CSV)
  partials/          head.php (top bar, sidebar), foot.php (boot data, scripts), icons.php
  css/admin.css      Console styles (scope body.fwm.fwa)
  js/admin.js        Core: API, toasts, dialogs, theme, menu, drawer, "Find a setting", the settings engine
  js/*.js            One script per page that needs one
```

## Settings: one registry

`lib/AdminSettings.php` lists every setting the console shows — label, help,
control, default, limits and **where the module reads it**:

| Area | Stored in | Read by |
|---|---|---|
| calendar | `company_settings` `calendar_*` | `calendar/lib/CalendarSettings.php`, `CalendarRepo::companyTz` |
| security | `company_settings` `security_*` | `fw_login_limits`, `fw_auth_single_device` (includes/auth_sessions.php), `includes/password_policy.php` |
| data | `company_settings` `signin_retention_days` | `includes/audit_retention.php` (hourly, from notifications/api/tick.php; sign-in records only) |
| payroll | `payroll_settings` columns | `PayKit::settings` / `PayKit::suggest` (new-run dates), `PayRuns::finalise` (auto-post) |
| suppliers | `ai_settings` columns + `crm_block_expired_suppliers`, `crm_reminder_days` | `SaKit::settings` (suppliers_ai/lib: searches, requests for quotes), `CrmCompliance::policy` |
| finance | via `FinSettings::saveEngine` (its guards apply) | `Fin::engineSettings` |
| payments | `companies.yoco_sec_key`, `yoco_webhook_secret` (never sent back to the browser) | `qi/lib/QiYoco.php`, `webhooks/yoco.php` |

Pages draw their rows from the registry (`AdminSettings::render`) inside
`<form data-settings="area">`; `js/admin.js` tracks changes, shows the
unsaved-changes bar (Ctrl/⌘ S saves) and posts only what changed to
`api/settings.php`, which validates every value against the same registry
(422 with per-field messages) and writes one readable audit line.
Settings no module reads are not shown.

Invitations are emailed from the inviting admin's own mailbox when connected,
else through system mail; otherwise the admin gets the link to copy.
`/accept-invite.php` handles new people (password rules apply, then signed
in) and existing Flowwork accounts (sign in, then join with one click).

## Database

`Migrations/2026-10-20-admin-overhaul.sql` adds `invites.custom_message`,
`last_sent_at`, `send_count` (also provisioned on first use by `AdminSchema`).

## Tests

`php tests/run.php admin` — `tests/admin/test_admin.php` saves every area and
checks the module that uses it reads the new value, refuses bad values field by
field, keeps secrets hidden, and covers password rules, sign-in record
retention (nothing else is pruned) and the audit filters.
