# Shopping

Shopping lists for the business and the kitchen — on the Maison design
system. Type or paste what you need, take the list to the shop, tick things
off with what they cost, and the price book fills itself. Lists are private
(only the owner) or shared with the team (everyone in the company sees them,
adds to them and ticks things off; viewers only look).

## What it does

* **"What do you need?"** (overview) and the **add bar** (on a list) read a
  typed or pasted list as you type (`ShParse`, unit tested):
  * a count first or last — `2x PVC elbow 20mm`, `10 bags of cement`,
    `6m conduit 16mm`, `cement x 10`, `Cable ties (100)`, `Gloves - 5 pairs`,
    `½ dozen eggs`, `2 sakke sement`;
  * sizes stay in the name — `PVC elbow 20mm` is one elbow and `milk 2L`
    one bottle (the old parser made them 20 mm and 2 L);
  * `@ R95` or `R18.99` is the price of one; `!`, *urgent* or *asap* makes
    it urgent; bullets, numbering and check boxes are dropped; a comma splits
    a line only when every part is an item (`milk, bread, eggs` but not
    `Cement, 50kg bag`).
  * The same thing again **adds up** (`cement x 5` on top of `10 bags cement`
    is 15 bags). A missing price comes from **the last one paid**. Each item
    lands in its **aisle** (building & hardware, electrical, plumbing,
    safety & PPE, office, cleaning, groceries, vehicle & fuel, everything
    else); move one and the next one goes there too.
* **A list** — by aisle or as added, urgent first; the trolley at the bottom.
  Each item: quantity and unit, aisle, expected price, needed-by date,
  project, store, notes. Tap to edit; remove with undo. Budget, date,
  project, usual store and a note on the list. Duplicate, save as a template,
  download (CSV, formulas kept as text), print (a clean checklist), delete
  (with "bring it back").
* **Start shopping** — the in-the-shop view: big rows (a tap anywhere ticks),
  the trolley total against the budget, "What did it cost?" as you tick (each
  or for all), the store you are at, the screen kept awake (Wake Lock), a
  buzz on each tick. **No signal?** Ticks and prices wait on the phone
  (localStorage) and are sent when it is back — sending one twice is
  harmless. **Shopping together:** everyone on the list sees the others'
  ticks appear (the change stamp is polled every 4 s while shopping, 15 s
  otherwise). **Finish** keeps, moves (to a new list) or takes off what was
  not found; the owner is told when someone else finishes.
* **Where to buy** (per item) — what you paid before and where (cheapest
  first), the suppliers who billed you for it (from Finance's posted bills;
  admins and bookkeepers only), your stores for that aisle, preferred CRM
  suppliers — each with call, WhatsApp, directions and "Buy here". When an
  admin switches it on, **AI ideas**: kinds of shop and chains near the
  company's town, from the item's name and the town only, marked as ideas,
  never with phone numbers or prices.
* **Share a link** with someone without Flowwork (a driver, a runner, family):
  `/shopping/share.php?t=…` opens on their phone with no sign-in; they tick
  things off (and add, when allowed) under the name they type. They never see
  prices or the budget. Links run for a day, a week or a month; stop one any
  time. The owner hears when everything is bought through a link.
* **Purchase order** (admins, bookkeepers) — what is still to buy goes on a
  draft order in Procurement: numbered `PO-2026-0001` from the company's
  document sequence, prices typed as paid (VAT included) and stored without
  VAT when the supplier has a VAT number. An item goes on one order only.
* **Templates** — the usual lists (monthly office, site start-up kit), typed
  out or saved from a list; start a list from one in a tap.
* **Prices & stores** — the price book (last and best price, where, how often,
  whether it went up) and the stores (aisles they are good for, linked to a
  CRM supplier, what was bought there).
* **Preferences** — ask the price as I tick, keep the screen on, the usual
  store, group by aisle or as added, what new lists are for, directions by
  car, foot or bike; admins switch AI store ideas on or off.
* **Home** — the Shopping tile says what is waiting ("12 to buy on 3 lists",
  "1 out shopping now").

## Structure

```
shopping/
  index.php        Overview: what do you need, the counts, list cards (open / mine / team / done), needed soon, lately bought, this month by aisle
  list.php?id=     One list (js/list.js): aisles, the trolley, shopping, where to buy, share, order, details
  templates.php    Templates (js/templates.js)
  prices.php       The price book and the stores (js/prices.js); #stores opens on the stores
  preferences.php  Preferences (js/prefs.js)
  share.php?t=     A shared list, no sign-in (js/share.js)
  api/             JSON (CSRF on every POST; errors and rate limits in _bootstrap.php)
    lists.php      overview, get, version, search, export, suppliers, projects, create, update, delete, restore,
                   duplicate, shop, shop_store, finish, reopen, share, unshare, template, po
    items.php      parse, add, update, toggle, price, remove, restore, reorder
    stores.php     list, prices, suggest, ai, plan, save, delete
    templates.php  list, save, use, delete
    settings.php   get, save
    share.php      for a shared link: get, version, toggle, add (its own CSRF and rate limit, no login)
  lib/
    Sh.php         loads everything          ShBoot.php    page bootstrap
    ShKit.php      roles, preferences, money, quantities, dates, rate limit, activity, audit, the bell
    ShSchema.php   columns provisioned on first use (mirrors Migrations/2026-10-23-shopping-rebuild.sql)
    ShParse.php    text → items, units, aisles, fingerprints (no database; unit tested)
    ShLists.php    who sees what, cards, overview, one list, the change stamp, shopping, finish, search, CSV
    ShItems.php    add (adding up, last price, learned aisle), edit, tick (safe to repeat), price book rows, remove
    ShStores.php   stores, where to buy, AI ideas, the price book
    ShTemplates.php  ShPo.php (purchase orders)  ShShare.php (links)  ShRetired.php (410 stubs)
  css/shopping.css  js/shopping.js (shared helpers, window.FWS)  partials/ (head, foot, icons)
  ajax/            the old endpoints — 410 Gone (the deploy never deletes files)
```

## Who may do what

| | owner | team member (team list) | viewer | admin |
|---|---|---|---|---|
| See the list | ✓ | ✓ | team lists only | own and team lists — a private list stays private |
| Add, tick, edit items, shop, finish | ✓ | ✓ | | ✓ (team lists) |
| Rename, budget, date, project, note | ✓ | ✓ | | ✓ |
| Who sees it, links, delete | ✓ | | | ✓ (team lists) |
| Purchase order | admins and bookkeepers |
| Supplier bills in "Where to buy" | admins and bookkeepers |
| AI store ideas on or off | admins |

## What the old Shopping did wrong (fixed)

* `ajax/session_close.php` closed **any company's** list and set its status
  (no company check); the item endpoints let anyone in the company change a
  **private** list; `list.php` showed any list in the company by its id.
* Store suggestions wrote the AI's text and CRM phone numbers **unescaped**
  into the page and into `onclick` handlers (stored XSS).
* The OpenAI key was hard-coded (and had been removed), so AI suggestions
  always failed; the prompt was fixed to one town.
* After a tick the page reloaded **removed items** back onto the list; item
  counts included removed items; "Est. total" was always R 0.
* "PVC elbow 20mm" became 20 **mm** of elbow; "milk 2L" became 2 litres.
* "Delete list" called a page that did not exist; Edit, Templates, Export,
  Share, Convert to RFQ and "Select store" were "coming soon".
* Purchase-order numbers were random (`mt_rand`, could repeat), every line
  was R 0.00, bought items went on the order too, and deleted suppliers
  could be chosen.
* Ticking never recorded a price, so the price history (and the "bought here
  before" suggestions) was always empty.
* Starting to shop twice made two sessions; stopping an empty list marked it done.

## Tests

```
php tests/run.php shopping     # tests/shopping/test_shopping_parse.php, _lists, _http
```
