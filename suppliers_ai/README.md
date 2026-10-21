# Suppliers AI

Find the right supplier or contractor, ask them all for quotes in one go,
compare, choose — and the purchase order is ready. On the Maison design system,
like the rest of Flowwork.

## What it does

* **"What do you need?"** — say it in your own words, in English or Afrikaans.
  As you type it is read without AI (`SaParse`, unit tested): the **trade**
  (36 of them: plumbing, electrical, building, sand & stone, glass, solar…),
  the **place** (“in”, “near”, “naby”, or a known town — also misspelt:
  “vandebijlpark”), **how many or how much** (“3 plumbers”, “20 cubes”,
  “10 tons”), **when** (“tomorrow”, “môre”, “by Friday”, “next week”,
  “12 Nov”, “in 3 days”, “asap”), a **budget** (“under R5k”, “begroting
  R8000”) and **requirements** (COC, B-BBEE level, CIDB grade, tax
  clearance, insured, PIRB, delivery to site, after hours, warranty).
  The budget never leaves the company and never reaches a title or an email.
* **Who can do it — and why.** Every result has a match (0–100) and the
  reasons in words (`SaScore`, unit tested):
  * **your suppliers first** — industry, tags, name and your notes in the CRM,
    preferred or not, a prospect or active;
  * **what you bought before** — posted bills in Finance and orders in
    Procurement for the same thing (the line, date and price for admins and
    bookkeepers; “you bought from them before” for everyone else);
  * **past requests** — who quoted, who won, who never replied, how fast they
    usually answer;
  * **delivery figures** from the CRM (on time, cancelled, lead time);
  * **documents** from the CRM (`CrmCompliance`): in order, expiring,
    expired, missing — and blocked when the company’s policy says so;
  * **businesses near the site on Google** (Places API (New), the company’s
    own key): phone, website, rating and the distance from the place,
    within the radius. A Google listing that is already in your CRM (same
    phone, website or a near-identical name) adds its rating to your supplier
    instead of showing twice;
  * **ideas from AI** — kinds of business and well-known chains to look at,
    **names only**, with a link to look them up. AI also reads the request
    (better search words, the trade when the words alone did not find it).
  * The company’s **never-suggest** list removes results; the **always-ask**
    list ranks them higher. Results below the minimum match are folded away
    (“Show 3 more”), never lost.
* **Nothing is made up.** A phone number or email shows only when it is in
  your CRM or listed by Google. (The old module asked OpenAI for “real
  suppliers with phone numbers” and stored what it invented — Eskom as an
  electrician, 0123456789. Those old searches are marked.)
* **Shortlist → ask for quotes.** Shortlist from any search (kept on the
  device), then one dialog: what, details, quantity, where, needed by,
  quotes by, budget, requirements. A Google find becomes a CRM supplier — a
  **prospect**, tagged with its trade (one you already have, same phone or
  email, is used instead).
* **Asking.** Each supplier gets their **own email** from your mailbox in
  Mail (`SaMail`; they never see each other), with your signature, filed in
  Sent, linked to the supplier in the CRM (activity, replies) and to the
  request (Mail shows “Quotes: …” on the conversation). Or **WhatsApp** with
  the message ready, or “I phoned”, or your own email app. The email is the
  company’s template (Settings) with `{first_name}` filled in for each
  person — or written by AI from the request (never the budget).
* **Replies are noticed.** A reply in the conversation, or a new email from
  the supplier’s address, marks them as replied — on the page (it looks
  every minute) and in the background tick every 15 minutes (it takes the
  requests in turn, so none is left out), which tells the person who asked
  (bell and phone) — unless they were looking at it when it came in. One email
  is one reply on one request: a reply in another request's conversation does
  not count here. When quotes are due, they are told how many came in. An
  email that waited in the Outbox and went later still counts.
* **Quotes, compared.** Write each quote down (VAT included, plus VAT or no
  VAT; lead time; valid until; notes). They are compared **with VAT**, as
  bars against the **budget**, best and over-budget marked (a quote from
  someone who then declined is left out). Remind the slow ones (from the same
  mailbox, in the same conversation), mark who declined.
* **Choose.** The chosen supplier is awarded, the others “not chosen”, a
  prospect becomes an active supplier, the CRM notes it. **Thank the others**
  in one go. **Purchase order** (admins, bookkeepers): a draft in
  Procurement, numbered `PO-2026-0001` from the company’s document sequence.
  The line is the price without VAT and Procurement adds VAT on it: a “plus
  VAT” quote is ordered with VAT on top (as it was compared); a “VAT
  included” quote from a VAT-registered supplier has the VAT taken out (to
  the cent that adds back up where one exists); anything else is ordered as
  quoted, without VAT. **Undo the choice** while the order is still a draft
  (the draft is cancelled with it); once it is approved or being received in
  Procurement, the choice stays. Suppliers your policy blocks cannot be
  asked, chosen or ordered from.
* **Searches** — every search the team ran: open it again (as it was found,
  documents as they are now) or search again; who, where, how many, what came of it.
* **Settings** — the Google and OpenAI keys (write-only: the page shows
  `sk-…AbCd`, never the key; test buttons), search radius, minimum match,
  “documents in order only”, suppliers per request, the company’s hourly and
  daily ceilings on Google/AI look-ups (an email written by AI counts as one),
  the company’s **words** (synonyms,
  its own trades), never-suggest and always-ask lists, and the request email
  with a live preview. The OpenAI key is the one Receipts and Shopping use.
* **Home** — the tile says what is waiting (“2 requests have quotes to compare”).

## Structure

```
suppliers_ai/
  index.php        Find suppliers: what do you need, the reading, results with reasons, ideas, the shortlist (js/find.js)
  requests.php     Requests for quotes as cards (js/requests.js)
  request.php?id=  One request: ask, replies, quotes, compare, choose, thank, order (js/request.js)
  history.php      Searches (js/history.js)
  settings.php     Settings (js/settings.js) — admins and bookkeepers; the keys are for admins
  help.php         How it works, and what leaves Flowwork
  api/             JSON (CSRF on every POST; errors and rate limits in _bootstrap.php)
    search.php     parse, run, get, history, jump
    suppliers.php  add (to the CRM, or link), log (call, WhatsApp, …), crm (look-up)
    requests.php   list, get, compose, create, update, add, remove, send, mark, quote, award, unaward, state, po, draft, refresh
    settings.php   get, save, rules, template, keys, test_ai, test_google
  lib/
    Sa.php         loads everything          SaBoot.php    page bootstrap
    SaKit.php      roles, settings (ai_settings), keys, money, dates, rate limits, ceilings, audit, the bell
    SaSchema.php   tables and columns provisioned on first use (mirrors Migrations/2026-10-24-suppliers-ai-rebuild.sql)
    SaParse.php    words → trade, place, quantity, budget, date, requirements (no database; unit tested)
    SaScore.php    the match and its reasons (no database; unit tested)
    SaSearch.php   the search: CRM, bills, orders, past requests, Google, AI ideas; saved searches; add to the CRM
    SaHttp.php     the one way out (tests swap the transport)   SaPlaces.php  Google Places (New)   SaAi.php  OpenAI
    SaRequests.php requests for quotes, replies, quotes, award, the tick
    SaMail.php     sending through Mail          SaPo.php      the purchase order          SaRetired.php (410 stubs)
  css/suppliers_ai.css  js/sai.js (shared helpers, window.FSA)  partials/ (head, foot, icons)
  ajax/            the old endpoints — 410 Gone (the deploy never deletes files)
```

Elsewhere: `home.php` (the tile), `mail/lib/MailRepo.php` and `mail/js/mail.js`
(“Quotes: …” links on conversations), `notifications/api/tick.php` (replies and
due quotes), `admin/` (the same search settings), `tests/suppliers_ai/`.

## Who may do what

| | Viewer | Member | Bookkeeper | Admin |
|---|---|---|---|---|
| Open searches and requests | ✓ | ✓ | ✓ | ✓ |
| Search, shortlist, add to the CRM, ask for quotes | | ✓ | ✓ | ✓ |
| Change a request (ask, quotes, choose, close) | | their own | ✓ | ✓ |
| See what was billed; make purchase orders | | | ✓ | ✓ |
| Settings and words | | | ✓ | ✓ |
| The OpenAI and Google keys | | | | ✓ |

## What leaves Flowwork

* **OpenAI** (only when an admin switched AI on): the request’s words, today’s
  date and the company’s town; to write an email, the request’s title,
  details, quantity, place, dates and requirements. Never the budget, your
  suppliers or their details. Answers are strict JSON; names with digits,
  `@` or web addresses are dropped.
* **Google** (only with the company’s key): the search words and the place.
* **Suppliers**: exactly the email you see before it is sent.

## What the old Suppliers AI did wrong (fixed)

* It asked OpenAI to “find REAL suppliers” with phone numbers and stored the
  answers as suppliers: invented companies and numbers (“Eskom” as an
  electrician, 0123456789), shown as “AI Found” with a score.
* A hard-coded OpenAI key was written into every new company’s settings, and
  the settings page put the saved OpenAI and Google keys back into its HTML.
* Every render of the results added another click listener, so after a few
  searches one click toggled the shortlist several times; shortlist ids were
  compared as strings and numbers, so the shortlist panel stayed empty.
* “Generate RFQ” made one **R 0.00 draft purchase order per supplier** with a
  random `RFQ-…` number (`mt_rand`, could repeat), created CRM suppliers on the
  side, logged `rfq_bulk` into an enum that stored `''`, and sent the browser to
  `/procurement/rfq_view.php`, which does not exist. Nothing was ever sent.
* “Send email” only opened `mailto:`; nothing was linked to the supplier or noticed.
* Without a detected trade the CRM was not searched at all (“glass suppliers”
  found nobody even with a glass supplier in the CRM); “at least” and “in
  stock” were read as places; the radius did nothing; the hourly limit was per
  person while Admin called it company-wide.
* `test_openai.php` echoed OpenAI’s raw error body, and the endpoints sent
  database error messages to the browser.
* Every page carried its own copy of the old “graphite 3D” design.

## Tests

```
php tests/run.php suppliers_ai   # tests/suppliers_ai/test_sai_parse.php, _engine, _http
```
