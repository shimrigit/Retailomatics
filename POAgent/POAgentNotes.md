# POAgent — Development Notes

**Project Location:** `C:\xampp\htdocs\website\POAgent`
**Spec:** `pre-demo` build, based on *Purchase Order & Delivery Note Matching System — Pre-Demo Development Spec (v3)* (Priority DB → 3 local directories, WhatsApp bot → web interface).
**Last Updated:** September 13, 2026
**Status:** Phase 1 complete (PO creation flow). DN ingestion pipeline complete end-to-end,
including the human-in-the-loop Review screen and DN/VS visibility from the history list — photo
import, OCR, manual correction, Diff Engine/VS generation, status transitions, and now DN/VS
lookup from `po_list.php` are all wired and verified (see §2, §6).

---

## 1. What This Is

A pre-demo stand-in for the full Priority-integrated PO/Delivery-Note matching system. Same
architecture principles as the eventual system, but:
- Priority DB → three local directories (`SuppliersDB/`, `POdir/`, `DNdir/`)
- WhatsApp bot → plain PHP web UI (thin client, no business logic)
- Single user session at a time, single laptop, no auth/security hardening (matches spec §8.9 —
  revisit only when deployed to a real server)

All business logic lives in `lib/` (the "brain"); UI screens only call into it and render.

---

## 2. Build Order Progress (spec §9)

| # | Item | Status |
|---|---|---|
| 1 | `SuppliersDB` reader + `SupplierStore` adapter | ✅ Done |
| 2 | `POStore` adapter + atomic PO counter + PO creation flow (Screens 1–2 + PO flow) | ✅ Done |
| 3 | DN upload + storage (no OCR yet) | ✅ Done |
| 4 | Wire in Retailomatics OCR + OCRsanity gate | ✅ Done — simplified single-shot vision call, not the full harmonizedFlow pipeline (see §6) |
| 5 | Barcode matching + Review screen | 🟡 Mostly done — editable Review screen built (`dn_review.php`/`dn_confirm.php`), correcting any field or deleting/adding a row all work; the one piece not built is "add to supplier catalog" for a genuinely-new item (spec §4.3's other half) — see §6 |
| 6 | Diff Engine (fused) — VS generation | ✅ Done |
| 7 | Status lifecycle transitions (`open`→`prcv`/`closed`) | ✅ Done |
| 8 | UI: status/history view, VS display | ✅ Done — `po_list.php`'s unified column links to `po_view.php`, the single PO+DN+VS 3-panel view |
| 9 | End-to-end test incl. multi-delivery + unknown-barcode + exact-match cases | 🟡 Partial — exact-match case verified for real (see §6); multi-delivery and unknown-barcode cases await the user's own variance-testing pass |

---

## 3. Directory Structure

```
POAgent/
├── index.php            Screen 1 — user select (user1/user2/user3 → generator_id)
├── select_user.php       Handles Screen 1 submit, stores generator_id in session
├── main_menu.php         Screen 2 — Create PO / Upload DN
├── po_supplier.php       PO flow step 1 — supplier list
├── po_items.php          PO flow step 2 — item list w/ prices + qty inputs
├── po_confirm.php        PO flow step 3 — confirm screen, re-derives prices server-side
├── po_create.php         PO flow step 4 — writes the PO via POStore, flash-redirects
├── po_success.php        PO flow step 5 — confirmation screen (one-time session flash)
├── po_view.php           Unified PO+DN+VS view (from po_list.php) — 3 panels side by side: PO on
│                         the right (always, sticky, same rendering as po_success.php), then one
│                         row per delivery with the DN photo+data in the middle and its VS on the
│                         left. Replaces the earlier separate dn_view.php/vs_view.php (deleted)
├── po_list.php           Status/history view — filename-glob backed, totals per PO, one unified
│                         "תעודות ודוחות" column (DN/VS counts folded into the po_view.php link).
│                         Defaults to ALL users' POs — pass ?mine=1 to narrow to the current
│                         session's user (demo-debugging default, revisit after the demo)
├── dn_select_po.php      DN flow step 1 — pick the open/prcv PO this delivery is against
│                         (shows ALL users' eligible POs, not just the current session's — by
│                         explicit request, for demo purposes)
├── dn_browse.php         DN flow step 2 — server-side folder browser (defaults to Desktop\DN
│                         pictures; no OS file-picker can be defaulted from a webpage)
├── dn_import.php         DN flow step 3 — copies the photo into DNdir/, OCRs it (one vision call),
│                         sanity-checks it, stashes the draft in session, redirects to dn_review.php
│                         (GET-only from here on — refreshing never re-runs OCR)
├── dn_review.php         DN flow step 4 — human-in-the-loop Review screen: editable table over the
│                         OCR draft (invalid cells highlighted red), correct/delete any row, 3 blank
│                         spare rows for anything OCR missed entirely — shown side by side with the
│                         actual DN photo (sticky, stays in view while the form column scrolls) so
│                         corrections can be made by eye against the source, not from memory
├── dn_confirm.php        DN flow step 5 — re-validates the (possibly hand-corrected) posted rows
│                         server-side, finalizes the DN JSON, runs DNPipeline::finalizeDelivery()
│                         (Diff Engine + VS + status), flash-redirects to dn_result.php
│                         (POST/redirect/GET, same pattern as po_create.php)
├── dn_result.php         DN flow step 6 — shows the OCR extraction + VS + status transition from
│                         the one-time flash
├── lib/
│   ├── ui_common.php      Shared HTML shell (RTL/Hebrew card layout) + session helper +
│   │                      poagent_render_po_detail() (shared by po_success.php/po_view.php) +
│   │                      poagent_render_dn_detail()/poagent_render_vs_detail() (shared by
│   │                      dn_result.php/po_view.php) + poagent_render_zoom_panel() (inline
│   │                      zoom/pan photo panel) + poagent_agorot_to_ils()
│   ├── filename_utils.php Sanitize-for-filename + write-to-temp-then-rename helpers
│   ├── SupplierStore.php  DataStore adapter — reads SuppliersDB/*.xlsx catalogs
│   ├── POStore.php        DataStore adapter — atomic counter, PO JSON read/write/status
│   ├── DNStore.php        DataStore adapter — DN image import (importImage) + finalized DN JSON
│   │                      write (finalize) + per-PO DN listing (listForPo) in DNdir/
│   ├── DNOcr.php          Single-shot OpenAI vision call (gpt-4.1-mini) on a whole DN photo,
│   │                      including the document's own printed grand total (dn_total, nullable) —
│   │                      reuses AIocr/config.json's key; NOT the harmonizedFlow pipeline (see §6)
│   ├── DNSanity.php       Lightweight validation gate (numeric qty/price, barcode digits/length) —
│   │                      OCRsanity's validation *concept*, reimplemented against JSON not xlsx;
│   │                      dn_confirm.php re-applies the same rules to hand-corrected values
│   ├── VSStore.php        DataStore adapter — per-PO delivery-index counter (in POcounter/,
│   │                      prefixed vs_...), VS JSON write/list into DNdir/
│   ├── DiffEngine.php     Fused qty+price comparison, cumulative across a PO's VS history, plus
│   │                      total_check (declared-vs-expected, declared-vs-computed) — ALWAYS
│   │                      produces a VS, matched or not (spec §4.4)
│   └── DNPipeline.php     Shared tail — finalize the (reviewed) DN record, run the Diff Engine,
│                          write the VS, apply the PO status transition. One implementation, called
│                          only from dn_confirm.php today, but factored out so a future caller
│                          (e.g. a "skip review" fast path) wouldn't have to duplicate it
├── tools/
│   └── seed_demo_suppliers.php   Dev-only: (re)writes 3 placeholder supplier catalogs
├── SuppliersDB/          supplier_<SupplierID>.xlsx catalogs (Barcode | ItemName | Price)
├── POdir/                PO JSON records — gitignored (generated data, not source; see §4)
├── POcounter/             Atomic counter files — PO##### counter (.po_counter) AND, per explicit
│                          request, every other POAgent counter lives here too (VSStore's per-PO
│                          delivery-index counters, prefixed vs_<po_core_name>.count) — deliberately
│                          its own dir, gitignored, so it can never be wiped as a side effect of
│                          clearing POdir/ or DNdir/
└── DNdir/                DN photos (dn_import.php) + finalized DN JSON + VS JSON (dn_confirm.php,
                         after the Review screen), gitignored
```

---

## 4. Key Implementation Decisions

- **DataStore adapters** (`SupplierStore`, `POStore`) are the only code that knows about the
  filesystem layout, per spec §7 — UI screens never touch `SuppliersDB/`/`POdir/` directly.
- **Prices are re-derived server-side, always.** Every step that receives a barcode+qty from a
  form (`po_confirm.php`, `po_create.php`) looks the price back up in the supplier catalog by
  barcode — client-submitted price/name values are never trusted, even though they're only
  echoed back as hidden fields.
- **Filename-glob is the index** (spec §8.1) — `POStore::listPOs()` just globs `POdir/`, no
  separate index file.
- **Atomic PO counter** (spec §8.2) — `POStore::nextPoId()` uses `flock()` on `.po_counter`
  around read-increment-write.
- **Counter lives in its own gitignored directory, `POcounter/`, separate from `POdir/`** (Aug
  18, 2026) — both `POdir/` (generated PO records) and `POcounter/` (the counter) are gitignored,
  each with its own `*` / `!.gitignore` (matches the convention already used by `downloads/`,
  `uploads/`, `ocrDir/`, etc. elsewhere in this repo). Splitting them into separate directories
  means clearing/regenerating `POdir/` (e.g. resetting demo data) can never take the counter down
  with it. `nextPoId()` does **not** try to reconstruct a missing counter from existing PO files
  in `POdir/` — if `.po_counter` is lost, it silently restarts at `PO00001`, colliding with any
  higher-numbered POs already on disk (reproduced deliberately — see Fix 4 below). Recovery is
  manual: write the desired last-used number as plain text into `POcounter/.po_counter`.
- **Write-to-temp-then-rename** (spec §8.6) — `poagent_write_json_atomic()` in
  `filename_utils.php`, used by every PO write.
- **PO status rename is a single code path** (spec §8.7) — `POStore::setStatus()` exists for
  later phases; nothing else is allowed to rename a PO file.
- **POST/redirect/GET on PO creation** — `po_create.php` writes the PO then redirects to
  `po_success.php`, which reads a one-time `$_SESSION['poagent_last_po']` flash and clears it.
  Refreshing the success page never creates a duplicate PO (verified).
- **Deviation from spec's own example:** the spec's pattern is `PO#####` (5 digits) but its
  worked example shows `PO0004` (4 digits) — implementation follows the 5-hash pattern literally
  (`PO00001`, `PO00002`, …). Flag if 4-digit is actually wanted.

---

## 5. Placeholder Supplier Data

`tools/seed_demo_suppliers.php` (re-runnable, overwrites) seeds 3 supplier catalogs reusing
supplier IDs already present in the root `suppliers.json` (so IDs stay consistent across the
codebase) with **fictional** items/prices, ≤10 per supplier, per explicit request:

| Supplier ID | Hebrew name | Items |
|---|---|---|
| `Osem` | אסם | 10 |
| `Tnuva` | תנובה | 8 |
| `Dansell` | דנסל | 6 |

Real supplier catalogs can replace these files directly — same filename convention
(`supplier_<SupplierID>.xlsx`) and column layout (`Barcode \| ItemName \| Price`), no code changes
needed.

---

## 6. Fixes Applied Since Initial Build

### Fix 1 — quantities lost on "back to items" from confirm screen
**Symptom:** clicking "חזרה לעריכת כמויות" on `po_confirm.php` reloaded `po_items.php` with every
quantity reset to 0, discarding the user's original picks.
**Fix:** the back link is now a POST form (`po_confirm.php`) carrying `supplier_id` + the
confirmed `qty[barcode]` values as hidden fields. `po_items.php` now accepts `supplier_id` from
either GET (fresh pick, all qty=0) or POST (return trip), and pre-fills each quantity `<input>`
from the posted values when present.
**Verified:** curl round-trip — entered 2×/3× on two items → confirm → simulated back-click →
items screen returned with those exact values, everything else at 0.

### Fix 2 — no order totals in the history view
**Symptom:** `po_list.php?all=1` listed POs but not their value.
**Fix:** added `POStore::totalAgorot($po)` (sum of `qty × unit_price_agorot` across a PO's line
items) and a "סה"כ" column in `po_list.php`.
**Verified:** rendered list shows correct per-PO totals (e.g. PO00001 → 36.30 ₪, matching its
confirm-screen total).

### Fix 3 — item picker didn't scale past ~20 SKUs (Aug 18, 2026)
**Symptom:** `po_items.php` rendered one `<tr>` per catalog item with a qty box next to each —
fine for the 6-10 item demo catalogs, unusable once a real supplier has hundreds of SKUs.
**Fix:** rebuilt `po_items.php` around a search/typeahead box: the full catalog is embedded once
as JSON and filtered client-side in vanilla JS (no per-keystroke round trip). Typing filters by
name or barcode (prefix matches ranked above substring matches, capped at 40 results); focusing
the box with nothing typed shows the catalog from the top so the user can scroll/browse instead
of searching. Clicking (or arrow-keys + Enter on) a result adds it to a running "picked items"
table below, where qty defaults to 1 and is edited/removed per-row. An exact barcode match on
Enter always adds directly, regardless of what's highlighted — covers a barcode-scanner feeding
the search box. On submit, JS generates one hidden `qty[<barcode>]` input per picked item, i.e.
**the POST shape to `po_confirm.php` is unchanged** from the old per-row table — `po_confirm.php`
still re-derives prices/names server-side by barcode and needed no changes. The "back to edit"
round trip from `po_confirm.php` (Fix 1) still works: posted `qty[]` values are matched back
against the catalog server-side into an `INITIAL_SELECTED` JSON blob that the JS renders as the
starting picked-items table.
**Verified:** `php -l` on both changed files; curl smoke test — loaded picker page for supplier
`Osem` (catalog JSON + picker markup present), POSTed `qty[barcode]=n` for two real barcodes
straight to `po_confirm.php` (unchanged — correct lines/total rendered), then replayed the same
POST to `po_items.php` (the "back" shape) and confirmed `INITIAL_SELECTED` carried the exact
qty=2/qty=3 back.
**Not yet done:** no visual/browser check of the JS itself (dropdown rendering, keyboard nav,
add/remove/qty-edit interactions) — curl can't drive JS. Worth an manual click-through in a
browser before the next demo, and worth revisiting once a supplier catalog actually reaches
hundreds of rows (current fake catalogs are 6-10 items, so the "scroll to browse hundreds" path
is unexercised at real scale).

### Feature — PO detail view from history (Aug 18, 2026)
**Gap:** `po_list.php` only ever showed the summary row per PO (id/supplier/user/date/status/
item-count/total) — the only way to see a PO's actual line items again (like the confirmation
screen shown right after creation) was gone once you navigated away from `po_success.php`.
**Fix:** extracted the id/status/supplier/generator/date/core-name header + items table (with
per-line and grand totals) out of `po_success.php` into a shared renderer,
`poagent_render_po_detail()` in `lib/ui_common.php`. Added `po_view.php?core_name=<core_name>`,
a read-only detail screen that calls `POStore::loadByCoreName()` (existing adapter method,
previously unused) and renders through the same shared function — so a PO looks identical
whether you just created it or you're looking it up later. `po_list.php` now has a "🔍 צפייה"
link per row pointing at it. `po_success.php` shrank to a thin wrapper around the shared
renderer and, as a side effect, now also shows a grand total (it didn't before).
**Security note:** `core_name` arrives via GET and feeds a `glob()` call inside
`loadByCoreName()`. `po_view.php` whitelists it to `^[A-Za-z0-9_-]+$` before calling in — blocks
`/`, `..`, and glob wildcards (`*`, `?`, `[`) — since core names are always plain
`<user>_<supplier>_<ddmmyy-His>_PO#####` segments built server-side.
**Verified:** `php -l` on all 4 touched/added files; curl smoke test — loaded the history list,
pulled a real `core_name` (PO00005 / Tnuva / user1, matching the screenshot that prompted this),
opened `po_view.php` with it and confirmed the id, all 3 line items, and the grand total render;
confirmed a path-traversal-shaped `core_name` (`../../etc/passwd`) gets redirected to
`po_list.php` instead of reaching `glob()`.

### Fix 4 — POdir/counter gitignored, counter moved to its own directory (Aug 18, 2026)
**Trigger:** while investigating "what happens if `.po_counter` gets deleted?" — reproduced it
(backed up the real counter, deleted it, called `nextPoId()` twice, saw it return `PO00001` then
`PO00002` again, restored the backup after). No crash: `fopen($file, 'c+')` silently recreates a
missing counter file and treats empty content as `0`. Since `POdir/` already had real
`PO00001`-`PO00005` on disk, a fresh PO created after such a loss would silently collide with an
existing PO number (different `core_name`/timestamp, so no file gets overwritten, but the
human-facing `PO#####` reference stops being unique).
**Fix:** (1) moved the counter out of `POdir/` into a new sibling directory, `POcounter/`, so
`POdir/` can be treated as fully disposable/regenerable without risking the counter — updated
`POStore.php`'s `POAGENT_COUNTER_DIR`/`POAGENT_PO_COUNTER_FILE` constants and added a
`mkdir()`-if-missing for the new directory alongside the existing one for `POdir/`. (2) Added
`.gitignore` (`*` / `!.gitignore`, same convention as `downloads/`, `uploads/`, etc.) to both
`POdir/` and `POcounter/`, and ran `git rm -r --cached` on the previously-tracked `POdir/`
entries (3 old PO json records + the old `.po_counter` path) so the ignore rule actually takes
over — working-tree files were untouched, this only stopped git from tracking them going
forward, and nothing was committed. Both directories are now fully local/untracked and evolve
together per machine, which also sidesteps the collision risk above in the common case: a fresh
clone starts both `POdir/` and the counter empty together, rather than getting a stale counter
next to someone else's committed PO history. Explicitly **not** implemented: reconstructing the
counter from existing `POdir/` files on startup — deletion still means a silent restart at
`PO00001`; recovery is manual (write the desired last number into `POcounter/.po_counter`), by
this ask's explicit choice.
**Verified:** `php -l` on `POStore.php`; confirmed all 5 real PO json files + the counter
survived the directory move/untrack with byte-identical content; ran a full PO creation through
the real HTTP flow (login → `po_create.php` → `po_success.php`) and confirmed it correctly
allocated `PO00006` (counter was at 5) from the new `POcounter/.po_counter` location, then
deleted that one test PO record afterward (counter intentionally left at 6, not rolled back —
rolling counters backward is exactly the collision risk being guarded against, so `PO00006` is
now a harmless gap, not reused).

### Feature — DN ingestion Stage 1: photo import, no OCR yet (Aug 18, 2026)
**What:** first stage of the DN/OCR/Diff Engine build (spec §9 steps 3–9), staged per explicit
request so each stage is testable before the next lands. This stage: `main_menu.php`'s "Upload DN"
button enabled (was disabled/"coming soon"); `dn_select_po.php` lists open/prcv POs to attach the
delivery to (originally scoped to the current session's user only, later widened to all users —
see the dated entry below); `dn_browse.php` is a **server-side folder browser** (not
a plain `<input type=file>` — browsers deliberately don't let a page preset the OS file-picker's
starting folder, so a real default wasn't achievable that way) defaulting to
`DNStore::defaultBrowseDir()`, which resolves the real Desktop's "DN pictures" folder — on this
machine that's `C:\Users\shimr\OneDrive\Desktop\DN pictures` (OneDrive-redirected, **not**
`C:\Users\shimr\Desktop`; the resolver tries OneDrive-redirected → plain Desktop → Desktop root →
home dir, so a machine without the folder yet still opens somewhere sane); a "שנה תיקייה" field
lets the user jump anywhere else on the local machine (no chroot/allowlist — free local browsing
is the point, matching spec §8.9's "no security hardening needed" for this single-user demo).
`dn_import.php` copies the chosen photo into `DNdir/` as `<PO_core_name>_DN_<ddmmyy-hhmmss>.<ext>`
(spec §6.3) via new adapter `lib/DNStore.php::importImage()` — original extension kept (`.jpeg`
for real WhatsApp-sourced photos, matching the convention already used in
`NPharmonized/process.php`). Stage 1 stops there: no OCR, no DN JSON, no PO status change — those
are Stages 2–5 of a plan agreed with the user (not currently saved as a repo file — see the
Claude Code plan-mode history for the full staged breakdown if it's needed again).
**Verified:** `php -l` on all 5 touched/added files; curl smoke test with a cookie jar — logged in
as `user1`, confirmed the enabled DN link on `main_menu.php`, confirmed `PO00001` (open) listed on
`dn_select_po.php`, loaded `dn_browse.php` for it and confirmed the folder browser opened on the
real Desktop\DN pictures path and listed all 5 real `.jpeg` files there, POSTed one
(`Osem 18-08-26 A.jpeg`) to `dn_import.php` and confirmed it landed in `DNdir/` as
`user1_Osem_130826-113128_PO00001_DN_180826-122946.jpeg` with the confirmation screen showing the
correct PO linkage and DN core name; deleted the test-imported file afterward so `DNdir/` stays
empty for the user's own first real test pass.

### Feature — DN picker shows all users' POs (Aug 18, 2026)
**What:** `dn_select_po.php` was scoped to `POStore::listPOs($generatorId)` — only the logged-in
session's own POs. Changed to `POStore::listPOs(null)` (list all) still filtered to open/prcv,
with a "משתמש" column added (mirroring `po_list.php`'s own all-users toggle) so it's clear whose
PO each row belongs to. **Why:** explicit request — for demo purposes a delivery should be
loggeable against any user's PO, not just whichever user happens to be "logged in" in this browser
session. `dn_import.php` needed no change — it already looks up the PO by core name regardless of
owner.
**Verified:** curl smoke test — logged in as `user2`, confirmed all 5 POs (spanning user1/2/3)
listed on `dn_select_po.php`, not just user2's own.

### Feature — OCR + Diff Engine + VS + status transitions, end-to-end (Aug 18, 2026)
**What:** Stages 2, 4, and 5 landed together in one pass, per explicit request ("continue to OCR,
and also the stage after where you compare DN to PO and create VS"). Stage 3 (the manual-review UI
for correcting a bad OCR read or adding an unmatched item to the catalog) is deliberately NOT
built yet — barcode matching happens, but everything auto-proceeds with no human-in-the-loop
screen, since these demo DN photos are literal screenshots of their own PO and are expected to
match exactly for now ("for now all will be zero but later i will change the DN to test the
variations").
- **`lib/DNOcr.php`** — one OpenAI vision call (`gpt-4.1-mini`, same call shape as
  `harmonizedFlow/step3_process_ocr.php`, reusing `AIocr/config.json`'s existing key) on the whole
  DN photo, no crop marking, no `suppliers.json` dependency. Prompted for
  `{supplier_name, dn_number, dn_date, items:[{barcode,name,qty,unit_price}]}`.
- **`lib/DNSanity.php`** — the OCRsanity validation *concept* (numeric qty/price, barcode all-digits
  ≤13 chars), reimplemented directly against the JSON instead of an xlsx; coerces invalid
  numeric fields to 0 so downstream code never re-checks `is_numeric()`.
- **`lib/DNStore.php::finalize()`** — writes the finalized DN JSON to `DNdir/`.
- **`lib/VSStore.php`** (new) — atomic per-PO delivery-index counter (in `POcounter/`, prefixed
  `vs_<po_core_name>.count` — consolidated into the existing counter directory per explicit
  request rather than a new `VScounter/` sibling), VS JSON write (spec §6.3 filename convention,
  never overwritten) and `listForPo()` (ordered by `delivery_index`, not filename timestamp, per
  spec §5).
- **`lib/DiffEngine.php`** (new) — `compare()` matches each DN item to the PO's own item list by
  barcode (not the supplier catalog directly — the PO already carries the catalog price it was
  created with); computes cumulative `po_qty_remaining_before` from every prior VS's `line_items`
  (spec §5's cumulative-quantity rule); integer-agorot price diff; items not on the PO land in
  `unmatched_dn_items`, never dropped. **Always** returns a VS body, `"matched"` or `"variance"` —
  no skip-if-matched branch (spec §4.4).
- **`dn_import.php`** rewritten to run the whole pipeline in one POST (import → OCR → sanity →
  finalize → diff → VS write → status transition), then flash-redirect to new **`dn_result.php`**
  (POST/redirect/GET, same pattern as `po_create.php`/`po_success.php` — refreshing the result page
  can't re-run the pipeline or duplicate a VS). Status transition: sums `dn_qty` received per
  barcode across ALL of a PO's VS records (including the one just written); `closed` if every PO
  item's cumulative received ≥ its ordered qty, `prcv` if some but not all received, unchanged if
  the PO's own items received nothing at all (e.g. a DN that came back entirely unmatched — a real
  VS still gets generated per spec §4.4, but no PO progress happened, so status stays whatever it
  already was).
**Verified with a real OpenAI call** (not mocked): PO00001 (Osem, open, 2×פסטה פנה @6.90 +
3××אסם קוסקוס @7.50) against the real `Osem 18-08-26 A.jpeg` (a screenshot of that same PO,
standing in for a photographed DN per this project's whole premise). OCR correctly read both
barcodes/qty/prices exactly (item *names* came back slightly off — "אסם" prefix dropped on one,
second item read as "זוקיני" instead of "קוסקוס" — harmless since matching is barcode-only, names
are display-only). VS generated with `delivery_index: 0`, `status: "matched"`, both line items at
`qty_diff: 0`/`price_diff_agorot: 0`, `unmatched_dn_items: []` — confirmed on disk byte-for-byte
matching spec §6.3's JSON shape. PO status flipped `open` → `closed` (file renamed
`..._PO00001_closed.json`), confirmed on disk. `dn_result.php` rendered the full breakdown
correctly (OCR fields, item table, VS table, status transition arrow). `php -l` clean on all 8
touched/added files.
**Known gap surfaced by this run:** once PO00001 is `closed`, it drops out of `dn_select_po.php`'s
open/prcv list — so testing a second delivery (multi-delivery/variance case) against the *same* PO
needs a fresh open PO, since there's no "reopen" path built. The other 4 seeded POs (PO00002–5,
Dansell/Tnuva) are still open for that.

### Feature — human-in-the-loop Review screen (Aug 18, 2026)
**Why:** without a review step, an OCR misread (wrong barcode digit, misread qty/price) and a
genuine supplier variance land in the VS looking identical — no way to tell them apart after the
fact. Explicit request to build spec §9 step 5's Review screen now rather than defer it further,
specifically as "an excel-like table... with the option to manually correct what the OCR missed."
**What:** split the previously-single-shot `dn_import.php` pipeline into three steps —
`dn_import.php` (copy + OCR + sanity → session draft), **`dn_review.php`** (new — editable table,
invalid cells highlighted red per `DNSanity`'s flags, any field correctable, per-row delete
checkbox, 3 blank spare rows for an item OCR missed entirely), **`dn_confirm.php`** (new —
re-validates every posted row with the *same* rules `DNSanity::check()` uses, never trusts a
correction blindly, then calls the finalize→diff→VS→status tail). That tail was factored out into
new **`lib/DNPipeline.php::finalizeDelivery()`** so there's exactly one implementation of it
(previously inlined in `dn_import.php`). Splitting the flow this way also means refreshing
`dn_review.php` never re-triggers an OCR API call — free to revisit, not just safe against
duplicate imports.
**Not built (scoped out on purpose):** "add to supplier catalog" for a genuinely-new/unordered
item (spec §4.3's other half of Review) — today an unmatched item just rides through to
`unmatched_dn_items` in the VS; permanently adding it to `SuppliersDB/supplier_<id>.xlsx` would
need a `SupplierStore::appendItem()` this pass didn't build. Worth adding once a real unmatched
item shows up worth keeping.
**Bug caught during testing, fixed same pass:** `DNPipeline::finalizeDelivery()`'s return value
was missing the `'po'` key that `dn_result.php` expects (`$flash['po']`) — the refactor moved the
finalize/diff/status logic out of `dn_import.php` without carrying that key along, so the first
real test threw `Undefined array key "po"` and rendered a blank PO line. Fixed by having
`finalizeDelivery()` return the original `$po` record alongside `dn`/`vs`/`old_status`/`new_status`.
**Verified with two more real OpenAI calls:** (1) PO00002 (Dansell) with a *deliberate* manual
correction on the Review screen — bumped one item's qty 3→5 and added an unordered item — confirmed
the edit actually reaches the VS (`qty_diff: +2`, flagged; unordered item in
`unmatched_dn_items`; badge "⚠ נמצאה שונות"). This is the exact scenario motivating the ask: a
human adjusting a value before it hits the Diff Engine. (2) PO00003 (Tnuva), submitted unedited
(pure pass-through) — all 3 items matched exactly, VS `matched`, PO closed; confirmed the
`'po'` fix held (no warning, correct PO/supplier line rendered). Both runs done via a small PHP
curl test harness (not manual browser clicking) to keep the human-edit and pass-through cases
scripted and repeatable; scratch test files deleted after. `php -l` clean on all 5 touched/added
files.
**Post-test cleanup:** the 3 real test deliveries above (PO00001/2/3) were reset back to `open` —
their DN images/JSON, VS JSON, and per-PO `vs_...` delivery-index counters deleted, status flipped
back via the real `POStore::setStatus()` code path (not a raw file edit) — so all 5 seeded POs are
open and untouched for the user's own testing, per explicit request rather than leaving 3 of the 5
already closed out from under them.

### Feature — po_list.php default to all users; DN/VS indicator columns (Aug 23, 2026)
**What (two related requests, same session):**
1. `po_list.php` now defaults to showing **all users' POs** (was: current session's user only) —
   explicit request, "will help with the debug for now... after the demo we might need full
   separation" (flagged with a `TODO` comment in the code, not just here). `?mine=1` narrows back
   to the logged-in user; the toggle button/label swapped accordingly. `dn_select_po.php` already
   did this (see the dated entry above) — `po_list.php` was the one screen still scoped to one user.
2. Two new columns, "תעודת משלוח (DN)" and "דוח שונות (VS)" — a dash when a PO has neither, else a
   count (📷 N / ✔ N or ⚠ N — the latter icon flips to a warning if ANY of that PO's VS records
   have `status: "variance"`) linking to two new read-only pages, **`dn_view.php`** and
   **`vs_view.php`** (both `?core_name=<po_core_name>`, same whitelist-regex guard as
   `po_view.php`'s `core_name` param). Both list every DN/VS the PO has, oldest/lowest-delivery-
   index first — not just the latest — since a PO can have more than one delivery (spec §5).
**Refactor alongside it:** `dn_result.php`'s DN-fields/item-table and VS-status/diff-table markup
was duplicated verbatim into what would have become `dn_view.php`/`vs_view.php` — pulled out into
two new shared renderers, `poagent_render_dn_detail()` and `poagent_render_vs_detail()` in
`lib/ui_common.php` (plus `poagent_agorot_to_ils()`, previously a local function in
`dn_result.php`), matching this file's existing `poagent_render_po_detail()` convention exactly.
`dn_result.php` now calls these too instead of its own inline copy — and picked up showing the
actual DN photo inline for free, which it didn't do before (`poagent_render_dn_detail()` takes an
optional `$imageUrl`). Images are served directly from `DNdir/` (confirmed no `.htaccess`
restriction blocks it — matches spec §8.9, no security hardening needed at this stage).
**Verified:** `php -l` on all 6 touched/added files; curl smoke test — confirmed `po_list.php`
defaults to all 5 seeded POs and `?mine=1` narrows correctly; confirmed the DN/VS columns show "—"
for POs with neither and a working count+link for one that has real data (PO00001, from earlier
manual testing); followed both links and confirmed `dn_view.php` renders the actual photo + OCR
table and `vs_view.php` renders the correct delivery index + matched/variance badge.

### Feature — total verification (Aug 23, 2026)
**What:** explicit request to add a "does the total add up" check on top of the existing per-item
qty/price diffs — two checks, both anchored on the DN document's own declared (OCR'd) grand total:
(a) does it match what this delivery was expected to be worth (the PO's remaining value for the
items it actually contains), (b) does it match the sum of the DN's own rows (catches a document
math error / OCR misread independently of any PO comparison). Requested to show in both
`dn_view.php` and `vs_view.php`.
- **`lib/DNOcr.php`** — the vision prompt didn't ask for a total at all before; added `"dn_total":
  number or null` to the requested JSON shape, instructed to read the printed grand total (often
  labeled סה"כ) as-is, never computed by the model itself, `null` if none is visible. Deliberately
  nullable end-to-end (not defaulted to 0) so "no total on this document" stays distinguishable
  from "total is genuinely zero" — a missing total must skip the checks, not false-flag them.
- **`lib/DNSanity.php`** — passes `dn_total` through unchanged (not a per-item field, doesn't
  affect `sanity_ok`).
- **`dn_review.php`/`dn_confirm.php`** — `dn_total` is now an editable field on the Review screen
  (same human-in-the-loop philosophy as everything else there) and re-validated server-side on
  confirm (empty/non-numeric → `null`, never trusted blindly).
- **`lib/DiffEngine.php`** — both checks computed once, in agorot (exact match, no tolerance, same
  convention as the qty/price diffs), added as a new `total_check` object on the VS record:
  `po_expected_total_agorot` (Σ `po_qty_remaining_before × po_price_agorot` over matched line items
  only — an unordered item legitimately makes the real total diverge from what was expected, and
  that's supposed to surface, not get averaged away), `dn_declared_total_agorot` (nullable),
  `dn_computed_total_agorot` (Σ over ALL DN rows, matched + unmatched — the document's total
  presumably includes everything printed on it), and the two diff/flagged pairs. A total mismatch
  now also counts toward the VS's overall `matched`/`variance` status, not just its own display.
- **`lib/ui_common.php`** — new shared renderer `poagent_render_total_check()` (single source of
  display, matching this file's existing `poagent_render_*_detail()` convention), called from
  `poagent_render_vs_detail()` automatically when `$vs['total_check']` is set. `dn_view.php` has no
  PO-comparison context of its own (a DN record alone can't know what it was compared against), so
  it matches each DN to its own VS by `dn_reference === dn_core_name` (they're always written
  together in one `dn_confirm.php` call) and renders that VS's `total_check` — same numbers,
  same function, single source of truth in `DiffEngine`, not recomputed twice.
- **Backward compatibility:** VS records written before this change have no `total_check` key —
  `vs_view.php`/`dn_result.php` simply skip the block (`isset()` guard), `dn_view.php` shows "אין
  נתוני בדיקת סה"כ עבור תעודה זו" instead of erroring.
**Bug caught while writing this, fixed before it ran:** `($dn['dn_total'] ?? null) !== null` in
`poagent_render_dn_detail()`'s new info-line addition — first draft omitted the parens
(`$dn['dn_total'] ?? null !== null`), and PHP's `??` binds looser than `!==`, so it silently
parsed as `$dn['dn_total'] ?? (null !== null)` = `$dn['dn_total'] ?? false`. Caught on
re-reading the diff, not by a test failure — fixed immediately.
**Verified with a real OpenAI call:** PO00002 (Dansell, full PO value 155.40 ₪) against the real
`Dansell 18-08-26 A.jpeg` — confirmed the review screen's `dn_total` field came back "155.4" (OCR
correctly read the screenshot's own "סה"כ להזמנה" row rather than needing anyone to sum it),
confirmed `dn_result.php`/`dn_view.php`/`vs_view.php` all rendered the same total-check block: all
three totals (expected/declared/computed) at 155.40 ₪, both checks ✔ at a 0.00 ₪ diff. Also
confirmed backward compatibility directly: `dn_view.php`/`vs_view.php` on PO00001's real
pre-existing VS (written before this feature) rendered with no errors and the expected fallback
text. `php -l` clean on all 8 touched files. Test PO00002 delivery reset back to `open` afterward
(same cleanup convention as the dated entry above) — PO00001's real data from the user's own
earlier testing was left untouched.

### Feature — dn_review.php side-by-side with the DN photo (Aug 23, 2026)
**Why:** explicit request — the Review screen had no image on it at all (the photo only appeared
later, on `dn_result.php`/`dn_view.php`), so correcting an OCR miss meant working from memory or
juggling a separate image tab. "Only then the user will be able to actually correct the DN."
**What:** widened the card (900→1500px, still capped at 92vw so it degrades on narrow viewports)
and wrapped the existing content in a two-column flex layout: the DN photo on one side
(`position:sticky; top:20px` — stays in view while the form/table column scrolls past it,
`object-fit:contain` capped at 85vh so a tall photo doesn't force the whole page to scroll past
it), the editable form/table on the other (`flex-wrap:wrap` so it drops to a stacked single column
on narrow screens rather than squeezing). Wrapped the item table itself in `overflow-x:auto` so it
scrolls independently instead of forcing the whole page wider on small screens. No new files —
same form/fields/table as before, same POST target (`dn_confirm.php`), purely a layout change.
**Verified:** `php -l` clean; real import (no re-confirmation needed — a pure layout check) —
confirmed the photo renders at a real, working `DNdir/...jpeg` URL, the flex/sticky CSS is present
in the output, and the form still posts to the same place. Left the review unconfirmed on purpose
(no PO/VS touched by this check) and deleted the one leftover unconfirmed image afterward.

### Feature — live per-row line total on dn_review.php (Aug 23, 2026)
**Why:** explicit request — the review table showed unit price per row but not qty×price, so
comparing a row against the photo's own "סה"כ" column (every DN photo has one — see spec §6.3's
worked table) meant doing the multiplication by hand. Needed to stay live, not just an initial
snapshot, since the whole point of the table is editing qty/price — a static total would go stale
the moment a value is corrected.
**What:** new "סה"כ שורה" column, server-rendered from the OCR draft's initial values (correct even
before JS runs) and recalculated live via a small vanilla-JS block (event-delegated on the table's
`input` event — no per-row listeners to wire up, works for the 3 blank spare rows too the moment
something is typed into them). Added a footer row, "סה"כ לפי שורות", summing all row totals —
server-rendered initially, kept in sync by the same JS — so the existing "סה"כ בתעודה" field just
above the table can be eyeballed against it directly during review, without waiting for
`dn_result.php`'s post-confirm `total_check` block. Purely additive to the table (`<td>` display
elements, not new form fields) — no change to what gets POSTed to `dn_confirm.php`.
**Verified:** `php -l` clean; real import against a correctly-matched photo (PO00004/Dansell,
5×7.90 + 2×8.90) — confirmed both row totals (39.50 ₪, 17.80 ₪) and the footer sum (57.30 ₪)
render server-side exactly matching the photo's own printed values before any JS executes. Left
unconfirmed on purpose (pure display check); leftover image deleted afterward, PO00004 confirmed
still `open`/untouched.

### Fix — PO items missing from a DN entirely were silently invisible in the VS (Aug 24, 2026)
**Symptom (found by the user's own testing, not by us):** deliberately deleted one row from a
3-item DN on the Review screen (barcode ending 77386, simulating an item genuinely not delivered)
against real PO00003. The confirmed VS/`dn_result.php` correctly showed the 2 delivered items and
correctly flagged the total mismatch, but the 3rd PO item just... wasn't mentioned anywhere. Not
in `line_items`, not in `unmatched_dn_items` — completely absent, no indication the PO ever had a
3rd item at all.
**Root cause:** `DiffEngine::compare()`'s only loop was `foreach ($finalizedDn['items'] as $dnItem)`
— it can only ever discover PO items that the DN actually mentions. A PO item the DN never
mentions at all has no code path that touches it, so it silently falls out of the VS entirely.
Under-delivery (0 received when qty was still owed) is exactly the kind of gap spec §4.4 says must
never be hidden — same standing rule that made over-delivery/price mismatches get flagged, just
missed for the "completely absent" case specifically.
**Fix:** added a second pass over the PO's own item list — any PO barcode NOT covered by the first
pass (i.e. not present in this DN) AND still owed (`po_qty_remaining_before > 0`, so an item
already fully settled by a *prior* delivery correctly does NOT get re-flagged every time some
other item ships) gets an explicit zero-delivery `line_items` entry: `dn_qty: 0`,
`qty_diff: -remaining`, `qty_flagged: true`, `not_delivered: true`, price fields shown as "—"
(nothing was delivered, so there's no DN price to meaningfully compare). `poagent_render_vs_detail()`
highlights these rows (red background + "⚠ לא נכלל בתעודה זו" note) so they read as "missing from
this delivery," not as a data error. Nice side effect on `total_check`: `po_expected_total_agorot`
now legitimately includes every owed item whether delivered or not, which makes the two total
checks mean something sharper — "expected vs declared" now correctly passes when a reviewer leaves
a document's original total untouched after deleting a row (the total still reflects the *whole*
order), while "declared vs computed-from-rows" correctly catches exactly that situation (the
printed total no longer matches what was actually itemized as received).
**Bug caught while testing the fix (unit test, not the app):** PHP silently casts a purely-numeric
array key back to `int` — `foreach ($poItems as $barcode => ...)` handed back an `int` barcode
even though the DN-item loop's `'barcode'` field is always an explicit `(string)` cast, an
inconsistency a strict `===` assertion in the test caught immediately (rendering itself wouldn't
have crashed — `htmlspecialchars()` coerces silently — so this would have stayed invisible without
the test). Fixed with an explicit `(string) $barcode` re-cast in the new loop.
**Verified with unit tests against `DiffEngine::compare()` directly** (no files touched, no API
cost — deliberately chosen over a real end-to-end run specifically to avoid adding another
delivery to the user's real PO00003 test data): (1) the user's exact scenario reproduced
synthetically — confirmed all 3 items now appear, the missing one flagged correctly
(`dn_qty=0, remaining_before=7, qty_diff=-7`), overall status `variance`, and `total_check` now
shows `po_expected_total_agorot` = 70.80 (was 40.70) with "expected vs declared" passing and
"declared vs computed" correctly catching the +30.10 gap. (2) A separate 2-delivery scenario
confirming an item fully settled in delivery #0 does NOT get re-flagged as missing in delivery #1.
(3) Rendered `poagent_render_vs_detail()` directly against a fully-not-delivered item and confirmed
the red-highlighted row, warning note, and dashed price columns all render correctly. `php -l`
clean on both touched files.
**Decision, asked explicitly:** the user's real pre-existing PO00003 VS (the one in the bug report
itself) predates this fix and still won't show the missing item if reopened — left as-is
(historical record, matches this app's own "VS is never overwritten" rule) rather than
regenerated, per the user's explicit choice when asked.

### Feature — zoomable DN photo lightbox (Aug 24, 2026)
**Why:** explicit request — the DN photo everywhere (`dn_result.php`, `dn_view.php`, `dn_review.php`)
was capped at a fixed display size, too small to actually read a barcode or a handwritten
correction against. "The picture is too small to detect."
**What:** a single shared zoom/pan lightbox, added once to `poagent_render_foot()` (so every page
that calls it — i.e. every POAgent screen — gets the overlay markup/CSS/JS for free, cost is
negligible on pages with no images) rather than pulling in an external library — no CDN dependency,
matches this codebase's existing all-vanilla-JS convention. Click any image tagged
`class="poagent-zoomable" data-src="..."` to open it full-screen: scroll-wheel zoom (toward
cursor), ＋/－ toolbar buttons, drag-to-pan once zoomed past fit, "100%" reset, close via button/
Escape/clicking the backdrop. Event-delegated on `document`, so it works for any number of
zoomable images on one page without per-image setup — matters for `dn_view.php`, which can render
several DN photos (one per delivery) on the same page.
Wired the class onto the two places a DN photo actually renders: `poagent_render_dn_detail()`'s
`<img>` (covers `dn_result.php` and `dn_view.php` in one edit, per this file's existing shared-
renderer convention) and `dn_review.php`'s own separate `<img>` (it doesn't go through that shared
function — it's rendering a still-in-session draft, not a finalized DN record).
**Verified:** `php -l` clean on all 4 touched files; confirmed via `dn_view.php` (real existing
PO00001 data, no new API call needed) and a fresh `dn_review.php` import that both pages render the
`poagent-zoomable` class + `data-src`, and the shared overlay/toolbar/viewport markup and script are
present and correctly structured. Left the `dn_review.php` test unconfirmed on purpose (pure
markup check); leftover test image deleted afterward. Noticed real user activity on PO00002 (now
`closed`, real confirmed DN/VS, plus a couple of unconfirmed leftover review images) from testing
done in parallel — left entirely untouched, not mine to clean up.

### Feature — unified PO+DN+VS view; inline zoom replaces the lightbox (Aug 24, 2026)
**Shorter entries from here on** — user flagged the write-ups were getting long; keeping to
what changed + why, skipping the step-by-step verification narrative.

- **`po_list.php`**: the DN column / VS column / view column merged into one ("תעודות ודוחות") —
  one link to `po_view.php`, with the DN/VS counts folded into the link text instead of separate
  cells.
- **`po_view.php`** is now the single unified page (replaces `dn_view.php`/`vs_view.php`, both
  deleted): three panels side by side — PO on the right (always, sticky), then one row per
  delivery with the DN photo+data in the middle and its VS on the left. Confirmed via screenshot
  (RTL flex DOM-order reasoning alone wasn't trusted after getting bitten once already).
- **Zoom mechanism changed**: replaced the click-to-open lightbox (previous session's approach)
  with an always-inline `poagent_render_zoom_panel()` — explicit request, "should not have the
  picture open in another screen." Same zoom/pan mechanics, just embedded in-page instead of a
  modal; multi-instance safe (each panel gets independent state).
- **Real bug fixed**: `max-width/max-height: 100%` on the panel's `<img>` only ever shrinks an
  oversized image, never enlarges a small one — these demo photos are tiny (~400×200px), so they
  rendered at native size and looked blank in a huge panel. Fixed with `width/height: 100%` +
  `object-fit: contain`, which scales both directions. Caught by screenshot, not by code review.
- **Data-loss incident**: an `rm -f *PO00004*` glob during test cleanup deleted the user's own real
  confirmed DN/VS records for PO00004 (not just the intended test leftover) — not recoverable (Git
  Bash `rm` bypasses the Recycle Bin). The PO record itself (`POdir/`) was untouched; only the
  delivery history (photo/OCR data/VS) was lost. Lesson: verify each match before a wildcard
  delete, don't glob-delete against directories holding real user data.

### Feature — plain-language variance summary (Aug 24, 2026)
The raw VS/total-check tables weren't "clear enough" — added a "סיכום" section below them (both
`po_view.php` and `dn_result.php`) that rolls findings up into plain sentences: 4 problem types
(unordered item / missing item / qty mismatch / price mismatch, one bullet per finding, a product
can have more than one) or a single "✔ ההזמנה סופקה במלואה" when nothing's wrong.
`DiffEngine::summarizeForPo($po, $allVs)` computes it — new method, cumulative by design (missing
= 0 received across ALL given VS records, not just one delivery). Callers pass an increasingly
complete slice of VS records per delivery row (`po_view.php`) or every VS on record including the
one just confirmed (`dn_result.php`), so the summary always reflects "everything received up to
this point," same philosophy as the qty `remaining` math elsewhere in `DiffEngine`.
Caught the same PHP numeric-array-key-coercion bug as before (barcode silently becoming an `int`)
in two more spots in the new method — fixed with the same `(string)` re-cast pattern.
Verified against real production data: PO00001 (backward-compat VS with no `total_check`) rendered
the summary fine regardless; PO00003 — the user's own original bug-report PO, re-tested by them
today — correctly produced "תעודת המשלוח חסרה את המוצר 7290000077386", confirming the missing-item
fix from earlier this session end-to-end.

---

## 7. Testing Notes

- No automated test suite yet — verification so far is manual smoke-testing via `curl` with a
  cookie jar (simulating the session across the multi-step flow) plus `php -l` syntax checks on
  every changed file, run after each change.
- Local dev server: XAMPP Apache, served at `http://localhost/website/POAgent/`. Apache is not
  installed as a Windows service in this environment — start via `C:\xampp\apache_start.bat` (or
  `apache\bin\httpd.exe -D FOREGROUND`) if it isn't already running.
- Real usage during development left PO00001–PO00003 in `POdir/` (Osem/Dansell/Tnuva across
  user1/2/3) — harmless leftover test data, safe to delete before a clean demo run, or leave as
  sample history.

---

## 8. Known Open Items / Next Steps

- DN upload/storage, OCR, the human-in-the-loop Review screen, Diff Engine (VS generation), and
  status-lifecycle transitions are all done and verified end-to-end with real OpenAI calls (see
  §6). The one remaining piece from spec §9 step 5's list is **adding an unmatched item to the
  supplier catalog** from the Review screen (`SupplierStore::appendItem()` doesn't exist yet) —
  today an unmatched item still surfaces correctly in the VS's `unmatched_dn_items`, it just can't
  be permanently added to `SuppliersDB/` from that screen. Build it once the user hits a real
  case worth keeping in the catalog.
- `po_view.php` (the per-PO detail screen, not the list) still doesn't surface its own DN/VS links
  the way `po_list.php` now does — only reachable today via `po_list.php`'s columns. Small
  follow-up if it turns out to matter (`po_view.php` already loads the PO by core name, so it's
  just adding the same two links `po_list.php` has).
- No "reopen a closed PO" path exists — see the known gap noted in §6's dated entry, relevant to
  planning multi-delivery test scenarios against a specific PO.
- No automated tests exist; consider adding a lightweight PHP test script now that DN/VS logic has
  landed, since manual curl smoke-tests won't scale well to the barcode-matching/variance paths
  the user is about to start exercising by hand.

---

## 9. WhatsApp Bot — Router + Benchmark Handler (Sept 2, 2026)

First slice of the real WhatsApp entry point. The **menu reply itself is still a benchmark**
(no branching on the user's A/B answer yet), but it now sits behind a real multi-app router so
adding future WhatsApp apps is a config change, not a webhook edit.

### The Meta constraint this works around
One App/WABA = exactly **one** webhook callback URL, and Meta fans **every** event for **every**
business number to it. So there can only ever be one physical endpoint; separating apps has to
happen inside our code.

### Layering (shared transport in `whatsapp_app/`, app logic in each app's folder)
| Layer | File(s) | Role |
|---|---|---|
| Ingress | `whatsapp_app/webhook.php` | The single Meta-registered URL. GET handshake unchanged. POST: append raw payload to `whatsapp_images/webhook_log.txt` (**unchanged — NP's `MessageFetching.php` still scrapes it**), ack Meta (`fastcgi_finish_request()` when available), then `WaRouter::handle($body)` in a `try/catch` that can't break the 200. Knows nothing about any app. |
| Router | `whatsapp_app/lib/WaRouter.php` | Parses the envelope once, matches each event's **business number** against `apps.json`, normalizes the message, calls the matched app's handler. Per-handler `try/catch` so one app failing can't stop the others. Logs every decision to `whatsapp_app/logs/router_YYYY-MM-DD.log` (`dispatch` / `unrouted` / `no_handler` / `handler_error` / `ignored_type`). |
| Registry | `whatsapp_app/apps.json` | Data, not code. One entry per app: `match.phone_number_id[]` + `match.display_phone_number[]`, and `handler.file` / `handler.function`. |
| Session store | `whatsapp_app/lib/WaSessionStore.php` | `wa_id → {app,state,data,expires_at}`, one JSON file per number under `wa_sessions/`, TTL-on-read (default 1h, sliding). **Wired but nothing consumes it yet** — it's there for the A/B-answer slice. Router does *not* route by session. |
| Outbound | `whatsapp_app/lib/WaClient.php` | `WaClient::text()` / `::buttons()`. One place that knows the Graph endpoint + token. Token still from `whatsapp_app/config.json` → `meta_key`. Failures logged to `whatsapp_app/logs/send_YYYY-MM-DD.log`. |
| POAgent handler | `POAgent/whatsapp/bot.php` | `poagent_whatsapp_handle_event(array $event)` — registered in `apps.json`. Gets the **normalized** event (never the raw body, does no number filtering). Replies with the menu, writes `state=awaiting_menu_choice` to the session, logs to `POAgent/whatsapp/logs/bot_YYYY-MM-DD.log`. |

All the new dirs are git-ignored (`*` + `!.gitignore`), same pattern as `POcounter/`.

### Routing = business number only
`WaRouter::matchApp()` checks `phone_number_id` first (exact), then `display_phone_number`
(digits-only) as a fallback for a number whose id we haven't recorded. Current registry:

| App | Match | Handler |
|---|---|---|
| `poagent` | pnid `1239615559241608` / display `972522649555` (052-2649555) | `poagent_whatsapp_handle_event` |
| `np` | display `15551732464` (+1 555 173 2464) | **none** — raw log in `webhook.php` is all NP needs; router logs `no_handler` and moves on |

NP's id is unknown until a real NP webhook arrives; grab it from `webhook_log.txt` then and add it
to `apps.json`. NP number will be replaced later (user's note) — that's just an `apps.json` edit.

### Normalized event (what a handler receives)
`app_id`, `business_phone_number_id` (reply "from" this), `business_display_number`, `from` / `wa_id`
(digits), `contact_name`, `message_id`, `timestamp`, `type`, `text` (best-effort: body / button title /
interactive reply title|id), `reply_id`, `raw_message`, `session` (current `WaSessionStore` record or
null).

### Behavior (POAgent handler)
Any `text` / `interactive` / `button` message on the POAgent line → the menu below (content
ignored; other types logged as `ignored_type`):
```
שלום 👋 הגעת ל-POAgent, מערכת ההזמנות ותעודות המשלוח.

מה תרצה לעשות?

A – הקם הזמנה חדשה
B – סריקת תעודת משלוח

השב/י באות A או B.
```

### Verified
- **Sept 2, 2026 — offline**, token blanked so no real send: `WaRouter::handle()` against synthetic
  payloads — POAgent line → `dispatch` + bot menu-reply + session written; display-only match works;
  NP line → `no_handler`, never reaches POAgent; unknown number → `unrouted`; reaction on POAgent
  line → `ignored_type`. `WaClient` cleanly logged `send_skipped` with no network call.
- **Sept 2, 2026 — live**: real round-trip confirmed from `webhook_log.txt` ("הי" → menu, "A" →
  menu again). The Meta app is in **Live mode** — user confirmed any phone (not just app
  admin/dev/tester roles) can text 972522649555 and get the menu. Status callbacks
  (`sent`/`delivered`/`read`) arrive as `value.statuses[]` and the router correctly skips them
  (no `value.messages`).

### Feature — sender allowlist, two modes (Sept 6, 2026)
**Why:** app is Live and open to any number. Not a laptop-security concern (traffic is
controllable at the source), but two other reasons to have the gate ready now: (1) a Live
business number carries a Meta quality score — strangers texting it and never replying can drag
that down; (2) once real PO/DN logic sits behind the handler, an unauthorized message could write
a real record. Cheap to add now, annoying to retrofit later.
**What:** per-app field in `apps.json`, checked in `WaRouter::handle()` right after an event is
normalized (per-message, since the sender is per-message) and before the handler is called:
- `allowlist_mode: "open"` (default — **also the behavior if the key is omitted entirely**, so
  existing registry entries need no change) — every sender is handled, current benchmark behavior.
- `allowlist_mode: "enforced"` — only wa_ids (digits, no `+`) listed in that app's
  `allowed_senders` get handled; everyone else is **silently dropped** (logged as `unauthorized`,
  no reply sent — doesn't spend messages on spam, doesn't confirm to a prober that the number is a
  live bot).
`WaRouter::isAllowed()` digit-strips both the incoming `from` and every entry in `allowed_senders`
before comparing, so a number can be entered in the list with or without formatting/`+`.
Both registry apps (`poagent`, `np`) currently have `allowlist_mode: "open"` — nothing changes
until someone flips `poagent`'s to `"enforced"` and populates `allowed_senders` with tester
numbers.
**Verified:** `php -l` clean; reflection-based unit test directly against `isAllowed()` (6 cases:
open mode, key omitted entirely, enforced+listed digits-only, enforced+listed
unformatted/`+`-prefixed, enforced+unlisted, enforced+empty `from`) — all passed.
### Feature — idle-session reminder + auto-close (Sept 6, 2026)
**Why:** once real branching lands, a user who goes quiet mid-flow needs a way back to a known
state rather than being stuck forever in `awaiting_menu_choice` (or whatever state they left off
in) until the 1h session TTL silently expires with no warning.
**What:** every inbound message now stamps `data.last_inbound_at` (+ resets
`data.reminder_sent_at` to null, + stores `data.business_phone_number_id` so a later sweep knows
which of our numbers to reply "from") in `bot.php`'s `WaSessionStore::set()` call. A new function,
`poagent_whatsapp_sweep_idle_sessions()`, scans every open POAgent session
(`WaSessionStore::allForApp()`, new shared method) and: **5 minutes** of silence → sends one Hebrew
"still there?" reminder ("עדיין שם/ה? אם לא נמשיך את השיחה, היא תיסגר בעוד דקה."), stamps
`reminder_sent_at`; **1 more minute** of silence after that → sends a Hebrew close message ("השיחה
הסתיימה. כדי להתחיל שיחה חדשה, פשוט שלח/י לנו הודעה.") and `WaSessionStore::clear()`s the session.
Any inbound message in between cancels both, since it resets `last_inbound_at`/`reminder_sent_at`.
**Trigger mechanism — deliberately not OS-specific:** there's no code path that runs without an
inbound webhook hit, so something external has to call the sweep on a timer. Discussed Windows
Task Scheduler vs. a manual loop; **explicit choice: a manual loop script**
(`POAgent/whatsapp/session_sweeper.php`, `while(true){ sweep(); sleep(15); }`, run by hand in a
terminal, Ctrl+C to stop) — this project may move off Windows to a real server later, and the
timing *logic* (`poagent_whatsapp_sweep_idle_sessions()`) doesn't know or care what wakes it up, so
swapping the runner for cron/systemd later is a one-file change. Not meant to survive as the
production mechanism — fine for the single/low-volume test sessions this is being exercised with
now, per explicit request.
**Verified:** `php -l` clean on all 3 touched/added files; functional test against real
`WaSessionStore` records (not mocked) — (1) a session idle 6 minutes with no reminder yet →
reminder sent, `reminder_sent_at` stamped, session still open; (2) same session with
`reminder_sent_at` 2 minutes in the past → close message sent, session file deleted; (3) a
just-touched ("fresh") session → sweep takes no action. Test used a throwaway wa_id, cleaned up
after.
**Side effect caught during that test, cleaned up immediately:** the sweep also scans *every* open
POAgent session, not just the test one — it picked up a real leftover session from the Sept 2 live
test (`972546997729.json`), which predates the `last_inbound_at` field and fell back to its stale
`updated_at`, triggering a (harmless - no `business_phone_number_id` on that old record, so
`WaClient` logged `send_skipped`, no real message went out) reminder attempt against it. Deleted
that stale leftover session file afterward so it doesn't linger half-mutated.
### Feature — A/B branching, stub flows (Sept 6, 2026)
**What:** closes the "read the A/B reply and branch" item, as an explicit stub — no real PO/DN
logic yet, per explicit request ("later i will add the actual logic"). `poagent_whatsapp_handle_event()`
now checks `$event['session']` (already supplied by `WaRouter`, no extra lookup): no open session →
unchanged greet-with-menu behavior. In `awaiting_menu_choice`, reply normalized (trim + uppercase)
to `A` → sends "מתחיל תהליך הקמת הזמנה" then immediately "הזמנה הושלמה" and clears the session
(`poagent_whatsapp_run_stub_flow()`, shared by both branches, `flow` param `'po'`/`'dn'` for
logging); `B` → same shape with "מתחיל תהליך סריקת תעודה" / "סריקת תעודה הושלמה"; anything else →
"עליך לשלוח A - להזמנה חדשה או B לסריקת תעודת משלוח", session stays in `awaiting_menu_choice` so
the user can retry (also keeps the idle-timeout fields — `last_inbound_at` reset,
`reminder_sent_at` cleared — same as every other inbound touch, so a retry-loop doesn't trip the
5-minute idle reminder prematurely).
**Verified:** `php -l` clean; functional test driving `poagent_whatsapp_handle_event()` directly
with a real `WaSessionStore` behind it (not mocked) — fresh contact → menu + session opens;
lowercase `a` → both stub messages logged + session cleared; a **fresh** contact sending `b` as
their very first message correctly gets the greeting menu instead of being treated as an answer
(no session existed yet); invalid reply → nudge text, session stays `awaiting_menu_choice`;
uppercase `B` → dn-flow stub + session cleared. Confirmed via the log file, no stray session files
left behind afterward.
### Feature — menu as tappable WhatsApp reply buttons (Sept 6, 2026)
**Why:** explicit request, prompted by a screenshot of a real business (ELAL) using WhatsApp's
native "reply buttons" for a language picker - asked whether POAgent could offer its menu the same
way instead of "type A or B".
**What:** the menu send now calls `WaClient::buttons()` (already existed, unused until now) with
two buttons - `הזמנה חדשה` (id `po_new`) and `סריקת תעודה` (id `dn_scan`) - instead of
`WaClient::text()` with the old spelled-out "A – ... / B – ..." instructions. Matching moved to the
button's **id** (`POAGENT_WA_BTN_PO_NEW`/`POAGENT_WA_BTN_DN_SCAN` constants), not its title text, so
a later wording/translation change can't silently break the branch. **Typed `A`/`B` is kept as a
fallback alongside the buttons** - free text always reaches a WhatsApp bot regardless of how the
menu rendered, and it's what the existing offline/dev test harness exercises without simulating a
real button tap.
**Verified:** `php -l` clean; functional test driving `poagent_whatsapp_handle_event()` directly -
button tap (`type: interactive`, `reply_id: po_new`, title text deliberately different from "A") →
po-flow stub fires; `reply_id: dn_scan` → dn-flow stub fires; typed `a`/unrecognized text still work
via the existing fallback/nudge paths. Also surfaced (not caused by this change): your own real
phone was live-testing the bot in parallel while this was being tested - a real open session
(`972546997729.json`, `awaiting_menu_choice`, sent as the old plain-text menu since it went out
before this edit landed) exists on disk; left untouched since it's real conversation state, not
test data - replying `A`/`B` to it will still work correctly via the typed fallback.
**Not done yet (next slices):**
- The real PO-creation and DN-scanning logic behind the menu (this session's explicit "later").
- `X-Hub-Signature-256` verification in `webhook.php` (currently any POST is trusted).
- Move the outbound send off the webhook request (queue / worker).

### Feature — real PO session over WhatsApp (Sept 6, 2026)
Replaces the A/B stub with the full mobile PO flow, driving the **same** `POStore` /
`SupplierStore` the laptop screens use — a PO created from the phone is byte-identical in shape to
a laptop one and shows up in `po_list.php` / `po_view.php` with no special-casing.

**Identity (apps.json + WaRouter):**
- `allowed_senders` entries may now be `{ "number": "...", "name": "..." }` objects (bare strings
  still accepted). `WaRouter::allowedNumbers()` digit-strips either shape; new
  `WaRouter::senderNameFor()` resolves the name and `normalizeEvent()` surfaces it as
  `event['allowlist_name']`. `normalizeEvent()`'s first param changed `string $appId` → `array $app`.
- `poagent` flipped to `allowlist_mode: "enforced"` with one seeded tester
  (`972546997729` → name `שמרי` — **edit the name / add testers in `apps.json`; an unlisted number
  now gets no reply at all**).
- **The PO's `generator_id` is the sender's phone number** (`$from`, digits) — the unique, stable
  identity, and the hook a later step will use to match a laptop user to their phone. The
  `allowlist_name` is only a **display nickname** for greeting text / the "נוצר ע"י" line,
  carried on the session as `data.user_name`; it may change or repeat and is never stored as
  identity. `poagent_wa_list_pos_for($from)` filters history by exact `generator_id` in the PO's
  JSON body. (A digits-only `generator_id` also sanitizes cleanly into the `core_name` —
  `972546997729_Osem_<ts>_PO#####` — unlike a Hebrew name, which `poagent_sanitize_segment`
  would strip to `x`.)

**Next step this sets up:** authenticating a laptop user by a message from their phone — the
phone-number `generator_id` written here is what that match will key on.

**Menu (bot.php):** first message → a WhatsApp **interactive list** (new `WaClient::list()`, since
`buttons()` caps at 3) mirroring the laptop `main_menu.php` minus "switch user":
`צור הזמנה חדשה` / `העלה תעודת משלוח` / `היסטוריית הזמנות` / `סיים תהליך`. DN is intentionally
**not** built on mobile — that option replies "use the browser" and returns to the menu. Typed
`1`–`4` / keyword fallback works for clients that render the list as plain text.

**PO conversation (`POAgent/whatsapp/po_flow.php`, new — bot.php is now just dispatch + menu +
idle sweep + logging):** states `main_menu → po_pick_supplier → po_build → po_confirm`.
- `po_pick_supplier` — supplier list (interactive list; `data.supplier_list` = ordered ids for the
  typed-number fallback).
- `po_build` — free-text search (prefix-ranked-above-substring, same ranking as `po_items.php`'s
  JS typeahead, capped at 9 shown; `data.last_results` = ordered barcodes). Reply `<n>` or
  `<n>*<qty>` (also `x` / `×` / space) adds result #n to `data.cart` (`{barcode: qty}`, qty
  merges on repeat). Only treated as a pick when `<n>` indexes the result set — otherwise it's a
  search, so pasting a full barcode still works. `סיום` → confirm, `ביטול`/`תפריט` → menu.
- `po_confirm` — server-side re-derived summary + 3 reply buttons
  (`po_confirm_yes`/`_add`/`_no`). `אשר` → `POStore::createPO($userName, $supplierId, $items)`
  (prices/names re-derived from the catalog by barcode one last time — cart never trusted for
  pricing), success text, back to the menu.
- History option → `poagent_wa_format_history()` (this user's own POs, newest-first, ≤10).
- Every inbound message refreshes the idle-timeout fields via `poagent_wa_set_state()`, so the
  existing 5-min-reminder / +1-min-close sweep keeps working unchanged.

**Verified:** `php -l` clean on all 4 touched/added files. Three functional tests (real
`WaSessionStore` + real `POStore`/`SupplierStore`, WaClient tokenless so nothing sends):
(1) full flow `first-contact → menu → pick supplier (list id) → search → add 1*2 → search another
by barcode → add → סיום → confirm (button id)` — PO written to `POdir/` with
`generator_id` = the sender's **phone number** (core_name `972555550001_Osem_…_PO#####`), both
line prices matching the catalog exactly, qty-merge correct, session back to `main_menu`, cart
cleared; history option (`poagent_wa_list_pos_for($from)`) then lists it; `סיים תהליך` clears the
session. Test PO records deleted afterward (counter left advanced — harmless gap).
(2) `WaRouter::isAllowed()`/`senderNameFor()` reflection test — object + bare-string entries,
digit-stripping, enforced-drop, name resolution, plus the real `apps.json` (enforced, tester
listed). (3) `WaRouter::handle()` integration against synthetic Meta payloads — listed number
dispatches + opens a `main_menu` session with the resolved name, unlisted number silently dropped
(logged `unauthorized`, no session), NP line still `no_handler` with no crash. Synthetic sessions
cleaned up after.

**Still not done:** real DN scanning on mobile; `X-Hub-Signature-256` in `webhook.php`; outbound
send still on the webhook request; `>10` suppliers would overflow the interactive list (only the
first 10 are tappable — a typed exact supplier name still works).

### Pivot — mobile users drive the real web pages via a WhatsApp login link (Sept 6, 2026)
Explicit change of direction: **no in-chat PO flow.** The phone user opens the actual PO web
screens (`po_supplier.php` → … → `po_create.php`, `po_list.php`) in their mobile browser,
authenticated as their phone number. The chat conversation from the entry above is **parked** —
`po_flow.php` stays on disk with a "NOT WIRED IN" banner, `bot.php` no longer requires it, and the
idle-session sweep (`poagent_whatsapp_sweep_idle_sessions`, still called by `session_sweeper.php`)
goes dormant since the bot now writes no sessions.

**Reachability:** Apache already `Listen 80` on all interfaces with `htdocs` = `Require all
granted`, so the pages are served on the LAN (`http://<PC-IP>/website/POAgent/…`, needs a Windows
Firewall rule for TCP 80) and on whatever public host already fronts `webhook.php` for Meta
(same Apache — `https://<that-host>/website/POAgent/…`). `localhost` from the phone does **not**
work (that's the phone itself).

**Token login (`POAgent/whatsapp/mobile_link.php`, new):**
- `poagent_wa_issue_token($phone, $name, $ttl=900)` — `base64url(json{p,n,iat,exp}) . "." .
  base64url(HMAC-SHA256(body, secret))`. Secret auto-generated once into
  `POAgent/POcounter/.link_secret` (that dir is already git-ignored) — zero config.
- `poagent_wa_verify_token()` — structure + `hash_equals` signature check + `exp` check; returns
  `['phone','name','exp']` or `null`. 60 s minimum TTL floor.
- `poagent_wa_mobile_link()` — `base_url + app_web_base + /POAgent/m/?t=<token>`. `base_url`:
  `POAGENT_WA_LINK_BASE_URL` env → `POAgent/POcounter/.link_base_url` file → the host of the
  webhook request (`X-Forwarded-Host`/`Host`). `app_web_base` (`/website`) derived from the
  webhook `SCRIPT_NAME`. Drop a one-line `.link_base_url` file if tunnel host-detection is wrong.
- Not one-time-use — short TTL is the mitigation for a pre-demo (noted for later).

**Bot (`bot.php`, rewritten):** any actionable inbound → greeting + a fresh link, `WaClient::text`,
log `mobile_link_sent`. No session. `allowlist_mode: "enforced"` still gates who gets a link.

**Landing (`POAgent/m/`, new):**
- `index.php` — verifies `?t=`; bad/expired → a Hebrew "ask the bot for a new link" page; valid →
  `session_set_cookie_params(lifetime 86400, SameSite=Lax)`, `session_regenerate_id(true)`, sets
  `$_SESSION['poagent_generator_id'] = <phone>` + `poagent_display_name` + `poagent_mobile=true`,
  redirects to `../main_menu.php`.
- `logout.php` — clears the session.

**`main_menu.php`** is now audience-aware: `poagent_mobile` in session → greet by nickname, show
only **צור הזמנת רכש** / **היסטוריית הזמנות** (`po_list.php?mine=1`) / **יציאה**; desktop is
unchanged (adds Upload-DN + "switch user"). Downstream PO screens needed **no change** —
`poagent_require_generator()` returns the phone number, so `POStore::createPO()` stamps
`generator_id` = phone and `core_name` = `972…_<supplier>_<ts>_PO#####` exactly like a desktop PO.

**Verified:** `php -l` clean on all 6 touched/added files. Unit test — token round-trip incl.
Hebrew name, tamper/malformed/expired all reject, link URL shape + embedded token verifies,
`.link_base_url` file override wins, bot handler sends a link and writes no session, `bot.php` has
no `require` of `po_flow.php`. Live HTTP (Apache running) — bad `?t=` → 200 + the invalid-link
page; good `?t=` → 302 to `../main_menu.php` with the 24 h `SameSite=Lax` cookie; following it,
`main_menu.php` renders the 3-item mobile menu (no DN, no "switch user"); `po_supplier.php` /
`po_list.php?mine=1` load under that session; a `po_create.php` POST wrote
`972546997729_Osem_…_PO00019_open.json` with `generator_id=972546997729` — then deleted (counter
left advanced).

**Still not done:** one-time-use tokens; a Firewall rule / documented public URL is a deploy step,
not code; `index.php` (desktop user picker) is still open on the public host and lets anyone
become user1/2/3 — lock it down before real exposure; PHP session GC (`gc_maxlifetime` default
1440 s on the non-`m/` pages) could still reap a mobile session earlier than 24 h under load.

### Feature — mobile-friendly responsive UI pass (Sept 12, 2026)
**Why:** the mobile web login link (dated entry above) worked functionally, but the user reported
the screens "look the same as on the computer" and feel less friendly on a phone.
**Root cause found first:** `poagent_render_head()` never emitted a `<meta name="viewport">` tag —
with none present, mobile browsers render the page assuming a ~980px desktop layout and shrink it
to fit, so every screen was effectively a zoomed-out desktop page rather than a real phone layout.
One-line fix, outsized effect.
**What else changed, all in the shared shell so every screen picks it up at once:**
- `lib/ui_common.php` — added the viewport meta tag; added a `@media (max-width: 640px)` block:
  tighter card padding, smaller headings, larger-relative form/button sizing for touch.
- New `.responsive-table` mechanism (same media query) — a `<table class="responsive-table">` with
  `data-label="..."` on each `<td>` becomes a stack of bordered cards (one per row, label above
  value) below the breakpoint, instead of a cramped multi-column table or sideways scrolling.
  Applied to all 4 shared table renderers in `ui_common.php` (`poagent_render_po_detail()`,
  `poagent_render_dn_detail()`, `poagent_render_vs_detail()`'s two tables,
  `poagent_render_total_check()`), plus `po_confirm.php`'s order-summary table and
  `po_list.php`'s history table.
- `po_list.php` — the "משתמש" column is now hidden entirely when `$_SESSION['poagent_mobile']` is
  set, since mobile always browses with `?mine=1` already (every row is the same user there).
- `po_items.php` — the JS-built "picked items" table (`renderPicked()`) now stamps
  `dataset.label` on each created `<td>` so it gets the same card-stack treatment on phone width.
- `po_supplier.php` and `main_menu.php` needed no changes (already just a `<select>` / full-width
  buttons). `po_view.php`'s multi-panel flex layout was left as-is — its existing `flex-wrap`
  already stacks reasonably at phone width; revisit if it still feels off there too.
**Verified:** `php -l` clean on all 4 touched files (`lib/ui_common.php`, `po_list.php`,
`po_items.php`, `po_confirm.php`). User tested live on their own phone after the change and
confirmed it now looks right.

### Fix — po_view.php: sticky PO panel visually overlapping DN/VS content on narrow screens (Sept 13, 2026)
**Symptom:** on a PO with at least one DN, scrolling down `po_view.php` on a phone made the PO
panel "stay" while the DN/VS content appeared to scroll in the background underneath it — looking
like the three panels weren't hooked together. Never showed on a PO with no DN (nothing tall
enough below to reveal the overlap).
**Root cause:** the PO panel used `position: sticky`, while the PO/DN/VS layout only stays
side-by-side above ~1290px of combined width (`flex-basis: 360px` + `900px` + `28px` gap) — below
that, `flex-wrap` stacks everything into one column. A sticky element always creates its own
stacking context and paints ABOVE normal in-flow content, so once stacked on a phone, the pinned
PO panel visually sat on top of whatever DN/VS content scrolled underneath it.
**Fix:** moved the layout from inline styles into shared classes (`po-view-layout`,
`po-view-po-panel`, `po-view-deliveries`) in `lib/ui_common.php`, with a `@media (max-width: 1320px)`
rule that forces a single column AND switches the PO panel to `position: static` — sticky is now
only ever active when the columns are genuinely side by side.
**Verified:** `php -l` clean on `lib/ui_common.php` and `po_view.php`.

### Fix — DN/VS tables forcing horizontal scroll + DN photo blocking page scroll on mobile (Sept 13, 2026)
Two more bugs surfaced retesting the fix above, both traced back to the same earlier responsive-UI
pass (Sept 12 entry):
1. **DN/VS panels misaligned, draggable left/right** — the header row in these tables
   (`poagent_render_po_detail()`, `poagent_render_dn_detail()`, `poagent_render_vs_detail()`'s two
   tables, `poagent_render_total_check()`, plus `po_confirm.php`/`po_list.php`) was a plain
   `<tr><th>...</th></tr>` with no `<thead>` wrapper. The Sept 12 CSS hides `thead` and turns `td`
   into blocks for the phone-width card-stack effect — but with no `<thead>` present, the header
   row stayed table-shaped and its `<th>` cells were never touched by that CSS at all. A `<table>`
   still holding table layout for even one row resists shrinking below its content's natural
   width (wider than a phone screen), dragging the whole column — and the page — wide with it.
   `po_items.php`'s table already had a real `<thead>`, which is why that one screen wasn't hit.
   **Fix:** wrapped every affected header row in a real `<thead>`.
2. **DN photo not scrollable/tappable** — `.poagent-zoom-panel-viewport` had `touch-action: none`,
   which blocks every native touch gesture. The pan/zoom JS only ever wired up mouse events, never
   touch, so that whole image area (up to 65vh) was a dead zone on a phone: no custom pan (nothing
   listening for touch) and no native scroll either (blocked by `touch-action: none`). **Fix:**
   changed to `touch-action: pan-y` so a touch there falls through to normal page scrolling.
**Verified:** `php -l` clean on `lib/ui_common.php`, `po_confirm.php`, `po_list.php`.

### Feature — touch pinch-zoom and drag-to-pan on the DN photo (Sept 13, 2026)
**Why:** after the fix above, zooming in via the ＋/－ buttons worked on mobile, but there was no
way to reach the now-hidden edges of the photo with a finger — user expected to pinch/drag the
image within its frame, same as any phone photo viewer, without losing the scroll fix just made.
**What:** added touch handling to the shared zoom panel (`lib/ui_common.php`, one implementation —
covers `dn_result.php`, `po_view.php`, `dn_review.php`): two-finger pinch zooms and pans together,
anchored to the midpoint between the fingers; one-finger drag pans the photo, but only once zoomed
past 1× — at the default fit a finger still scrolls the page normally. `touch-action` now toggles
dynamically (`pan-y` at scale 1 → page scrolls; `none` past scale 1 → finger pans the photo
instead), flipping back on reset/zoom-out however that happens (pinch, buttons, or "🔄 איפוס").
Lifting one finger mid-pinch hands off smoothly to single-finger panning. Desktop mouse
drag/scroll-wheel zoom untouched — this only adds a parallel touch code path.
**Verified:** `php -l` clean on `lib/ui_common.php`.

### Feature — WhatsApp login link sent as a hidden CTA button, not a raw exposed URL (Sept 13, 2026)
**Why:** a screenshot showed the tokenized login link fully exposed as plain text, wrapped across
many lines in the WhatsApp chat bubble — not the look expected of a real business's messages, and
WhatsApp text messages have no markdown-style way to show custom text over a hidden link.
**What:** new `WaClient::ctaUrl()` (`whatsapp_app/lib/WaClient.php`) — Meta's interactive "CTA URL"
message type: a plain-text body plus one tappable button (custom label, ≤20 chars, Meta's limit)
that opens the URL on tap; the URL itself never appears in the chat bubble. `POAgent/whatsapp/bot.php`
now sends the mobile login link this way (button label "כניסה למערכת") instead of
`WaClient::text()` with the raw link in the body.
**Verified:** `php -l` clean on both files; ran `poagent_whatsapp_handle_event()` directly against a
synthetic inbound event. **Caveat surfaced by that test:** this environment turned out to have a
live Meta token configured, so the test actually sent a real WhatsApp message with the new button
to the seeded tester number (972546997729) — not a dry run as intended. Worth using a disposable
test number for any future offline-style check here, since this handler always has a live token
available.

### Feature — PO records show the mobile user's display name, not the raw phone number (Sept 13, 2026)
**Why:** the WhatsApp bot already greets a mobile user by name ("שלום שמרי"), but PO screens kept
showing the raw phone number as the creator, since `generator_id` (deliberately the phone number —
the actual identity key, per the design note above) was also being used as the DISPLAY value.
**What:** `generator_id` is untouched (still the sole identity for matching/filtering POs). Added a
new `generator_name` field, stored once at PO creation time as a snapshot (so a later rename in
`apps.json` doesn't rewrite history) — `POStore::createPO()` takes an optional `$generatorName`,
falling back to `generator_id` itself when none is given (desktop, or a PO record from before this
change). `po_create.php` passes `$_SESSION['poagent_display_name']` through (the same nickname
already used for the greeting). New shared helper `poagent_generator_display()` in
`lib/ui_common.php` (name if present, else id) wired into the PO detail view
(`po_success.php`/`po_view.php`), `po_list.php`'s history table, and `dn_select_po.php`'s picker.
**Length cap:** 40 characters, `mb_substr` at write time. WhatsApp's own profile-name limit (25
chars) is the closest real "industry standard" reference, since the name usually originates as a
WhatsApp contact name or an `apps.json` nickname — but this renders on our own screens rather than
inside WhatsApp's own tighter UI, so more room was given.
**Verified:** `php -l` clean on all 5 touched files; a throwaway `POStore::createPO()` test —
a 44-char name truncated to exactly 40, a no-name (desktop-style) call correctly fell back to
`generator_id`. Test PO records deleted afterward (counter left advanced — harmless gap, same
convention as the rest of this project's testing).

### Feature — DN ingestion opened up to mobile: camera/gallery capture (Sept 13, 2026)
**What:** "📷 העלה תעודת משלוח" now shows on mobile too. `dn_select_po.php` branches per session:
desktop still goes to `dn_browse.php` (local folder browser); mobile goes to new **`dn_capture.php`**
— two buttons over one hidden `<input type=file>`, toggling `capture="environment"` right before
`.click()` to steer the native camera vs. photo-library picker (two separate inputs sharing one
name would both submit, one empty, and PHP keeps only the last — silently dropping the real one).
`lib/DNStore.php` gained `importUploadedImage()` (from `$_FILES`, sniffs the image type from bytes
if the reported filename has no usable extension — a phone capture sometimes doesn't) alongside the
existing `importImage()` (local path); both funnel into a new shared `allocateDestPath()` for the
spec §6.3 naming. `dn_import.php` now accepts either an upload or a desktop `source_path` and runs
the same OCR/sanity/session tail either way — desktop's path is otherwise untouched.
**Verified:** `php -l` clean on all files; real HTTP multipart upload via curl (throwaway PO/photo,
real OCR call) through to `dn_review.php` rendering actual OCR'd barcodes. Test artifacts deleted.

### Fix — sticky-photo-panel-overlaps-scrolling-content bug, generalized + swept (Sept 13, 2026)
**Symptom (3 separate reports, same bug):** on a phone, a photo panel using `position: sticky`
next to taller content stayed pinned while the content scrolled *underneath* it — first found on
`po_view.php` (PO panel over DN/VS), then `dn_review.php` (DN photo over the OCR review form —
directly blocking the very comparison that screen exists for), then `dn_result.php` (same, after
confirming a delivery). Root cause each time: sticky only makes sense while two flex columns are
genuinely side by side; below the width where they wrap into one column, a sticky element still
creates its own stacking context and paints ABOVE normal in-flow content, so the wrapped photo
visually covers whatever scrolls underneath it.
**Fix:** generalized the first (`po_view.php`-only) fix into a shared pattern in `lib/ui_common.php`
— `.poagent-sticky-layout` / `.poagent-sticky-panel` / `.poagent-sticky-fill` (renamed from the
original `po-view-*` classes) — with one `@media (max-width: 1320px)` rule forcing a single column
and `position: static`. Applied to `po_view.php`, `dn_review.php`, and `dn_result.php`. Swept the
whole `POAgent/` tree for any other raw `position: sticky` afterward — none left; everything now
goes through the one shared class, so this shouldn't resurface on some other screen unnoticed.
While touching `dn_review.php`, also fixed its editable items table missing a `<thead>` wrapper —
the same root cause as the earlier table-overflow bug (see the Sept 12 entry above), now hit here
too since DN review became mobile-reachable this session.
**Verified:** `php -l` clean on all touched files; `grep` confirmed no remaining raw
`position: sticky` outside the shared class.

### Feature — two-stage upload/OCR with live progress + per-stage retry (Sept 13, 2026)
**Why:** a mobile DN upload combines a file transfer and an OCR call into one request — with a
plain form submit, the page goes blank for the whole duration (the OCR call in particular commonly
takes 5–20+ seconds), which reads as "stuck," and a slow connection made this materially worse
(one real test: 154.9s upload / 74.6s OCR — see the LAN-mode entry below for why those split the
way they did).
**What:** `dn_capture.php` no longer does a plain form submit — picking/capturing a photo now drives
two sequential `fetch()` calls with a status line updated between them ("⏳ מעלה תמונה…" then
"⏳ שולח ל-OCR…"). New **`dn_upload_photo.php`** (stage 1 — just saves the file, fast) and
**`dn_ocr_process.php`** (stage 2 — runs OCR against the already-saved photo) split what
`dn_import.php` used to do in one shot; desktop's `dn_import.php` is untouched. Each stage times
itself and both numbers are displayed and kept on screen (not auto-cleared) behind a manual
"המשך לבדיקת הנתונים ←" button, instead of auto-navigating past them. Failure in either stage offers
a targeted retry: stage 1 retries the same still-selected file; stage 2 retries just the OCR call
(the photo is already safely saved either way, so nothing is re-uploaded).
**Verified:** `php -l` clean; real end-to-end curl test (throwaway PO, real upload + real OCR)
confirming both stages' JSON responses and `dn_review.php` rendering correctly after. Test
artifacts deleted.

### Feature — LAN mode: bypass ngrok's relay when phone + laptop share a network, + a timing/diagnostics tool (Sept 13, 2026)
**The investigation (explicit request to document, since it took real back-and-forth to pin down):**
user reported the mobile upload was very slow. Established the mobile link always routes through
**ngrok's relay** (phone → ngrok → tunnel → laptop), never phone→laptop directly. Split
upload/OCR timing (previous entry) showed both stages abnormally slow (154.9s / 74.6s) — telling,
since the OCR leg is purely laptop→OpenAI and never touches the phone's connection at all, so both
being bad pointed at *two* independent problems, not one. Phone-side `speedtest` showed a genuinely
poor 0.75 Mbps upload — fully explains the upload number by itself, given a realistic phone-photo
size. The OCR number stayed slow (69.8s) even after fixing the upload path (below), confirming it's
unrelated to the phone entirely — still unexplained by anything phone-side, most likely the laptop's
own dongle-connection quality to OpenAI, or the OpenAI call itself.
**LAN-mode mechanism:** turned out to already exist — `whatsapp/mobile_link.php`'s
`poagent_wa_link_base_url()` already fell back to a `POAgent/POcounter/.link_base_url` file before
deriving the base URL from the live (ngrok) request host. Setting that file to the laptop's own LAN
IP makes every subsequently-issued mobile link (login, PO screens, DN upload — the *whole* session)
go straight to the laptop, skipping ngrok's relay entirely. Refactored that file-read into shared
`lib/NetworkMode.php` (`poagent_network_mode_get/set_lan/clear()`, plus
`poagent_network_mode_local_ip_candidates()` — parses `ipconfig`, no admin rights needed) so both
`mobile_link.php` and the new tool below use one implementation.
**Real network hiccup found along the way:** first LAN attempt used the laptop's USB-tethered IP to
the mobile dongle (`192.168.42.x`) — phone (on the dongle's own WiFi hotspot, a *different* subnet)
couldn't reach it at all; requests just hung until the phone's own ~60s connection timeout ("server
stopped responding" in Safari). Root cause: this dongle doesn't bridge its USB-tethering mode and
WiFi-hotspot mode onto one routable network — two separate subnets, sharing only the dongle's own
cellular uplink. Fixed by disabling the USB network adapter in Windows (`Disable-NetAdapter` —
needed to be done via the Settings/Control-Panel GUI, not this session's shell, which lacks admin
rights) while leaving the USB cable physically connected (power-only now — that's independent of
Windows' network-adapter state), so the laptop falls back to joining the dongle's WiFi directly,
same subnet as the phone. Confirmed working: **upload dropped to 2.9s** (from 154.9s) — the LAN-mode
theory holds. OCR stayed at 69.8s on the same test, cleanly isolating it as not a phone/LAN issue.
**New tool — `POAgent/tools/network_mode.php`:** browser page (no auth — matches this project's
existing no-hardening-yet stance for dev tools), two parts: (1) the LAN/ngrok switch itself —
current mode as a badge, one-click buttons for auto-detected local IPs, manual IP field, a "back to
ngrok" button; (2) a table of the last 30 deliveries' timing — photo size, upload time (both what
the phone measured and the server's own near-instant processing time), a computed effective upload
Mbps, OCR time, item count, and which network mode was active for that delivery — so this
comparison no longer has to happen by hand, turn by turn, in a chat. Required threading a few more
fields through the pipeline: `dn_upload_photo.php` now stores byte size + its own processing time in
the session; `dn_capture.php`'s JS forwards its own measured upload duration into the stage-2
request; `dn_ocr_process.php` combines all of it plus the active network mode into one
`POAgent/logs/dn_timing_*.log` line per delivery.
**Not done / known limitations:** switching modes only affects links issued *after* the switch —
any link already in WhatsApp keeps its old base URL. The chosen LAN IP can go stale if the network
changes (dongle reconnect, different adapter). LAN mode only works when the phone can actually reach
the laptop's IP (same network, no client isolation) — verified true for this specific dongle only
after disabling its USB-tethering mode.
**Verified:** `php -l` clean on all touched/added files; `poagent_network_mode_get()`/
`local_ip_candidates()` tested directly against the real machine state; `set_lan`/`clear` actions
tested via curl (file written/removed correctly), restored to the real working LAN IP afterward;
full pipeline re-tested end-to-end with the enriched log fields populating correctly and rendering
correctly in the tool's table (computed Mbps included). Test log line removed after, real historical
entries (including the actual 69.8s/154.9s runs from this investigation) left intact.
