# OCR Subproject Documentation

**Project Location:** `C:\xampp\htdocs\website`
**Last Updated:** September 2, 2026
**Status:** Phase 1 Complete - Ready for Commercial Layer

---

## Recent Updates (September 6, 2026)

### `whatsapp_app/` — named+enforced allowlist, `WaClient::list()`; POAgent PO session over WhatsApp

- **`apps.json`** `allowed_senders` entries may now be `{ "number", "name" }` objects (bare
  strings still work). `poagent` is now `allowlist_mode: "enforced"` — an unlisted number gets no
  reply. `WaRouter` gained `allowedNumbers()` / `senderNameFor()`; the normalized event carries
  `allowlist_name` (its `normalizeEvent()` first arg changed `string $appId` → `array $app`).
- **`WaClient::list()`** — interactive list message (up to 10 rows) for menus with more than 3
  options, alongside the existing `text()` / `buttons()`. (Added for the in-chat menu; currently
  unused after the pivot to a web link, kept as generic client capability.)
- **POAgent WhatsApp bot** is now a **mobile web launcher**: any inbound message → a greeting +
  a short-lived signed link into the real PO web pages (`POAgent/m/?t=<token>`), authenticated as
  the sender's phone number (= the PO's `generator_id`). No PO flow in chat. `main_menu.php` is
  now audience-aware (mobile = create-PO / history / logout only). An earlier in-chat
  conversational flow was built then parked (`POAgent/whatsapp/po_flow.php`, unwired). Full detail
  in `POAgent/POAgentNotes.md` §9. NP is untouched.

---

## Recent Updates (September 2, 2026)

### `whatsapp_app/` — multi-app router in front of the single Meta webhook

Meta allows exactly one webhook callback URL per app/WABA and fans every event for every business
number to it. `whatsapp_app/webhook.php` is that URL. It still appends every raw payload to
`whatsapp_images/webhook_log.txt` unchanged (NP's `MessageFetching.php` depends on that), then —
after ack'ing Meta — hands the body to a new **`WaRouter`** (`whatsapp_app/lib/WaRouter.php`).

`WaRouter` matches each event's **business number** (`phone_number_id`, or `display_phone_number`
as a fallback) against **`whatsapp_app/apps.json`** and calls the one matching app's handler with a
normalized event. Registry today: `poagent` (052-2649555 → `POAgent/whatsapp/bot.php`) and `np`
(+1 555 173 2464 → no live handler; the raw log is all NP uses). Adding a WhatsApp app = a new
`apps.json` entry + a handler file; no change to `webhook.php` or `WaRouter`.

New shared infra under `whatsapp_app/lib/`: `WaClient` (outbound Graph API send, token still from
`config.json` → `meta_key`), `WaSessionStore` (`wa_id`→state files under `wa_sessions/`, wired but
not consumed yet). Router/send logs under `whatsapp_app/logs/`. All new dirs git-ignored.

NP is untouched: its data path is the raw-log append in `webhook.php`, which runs before the router;
its `apps.json` entry has no handler. See `POAgent/POAgentNotes.md` §9 for the full picture.

---

## Recent Updates (August 10, 2026)

### 1. Barcode Checksum Verification (BR stage + final review)

`NPharmonized/barcode_validate.php` (new, shared) — `validateBarcode($code)`: standard mod-10 checksum for **EAN-13** (13 digits, odd position ×1 / even ×3), **UPC-A** (12 digits, odd ×3 / even ×1), **EAN-8** (8 digits, odd ×3 / even ×1). Check digit = `(10 - sum%10) % 10`, compared to the barcode's last digit. Returns `false` for any length other than 8/12/13, non-digit input, or a failed check digit. Verified against 12 test cases including 3 real-world known-valid barcodes (EAN-13/UPC-A/EAN-8) plus corrupted/wrong-length/non-numeric variants — all passed.

- **`stage_br.php`**: a second, non-blocking advisory warning under each barcode field ("⚠ ברקוד לא תקין (אורך או ביקורת ספרה)") — distinct from, and independent of, the existing hard block on >13-digit barcodes (which still disables the submit button as before). **Checksum failure never blocks moving to the next stage** — purely advisory, per explicit requirement. Live-updates via JS (a hand-mirrored copy of the same algorithm) as the AI-read value is edited.
- **`stage_dcl.php` / `stage_dcl_save.php`**: new **"ביקורת ספרה"** column showing ✔ (green) / ✘ (red) per row. Live-updates on `stage_dcl.php` as the barcode is corrected there too.

### 2. Enhanced Final Check — "FTP" (Failed To Pass) Section

`stage_dcl.php` computes, per row:
```
isFtp = !checksumOk || status !== 'ok'
```
— covering all three required conditions: checksum failed, CHPF returned no data at all (`status === 'na'`, confirmed this also correctly catches the "no product description AND no min/max price" case — CHPF writes the literal string `"NA"` rather than leaving cells blank, so the row survives the collection loop and is correctly flagged), or the sale price is outside the CHP min/max range.

A new section renders below the main table (only when ≥1 row is FTP): lists the barcode as read, the specific failure reason(s) (a row can show more than one), and **the source photo again with the same zoom in/out (＋/－) controls as `stage_br.php`**, served via the existing `serve_image.php`.

To make the photo re-lookup possible without re-deriving harvest-mode/sort order, **`stage_br_save.php` now also writes the source image filename into column K** per row. **`stage_dcl_save.php` clears column K** (alongside D/E/H) before the final `_DCL.xlsx` save — it's working data for the review screen, not meant for the deliverable file.

#### Small related correction — barcode columns A/C
A full A=C mirroring attempt (BR stage + final stage both forcing the columns identical, and stopping `stage_chpf.php` from writing CHP's own returned barcode into C) was implemented and then **rolled back** at the user's request ("caused a problem"). A narrower, explicitly-requested version was then added instead: **only at the final save (`stage_dcl_save.php`, on the "אישור – שמור וסיים תהליך" click)**, the barcode is copied **A → C** (A, the user-verified/corrected value, is authoritative; C is overwritten to match, discarding whatever CHP's lookup had put there). BR stage and CHPF stage are untouched — confirmed via diff that only `stage_dcl_save.php` changed.

### 3. Flow Mode

**`index.php`**: new switch — "מצב תהליך": **Flow (default)** / Steps.

- **Flow**: auto-advances through every stage from `confirm.php` through `stage_chpf.php` → `stage_dcl.php`, without requiring the user to click each stage's own אישור button — **except `stage_dcl.php` (final review), which always requires the manual "אישור – שמור וסיים תהליך" click, in every mode.** Confirmed via grep: zero auto-advance calls exist in `stage_dcl.php`.
- **Steps**: the original click-through behavior, a complete fallback — zero JS auto-submit anywhere. Use if Flow misbehaves.
- **Auto-advance never bypasses real validation** — it only fires when the stage's own success condition already holds, mirroring whatever already gated that stage's submit button (or an equivalent new safety gate added where none existed):

| Stage | Auto-advance gate |
|---|---|
| `confirm.php` | `$allOk` (same as the existing disabled-button condition) |
| `process.php` | `empty($errors)` (new safety gate — button wasn't previously conditional) |
| `process_whatsapp.php` | `$skipped === 0` (new safety gate) |
| `stage_br.php` → `stage_br_save.php` | `!$anyLong` — new server-side mirror of the pre-existing client-side >13-digit block |
| `stage_br_save.php` → `stage_chpf.php` | unconditional (no failure path reaches this output — a `die()` upstream already stops it) |
| `stage_chpf.php` → `stage_dcl.php` | `$saveOk` (same as the existing disabled-button condition) |

Mechanism — `NPharmonized/flow_mode.php` (new, shared):
- `npFlowMode()` — reads `mode` from POST/GET, defaults to `flow`, anything but `'steps'` falls back to `flow`.
- `renderFlowAutoAdvance($mode, $formId, $delaySeconds = 3)` — renders a countdown banner + delayed `form.submit()` of the named form id, flow-mode only.
- `renderFlowStopNotice($mode)` — small explanatory banner shown on `stage_dcl.php` in flow mode ("this is the final checkpoint").

**Cross-stage filesystem race protection** — this is the retry-with-backoff mechanism that was drafted earlier and deliberately deferred ("hold it for that future flow-mode work"); this is that moment. `NPharmonized/xlsx_retry.php` (new, shared) — `loadSpreadsheetWithRetry()`: up to 4 attempts (1 + 3 retries), **3 seconds** apart, before throwing a clear "נכשל בטעינת הקובץ אחרי N ניסיונות (כנראה בעיית סנכרון עם מערכת הקבצים)" error instead of PhpSpreadsheet's raw "Unable to identify a reader" message. Applied in `stage_br_save.php`, `stage_chpf.php`, `stage_dcl.php`, `stage_dcl_save.php` — **regardless of Flow/Steps mode** (pure robustness, fast path adds zero delay; verified: valid file loads in ~0.05s, a genuinely missing file correctly retries the configured number of times before throwing with the augmented message).

**Special case — 6 second wait for the BR verification step:** after Flow mode landed, the user found the default 3-second countdown on `stage_br.php` (AI-read barcode verification — "the most sensitive part of the flow") too fast to intervene manually when needed. That one transition (`stage_br.php` → `stage_br_save.php`) now uses `renderFlowAutoAdvance($mode, 'advanceForm', 6)`. Every other transition stays at the default 3 seconds.

### 4. MessageFetching — Numeric Price Extraction & Caption Borrowing

`whatsapp_app/MessageFetching.php` — two fixes to how an image's price (the "Y" in `ddmmyy-hhmmss X Y.ext`) is derived from the WhatsApp caption:

- **Numeric-only extraction** (`extractNumericCaption()`): captions sometimes carry extra text alongside the price (e.g. `"13.9 מבצע"`, `"מחיר 24.9 כולל מעמ"`) — only the first number (digits + optional decimal point) is kept, the rest discarded. Verified with 8 cases.
- **Caption borrowing when the image itself has no caption** (`resolveCaptionSources()`, two passes over the whole message list):
  1. **Forward** — borrow the *next* message's number if it's a text-only message (no image) containing one. (Original ask: caption sent as a separate follow-up text.)
  2. **Backward** (fallback only) — borrow the *preceding* message's number instead, but only if pass 1 didn't already claim it for a different, earlier image. Added after a real log showed the *opposite* order happening in practice: a photo's own message timestamp landed **after** a text sent moments later, because large media takes longer to upload than text — so the caption text can reach the webhook before its own image does, even though it was sent second.
  - Forward always resolves before backward runs, so a number-text sitting between two textless images is claimed exactly once (by the image before it), never both — verified with 10 checks including that exact ambiguous case.
  - User-side mitigation adopted alongside this: send an image+price pair back-to-back, not interleaved with other photos, to keep them adjacent in the log.

#### Files Created/Modified (this round)
| File | Change |
|---|---|
| `whatsapp_app/MessageFetching.php` | Numeric-only caption extraction; two-pass forward/backward caption borrowing for captionless images |
| `NPharmonized/barcode_validate.php` | NEW — `validateBarcode()`, mod-10 checksum for EAN-13/UPC-A/EAN-8 |
| `NPharmonized/xlsx_retry.php` | NEW — `loadSpreadsheetWithRetry()`, 4 attempts / 3s apart |
| `NPharmonized/flow_mode.php` | NEW — `npFlowMode()`, `renderFlowAutoAdvance()`, `renderFlowStopNotice()` |
| `NPharmonized/index.php` | Flow/Steps switch (Flow default) |
| `NPharmonized/confirm.php` | mode threading + auto-advance on `$allOk` |
| `NPharmonized/process.php` | mode threading + auto-advance on `empty($errors)` |
| `NPharmonized/process_whatsapp.php` | mode threading + auto-advance on `$skipped === 0` |
| `NPharmonized/stage_br.php` | checksum advisory warning, `!$anyLong` auto-advance gate, 6s delay to `stage_br_save.php` |
| `NPharmonized/stage_br_save.php` | retry-load, mode threading, unconditional auto-advance, writes col K (image filename) |
| `NPharmonized/stage_chpf.php` | retry-load, mode threading, auto-advance on `$saveOk` |
| `NPharmonized/stage_dcl.php` | retry-load, checksum column, FTP section (photo + zoom re-check), flow-stop notice, **no auto-advance** |
| `NPharmonized/stage_dcl_save.php` | retry-load, clears col K, A→C barcode copy on final save |

---

## Recent Updates (August 5–9, 2026)

### WhatsApp Picture-Load Pipeline for the NP Process

#### Overview
New capability letting the New Product (NP) process pull product photos + prices from a WhatsApp bot conversation instead of manual file placement. Two parts: (1) a standalone WhatsApp receiver/fetcher under `whatsapp_app/`, and (2) a "WhatsApp" harvest mode woven into the existing `NPharmonized/` flow, which rejoins the original ("Manual") flow at the barcode-reading stage.

#### Part 1 — `whatsapp_app/` (Meta WhatsApp Cloud API receiver)

| File | Purpose |
|---|---|
| `webhook.php` | Meta webhook endpoint. GET = verification handshake (`hub_verify_token` check). POST = **only** appends the raw JSON payload to `whatsapp_images/webhook_log.txt` (timestamped). Does **not** download images or write any per-message file itself — that's `MessageFetching.php`'s job. |
| `MessageFetching.php` | Run manually (browser or CLI) after sending WhatsApp photos to the bot. Parses `webhook_log.txt` top to bottom, flattens every `entry[].changes[].value.messages[]` across *all* logged payloads into one arrival-ordered list, and assigns each message a **serial number** (1, 2, 3, … — counts every message, not just images). For `image` messages, downloads via the message's `url` (falling back to the media-lookup Graph API endpoint + `meta_key` from `config.json`) and saves as `ddmmyy-hhmmss X Y.ext` where X = serial number, Y = sanitized caption, ext = from the mime type (e.g. `image/jpeg` → `jpeg`). Skips re-downloading if the target filename already exists (idempotent — safe to re-run). Browser output is wrapped in `<pre style="font-size:2em">` so each log line renders on its own row at 2× size (plain `echo "...\n"` doesn't produce line breaks in an HTML response, and text renders at normal size without this). |
| `messages.php` | **Removed** — was a browser viewer for `messages.jsonl`, which is no longer produced. |
| `whatsapp_images/` | Drop zone: `webhook_log.txt` (arrival log, permanent), downloaded images, `.gitignore`. Images are the staging area consumed by `NPharmonized/process_whatsapp.php` — see below. Originals are **copied**, not moved, into the NP directory, so this folder keeps accumulating history for debugging (not auto-cleared). |
| `config.json` | Holds `meta_key` (WhatsApp Cloud API access token) used for media downloads. |

**Key fact used for the naming convention:** message `timestamp` (unix epoch, UTC) formatted with `gmdate('dmy-His', ...)` matches Meta's value exactly with no timezone conversion — verified against a real payload (`1785917371` → `050826-080931` UTC).

#### Part 2 — `NPharmonized/` WhatsApp harvest mode

**Start screen (`index.php`):** added a switch — "אופן איסוף תמונות": ידני (Manual) / WhatsApp. **WhatsApp is the default** (as of Aug 9; originally Manual was default when the switch was first added).

**`confirm.php`** branches on `harvest_mode`:
- **Manual** — unchanged from the original flow (npDir + xlsx + jpeg-count pre-flight checks).
- **WhatsApp** — creates the NP directory if it doesn't exist (`mkdir` recursive), then validates every image in `whatsapp_app/whatsapp_images` against the `ddmmyy-hhmmss n y.jpe?g` convention: proper naming, `n` must be a pure integer, and all `n` must be unique. Any violation is listed inline (bad filenames / duplicate serials) and the אישור button stays disabled — this is the "prompt and stop" behavior. Only `.jpg/.jpeg` is accepted (matches a system-wide assumption elsewhere — see Known Limitations below).

**`process_whatsapp.php`** (new — runs after אישור, mirrors `process.php`'s role for Manual mode):
1. Re-validates the staged images (defensive re-check).
2. Sorts by **serial number**, not filename timestamp.
3. **Copies** (not moves) each image into the NP directory — originals stay in `whatsapp_images` for debugging.
4. Generates a fresh NPL workbook with headers `A1:G1` = מס פריט / שם פריט / ברקוד / מחיר קניה / ספק / מחיר מכירה / מחלקה (bold).
5. Writes each image's caption into column F at row `(index-in-sorted-order + 2)` — this is a **compacted** write, so a serial gap (e.g. images numbered 1, 3, 4) never produces a blank row; no separate "delete empty rows" pass is needed.
6. Hands off to `stage_br.php` with `harvest_mode=whatsapp` in the form.

**`stage_br.php`** is now mode-aware: for `harvest_mode=whatsapp` it matches the `ddmmyy-hhmmss n y` filename and **sorts by the serial number `n`**, never by the embedded timestamp — two messages can land in the same second, so serial order is the only reliable source of truth. Manual mode's original date-string sort is untouched. `process.php` (Manual path) now explicitly passes `harvest_mode=manual` for clarity.

From `stage_br.php` onward (CHPF, DCL) the two modes are fully unified — no further mode branching anywhere downstream.

#### Part 3 — DCL final screen: editable review before the file is finalized

Split the old single-pass "`stage_dcl.php`: classify + auto-save + show results" into an edit-then-confirm flow, mirroring the existing BR-stage pattern (`stage_br.php` compute → `stage_br_save.php` save):

- **`stage_dcl.php`** — now compute-only (calls OpenAI for dept classification, builds the review data) and **saves nothing**. Renders an editable table: `#, ברקוד, שם מוצר, מחיר מכירה, מחיר מול CHP (בטווח/מעל המקסימום/מתחת למינימום), מספר מחלקה, שם מחלקה, הערת AI`. **Barcode, product name, and department number are editable `<input>` fields** (pre-filled or blank), so missing data can be filled in. Editing department number live-updates the (read-only) department name next to it via a client-side lookup — no way to end up with a mismatched pair. A sticky side panel lists every department name+number for the shop, for reference while editing.
- **`stage_dcl_save.php`** (new) — runs on the אישור click. Re-loads the pristine `_BR_CHPF.xlsx`, writes the edited barcode/name into A/B, department number into G, recomputes the price-vs-CHP status from D/E/F (unaffected by edits), and saves the definitive `_DCL.xlsx`. This is the actual end of the process now. Shows the completion banner + price-warning summary (moved here from the old `stage_dcl.php`).
- **Final-file cleanup**: `stage_dcl_save.php` explicitly clears columns **D and E** (CHP min/max — only needed transiently to compute the status) and **H** (department name) before saving, and no longer writes the old I/J full-department-reference-list at all. The final `_DCL.xlsx` therefore carries only the department **number** (G), not the name — the name is shown on screen for reference only. This was a deliberate, explicit user request (avoid cluttering the final NP file with working/lookup data now that the app shows it interactively).
- **Column width fix**: the editable table uses `table-layout: fixed` with `calc()`-based widths that account for the input's padding/border overhead (`box-sizing: border-box` eats padding out of a plain `ch` width, which is why a first attempt at `3ch` for department-number rendered as an invisible sliver). Current widths: barcode `calc(13ch + 30px)`, product name `calc(13ch * 2.5)`, department number `calc(3ch + 40px)`, AI-note column narrowed and wrapping enabled.

#### Barcode columns A vs C — attempted unification, reverted
Columns A and C both end up holding a barcode-like value for historical reasons: **A** = the barcode the user verified/edited in the BR stage (`stage_br_save.php`) and can further edit in the DCL stage (`stage_dcl_save.php`); **C** = the barcode *CHP's own database* returns for the matched product (written in `stage_chpf.php`), which can legitimately differ from A. A change was made to force C to always mirror A (stop `stage_chpf.php` writing CHP's returned barcode into C, write the same value to A+C in both save stages) — the user reported this **caused a problem** and asked for a full rollback, which was done. **Current state is back to the original: A and C can differ, and that's expected.** Do not re-attempt without first understanding what broke.

#### Known issue — cross-stage filesystem race on save→load handoff
**Symptom:** `שגיאה בטעינת Excel: Unable to identify a reader for this file` when a stage (e.g. `stage_chpf.php`) loads an xlsx that the *immediately preceding* stage had just saved a moment earlier, on the `Z:\...` (RetailomaticsCloud) network/cloud-synced drive.

**Diagnosis:** Not file corruption — the file in question was verified as a structurally valid xlsx (`unzip -l` showed a complete ZIP) and loaded successfully via PhpSpreadsheet moments after the error. Most likely a transient lock/scan (antivirus real-time scanning, or the cloud-sync agent) on a freshly-written file on the network drive, in the brief window right after the previous stage's `save()` call returns. **User confirmed waiting ~3 seconds between stages avoids it** — this is a click-pace issue, not a data-integrity issue.

**Planned fix (not yet implemented):** when an automatic/continuous flow mode is built (stages advancing on their own without the user manually clicking through each one), add a retry-with-backoff around `IOFactory::load()` for xlsx files that were just written by the previous stage, so it waits briefly for the filesystem to settle instead of failing immediately. A first draft of this (`xlsx_retry.php`, `loadSpreadsheetWithRetry()`) was written and then intentionally **not** adopted yet — hold it for that future flow-mode work rather than adding it to the current manual-click flow.

#### Known Limitations
- Barcode-reading (`stage_br.php`'s `askBarcode()`) hardcodes `data:image/jpeg;base64,...` regardless of actual file type — the whole pipeline assumes JPEG end-to-end. WhatsApp-mode image validation in `confirm.php`/`process_whatsapp.php` only accepts `.jpg/.jpeg` for this reason; a PNG/WEBP caption photo is correctly flagged as invalid naming rather than silently mishandled.
- `whatsapp_app/whatsapp_images` is a single shared staging folder, not scoped per NP session — every image currently sitting there when `process_whatsapp.php` runs is treated as belonging to that session.

#### Files Created/Modified
| File | Change |
|---|---|
| `whatsapp_app/webhook.php` | Simplified to raw-log-only (no image download / no jsonl) |
| `whatsapp_app/MessageFetching.php` | NEW — parses log, serial-numbers messages, downloads images, HTML report |
| `whatsapp_app/messages.php` | Removed |
| `NPharmonized/index.php` | Harvest-mode switch (WhatsApp default) |
| `NPharmonized/confirm.php` | Branches Manual/WhatsApp; WhatsApp dir creation + image validation |
| `NPharmonized/process_whatsapp.php` | NEW — copies images (serial order), generates NPL, gap-compacted F column |
| `NPharmonized/process.php` | Passes `harvest_mode=manual` explicitly |
| `NPharmonized/stage_br.php` | Mode-aware image ordering (serial vs. date) |
| `NPharmonized/stage_dcl.php` | Rewritten: compute + editable review only, no save |
| `NPharmonized/stage_dcl_save.php` | NEW — applies edits, saves final `_DCL.xlsx`, clears D/E/H, drops I/J ref list |
| `NPharmonized/stage_chpf.php` | Unchanged from original (A/C unification reverted here) |
| `NPharmonized/stage_br_save.php` | Unchanged from original (A/C unification reverted here) |

---

## Recent Updates (June 25, 2026)

### NP Harmonized Module — Core Stages Complete

#### Overview
New module `NPharmonized/` integrates three previously standalone NP tools (NPbarcode, CHPfetch, NPclassify) into a single sequential flow. The user runs one process end-to-end; intermediate Excel files are saved at each stage for debugging.

#### Full Flow

```
index.php         → Input: date, shop, root drive letter
confirm.php       → Verify paths & file counts (✅/❌ per check); אישור button gated on all-pass
process.php       → Rename WhatsApp JPEGs: "WhatsApp Image yyyy-mm-dd at hh.mm.ss.jpeg"
                     → "yyyy-mm-dd at hh.mm.ss {price}.jpeg" (chronological match to col F prices)
stage_br.php      → Stream GPT-4.1-mini barcode reading per JPEG; verification table with images
stage_br_save.php → Write barcode→col A (TYPE_STRING), price→col B; save _BR.xlsx; auto-advance
stage_chpf.php    → Stream CHP fetch per barcode; write name→B, barcode→C, max→D, min→E;
                     auto-size B–G; save _CHPF.xlsx; price-range check shown; אישור button
stage_dcl.php     → GPT-4.1-mini dept classification (batch); write dept#→G, dept name→H;
                     save _DCL.xlsx (final); price-range summary + WhatsApp copy button
serve_image.php   → Serves JPEG from np_dir safely (path-traversal guard) for verification table
```

#### Intermediate Excel Files Saved in NP Directory
| File suffix | Content added |
|---|---|
| `_BR.xlsx` | Col A = barcode (string), Col B = sale price |
| `_BR_CHPF.xlsx` | Col B = Hebrew name, C = CHP barcode, D = max price, E = min price |
| `_BR_CHPF_DCL.xlsx` | Col G = dept number, Col H = dept name (final output) |

#### NP Working Directory Path
`{root}:/RetailomaticsCloud/RetailomaticsArchive/{shop}/NewProducts/{YYYY}/{MM_Mmm}/{dd-mm-yy} NP`

#### Price Range Warning Feature
After CHPF fetch, the sale price (col F of original NPL, never overwritten) is compared against CHP min/max (cols E/D). Rows where sale > max or sale < min are flagged:
- In the CHPF stage results table (per-row status column + summary box)
- On the final DCL screen (summary box + **"📋 העתק להודעת WhatsApp" button** that copies the warning text to clipboard)
- Rows where CHP returned no prices (NA) are counted separately; no comparison is made for them

#### Key Technical Decisions
- All pages rendered at 150% scale (font-size, padding) — consistent with user preference
- UI language: Hebrew RTL (`dir="rtl"`, `lang="he"`)
- `session_start()` used in stage_br.php / stage_br_save.php to pass large result arrays across the streaming → save boundary (same pattern as original NPbarcode)
- JPEG rename pattern guard: only files matching `/^WhatsApp Image \d{4}-\d{2}-\d{2} at \d{2}\.\d{2}\.\d{2}\.jpeg$/i` are renamed
- `serve_image.php` uses `realpath()` + prefix check to prevent path traversal when serving from Z:\ drive

#### Files Created
| File | Purpose |
|---|---|
| `NPharmonized/index.php` | Input form |
| `NPharmonized/confirm.php` | Pre-flight checks |
| `NPharmonized/process.php` | JPEG rename |
| `NPharmonized/stage_br.php` | Barcode reading (streaming) |
| `NPharmonized/stage_br_save.php` | Save _BR.xlsx |
| `NPharmonized/stage_chpf.php` | CHP fetch (streaming) + price check |
| `NPharmonized/stage_dcl.php` | Dept classification + final summary |
| `NPharmonized/serve_image.php` | Image server for Z:\ drive |

#### Bug Fixes Applied to Standalone Modules (pre-harmonization)
- **CHPfetch/process.php**: Column C (returned barcode) now saved as `TYPE_STRING` to prevent Excel scientific-notation conversion of long barcode numbers
- **CHPfetch/process.php**: Auto-size added for columns B–G before save

---

## Recent Updates (May 9, 2026)

### Verify Price Changes Screen — Handsontable Rewrite & Bidirectional Editing

#### Overview
The PC verification screen (`commercialLayer/verify_price_changes.php`) was fully rewritten from an HTML form/table to a **Handsontable 12.3.1** Excel-like grid, with bidirectional price↔margin editing, auto-save on Continue, and MyShop department/supplier config files added.

#### Handsontable Grid (replaced HTML table)

- **Columns A-B hidden** from view (InvoiceNo + OriginalIndex) — data preserved in Excel on save
- **`direction: ltr`** on container + `layoutDirection: 'ltr'` in Handsontable config — prevents RTL page inheritance which caused browser-level horizontal scrollbar at normal zoom
- **Dynamic height** via `getBoundingClientRect().top` — `Math.floor(window.innerHeight - top - 12)` ensures scrollbars appear at any browser zoom level
- **Barcode column**: click-to-copy → populates CHP search barcode field
- **"Not Found" barcodes**: bold border on cell (`notFoundRowSet`)
- **Recommend column**: green text for YES, red for NO

#### Editable Columns & Yellow Headers

Three columns are editable; all three have **bright yellow (`#FFFF00`) column headers** to signal editability:

| Column | Editable | Behaviour |
|---|---|---|
| **Rec Price** | Yes | Editing recalculates Rec Mrgn; output cell turns gold |
| **Rec Mrgn** | Yes | Editing recalculates Rec Price; output cell turns gold |
| **Recommend** | Yes | Free-text; tracked as dirty, saved as-is |

#### Bidirectional Price ↔ Margin Calculation

Both fields editable; the **output** cell (the one the system calculated) is highlighted **gold (`#FFD700`)**, the input cell stays normal.

- **User edits Rec Price** → `Rec Mrgn = (RecPrice/1.18 − ActualUnitPrice) / (RecPrice/1.18) × 100` → Rec Mrgn cell turns gold
- **User edits Rec Mrgn** → `Rec Price = ActualUnitPrice / (1 − RecMrgn%) × 1.18` → Rec Price cell turns gold
- **Recommend** updates automatically in both directions (YES. increase / YES. decrease / NO based on SalesPrice comparison)

Gold highlight is tracked in a client-side `highlightedCells` Map and applied via Handsontable's `cells()` callback. On page load, rows with gold Rec Mrgn in the Excel file are pre-populated into `highlightedCells`.

#### Save Logic (`doSave()`)

All edits are tracked in a `dirtyRows` Set. Source filtering in `afterChange`:
- `source === 'calculated'` — our own bidirectional update → skip (prevents infinite loop)
- `source === 'external'` — server response applied back to table → skip (prevents floating-point drift re-triggering a recalculation)

`doSave()` is a reusable Promise-based function used by both the Re-calculate button and the Continue button:

```javascript
// For each dirty row, sends:
{ rowNum, recPrice (or null if only Recommend changed), recommend }
```

PHP endpoint (`action: recalculate`):
- If `recPrice` provided → saves Rec Price, recalculates Rec Mrgn (with gold fill in Excel), saves both
- Always saves `recommend` as-is from the table (preserves manual edits, not re-derived from formula)

#### Auto-Save on "Continue to New Product Process"

Previously, clicking Continue without first clicking Re-calculate would discard all edits. Now:
1. `continueToNP()` is called on button click
2. If `dirtyRows.size > 0` → `doSave(silent=true)` runs first
3. Only after a successful save does the form submit (POST `finalize=1`)
4. If save fails → button re-enables, user is alerted

#### MyShop Config Files Added

`configDir/MyShop_Departments.json` and `configDir/MyShop_suppliers.json` created (copied from BernardYahud) so that PC files generated for the MyShop customer resolve department margin targets (LMB/HMB) correctly instead of showing "Department not found".

#### Files Modified

| File | Change |
|---|---|
| `commercialLayer/verify_price_changes.php` | Full rewrite of display section to Handsontable; bidirectional editing; gold cell highlight; yellow headers; doSave(); auto-save on Continue; Recommend editable |
| `configDir/MyShop_Departments.json` | NEW — 14 departments with margin targets |
| `configDir/MyShop_suppliers.json` | NEW — 26 suppliers |

---

## Recent Updates (May 7, 2026)

### Demo Mode in Harmonized Flow

#### Overview
Added a **Demo** processing mode to the harmonized flow start screen, allowing a full end-to-end demonstration of the system without running a real crop/OCR pipeline. Demo mode uses pre-prepared files stored in `preProcessDir/Demo/`.

#### How It Works

1. **Start Screen** (`harmonizedFlow/start_harmonized_flow.php`): A third mode card "🎯 Demo" is shown alongside "New Invoice Processing" and "Resume from OCR Sanity File". Description: "Demonstrate the system capabilities". PDF files listed are sourced from `preProcessDir/Demo/` instead of `preProcessDir/`.

2. **Step 2 / OCR Imitation** (`harmonizedFlow/step2_crop_launcher.php`): In demo mode the crop/OCR pipeline is skipped entirely. Instead:
   - The matching OCRsanity file is located in `preProcessDir/Demo/` by matching supplier name + process date (newest picked if multiple).
   - Both the OCRsanity file and the PDF are copied to `OCRsanity/sanity_files/` so the downstream screen can read them.
   - A fake "Crop Regions Saved Successfully / Processing OCR with OpenAI…" screen is shown for **4 seconds** to imitate the real OCR step, then the browser auto-redirects to `verify_ocrsanity.php`.
   - `demoMode: true` is stored in `$_SESSION['harmonizedFlow']`.

3. **Commercial Layer – initial processing** (`harmonizedFlow/step5_commercial_layer.php`): When `demoMode` is set in session, the price list is taken from `preProcessDir/Demo/` (file whose name **starts with** the shop name, newest if multiple) instead of `commercialLayer/price_list_files/`.

4. **Commercial Layer – Retrieve Again** (`commercialLayer/retrieve_commercial_layer.php`): The `verify_commercial_layer.php` page reads `demoMode` from session, injects it as a JS constant, and passes it in the AJAX body. `retrieve_commercial_layer.php` reads `demoMode` from the JSON payload and applies the same Demo price-list lookup when true.

#### Demo Directory Structure (`preProcessDir/Demo/`)

| File pattern | Purpose |
|---|---|
| `SupplierName dd-mm-yy.pdf` | Demo invoice PDF |
| `OCRsanity_Supplier_dd-mm-yyyy_Z_timestamp[_CL_timestamp].xlsx` | Pre-prepared OCRsanity (or CL) file matched to the PDF |
| `ShopName *.xlsx` | Price list for the shop (must start with shop name) |

#### Files Modified

| File | Change |
|---|---|
| `harmonizedFlow/start_harmonized_flow.php` | Added Demo mode card; server-side demo PDF list; updated `selectMode()` JS and form validation |
| `harmonizedFlow/step2_crop_launcher.php` | Reads `processMode`; uses `preProcessDir/Demo` for PDF path; stores `demoMode` in session; finds + copies OCRsanity file; renders 4-second fake OCR screen then redirects |
| `harmonizedFlow/step5_commercial_layer.php` | In demo mode uses `preProcessDir/Demo` for price list lookup (file starts with shop name) |
| `commercialLayer/verify_commercial_layer.php` | Adds `session_start()`; injects `isDemoMode` JS constant; passes it in retrieve AJAX body |
| `commercialLayer/retrieve_commercial_layer.php` | Reads `demoMode` from JSON body; in demo mode uses `preProcessDir/Demo` for price list |

#### OCRsanity File Matching Logic
- Glob `preProcessDir/Demo/OCRsanity_*.xlsx`
- Regex: `OCRsanity_{supplier}_{dd-mm-yyyy}_` — match supplier (case-insensitive) and process date
- If multiple matches → pick the file with the latest `filemtime`

#### Notes
- The OCRsanity file from Demo is only used for the **first upload** (copied to `sanity_files/`). For the commercial layer step, `step5_commercial_layer.php` picks the latest file from `sanity_files/` as usual.
- Only the price list source changes for demo mode; all other processing (OCRsanity verification UI, CL processing logic, etc.) is identical to the normal harmonized flow.

---

## Recent Updates (December 24, 2025)

### Discount2 Verification Method Implementation

#### Overview
Implemented a new verification method **Discount2** for suppliers that use two sequential discount percentages (like Osem). This method verifies that the LineTotal matches the calculation: `Qty × UnitPrice / (1 - Discount1/100) / (1 - Discount2/100)`.

#### Key Implementation Details

**Formula**: `Qty × UnitPrice / (1 - Discount1/100) / (1 - Discount2/100) = LineTotal`
- Division formula (not multiplication) - discounts reduce the base price
- ActualUnitPrice for Discount2: Direct copy of UnitPrice (no discount applied to column K)
- Total Equality Indicator: Works same as other methods (sum of LineTotal vs Invoice Total)

#### Files Modified

1. **process_ocrsanity.php** (`OCRsanity/`)
   - Added `applyDiscount2CalcVerification()` function (lines 245-306)
   - Updated validation to include 'Discount2' in `$validMethods` array (line 452)
   - Added Discount2 case in required fields validation (lines 473-476)
   - Added Discount2 case in main switch statement (lines 44-49)
   - Added total indicator condition for Discount2

2. **reverify_ocrsanity.php** (`OCRsanity/`)
   - Added 'Discount2' to `$validMethods` array (line 90)
   - Updated ActualUnitPrice calculation: Discount2 uses direct UnitPrice copy (lines 135-140)
   - Added Discount2 case in `applySanityVerification()` switch (lines 275-280)
   - Added `applyDiscount2CalcVerification()` function (lines 450-504)

3. **verify_ocrsanity.php** (`OCRsanity/`)
   - **LDC Screen Updates**: Added Discount1 and Discount2 columns (4th and 5th columns)
   - Changed formula column header from "Qty*Cost" to "Qty×Price/(1-D1%/100)/(1-D2%/100)"
   - Updated column structure: Index, Qty, UnitPrice, Discount1, Discount2, Formula, LineTotal, Diff (8 columns)
   - Updated Diff calculation: `Formula - LineTotal` (previously `LineTotal - Qty*Cost`)
   - Modified read-only columns to 0, 5, 7 (Index, Formula, Diff)
   - Updated Re-calculate button to use Discount2 formula
   - Fixed Total Equality Indicator initialization for Discount2 (line 553)
   - Fixed Total Equality Indicator update after re-verify for Discount2 (line 1420)

4. **positive_discount_values.php** (`OCRsanity/`) - NEW FILE
   - Created backend processor to convert negative discount values to positive
   - Removes minus (-) signs from Discount1 (column I) and Discount2 (column J)
   - Returns count of converted values
   - Used for Osem invoices where discounts come with minus signs

5. **step2_crop_launcher.php** (`harmonizedFlow/`)
   - Added 'Discount2' to validation array (line 101)
   - Updated error page to mention Discount2 as valid method (line 254)

#### UI Changes

**Positive Discount Values Button**:
- Location: OCR Sanity verification screen
- Purpose: Remove minus signs from Discount1 and Discount2 columns
- Workflow: Save → Convert → Reload (with skipPdfDelete flag)
- Hebrew message: "המרה הושלמה בהצלחה! X ערכי הנחה הומרו לחיוביים."

**LDC Screen Enhancements**:
- Now shows Discount1 and Discount2 values for analysis
- Formula column displays calculated result based on Discount2 formula
- Diff shows difference between formula and LineTotal
- All discount-related columns are editable for testing scenarios

#### Configuration Example

```json
{
    "supplierName": "Osem",
    "OCRsanityMethod": "Discount2",
    "hebrewName": "אסם",
    "jsonToOcrSanity": {
        "Barcode": 9,
        "ItemName": 8,
        "Qty": 5,
        "UnitPrice": 4,
        "LineTotal": 1,
        "Discount1": 3,
        "Discount2": 2
    }
}
```

---

### Checklist for Adding New Sanity Methods

When implementing a new verification method (e.g., "Discount3", "TaxBased", etc.), update the following files and locations:

#### 1. Backend Verification Logic

**File: `OCRsanity/process_ocrsanity.php`**
- [ ] Line ~452: Add method to `$validMethods` array
- [ ] Lines ~462-476: Add method case in required fields validation switch
- [ ] Lines ~40-50: Add method case in main verification switch statement
- [ ] Create new verification function (e.g., `applyDiscount3CalcVerification()`)
- [ ] Line ~440: Update total indicator display condition if needed

**File: `OCRsanity/reverify_ocrsanity.php`**
- [ ] Line ~90: Add method to `$validMethods` array
- [ ] Lines ~134-164: Add method case in ActualUnitPrice calculation switch
- [ ] Lines ~265-291: Add method case in `applySanityVerification()` switch
- [ ] Create corresponding verification function matching process_ocrsanity.php

#### 2. Frontend UI Updates

**File: `OCRsanity/verify_ocrsanity.php`**
- [ ] Line ~440: Update total indicator condition if method uses LineTotal verification
- [ ] Line ~553: Add method to indicator initialization condition
- [ ] Line ~1420: Add method to indicator update condition after re-verify
- [ ] Update LDC screen if formula changes:
  - [ ] Lines ~963: Update header row with new column names
  - [ ] Lines ~966-1009: Extract required columns and calculate formula
  - [ ] Lines ~1135-1141: Update read-only columns
  - [ ] Lines ~1195-1219: Update Re-calculate button logic

#### 3. Harmonized Flow Validation

**File: `harmonizedFlow/step2_crop_launcher.php`**
- [ ] Line ~101: Add method to validation array
- [ ] Line ~254: Update error message to include new method

#### 4. Configuration Tool

**File: `configTools/suppliers_config_setup.php`**
- No changes needed - automatically supports any method name

#### 5. Additional Files (if needed)

- [ ] Create helper processor if method requires special data transformation (like `positive_discount_values.php`)
- [ ] Add method-specific button to `verify_ocrsanity.php` if needed
- [ ] Update suppliers.json with example configuration

#### 6. Testing Checklist

- [ ] Test initial OCR sanity file creation with new method
- [ ] Test Re-verify button functionality
- [ ] Test Total Equality Indicator (if applicable)
- [ ] Test LDC screen with new formula
- [ ] Test ActualUnitPrice calculation in column K
- [ ] Test validation error messages
- [ ] Test with supplier that has jsonToOcrSanity mapping
- [ ] Test with supplier without jsonToOcrSanity mapping
- [ ] Test early validation in harmonized flow step 2

#### 7. Documentation

- [ ] Add implementation details to NOTES.md
- [ ] Document formula and special behavior
- [ ] Provide configuration example
- [ ] Note any UI additions (buttons, indicators, etc.)

---

## Recent Updates (December 23, 2025 - Session 2)

### OCR Sanity Verification Enhancements
- **Undo/Redo Functionality**: Added undo/redo buttons and keyboard shortcuts (Ctrl+Z, Ctrl+Y) to Handsontable for easy reversal of editing mistakes.
- **Excel-like Delete Operations**: Implemented delete menu (Ctrl+-) with 4 options: shift cells left/up, delete entire row/column, matching Microsoft Excel behavior.
- **File Modified**: `OCRsanity/verify_ocrsanity.php`

### Harmonized Flow Improvements
- **Early Supplier Validation**: Moved supplier configuration validation from step 4 (after cropping) to step 2 (after upload), preventing users from wasting time cropping PDFs for unconfigured suppliers.
- **Back Button Fix**: Fixed non-functional "Back to Upload" buttons in error pages to correctly redirect to `start_harmonized_flow.php`.
- **Files Modified**: `harmonizedFlow/step2_crop_launcher.php`, `OCRsanity/process_ocrsanity.php`

### Minor Improvements
- **Remove Success Alert**: Removed unnecessary "Margins calculated successfully!" popup from Calculate Margins button in Verify New Products screen.
- **File Modified**: `commercialLayer/verify_new_products.php`

## Recent Updates (December 23, 2025 - Session 1)

### Clean Numeric Fields Feature (New)
- **Manual Cleaning Button**: Added "Clean non-numeric" button to OCR sanity verification screen
- **Purpose**: Remove non-numeric characters from columns D, F, G, H, I, J (keeps only digits, dot, minus)
- **Workflow**: Saves current edits → cleans numeric fields → reloads with cleaned data
- **Files**: `OCRsanity/clean_numeric_fields.php` (new), `OCRsanity/verify_ocrsanity.php`, `OCRsanity/save_ocrsanity.php`
- **ItemName Truncation**: Increased from 15 to 60 characters in `OCRsanity/process_ocrsanity.php`

## Recent Updates (December 18, 2025)

### Suppliers Configuration Tool (New)
- **Location**: `configTools/suppliers_config_setup.php`
- **Purpose**: Web-based GUI for managing supplier configurations in `suppliers.json`
- **Features**:
  - Searchable dropdown with live filtering (Hebrew/English)
  - Add new suppliers or edit existing ones
  - Configure: supplierName, OCRsanityMethod, hebrewName
  - Optional JSON column mapping (Barcode, ItemName, Qty, UnitPrice, LineTotal, Discount1, Discount2)
  - Field validation with error messages
  - LTR interface

### OCR Sanity Enhancements
- **LDC Feature Updates**: Added Qty and UnitPrice columns, made editable with change tracking and re-calculate button
- **Color-Coded Borders**: Orange borders for diffs ≤0.1 ILS, red for >0.1 ILS (applied to LineTotal column)
- **UI Improvements**: Removed row headers from LDC and main table displays
- **Files Modified**: `OCRsanity/verify_ocrsanity.php`, `OCRsanity/process_ocrsanity.php`, `OCRsanity/reverify_ocrsanity.php`, `commercialLayer/get_cell_styles.php`

### Harmonized Flow Fix
- **PDF Selection**: Fixed file count mismatch by disabling recursive directory search (depth 2→1)
- **File Modified**: `harmonizedFlow/select_pdf_for_sanity.php`

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Architecture & Data Flow](#architecture--data-flow)
3. [Directory Structure](#directory-structure)
4. [Key Components](#key-components)
5. [Configuration Files](#configuration-files)
6. [Verification Components](#verification-components)
7. [Code Flow Diagrams](#code-flow-diagrams)
8. [Important Notes](#important-notes)
9. [Next Steps](#next-steps)

---

## Project Overview

The OCR subproject is a comprehensive invoice processing system that extracts, validates, and verifies invoice data from PDF files using AI-powered OCR technology. The system handles the complete workflow from PDF upload to verified Excel output.

### Main Goals

1. **Extract** invoice data from PDF files using AI/OCR
2. **Transform** extracted data into structured Excel files
3. **Validate** data integrity using configurable verification methods
4. **Verify** invoice accuracy with side-by-side PDF/Excel comparison
5. **Save** verified data for downstream processing

### Technologies Used

- **Backend:** PHP 8.x with PhpSpreadsheet library
- **Frontend:** HTML5, JavaScript, Handsontable (Excel-like editing)
- **PDF Processing:** PDF.js (multi-page rendering with text overlay)
- **AI/OCR:** Anthropic Claude API (vision and text processing)
- **Data Storage:** Excel (.xlsx) files, JSON configuration files

---

## Architecture & Data Flow

### Three Main Stages

```
┌─────────────────────────────────────────────────────────────┐
│                     STAGE 1: AI/OCR                         │
│                  (AIocr Directory)                           │
└─────────────────────────────────────────────────────────────┘
                              │
                    PDF Invoice Upload
                              ↓
        ┌─────────────────────────────────────┐
        │  1. PDF → Images (page by page)     │
        │  2. Rectangle Crop Selection        │
        │  3. AI Vision Analysis              │
        │  4. Prompt-based Extraction         │
        │  5. Output: OCRjson_*.json          │
        └─────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                STAGE 2: OCR SANITY                          │
│                (OCRsanity Directory)                         │
└─────────────────────────────────────────────────────────────┘
                              │
            JSON + PDF Files (with specific naming)
                              ↓
        ┌─────────────────────────────────────┐
        │  1. Supplier Name Extraction        │
        │  2. Configuration Validation        │
        │  3. JSON → Excel Conversion         │
        │  4. Data Type Verification          │
        │  5. Barcode Sanity Check            │
        │  6. Line Calculation Verification   │
        │  7. Total Sum Verification          │
        │  8. Output: OCRsanity_*.xlsx        │
        └─────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────┐
│             STAGE 3: VERIFICATION                            │
│       (OCRsanity Directory - verify_ocrsanity.php)          │
└─────────────────────────────────────────────────────────────┘
                              │
                  Excel + PDF Side-by-Side
                              ↓
        ┌─────────────────────────────────────┐
        │  1. Side-by-side PDF/Excel View     │
        │  2. Editable Handsontable           │
        │  3. PDF Text Overlay (click-to-copy)│
        │  4. Total Equality Indicator        │
        │  5. Re-verify Functionality         │
        │  6. Save Verified Excel             │
        └─────────────────────────────────────┘
```

---

## Directory Structure

### `/AIocr` - Stage 1: AI-Powered OCR Extraction

```
AIocr/
├── multi_rectangle_crop_viewer.html    # UI for selecting invoice areas
├── process_ocr_ai.php                  # Main OCR processing logic
├── invoice_image_reader.php            # PDF to image conversion
├── save_crops.php                      # Save selected crop coordinates
├── config.json                         # OCR configuration
├── crops/                              # Saved crop coordinate definitions
├── ocr_extracted/                      # Output JSON files (OCRjson_*.json)
└── prompts/                            # AI prompt templates per supplier
```

**Key Files:**

- **`multi_rectangle_crop_viewer.html`**: Interactive tool to define rectangular regions on PDF pages for data extraction. Users select areas (invoice number, date, total, table, etc.) and save coordinates.

- **`process_ocr_ai.php`**: Core OCR processing engine. Reads PDF, applies crops, sends images to Claude AI with supplier-specific prompts, extracts structured data, outputs JSON.

- **`prompts/[SupplierName]_prompt.txt`**: Custom AI prompts per supplier to guide data extraction (e.g., "Extract invoice number from top-right", "Parse table with columns: Barcode, Name, Qty, Price").

### `/OCRsanity` - Stage 2 & 3: Validation & Verification

```
OCRsanity/
├── process_ocrsanity.php               # JSON → Excel conversion + validation
├── verify_ocrsanity.php                # Side-by-side verification UI
├── save_ocrsanity.php                  # Save edited Excel data
├── reverify_ocrsanity.php              # Re-run verification after edits
└── sanity_files/                       # Excel and temp PDF files
    ├── OCRsanity_*.xlsx                # Generated Excel files
    └── temp_*.pdf                      # Temporary PDF copies for verification
```

**Key Files:**

- **`process_ocrsanity.php`**:
  - Validates supplier configuration (suppliers.json)
  - Converts JSON to Excel with proper formatting
  - Applies verification methods (DataType, BarcodeSanity, LineSum, etc.)
  - Calculates Total Equality Indicator
  - Creates metadata sheet for tracking

- **`verify_ocrsanity.php`**:
  - Split-screen UI: Editable Excel (Handsontable) + PDF viewer
  - PDF text overlay for click-to-copy functionality
  - Multi-page PDF rendering with scroll
  - Total Equality Indicator (color-coded: green/orange/red)
  - Re-verify button to revalidate after edits

- **`save_ocrsanity.php`**: Saves Handsontable edits back to Excel file, preserving number formats and text types (especially barcodes).

- **`reverify_ocrsanity.php`**: Clears previous verification styling, re-applies validation rules, recalculates Total Equality Indicator, returns updated styles to frontend.

### `/PDFverification` - Legacy/Reference Implementation

```
PDFverification/
├── verify_invoice_ocrsanity.html       # Original verification UI
├── load_ocrsanity.php                  # Load Excel data as JSON
├── save_ocrsanity.php                  # Save Excel edits
└── uploads/                            # Uploaded files
```

**Note:** This directory contains the original/reference implementation. The current active system uses `/OCRsanity/verify_ocrsanity.php`.

### Root Configuration Files

```
website/
├── suppliers.json                      # Supplier configurations
├── shops.json                          # Shop/branch data
├── composer.json                       # PHP dependencies
└── vendor/                             # PhpSpreadsheet library
```

---

## Key Components

### 1. Supplier Configuration (`suppliers.json`)

Central configuration file defining how each supplier's invoices should be processed.

**Structure:**

```json
{
  "supplierName": "Tayari",
  "OCRsanityMethod": "Discount1",
  "jsonToOcrSanity": {
    "Barcode": 1,
    "ItemName": 2,
    "Qty": 3,
    "UnitPrice": 4,
    "LineTotal": 5,
    "Discount1": 6
  }
}
```

**Fields:**

- **`supplierName`** (string, required): Unique identifier, must match PDF filename prefix
- **`OCRsanityMethod`** (string, required): Validation method to use
  - `"Simple"`: DataType + BarcodeSanity
  - `"LineTotal"`: Simple + LineSum + TotalSum
  - `"Discount1"`: Simple + Discount1Calc + TotalSum
- **`jsonToOcrSanity`** (object, required): Maps JSON property numbers to Excel columns
  - Keys: Column names (Barcode, ItemName, Qty, UnitPrice, LineTotal, Discount1, Discount2)
  - Values: Property index in JSON table array (0-based)

### 2. PDF Filename Convention

**Format:** `SupplierName dd-mm-yy X.pdf`

**Examples:**
- `Tayari 26-03-25 A.pdf` → Supplier: Tayari, Date: 26-03-2025
- `FarmaDeal 20-10-25 B.pdf` → Supplier: FarmaDeal, Date: 20-10-2025

**Parsing Logic:**
1. Extract supplier name (everything before first space)
2. Extract date (8 characters after first space: dd-mm-yy)
3. Convert yy to yyyy (e.g., 25 → 2025)

### 3. OCR JSON Output Format

**Filename:** `OCRjson_[SupplierName]_[DateTime]_[RandomID].json`

**Structure:**

```json
{
  "invoice_number": "216235",
  "invoice_date": "25-03-2025",
  "invoice_total": "3172.04",
  "table": [
    {
      "index": 1,
      "0": "5000382114727",  // Property mapping defined in suppliers.json
      "1": "גולני גדול יבש",
      "2": "24",
      "3": "4.50",
      "4": "108.00",
      "5": "0"
    },
    // ... more rows
  ]
}
```

**Note:** Table rows use numeric keys (0, 1, 2...) which map to Excel columns via `jsonToOcrSanity` configuration.

### 4. Excel File Structure

**Filename:** `OCRsanity_[SupplierName]_[Date]_[Timestamp].xlsx`

**Sheets:**
1. **Main Sheet** (visible):
   - **Column A**: Labels (InvoiceNo, supplierName, InvoiceDate, InvoiceTotal, Remark)
   - **Column B**: Values
   - **Column C**: Index (row numbers)
   - **Columns D-J**: Table data (Barcode, ItemName, Qty, UnitPrice, LineTotal, Discount1, Discount2)
   - **Rows 1**: Headers (bold)
   - **Rows 2+**: Data with verification styling (backgrounds, borders)

2. **_Metadata Sheet** (hidden):
   - **A1**: "TotalDifference", **B1**: Calculated difference (B4 - sum(H))
   - **A2**: "SanityMethod", **B2**: Method name (Simple/LineTotal/Discount1)

**Cell Styling:**
- **Light Purple Background**: DataType verification failed (non-numeric value)
- **Light Yellow Background**: BarcodeSanity verification failed (invalid barcode)
- **Bold Black Border**: LineSum/Discount1Calc verification failed (calculation mismatch)

### 5. Verification Components

Modular validation units that check data integrity.

#### DataType Verification

**Purpose:** Ensure numeric columns contain valid numbers

**Columns Checked:** F, G, H, I, J (Qty, UnitPrice, LineTotal, Discount1, Discount2)

**Failure Action:** Apply light purple background (ARGB: FFE6CCE6)

**Code Location:**
- `process_ocrsanity.php`: Lines 51-79
- `reverify_ocrsanity.php`: Lines 191-210

#### BarcodeSanity Verification

**Purpose:** Validate barcode format and length

**Rules:**
- Must not be empty
- Must contain only digits (0-9)
- Must not exceed 13 digits

**Failure Action:** Apply light yellow background (ARGB: FFFFFFCC)

**Code Location:**
- `process_ocrsanity.php`: Lines 81-118
- `reverify_ocrsanity.php`: Lines 215-242

#### LineSum Verification

**Purpose:** Verify line total calculation: `Qty × UnitPrice = LineTotal`

**Formula:** `F × G = H`

**Tolerance:** ±0.01 (for floating-point precision)

**Failure Action:** Apply bold border (Border::BORDER_THICK) to cell H

**Code Location:**
- `process_ocrsanity.php`: Lines 120-158
- `reverify_ocrsanity.php`: Lines 247-273

#### Discount1Calc Verification

**Purpose:** Verify line total with discount: `Qty × UnitPrice × (1 - Discount1/100) = LineTotal`

**Formula:** `F × G × (1 - I/100) = H`

**Tolerance:** ±0.01

**Failure Action:** Apply bold border to cell H (columns F, G, H explicitly set)

**Special Feature:** Auto-fills column I with 0 if Discount1 not in jsonToOcrSanity

**Code Location:**
- `process_ocrsanity.php`: Lines 162-207
- `reverify_ocrsanity.php`: Lines 282-327

**Important Fix Applied:** Lines 187-190 & 201-204 use explicit cell reference `$sheet->getCell("H{$row}")` instead of `$lineTotalCell->getStyle()->getBorders()->getOutline()` to avoid border placement issues.

#### TotalSum Verification

**Purpose:** Calculate difference between invoice total (B4) and sum of all line totals (column H)

**Formula:** `B4 - sum(H2:H[lastRow]) = difference`

**Output:** Signed difference (can be negative or positive)

**Display:** Total Equality Indicator (only for LineTotal and Discount1 methods)

**Color Coding:**
- **Green**: difference = 0 (perfect match)
- **Orange**: -3 ≤ difference ≤ 3 (acceptable tolerance)
- **Red**: difference < -3 OR difference > 3 (requires attention)

**Code Location:**
- `process_ocrsanity.php`: Lines 209-237
- `reverify_ocrsanity.php`: Lines 329-357
- `verify_ocrsanity.php`: Lines 393-411 (indicator update logic)

**Important Fix Applied:** Changed from `abs($columnHSum - $invoiceTotal)` to `$invoiceTotal - $columnHSum` to show signed difference.

### 6. Sanity Methods

Combinations of verification components applied based on supplier configuration.

| Method | Components | Use Case |
|--------|-----------|----------|
| **Simple** | DataType + BarcodeSanity | Basic validation, no calculations |
| **LineTotal** | DataType + BarcodeSanity + LineSum + TotalSum | Standard invoices with Qty × Price |
| **Discount1** | DataType + BarcodeSanity + Discount1Calc + TotalSum | Invoices with discount column |

**Code Location:** `process_ocrsanity.php` Lines 16-50 (applySanityVerification function)

---

## Configuration Files

### suppliers.json Validation

**Early Validation** (before Excel creation) - Lines 323-459:

1. ✅ Check if suppliers.json file exists
2. ✅ Check if supplier (from PDF filename) exists in config
3. ✅ Check if supplier has `jsonToOcrSanity` array
4. ✅ Check if `OCRsanityMethod` is valid/implemented (Simple, LineTotal, Discount1)
5. ✅ Check if required fields exist in `jsonToOcrSanity` for selected method

**Required Fields per Method:**

- **Simple**: `Barcode`, `ItemName`
- **LineTotal**: `Barcode`, `ItemName`, `Qty`, `UnitPrice`, `LineTotal`
- **Discount1**: `Barcode`, `ItemName`, `Qty`, `UnitPrice`, `LineTotal` (Discount1 optional - auto-filled with 0)

**Error Handling:** If validation fails, user sees detailed error page with:
- Error message
- Supplier name extracted from PDF
- Instructions to fix configuration
- "Back to Upload" button

**No Excel file created on validation failure** - process stops immediately.

---

## Code Flow Diagrams

### Process OCR Sanity Flow

```
┌─────────────────────────────────────────────────────────────┐
│  process_ocrsanity.php                                      │
└─────────────────────────────────────────────────────────────┘

1. User uploads JSON + PDF files via HTML form
   ↓
2. Validate file types (JSON, PDF)
   ↓
3. Parse JSON content (invoice_number, invoice_date, invoice_total, table)
   ↓
4. Extract supplier name from PDF filename
   └─> Format: "SupplierName dd-mm-yy X.pdf"
   ↓
5. ⚠️ EARLY VALIDATION (Lines 323-459)
   ├─> suppliers.json exists?
   ├─> Supplier exists in config?
   ├─> jsonToOcrSanity array exists?
   ├─> OCRsanityMethod valid? (Simple/LineTotal/Discount1)
   └─> Required fields present in jsonToOcrSanity?
   │
   └─> ❌ IF VALIDATION FAILS:
       ├─> Show error page (Lines 392-458)
       └─> exit (NO Excel file created)
   │
   └─> ✅ IF VALIDATION PASSES: Continue...
   ↓
6. Create Excel file (Spreadsheet object)
   ↓
7. Write metadata (columns A-B, rows 1-5)
   ├─> InvoiceNo, supplierName, InvoiceDate, InvoiceTotal, Remark
   └─> Headers in row 1: Index, Barcode, ItemName, Qty, UnitPrice, LineTotal, Discount1, Discount2
   ↓
8. Map JSON table data to Excel columns (Lines 423-655)
   └─> Use jsonToOcrSanity to map property numbers to columns
   └─> Special handling:
       ├─> Column D (Barcode): setCellValueExplicit(..., TYPE_STRING) to prevent scientific notation
       ├─> Column E (ItemName): Truncate to 15 characters
       ├─> Columns F-J: Convert to float with formatting (#,##0.00)
   ↓
9. Auto-size columns A-J
   ↓
10. Auto-fill Discount1 column with 0 if needed (Lines 674-690)
    └─> If method = 'Discount1' AND 'Discount1' not in jsonToOcrSanity
        └─> Fill column I with 0 for all data rows
   ↓
11. Apply verification based on sanity method (Lines 692-693)
    ├─> Call applySanityVerification($sheet, $sanityMethod)
    └─> Returns $totalDifference for indicator
   ↓
12. Create hidden _Metadata sheet (Lines 697-703)
    ├─> Store TotalDifference (B1)
    └─> Store SanityMethod (B2)
   ↓
13. Save Excel file to sanity_files/ (Lines 718-719)
    └─> Filename: OCRsanity_{SupplierName}_{Date}_{Timestamp}.xlsx
   ↓
14. Save temporary PDF copy (Lines 721-724)
    └─> Filename: temp_{ExcelFileName}.pdf
   ↓
15. Redirect to verify_ocrsanity.php (Line 727)
    └─> Pass Excel and PDF filenames as URL parameters
```

### Verify OCR Sanity Flow

```
┌─────────────────────────────────────────────────────────────┐
│  verify_ocrsanity.php                                       │
└─────────────────────────────────────────────────────────────┘

1. Receive Excel and PDF filenames from URL parameters
   ↓
2. Load Excel file using PhpSpreadsheet (Lines 23-30)
   ├─> Read main sheet data as 2D array
   ├─> Read _Metadata sheet (TotalDifference, SanityMethod)
   └─> Extract cell styles (background colors, borders)
   ↓
3. Build styleMap for Handsontable (Lines 48-94)
   ├─> For each cell with background or border:
   │   ├─> backgroundColor: Extract ARGB, convert to hex
   │   └─> boldBorder: Check if any border is BORDER_THICK
   └─> Store as [{row, col, backgroundColor?, boldBorder?}, ...]
   ↓
4. Convert Excel data to JSON (Line 99)
   └─> JavaScript-readable array
   ↓
5. Render HTML page with split layout
   ├─────────────────────────┬─────────────────────────┐
   │   Left: Handsontable    │   Right: PDF Viewer     │
   │   (Editable Excel)      │   (Multi-page)          │
   ├─────────────────────────┴─────────────────────────┤
   │   Top: Header with buttons and indicator          │
   │   - Total Equality Indicator (if LineTotal/Discount1)
   │   - Save OCR Sanity button                        │
   │   - Re-verify button                              │
   └───────────────────────────────────────────────────┘
   ↓
6. Initialize Handsontable (Lines 420-464)
   ├─> Set data from Excel
   ├─> Enable editing (all cells editable)
   ├─> Apply custom renderer for cell styles (Lines 435-458)
   │   └─> Check styleMap, apply backgroundColor and/or boldBorder
   ├─> Set height: calc(100vh - 200px) for proper scrolling
   └─> Enable scrollbars (overflow: auto)
   ↓
7. Initialize PDF.js multi-page viewer (Lines 510-602)
   ├─> Load all pages sequentially
   ├─> Render pages one below the other (continuous scroll)
   ├─> Create text overlay for each page (Lines 557-602)
   │   └─> Extract text items from PDF
   │   └─> Position absolutely over rendered canvas
   │   └─> Enable click-to-copy to selected Handsontable cell
   └─> Container height: calc(100vh - 200px) for proper scrolling
   ↓
8. Initialize Total Equality Indicator (Lines 413-416)
   └─> If sanityMethod = 'LineTotal' OR 'Discount1':
       └─> Call updateTotalIndicator(totalDifference)
       └─> Set color: green (=0), orange (≤3), red (>3)
   ↓
9. User Actions:
   │
   ├─> Edit Cell:
   │   └─> Handsontable tracks changes in memory
   │
   ├─> Click "Save OCR Sanity" (Lines 608-662)
   │   ├─> Get updated data: hot.getData()
   │   ├─> Send to save_ocrsanity.php via fetch POST
   │   └─> Receive success message
   │
   └─> Click "Re-verify" (Lines 667-720)
       ├─> Get updated data: hot.getData()
       ├─> Send to reverify_ocrsanity.php via fetch POST
       ├─> Receive: {cellStyles[], totalDifference}
       ├─> Clear styleMap
       ├─> Populate styleMap with new styles
       ├─> Update Total Equality Indicator (Lines 697-700)
       └─> Re-render Handsontable: hot.render()
```

### Reverify OCR Sanity Flow

```
┌─────────────────────────────────────────────────────────────┐
│  reverify_ocrsanity.php                                     │
└─────────────────────────────────────────────────────────────┘

1. Receive POST data: {filename, data}
   ├─> filename: Excel file to update
   └─> data: 2D array from Handsontable (edited values)
   ↓
2. Load Excel file from sanity_files/
   ↓
3. Clear all previous verification styling (Lines 41-50)
   ├─> For rows 2 to end, columns A-J:
   │   ├─> Clear background: setFillType(FILL_NONE)
   │   └─> Clear borders: setBorderStyle(BORDER_NONE)
   └─> This ensures re-verification starts clean
   ↓
4. Update cells with new data from Handsontable (Lines 52-83)
   ├─> Map Handsontable indices to Excel rows/columns
   ├─> Skip empty values
   ├─> Special handling:
   │   ├─> Column D (Barcode): setCellValueExplicit(..., TYPE_STRING)
   │   └─> Columns B, F, G, H, I, J: Remove thousand separators, convert to float
   └─> ⚠️ CRITICAL FIX (Line 70-77): Remove commas from formatted numbers
       └─> Example: "3,172.04" → 3172.04 (prevents B4 value corruption)
   ↓
5. Get sanity method from _Metadata sheet (Lines 85-93)
   └─> Default to 'Simple' if not found
   ↓
6. Apply sanity verification (Line 96)
   ├─> Call applySanityVerification($sheet, $sanityMethod)
   └─> Returns $totalDifference
   ↓
7. Update _Metadata sheet (Lines 99-101)
   └─> Write new totalDifference to B1
   ↓
8. Save Excel file (Lines 104-105)
   ↓
9. Read back cell styles for frontend (Lines 107-150)
   ├─> For each cell:
   │   ├─> Check background color (FILL_NONE?)
   │   ├─> Check borders (BORDER_THICK?)
   │   └─> If either exists, add to cellStyles array
   └─> Return: [{row, col, backgroundColor?, boldBorder?}, ...]
   ↓
10. Send JSON response (Lines 152-157)
    └─> {success: true, cellStyles: [...], totalDifference: X.XX}
```

---

## Important Notes

### Critical Bug Fixes Applied

#### 1. Bold Border Placement Issue (Discount1Calc)

**Problem:** Borders appeared on column I (Discount1) instead of column H (LineTotal)

**Root Cause:** `getOutline()->setBorderStyle()` method had unexpected behavior

**Solution:** Use explicit cell references with individual border sides:
```php
$sheet->getCell("H{$row}")->getStyle()->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK);
$sheet->getCell("H{$row}")->getStyle()->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
$sheet->getCell("H{$row}")->getStyle()->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THICK);
$sheet->getCell("H{$row}")->getStyle()->getBorders()->getRight()->setBorderStyle(Border::BORDER_THICK);
```

**Files Modified:**
- `process_ocrsanity.php`: Lines 187-190, 201-204
- `reverify_ocrsanity.php`: Lines 307-310, 321-324

#### 2. TotalSum Calculation (Signed vs Absolute)

**Problem:** Total Equality Indicator always showed positive values

**Root Cause:** Using `abs($columnHSum - $invoiceTotal)` removed the sign

**Solution:** Changed to `$invoiceTotal - $columnHSum` to preserve sign

**Color Logic Fix:** Changed from `difference <= 3` to `Math.abs(difference) <= 3`

**Files Modified:**
- `process_ocrsanity.php`: Line 233
- `reverify_ocrsanity.php`: Line 347
- `verify_ocrsanity.php`: Line 406

#### 3. B4 Value Corruption During Re-verify

**Problem:** After re-verify, B4 value became 0 or incorrect, breaking TotalSum calculation

**Root Cause:** Handsontable sent formatted strings with thousand separators (e.g., "3,172.04"), which were written back as strings. When converted to float, commas caused parsing errors.

**Solution:** Strip commas before saving numeric columns:
```php
if (in_array($excelCol, [2, 6, 7, 8, 9, 10])) { // B, F, G, H, I, J
    $numericValue = str_replace(',', '', $value);
    if (is_numeric($numericValue)) {
        $sheet->setCellValueByColumnAndRow($excelCol, $excelRow, (float)$numericValue);
    }
}
```

**Files Modified:**
- `reverify_ocrsanity.php`: Lines 70-77

#### 4. Validation Happens Too Late

**Problem:** Excel file created even when supplier doesn't exist in suppliers.json

**Root Cause:** Validation occurred after Excel file creation and data mapping

**Solution:** Moved validation to lines 323-459 (immediately after supplier name extraction, before Excel creation)

**Validation Order:**
1. Extract supplier name from PDF filename
2. **Validate configuration (EARLY - before Excel creation)**
3. If validation fails → Show error page, exit
4. If validation passes → Create Excel file, continue processing

**Files Modified:**
- `process_ocrsanity.php`: Lines 323-459 (early validation), removed duplicate validation at lines 669+

#### 5. Barcode Scientific Notation

**Problem:** Long barcodes (13 digits) saved as scientific notation (e.g., 5.00038E+12)

**Solution:** Use `setCellValueExplicit()` with `TYPE_STRING` for column D:
```php
$sheet->setCellValueExplicit("{$columnLetter}{$excelRow}", $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
```

**Files Modified:**
- `process_ocrsanity.php`: Lines 457-460
- `save_ocrsanity.php`: Lines 47-53
- `reverify_ocrsanity.php`: Lines 64-67

#### 6. Total Equality Indicator Not Showing for Discount1

**Problem:** Indicator only displayed for LineTotal method, not Discount1

**Solution:** Updated all conditionals to include both methods:
```php
// Before: if ($sanityMethod === 'LineTotal')
// After:  if ($sanityMethod === 'LineTotal' || $sanityMethod === 'Discount1')
```

**Files Modified:**
- `verify_ocrsanity.php`: Lines 330, 414, 697

### PhpSpreadsheet Deprecation Warnings

**Functions marked deprecated (but still functional):**
- `setCellValueByColumnAndRow()`
- `getCellByColumnAndRow()`

**Action:** Ignore warnings for now. Functions work correctly. Can be replaced in future with:
- `setCellValue()` with column letter conversion
- `getCell()` with column letter conversion

**Not critical** - no impact on functionality.

### Auto-fill Discount1 with Zeros

**Feature:** If supplier uses "Discount1" sanity method but jsonToOcrSanity doesn't have "Discount1" field:
- Column I (Discount1) automatically filled with 0 for all data rows
- Allows formula `Qty × UnitPrice × (1 - 0/100)` to work correctly (equivalent to no discount)

**Code Location:** `process_ocrsanity.php` Lines 674-690

---

## Recent Enhancements (Phase 2)

### 1. ActualUnitPrice Column (Column K)

**Added:** October 29, 2025

**Purpose:** Calculate the actual price paid per unit after applying discounts.

**Implementation:**

When saving OCR Sanity, a new column K is added with header "ActualUnitPrice" (bold).

**Calculation Logic by Sanity Method:**

| Sanity Method | Formula | Description |
|---------------|---------|-------------|
| **Simple** | `K = G` | Copies UnitPrice (no discount) |
| **LineTotal** | `K = G` | Copies UnitPrice (no discount) |
| **Discount1** | `K = G × (1 - I/100)` | Applies Discount1 percentage |

**Example (Discount1 method):**
- UnitPrice (G) = 100
- Discount1 (I) = 5
- ActualUnitPrice (K) = 100 × (1 - 5/100) = 100 × 0.95 = **95.00**

**Code Locations:**
- Initial calculation: `process_ocrsanity.php` Lines 703-747
- Re-verify calculation: `reverify_ocrsanity.php` Lines 118-162
- Number formatting: `#,##0.00` (two decimal places with thousand separators)

**Re-verify Behavior:**
- ActualUnitPrice is **always recalculated** when re-verify is clicked
- Updates dynamically if user edits UnitPrice (G) or Discount1 (I)
- Calculation uses the sanity method from cell B6 (not suppliers.json)

---

### 2. Sanity Method Display in Cell B6

**Added:** October 29, 2025

**Purpose:** Show which sanity method is being used and allow dynamic switching.

**Implementation:**

- **Cell A6:** Label "Sanity Method" (bold)
- **Cell B6:** Value showing the method (e.g., "Simple", "LineTotal", "Discount1")

**Initial Value:** Comes from `suppliers.json` → `OCRsanityMethod` field

**Dynamic Switching:**
- User can **edit B6** to change the sanity method
- On re-verify, system reads B6 (not suppliers.json)
- Allows testing different verification methods without re-uploading

**Code Locations:**
- A6/B6 creation: `process_ocrsanity.php` Lines 474, 698
- B6 validation: `reverify_ocrsanity.php` Lines 85-105
- Invalid method popup: `verify_ocrsanity.php` Lines 714-722

---

### 3. Dynamic Sanity Method for Re-verify

**Added:** October 29, 2025

**Purpose:** All re-verify operations use B6 value instead of original suppliers.json configuration.

**Breaking Change from Phase 1:**
- **Before:** Re-verify always used method from suppliers.json
- **After:** Re-verify uses method from cell B6 (user-editable)

**Workflow:**

1. **Initial Upload:**
   - Supplier "Tayari" has `OCRsanityMethod: "Discount1"` in suppliers.json
   - Excel created with B6 = "Discount1"
   - Verification applied using "Discount1" method

2. **User Changes Method:**
   - User edits B6 from "Discount1" to "LineTotal"
   - User clicks "Re-verify"
   - System validates B6 value
   - Verification applied using "LineTotal" method (from B6)
   - ActualUnitPrice recalculated using "LineTotal" formula

3. **Invalid Method Error:**
   - User edits B6 to "MyCustomMethod"
   - User clicks "Re-verify"
   - Popup appears:
   ```
   ❌ Invalid Sanity Method in cell B6!

   Current value: "MyCustomMethod"

   Please change cell B6 to one of these valid methods:
   Simple, LineTotal, Discount1

   Then click Re-verify again.
   ```

**Validation:**
- Valid methods: `['Simple', 'LineTotal', 'Discount1']`
- Empty B6: Defaults to "Simple"
- Invalid method: Shows error popup with valid options

**What Re-verify Does Based on B6:**

| Operation | Behavior |
|-----------|----------|
| **Verification Components** | Applies DataType, BarcodeSanity, LinSum/Discount1Calc based on B6 |
| **Bold Frames on Column H** | Applied based on B6 method logic |
| **ActualUnitPrice Calculation** | Uses formula matching B6 method |
| **Total Equality Indicator** | Shows/hides based on B6 method (LineTotal or Discount1) |
| **Metadata Sync** | Updates hidden _Metadata sheet with B6 value |

**Code Locations:**
- Read B6: `reverify_ocrsanity.php` Line 86
- Validate B6: `reverify_ocrsanity.php` Lines 88-105
- Apply verification: `reverify_ocrsanity.php` Line 110
- Update metadata: `reverify_ocrsanity.php` Lines 112-116

---

### 4. Column H (LineTotal) Behavior Clarification

**Important:** Column H is **NEVER recalculated** by the system.

**Sources of Column H Values:**
1. **OCR Extraction:** Initial value from AI/OCR JSON
2. **Manual Edit:** User types directly into cell H
3. **PDF Click-to-Copy:** User clicks highlighted text in PDF overlay

**Re-verify Does NOT Recalculate H:**
- Column H keeps its current value (from OCR or user edits)
- **Only verification is applied** (bold frames if mismatch detected)

**Verification Logic (Bold Frames on H):**

| Sanity Method | Verification Rule |
|---------------|-------------------|
| **Simple** | No verification on H (no bold frames) |
| **LineTotal** | Bold frame if `F × G ≠ H` |
| **Discount1** | Bold frame if `F × G × (1 - I/100) ≠ H` |

**Why H is Never Recalculated:**
- OCR-extracted LineTotal might be correct even if calculation differs slightly
- Users may need to manually correct OCR errors
- PDF values override calculated values (user knows the invoice better than formula)

**Code Locations:**
- LineSum verification: `reverify_ocrsanity.php` Function `applyLineSumVerification()`
- Discount1Calc verification: `reverify_ocrsanity.php` Function `applyDiscount1CalcVerification()`

---

### 5. Re-verify Data Synchronization Fix

**Fixed:** October 29, 2025

**Bug:** When user edited cells and clicked re-verify, ActualUnitPrice calculations were correct in Excel file but not displayed in Handsontable.

**Root Cause:** Server was recalculating ActualUnitPrice and saving to Excel, but only returning cell styles (colors/borders) to client, not the updated cell values.

**Solution:**
- Server now returns both `cellStyles` AND `cellData` in re-verify response
- Client reloads all cell values using `hot.loadData(data.cellData)`
- Handsontable display stays synchronized with Excel file

**Code Locations:**
- Server adds cellData: `reverify_ocrsanity.php` Lines 153-210
- Client reloads data: `verify_ocrsanity.php` Lines 702-705

**Before Fix:**
```
User changes G6 from 5 to 11
↓
Re-verify clicked
↓
Server calculates K6 = 11
↓
Server saves Excel ✓
↓
Server returns only styles ✗
↓
Handsontable still shows K6 = 5 ✗
```

**After Fix:**
```
User changes G6 from 5 to 11
↓
Re-verify clicked
↓
Server calculates K6 = 11
↓
Server saves Excel ✓
↓
Server returns styles + data ✓
↓
Handsontable reloads K6 = 11 ✓
```

---

### 6. Commercial Layer Configuration Setup

**Added:** October 29, 2025

**Purpose:** Prepare for multi-shop commercial layer development.

**New Files Created:**

1. **`configDir/shops_V2.json`**
   - Stores shop-specific configurations
   - Named "V2" to avoid confusion with legacy `shops.json`
   - Initial shop: "CountryMZ" with BackOfficeType "comax"

**Structure:**
```json
[
  {
    "shopName": "CountryMZ",
    "BackOfficeType": "comax"
  }
]
```

**Future Parameters (planned):**
- Shop address, contact info
- Tax rates, currency settings
- Default markup percentages
- Inventory thresholds
- Supplier relationships

**Code Location:** `C:\xampp\htdocs\website\configDir\shops_V2.json`

---

### Summary of Phase 2 Changes

| Feature | Files Modified | Lines Changed |
|---------|---------------|---------------|
| ActualUnitPrice Column | process_ocrsanity.php, reverify_ocrsanity.php | ~100 lines |
| Sanity Method in B6 | process_ocrsanity.php | ~5 lines |
| Dynamic B6 Re-verify | reverify_ocrsanity.php, verify_ocrsanity.php | ~50 lines |
| Re-verify Data Sync | reverify_ocrsanity.php, verify_ocrsanity.php | ~20 lines |
| Commercial Config | shops_V2.json (new file) | New file |

**Total Impact:** ~175 lines of code across 3 PHP files + 1 new config file

**Testing Status:** Ready for user testing with all three sanity methods

---

## Next Steps

### Immediate Tasks

1. **Test all sanity methods** with real supplier data:
   - Simple method (basic suppliers)
   - LineTotal method (standard invoices)
   - Discount1 method (invoices with discounts)

2. **Add more suppliers** to suppliers.json with correct configurations

3. **Create AI prompts** for each supplier in `AIocr/prompts/`

### Future Enhancements (Commercial Layer)

1. **Multi-shop support**: Process invoices for different branches/shops
   - Integration with shops.json
   - Shop-specific pricing verification
   - Inventory linkage

2. **Database integration**: Store verified invoices in MySQL/PostgreSQL
   - Invoice header table (invoice_id, supplier, date, total, status)
   - Invoice lines table (invoice_id, line_num, barcode, qty, price, etc.)
   - Verification audit trail

3. **Discount2 sanity method**: Implement second discount column verification
   - Formula: `Qty × UnitPrice × (1 - Discount1/100) × (1 - Discount2/100) = LineTotal`

4. **NoLineTotal sanity method**: For invoices without line-by-line totals
   - Skip LineSum/Discount1Calc verification
   - Only verify overall TotalSum

5. **Batch processing**: Process multiple invoices in one session
   - Queue system
   - Progress tracking
   - Error handling for partial failures

6. **Reporting dashboard**:
   - Daily/weekly/monthly invoice counts
   - Verification success rates
   - Common error patterns
   - Supplier performance metrics

7. **API endpoints**: Expose OCR functionality as REST API
   - POST /ocr/extract (PDF → JSON)
   - POST /ocr/validate (JSON → Excel + verification)
   - GET /ocr/status/{id} (Check processing status)

8. **Email notifications**: Alert users on completion/errors

9. **Advanced verification**:
   - Price history validation (flag unusual price changes)
   - Duplicate invoice detection
   - Cross-supplier barcode consistency checks

### Technical Debt

1. Replace deprecated PhpSpreadsheet methods (low priority)
2. Add unit tests for verification components
3. Refactor large functions into smaller, testable units
4. Add logging/debugging infrastructure
5. Implement proper error handling (try-catch blocks)
6. Add CSRF protection to forms
7. Sanitize all user inputs (especially file uploads)

---

## Developer Handoff Checklist

### For New Developers / Claude Sessions

**To Continue This Project:**

1. ✅ Read this NOTES.md file completely
2. ✅ Review `suppliers.json` structure - this is the core configuration
3. ✅ Understand the three-stage flow: AIocr → OCRsanity → Verification
4. ✅ Test the system end-to-end with a sample invoice:
   - Upload PDF to AIocr (multi_rectangle_crop_viewer.html)
   - Process with AI (process_ocr_ai.php) → generates JSON
   - Convert to Excel (process_ocrsanity.php) → generates OCRsanity_*.xlsx
   - Verify side-by-side (verify_ocrsanity.php) → edit and save

5. ✅ Check recent bug fixes (see "Critical Bug Fixes Applied" section)
6. ✅ Review verification components and their purposes
7. ✅ Understand sanity methods (Simple, LineTotal, Discount1)

**To Add a New Supplier:**

1. Add supplier entry to `suppliers.json`:
   ```json
   {
     "supplierName": "NewSupplier",
     "OCRsanityMethod": "LineTotal",
     "jsonToOcrSanity": {
       "Barcode": 1,
       "ItemName": 2,
       "Qty": 3,
       "UnitPrice": 4,
       "LineTotal": 5
     }
   }
   ```

2. Create AI prompt file: `AIocr/prompts/NewSupplier_prompt.txt`

3. Use crop viewer to define invoice layout: `AIocr/multi_rectangle_crop_viewer.html`

4. Save crop coordinates: `AIocr/crops/NewSupplier_crops.json`

5. Test with real invoice PDF

**To Add a New Verification Component:**

1. Create function in `process_ocrsanity.php` (e.g., `applyCustomVerification()`)
2. Duplicate function in `reverify_ocrsanity.php`
3. Add to sanity method in `applySanityVerification()` switch statement
4. Update required fields validation in early validation section
5. Test with sample data

**To Debug Issues:**

1. Check PHP error log: `C:\xampp\apache\logs\error.log`
2. Check browser console (F12) for JavaScript errors
3. Use `error_log()` statements in PHP for debugging
4. Check `sanity_files/` directory for generated files
5. Verify suppliers.json is valid JSON (use JSONLint)

---

## Contact & Support

**Project Files:** `C:\xampp\htdocs\website`

**Key Directories:**
- `/AIocr` - Stage 1 (OCR extraction)
- `/OCRsanity` - Stage 2 & 3 (validation & verification)
- `/PDFverification` - Legacy reference
- `/vendor` - Dependencies (PhpSpreadsheet)

**Configuration Files:**
- `suppliers.json` - Supplier configurations
- `shops.json` - Shop/branch data
- `composer.json` - PHP dependencies

**For Questions:**
- Review this NOTES.md file
- Check inline code comments
- Review git commit history for change context

---

## Commercial Layer Process (Stage 4)

**Added:** November 23, 2025
**Status:** ✅ Complete - Price Change & New Products Workflow Implemented

The Commercial Layer is the final stage of invoice processing. It takes verified OCR Sanity files and performs commercial analysis by comparing invoice prices against current market prices, identifying price changes, and detecting new products that need to be added to the inventory system.

### Commercial Layer Architecture

```
┌─────────────────────────────────────────────────────────────┐
│             STAGE 4: COMMERCIAL LAYER                        │
│          (commercialLayer Directory)                         │
└─────────────────────────────────────────────────────────────┘

Input: OCRsanity_*.xlsx + shop selection
                              ↓
        ┌─────────────────────────────────────┐
        │  1. Commercial Layer File Creation  │
        │     - Copy OCRsanity to CL file     │
        │     - Add ActualUnitPrice column    │
        │     - Add CHPprice column           │
        │     - Add PriceDiff column          │
        │  2. CHP Price Search (Puppeteer)    │
        │     - For each barcode, search CHP  │
        │     - Extract market price          │
        │     - Calculate price difference    │
        │  3. CL Verification Screen          │
        │     - Side-by-side view             │
        │     - Editable spreadsheet          │
        │     - Auto-save changes             │
        │  4. Save CL File                    │
        └─────────────────────────────────────┘
                              ↓
        ┌─────────────────────────────────────┐
        │  5. Generate PC and NP Files        │
        │     - Check PriceDiff column        │
        │     - Generate PC file if changes   │
        │     - Generate NP file if new items │
        │  6. Verify Price Changes            │
        │     - Split-screen interface        │
        │     - CHP search panel              │
        │     - Approve/reject changes        │
        │  7. Verify New Products             │
        │     - Department assignment         │
        │     - Margin calculation            │
        │     - Sale price determination      │
        └─────────────────────────────────────┘
                              ↓
        ┌─────────────────────────────────────┐
        │  8. Completion                      │
        │     - CL file saved                 │
        │     - PC file saved (if applicable) │
        │     - NP file saved (if applicable) │
        │     - Temp PDF deleted              │
        └─────────────────────────────────────┘
```

---

### Directory Structure

```
commercialLayer/
├── commercial_layer.html               # Shop selection interface
├── process_commercial_layer.php        # Main CL processing logic
├── verify_commercial_layer.php         # CL verification screen
├── save_commercial_layer.php           # Save CL file edits
├── generate_pc_np_files.php            # Generate PC and NP files
├── verify_price_changes.php            # PC verification screen
├── save_price_changes.php              # Save PC file edits
├── price_changes_complete.php          # PC completion screen
├── generate_np_file.php                # Generate NP file
├── verify_new_products.php             # NP verification screen
├── calculate_margins.php               # Margin calculation endpoint
├── save_new_products.php               # Save NP file edits
├── new_products_complete.php           # NP completion screen
├── getShopCHPprice.js                  # Puppeteer CHP price search
├── commercial_invoice_files/           # CL, PC, NP files directory
└── README.md                           # Commercial Layer documentation
```

---

### File Naming Conventions

**Commercial Layer (CL) File:**
- Format: `OCRsanity_[SupplierName]_[Date]_[Timestamp]_CL_[Timestamp].xlsx`
- Example: `OCRsanity_Gad_10-11-2025_10112025_110745_CL_23112025_085147.xlsx`
- Created from OCRsanity file with `_CL_[Timestamp]` suffix

**Price Change (PC) File:**
- Format: `OCRsanity_[SupplierName]_[Date]_[Timestamp]_CL_[Timestamp]_PRICE-CHANGE_[Timestamp].xlsx`
- Example: `OCRsanity_Gad_10-11-2025_10112025_110745_CL_23112025_085147_PRICE-CHANGE_231125_120000.xlsx`
- Created from CL file with `_PRICE-CHANGE_[Timestamp]` suffix

**New Products (NP) File:**
- Format: `OCRsanity_[SupplierName]_[Date]_[Timestamp]_CL_[Timestamp]_NEW-PRODUCTS_[Timestamp].xlsx`
- Example: `OCRsanity_Gad_10-11-2025_10112025_110745_CL_23112025_085147_NEW-PRODUCTS_231125_120000.xlsx`
- Created from CL file with `_NEW-PRODUCTS_[Timestamp]` suffix

**Timestamp Format:** `ddmmyy_hhmmss` (e.g., `231125_120000` = November 23, 2025 12:00:00)

---

### Commercial Layer File Structure (CL)

**Created from OCRsanity file with additional columns:**

| Column | Header | Source | Type | Description |
|--------|--------|--------|------|-------------|
| A-B | Metadata | OCRsanity | Various | InvoiceNo, SupplierName, InvoiceDate, InvoiceTotal, Remark, Sanity Method |
| C | Index | OCRsanity | Number | Row number |
| D | Barcode | OCRsanity | Text | Product barcode (13 digits max) |
| E | ItemName | OCRsanity | Text | Product name (truncated to 15 chars) |
| F | Qty | OCRsanity | Number | Quantity ordered |
| G | UnitPrice | OCRsanity | Number | Price per unit from invoice |
| H | LineTotal | OCRsanity | Number | Total for line item |
| I | Discount1 | OCRsanity | Number | Discount percentage (0-100) |
| J | Discount2 | OCRsanity | Number | Second discount percentage |
| K | ActualUnitPrice | OCRsanity | Number | Calculated actual unit price after discount |
| **L** | **CHPprice** | **CL Process** | **Number** | **Market price from CHP website** |
| **M** | **MinimalQty** | **CL Process** | **Number** | **Minimum order quantity (from CHP)** |
| **N** | **Origin** | **CL Process** | **Text** | **Product origin/manufacturer (from CHP)** |
| **O** | **PromoPrice** | **CL Process** | **Number** | **Promotional price if available (from CHP)** |
| **P** | **PriceDiff** | **CL Process** | **Text/Number** | **Price difference % or "Not Found"** |

**PriceDiff Calculation:**
- If product found in CHP: `((ActualUnitPrice - CHPprice) / CHPprice) × 100`
- If product not found in CHP: `"Not Found"`
- Formatted as percentage with 2 decimals (e.g., `5.25%`, `-3.10%`)

**Special Handling:**
- Columns L-P use ShopDefaultCity from shop configuration for CHP search
- CHP search powered by Puppeteer headless browser (getShopCHPprice.js)
- Temp PDF file created for verification (naming: `temp_[CLFileName].pdf`)

---

### Price Change File Structure (PC)

**Created from CL file, containing only rows with price differences:**

| Column | Header | Description |
|--------|--------|-------------|
| A-B | Metadata | Copied from CL (all rows, columns A-B only) |
| C | Original Index | Index from CL file (to track original position) |
| D | Barcode | Product barcode |
| E | ItemName | Product name |
| F | ItemERPName | ERP system name (editable by user) |
| G | ActualUnitPrice | Current invoice price |
| H | CHPprice | Market price from CHP |
| I | PriceDiff | Price difference percentage |
| J | ApprovedNewPrice | User-approved new price (editable) |
| K | InvoiceIdentifier | Invoice reference (without timestamp) |

**Row Population Logic:**
1. Copy columns A-B from CL file (ALL rows)
2. Populate columns C-K ONLY for rows where:
   - PriceDiff is numeric (not "Not Found")
   - PriceDiff ≠ 0
3. Rows are populated sequentially from row 2 onwards

**InvoiceIdentifier Extraction:**
- From CL filename: `OCRsanity_[SupplierName]_[Date]_[Timestamp]_CL_[Timestamp]`
- Extract: `[SupplierName]_[Date]` (remove both timestamps)
- Example: `Gad_10-11-2025` or `Gad_10-11-2025 A` (with letter suffix)

---

### New Products File Structure (NP)

**Created from CL file, containing only rows with "Not Found" in PriceDiff:**

| Column | Header | Description |
|--------|--------|-------------|
| A-B | Metadata | Copied from CL (all rows, columns A-B only) |
| C | Original Index | Index from CL file |
| D | Barcode | Product barcode (editable) |
| E | ItemName | Product name |
| F | ItemERPName | ERP system name (editable) |
| G | ActualUnitPrice | Current invoice price |
| H | Supplier | Supplier Hebrew name (from suppliers.json) |
| I | DepartmentName | Department (searchable dropdown - editable) |
| J | DepartmentMargin | Expected margin % (calculated, placeholder initially) |
| K | SalePrice | Sale price (editable) |
| L | ActualMargin | Actual margin % (calculated, placeholder initially) |
| M | InvoiceIdentifier | Invoice reference (without timestamp) |

**Row Population Logic:**
1. Copy columns A-B from CL file (ALL rows)
2. Populate columns C-M ONLY for rows where:
   - PriceDiff = "Not Found"
3. Rows are populated sequentially from row 2 onwards

**Margin Placeholders:**
- Initially set to "To be calculated"
- Orange border applied to cells J and L
- Calculated when user clicks "Calculate Margins" button

**Margin Calculation Formulas:**

**DepartmentMargin (Column J):**
- If DepartmentName is empty/null: `"Department Name is missing"`
- If DepartmentName is valid: Get `ExpectedMarginPercentage` from department config
- Convert to percentage format: `0.35 → 35%`
- Example: Department "Dairy" has ExpectedMarginPercentage = 0.40 → `40%`

**ActualMargin (Column L):**
- If SalePrice is empty/null/zero/negative: `"Sale Price is incorrect"`
- If SalePrice is valid: `((SalePrice/1.18) - ActualUnitPrice) / (SalePrice/1.18)`
- Convert to percentage format with 2 decimals: `0.3542 → 35.42%`
- Example: SalePrice = 100, ActualUnitPrice = 70 → `((100/1.18) - 70) / (100/1.18) → 17.52%`

**Supplier Hebrew Name Lookup:**
- Extract SupplierName from InvoiceIdentifier (first part before underscore)
- Example: `Gad_10-11-2025` → SupplierName = `Gad`
- Lookup in suppliers.json: `supplier.hebrewName`
- Fallback to English name if Hebrew name not found

---

### Configuration Files

**shops_V2.json Structure:**
```json
[
  {
    "shopName": "CountryMZ",
    "BackOfficeType": "comax",
    "shopDefaultCity": "Beer Sheva",
    "Departments": [
      {
        "DepartmentName": "Dairy",
        "ExpectedMarginPercentage": 0.35
      },
      {
        "DepartmentName": "Meat",
        "ExpectedMarginPercentage": 0.40
      }
    ]
  }
]
```

**Department Configuration Files:**
- Named: `[ShopName]_Departments.json`
- Example: `CountryMZ_Departments.json`
- Contains array of department names (for dropdown population)

**Shop Configuration Parameters:**
- `shopName`: Unique shop identifier
- `BackOfficeType`: Back-office system type ("comax", "priority", etc.)
- `shopDefaultCity`: City for CHP price search (e.g., "Beer Sheva", "Tel Aviv")
- `Departments`: Array of department objects with margin expectations
- `Invoice_to_upload_HeadersMapping_Comax`: Mapping configuration for generating ERP-compatible invoice files from CL files

---

### Invoice to Upload Headers Mapping

**Purpose:** Configurable mapping system to transform Commercial Layer (CL) files into ERP-specific invoice upload formats.

**Location:** `shops_V2.json` → `Invoice_to_upload_HeadersMapping_Comax`

**Structure:**
```json
"Invoice_to_upload_HeadersMapping_Comax": {
  "DestinationColumn": ["Header", "OriginColumn", "DataType"],
  ...
}
```

**Parameters:**

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| **DestinationColumn** | String | Column letter in the Invoice_to_upload file | "A", "B", "C" |
| **Header** | String | Column header text (row 1) in destination file | "שורה", "פריט", "כמות" |
| **OriginColumn** | String/Number | Source column in CL file, or `0` if no origin | "D", "F", "K", 0 |
| **DataType** | String | Data type for Excel formatting: "T" (text), "D" (double), "I" (integer), or `0` (no formatting) | "T", "D", "I", 0 |

**Example Configuration (CountryMZ - Comax):**
```json
"Invoice_to_upload_HeadersMapping_Comax": {
  "A": ["שורה", 0, 0],
  "B": ["פריט", "D", "T"],
  "C": ["שם פריט", 0, 0],
  "D": ["כמות", "F", "D"],
  "E": ["מחיר מכירה", 0, 0],
  "F": ["הנחה", 0, 0],
  "G": ["מחיר", "K", "D"]
}
```

**Mapping Breakdown:**

| Destination | Header (Hebrew) | Origin (CL Column) | Data Type | Description |
|-------------|-----------------|-------------------|-----------|-------------|
| A | שורה | 0 | 0 | Row number (auto-generated sequential) |
| B | פריט | D | T | Barcode (from CL column D, as text to prevent scientific notation) |
| C | שם פריט | 0 | 0 | Item name (no origin, left empty or manually filled) |
| D | כמות | F | D | Quantity (from CL column F, as double) |
| E | מחיר מכירה | 0 | 0 | Sale price (no origin, left empty or manually filled) |
| F | הנחה | 0 | 0 | Discount (no origin, left empty or manually filled) |
| G | מחיר | K | D | Cost price (from CL column K - ActualUnitPrice, as double) |

**Data Type Codes:**

- **`"T"`** - Text: Uses `setCellValueExplicit(..., DataType::TYPE_STRING)` to preserve formatting (prevents Excel from converting long numbers to scientific notation)
- **`"D"`** - Double: Numeric value with decimals, formatted as `#,##0.00`
- **`"I"`** - Integer: Whole number, formatted as `#,##0`
- **`0`** - No formatting: No specific data type enforcement, Excel auto-detects

**Processing Logic:**

1. **Load CL File:** Read Commercial Layer Excel file (columns A-P)
2. **Load Mapping:** Get `Invoice_to_upload_HeadersMapping_Comax` from shop config
3. **Create Destination File:** New Excel spreadsheet for ERP upload
4. **Set Headers:** Write header text to row 1 based on mapping
5. **Process Data Rows:**
   - For each row in CL file (starting from row 2):
     - For each destination column in mapping:
       - If `OriginColumn = 0`: Skip or auto-generate (e.g., row number)
       - If `OriginColumn = letter`: Copy value from CL file column
       - Apply `DataType` formatting if specified
6. **Save File:** Output as XLSX or CSV based on `BackOfficeType`

**Origin Column = 0 Special Cases:**

| Column | Header | Behavior |
|--------|--------|----------|
| A | שורה | Auto-generate sequential row numbers (1, 2, 3...) |
| C | שם פריט | Leave empty (to be filled manually or from price list) |
| E | מחיר מכירה | Leave empty (to be filled manually or from price list) |
| F | הנחה | Leave empty or default to 0 |

**CL File Column Reference:**

From Commercial Layer file structure:
- **Column D:** Barcode
- **Column E:** ItemName
- **Column F:** Qty
- **Column G:** UnitPrice
- **Column H:** LineTotal
- **Column I:** Discount1
- **Column K:** ActualUnitPrice (calculated after discounts)
- **Column L:** CHPprice
- **Column P:** PriceDiff

**Shop-Specific Customization:**

Different shops may have different ERP requirements:

**Comax Format:**
- XLSX file format
- Hebrew headers
- Specific column order (Row, Barcode, Name, Qty, Sale Price, Discount, Cost Price)
- Barcode as text to prevent scientific notation

**All4Shops Format (Future):**
- CSV file format
- Different column order
- Different headers
- Additional mapping required

**Priority Format (Future):**
- Different structure entirely
- May require additional columns

**Benefits:**

1. **Flexibility:** Each shop can have custom ERP upload format
2. **Maintainability:** Change mapping without code changes
3. **Scalability:** Easy to add new shops with different ERP systems
4. **Clarity:** Clear documentation of column sources and types
5. **Validation:** Data types ensure proper Excel formatting

**Code Location (Future):**
- Implementation: `BOpack/generateInvoiceToUpload.php`
- Reads mapping from `shops_V2.json`
- Processes CL files from `commercialLayer/commercial_invoice_files/`
- Outputs to `BOpack/output/` directory

---

### CHP Price Search Integration

**Technology:** Puppeteer (headless Chrome browser)

**Implementation:** [getShopCHPprice.js](commercialLayer/getShopCHPprice.js:1)

**Search Process:**
1. Launch headless browser
2. Navigate to CHP website
3. Select city from shop configuration (`shopDefaultCity`)
4. Enter barcode in search field
5. Extract product data:
   - Market price (CHPprice)
   - Minimal quantity (MinimalQty)
   - Origin/manufacturer (Origin)
   - Promotional price (PromoPrice)
6. Return data or "Not Found" if product doesn't exist

**Search Endpoint:** Called by [process_commercial_layer.php](commercialLayer/process_commercial_layer.php)

**Error Handling:**
- Network timeout: Return "Search Error"
- Product not found: Return "Not Found"
- Invalid barcode: Return "Invalid Barcode"

**Performance:**
- Processes one barcode at a time
- Average search time: 2-3 seconds per barcode
- Progress indicator shown during batch processing

---

### Verification Screens

#### 1. CL Verification Screen

**File:** [verify_commercial_layer.php](commercialLayer/verify_commercial_layer.php)

**Features:**
- **Auto-save:** Changes saved automatically on cell blur (no manual save button)
- **Editable columns:** All columns editable (especially L-P for manual corrections)
- **PDF viewer:** Temp PDF displayed for reference
- **Real-time updates:** Cell edits immediately persisted to Excel file

**Layout:**
```
┌────────────────────────────────────────────────────────┐
│  Header: Commercial Layer Verification                │
│  File: [filename] | Shop: [shopname]                  │
│  [Conclude CL - no PC/NP] [Generate PC & NP Files]   │
└────────────────────────────────────────────────────────┘
┌─────────────────────┬──────────────────────────────────┐
│                     │                                  │
│  Excel Spreadsheet  │       PDF Viewer                 │
│  (Handsontable)     │       (PDF.js)                   │
│  Auto-save on blur  │       Multi-page scrollable      │
│                     │                                  │
└─────────────────────┴──────────────────────────────────┘
```

**Button Actions:**
- **"Conclude CL - no PC/NP"**: Save CL file, delete temp PDF, show completion message
- **"Generate PC & NP Files"**: Save CL file, delete temp PDF, generate PC and NP files

#### 2. PC Verification Screen

**File:** [verify_price_changes.php](commercialLayer/verify_price_changes.php)

**Features:**
- **Split-screen layout:** PC data (right, Handsontable grid) + CHP search panel (left)
- **CHP search panel:** Live price search by barcode (click-to-copy from table)
- **Editable columns:** Rec Price, Rec Mrgn, Recommend — all three have bright yellow headers
- **Bidirectional editing:** Edit Rec Price → Rec Mrgn auto-updates (gold); edit Rec Mrgn → Rec Price auto-updates (gold)
- **Auto-save on Continue:** Dirty rows saved to Excel before navigating to NP process
- **Final action:** "Re-calculate Recommended Margin" (saves) + "Continue to New Product Process"

**Layout:**
```
┌────────────────────────────────────────────────────────┐
│  Header: Verify Price Changes                         │
│  [🔄 Re-calculate] [➡️ Continue to NP]  - Right side  │
└────────────────────────────────────────────────────────┘
┌─────────────────────┬──────────────────────────────────┐
│                     │                                  │
│  CHP Search Panel   │    Handsontable Grid             │
│  - City input       │    Columns A-B hidden            │
│  - Barcode input    │    Editable: Rec Price,          │
│  - Search button    │             Rec Mrgn,            │
│  - Results table    │             Recommend            │
│                     │    Yellow headers on editable    │
│                     │    Gold cell = calculated output │
│                     │                                  │
└─────────────────────┴──────────────────────────────────┘
```

**CHP Panel Features:**
- City input (pre-filled from shop config default city)
- Barcode input field (populated by clicking barcode in table)
- Search button (triggers Puppeteer/CHP search via AJAX)
- Results display: min/avg/max price stats + per-store price table
- Loading indicator during search

#### 3. NP Verification Screen

**File:** [verify_new_products.php](commercialLayer/verify_new_products.php)

**Features:**
- **Split-screen layout:** NP data (right) + CHP search panel (left)
- **Searchable dropdown:** Department selection using Select2 library
- **Editable columns:** D (Barcode), F (ItemERPName), I (DepartmentName), K (SalePrice)
- **Margin calculation:** "Calculate Margins" button fills columns J and L
- **Final action:** "Save New Products File" button

**Layout:**
```
┌────────────────────────────────────────────────────────┐
│  Header: Verify New Products                          │
│  [Calculate Margins] [Save New Products File]         │
│  - Right side                                         │
└────────────────────────────────────────────────────────┘
┌─────────────────────┬──────────────────────────────────┐
│                     │                                  │
│  CHP Search Panel   │    NP Data Table                 │
│  (Same as PC)       │    (Columns C-M visible)         │
│                     │    Columns A-B hidden            │
│                     │    Editable: D, F, I, K          │
│                     │    Searchable dropdown: I        │
│                     │    Orange placeholders: J, L     │
│                     │                                  │
└─────────────────────┴──────────────────────────────────┘
```

**Margin Calculation Workflow:**
1. User fills in DepartmentName (column I) using searchable dropdown
2. User fills in SalePrice (column K)
3. User clicks "Calculate Margins"
4. System calculates:
   - DepartmentMargin (column J) from department config
   - ActualMargin (column L) from formula
5. Orange borders removed from calculated cells
6. Page reloads to show calculated values
7. User reviews and clicks "Save New Products File"

---

### Complete Workflow Example

**Scenario:** Processing Gad supplier invoice for CountryMZ shop

**Step 1: Upload OCR Sanity File**
- User uploads: `OCRsanity_Gad_10-11-2025_10112025_110745.xlsx`
- User selects shop: "CountryMZ"
- System creates: `OCRsanity_Gad_10-11-2025_10112025_110745_CL_23112025_085147.xlsx`

**Step 2: CL Processing**
- System copies all data from OCRsanity file
- System searches CHP for each barcode (using "Beer Sheva" city)
- System populates columns L-P with CHP data and price differences
- System creates temp PDF: `temp_OCRsanity_Gad_10-11-2025_10112025_110745_CL_23112025_085147.xlsx.pdf`
- System redirects to CL verification screen

**Step 3: CL Verification**
- User reviews CL file with PDF side-by-side
- User makes corrections if needed (auto-saved)
- User clicks "Generate PC & NP Files"
- System saves CL file
- System deletes temp PDF (CL stage concluded)

**Step 4: PC/NP Generation**
- System scans PriceDiff column (P)
- Finds 5 rows with numeric price differences (price changes)
- Finds 2 rows with "Not Found" (new products)
- System generates PC file with 5 rows of data
- System generates NP file with 2 rows of data
- System redirects to PC verification screen

**Step 5: PC Verification**
- User reviews 5 price changes
- User uses CHP panel to verify prices
- User approves new prices in column J
- User fills in ItemERPName in column F
- User clicks "Save Price Changes File"
- System saves PC file
- System redirects to PC completion screen
- User clicks "Continue to New Product Process"

**Step 6: NP Verification**
- User reviews 2 new products
- User uses CHP panel to search for market prices
- User fills in ItemERPName (column F)
- User selects Department from searchable dropdown (column I)
- User enters SalePrice (column K)
- User clicks "Calculate Margins"
- System calculates DepartmentMargin (40%) and ActualMargin (35.42%)
- User reviews calculations
- User clicks "Save New Products File"
- System saves NP file
- System redirects to NP completion screen

**Final Result:**
- CL file saved: ✅
- PC file saved: ✅ (5 price changes documented)
- NP file saved: ✅ (2 new products ready for inventory)
- Temp PDF deleted: ✅
- Commercial Layer process complete

---

### Key Features

**1. Auto-Save in CL Verification**
- Eliminates need for manual save button
- Cell changes persisted immediately on blur
- Reduces risk of data loss
- Improves user experience

**2. Searchable Department Dropdown**
- Uses Select2 jQuery plugin
- RTL (right-to-left) support for Hebrew
- Type-to-search functionality
- Clear selection option

**3. Click-to-Copy Barcodes**
- Barcodes in PC/NP tables are clickable
- Clicking copies barcode to CHP search panel
- Barcode field is also editable (dual functionality)
- Streamlines price verification workflow

**4. Dynamic Margin Calculation**
- Margins calculated on-demand (not automatic)
- User controls when to calculate
- Validates required fields (DepartmentName, SalePrice)
- Shows error messages in cells if validation fails

**5. Sequential Row Population**
- PC/NP files always start from row 2
- Rows populated consecutively (no gaps)
- Columns A-B preserved from CL file (all rows)
- Columns C onwards populated only for relevant rows

**6. Session State Management**
- CL filename stored in session
- Shop name preserved across screens
- NP filename stored for continuation
- Enables smooth multi-screen workflow

---

### File Lifecycle

**CL File:**
1. Created in [process_commercial_layer.php](commercialLayer/process_commercial_layer.php)
2. Edited in [verify_commercial_layer.php](commercialLayer/verify_commercial_layer.php) (auto-save)
3. **Saved and concluded** in [generate_pc_np_files.php](commercialLayer/generate_pc_np_files.php:53-55)
4. Remains in `commercial_invoice_files/` directory (permanent)

**Temp PDF:**
1. Created in [process_commercial_layer.php](commercialLayer/process_commercial_layer.php)
2. Displayed in [verify_commercial_layer.php](commercialLayer/verify_commercial_layer.php)
3. **Deleted** in [generate_pc_np_files.php](commercialLayer/generate_pc_np_files.php:57-62)
4. Purpose: Temporary reference for CL verification only

**PC File:**
1. Created in [generate_pc_np_files.php](commercialLayer/generate_pc_np_files.php)
2. Edited in [verify_price_changes.php](commercialLayer/verify_price_changes.php)
3. Saved in [save_price_changes.php](commercialLayer/save_price_changes.php)
4. Remains in `commercial_invoice_files/` directory (permanent)

**NP File:**
1. Created in [generate_np_file.php](commercialLayer/generate_np_file.php)
2. Edited in [verify_new_products.php](commercialLayer/verify_new_products.php)
3. Margin calculation in [calculate_margins.php](commercialLayer/calculate_margins.php)
4. Saved in [save_new_products.php](commercialLayer/save_new_products.php)
5. Remains in `commercial_invoice_files/` directory (permanent)

---

### Important Implementation Details

**1. InvoiceIdentifier Extraction**

InvoiceIdentifier is extracted from CL filename by removing both timestamps:

```php
// CL filename: OCRsanity_Gad_10-11-2025_10112025_110745_CL_23112025_085147.xlsx
// Extract: Gad_10-11-2025_10112025_110745
// Remove timestamp: _10112025_110745
// Result: Gad_10-11-2025
```

Regex pattern: `/_\d{8}_\d{6}$/` (removes `_ddmmyyyy_hhmmss` from end)

**2. Column A-B Preservation**

All generated files (PC, NP) copy columns A-B from CL file for ALL rows:
- Preserves metadata alignment
- Enables Excel formula references
- Maintains file structure consistency
- Columns A-B hidden in UI (not shown to user)

**3. Sequential Row Population (PC/NP)**

Critical rule: When populating columns C onwards in PC/NP files:
- First qualifying row from CL → Row 2 in PC/NP
- Second qualifying row from CL → Row 3 in PC/NP
- Third qualifying row from CL → Row 4 in PC/NP
- etc.

**Example:**
```
CL File:
Row 5: PriceDiff = 5.25%     → PC Row 2
Row 12: PriceDiff = -3.10%   → PC Row 3
Row 23: PriceDiff = 0.75%    → PC Row 4

NP File:
Row 8: PriceDiff = "Not Found"  → NP Row 2
Row 18: PriceDiff = "Not Found" → NP Row 3
```

**4. No Price Changes or New Products**

If CL file has:
- No price changes (all PriceDiff = 0 or "Not Found" only)
- No new products (no "Not Found" entries)

User clicks "Conclude CL - no PC/NP" button:
- CL file saved
- Temp PDF deleted
- Completion message shown
- No PC or NP files generated

---

### Technologies Used

**Backend:**
- PHP 8.x with PhpSpreadsheet library
- Puppeteer (Node.js) for CHP price scraping
- Session management for workflow state

**Frontend:**
- HTML5, CSS3, JavaScript
- Handsontable (Excel-like editing)
- PDF.js (PDF rendering)
- jQuery + Select2 (searchable dropdowns)

**External Integration:**
- CHP website (market price source)
- Headless Chrome (Puppeteer automation)

---

### Future Enhancements

**1. Bulk Price Approval**
- Select multiple price changes
- Approve all in one action
- Reject with reason codes

**2. Price History Tracking**
- Store historical price changes
- Show price trends over time
- Flag unusual price spikes

**3. Auto-Department Assignment**
- AI-based department suggestion from product name
- Historical department mapping
- Supplier-specific department rules

**4. Margin Optimization Suggestions**
- Recommend sale prices based on desired margin
- Compare margins across similar products
- Alert on below-minimum margin thresholds

**5. Multi-Supplier Batch Processing**
- Process multiple invoices in sequence
- Consolidated PC/NP reports
- Batch approval workflows

**6. ERP Integration**
- Direct export to back-office system (Comax, Priority)
- Automatic product creation
- Price update synchronization

---

### Troubleshooting

**Issue:** CHP search times out
- **Cause:** Network latency, website down, or city not available
- **Solution:** Check getShopCHPprice.js timeout settings, verify city name in shop config

**Issue:** NP file shows wrong margins
- **Cause:** Department config missing or incorrect ExpectedMarginPercentage
- **Solution:** Check [ShopName]_Departments.json file structure

**Issue:** PC file is empty even with price changes
- **Cause:** PriceDiff column has "Not Found" or zero values
- **Solution:** Verify CHP search completed successfully in CL stage

**Issue:** Columns A-B showing in PC/NP screens
- **Cause:** Display logic issue in verify_price_changes.php or verify_new_products.php
- **Solution:** Check `if ($colIdx < 2) continue;` condition in table rendering

**Issue:** Sequential row population broken
- **Cause:** Using CL row indices instead of sequential counter
- **Solution:** Verify `$pcRowNum++` or `$npRowNum++` incrementation logic

---

**Last Updated:** November 23, 2025
**Status:** ✅ Commercial Layer Complete - Full PC & NP Workflow Implemented
**Next Phase:** ERP integration, price history tracking, bulk approval workflows

---

## Stage 5: Back Office Pack (BOpack) Process

**Location:** `C:\xampp\htdocs\website\BOpackR\`
**Version:** BOpackR (Rewritten)
**Created:** December 15, 2025
**Status:** ✅ Complete - Ready for Testing

### Overview

The Back Office Pack (BOpack) process is the **final stage** in the Retailomatics Beta pipeline. It consolidates all processed invoices and price/product data for a specific shop and date, generating ERP-ready files for upload to the back-office system.

**Purpose:**
- Aggregate all Commercial Layer (CL) files for a shop/date
- Generate consolidated invoice list and individual upload files
- Create aggregated price change and new product lists
- Prepare data in ERP-specific format using configurable mappings

**Input:**
- Commercial Layer files (`*_CL_*.xlsx`)
- Price Change files (`*_PRICE-CHANGE_*.xlsx`)
- New Products files (`*_NEW-PRODUCTS_*.xlsx`)

**Output:**
- `invoice_list_ddmmyy.xlsx` - Master invoice list
- `invoice_to_upload_X.xlsx` - Individual invoice upload files (one per CL file)
- `price_change_list_ddmmyy.xlsx` - Consolidated price changes
- `new_products_list_ddmmyy.xlsx` - Consolidated new products

---

### BOpack Architecture

**Entry Point:** [bopack_start.php](BOpackR/bopack_start.php)
- Shop selection (dropdown with Select2)
- Process date selection (date picker)
- Submits to main processing script

**Main Processor:** [process_bopack_r.php](BOpackR/process_bopack_r.php)
- File discovery and grouping
- Invoice list generation
- Invoice upload files generation
- Price change list generation
- New products list generation

---

### File Discovery Algorithm

**Process:**
1. Scan `commercialLayer/commercial_invoice_files/` directory
2. Filter files by shop name and process date
3. Categorize files into three groups:
   - **CL files:** Match pattern `*_CL_ddmmyyyy_hhmmss.xlsx`
   - **PC files:** Match pattern `*_PRICE-CHANGE_ddmmyy_hhmmss.xlsx`
   - **NP files:** Match pattern `*_NEW-PRODUCTS_ddmmyy_hhmmss.xlsx`

**Important Notes:**
- PC and NP files contain `_CL_` in their names, so they must be checked FIRST
- Date format in CL files: `ddmmyyyy` (8 digits)
- Date format in PC/NP files: `ddmmyy` (6 digits)
- Files are grouped by timestamp to match related CL, PC, and NP files

**Code Location:** [process_bopack_r.php:132-158](BOpackR/process_bopack_r.php#L132-L158)

```php
// Check PC and NP first since they contain _CL_ in their names
if (preg_match('/_PRICE-CHANGE_\d{6}_\d{6}\.xlsx$/', $file)) {
    $pcFiles[] = [...];
} elseif (preg_match('/_NEW-PRODUCTS_\d{6}_\d{6}\.xlsx$/', $file)) {
    $npFiles[] = [...];
} elseif (preg_match('/_CL_\d{8}_\d{6}\.xlsx$/', $file)) {
    $clFiles[] = [...];
}
```

---

### Step 1: Invoice List Generation

**Purpose:** Create a master list of all invoices processed for the shop/date

**File Name:** `invoice_list_ddmmyy.xlsx`

**Structure:**
| Column | Header | Source | Format |
|--------|--------|--------|--------|
| A | Invoice Number | CL Cell A3 | Text |
| B | Invoice Date | CL Cell B3 | dd.mm.yyyy |
| C | Invoice File Name | CL filename | Text |

**Date Formatting Logic:**
- Handles multiple input formats: `dd-mm-yyyy`, `dd.mm.yyyy`, `dd-mm-yy`, `dd.mm.yy`
- Converts 2-digit years: 00-50 → 2000-2050, 51-99 → 1951-1999
- Output format: Always `dd.mm.yyyy`

**Code Location:** [process_bopack_r.php:178-210](BOpackR/process_bopack_r.php#L178-L210)

---

### Step 2: Invoice Upload Files Generation

**Purpose:** Create individual upload files for each invoice, formatted for ERP system

**File Name:** `invoice_to_upload_X.xlsx` (X = sequential counter starting at 1)

**Configuration:** Uses `Invoice_to_upload_HeadersMapping_Comax` from `shops_V2.json`

**Mapping Structure:**
```json
"Invoice_to_upload_HeadersMapping_Comax": {
  "A": ["שורה", 0, 0],           // Row index (origin=0 means auto-generated)
  "B": ["ברקוד", "D", "T"],      // Barcode from CL column D, Text type
  "C": ["כמות", "F", "I"],       // Quantity from CL column F, Integer type
  "D": ["מחיר יחידה", "K", "D"]  // Unit price from CL column K, Double type
}
```

**Mapping Parameters:**
- **[0]** - Header name (Hebrew/ERP-specific)
- **[1]** - Source column from CL file (or 0 for auto-generated)
- **[2]** - Data type: "T" (Text), "D" (Double), "I" (Integer), or 0 for row index

**Row Indexing:**
- Starts at 1 for first data row (row 2 in Excel)
- Sequential numbering independent of source row numbers
- Handles empty rows by skipping them

**File Deletion:**
- Deletes existing file before creation to avoid conflicts

**Code Location:** [process_bopack_r.php:218-350](BOpackR/process_bopack_r.php#L218-L350)

---

### Step 3: Price Change List Generation

**Purpose:** Consolidate all price changes from PC files into one master list

**File Name:** `price_change_list_ddmmyy.xlsx`

**Configuration:** Uses `Price_change_list_HeadersMapping_Comax` from `shops_V2.json`

**Mapping Structure:**
```json
"Price_change_list_HeadersMapping_Comax": {
  "A": ["שורה", 0, 0],
  "B": ["ברקוד", "D", "T"],
  "C": ["מחיר ישן", "I", "D"],
  "F": ["שינוי עלות", "J", "P"],    // P = Percentage type
  "G": ["מרווח נוכחי", "K", "P"],
  "H": ["מרווח מומלץ", "P", "P"]
}
```

**Data Types:**
- **T** (Text): Stored as string
- **D** (Double): Formatted with comma separator (e.g., 1,234.56)
- **I** (Integer): Formatted as whole number
- **P** (Percentage): Special handling for percentage values

**Percentage Formatting:**
```php
// Remove % sign, convert to decimal
$percentValue = str_replace('%', '', $value);
$percentDecimal = floatval($percentValue) / 100;

// Store as decimal and format as percentage
$sheet->setCellValue($cell, $percentDecimal);
$sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.00%');
```

**Empty Row Detection:**
- Checks key columns (D, I, J, K, P) for data
- Skips rows where all key columns are empty or zero

**Code Location:** [process_bopack_r.php:385-497](BOpackR/process_bopack_r.php#L385-L497)

---

### Step 4: New Products List Generation

**Purpose:** Consolidate all new products from NP files into one master list

**File Name:** `new_products_list_ddmmyy.xlsx`

**Configuration:** Uses `New_products_list_HeadersMapping_Comax` from `shops_V2.json`

**Mapping Structure:**
```json
"New_products_list_HeadersMapping_Comax": {
  "A": ["מס פריט", "D", "T"],      // Item number from column D
  "B": ["שם פריט", "F", "T"],      // Item name from column F
  "C": ["ברקוד", "D", "T"],        // Barcode from column D
  "D": ["מחיר קניה", "G", "D"],    // Purchase price from column G
  "E": ["ספק", 0, "I"],            // Supplier code (special handling)
  "F": ["מחיר מכירה", "K", "D"],   // Sale price from column K
  "G": ["מחלקה", 0, "I"],          // Department code (special handling)
  "H": ["שורה מקורית", "C", "I"],  // Original row from column C
  "I": ["מזהה חשבונית", "M", "T"], // Invoice ID from column M
  "J": ["שם מחלקה", "H", "T"]      // Department name from column H
}
```

**Special Handling for Origin=0:**
When mapping has `origin=0`, the column requires special handling:

- **Column E (ספק - Supplier):** Populated from NP file column N (SupplierCode)
- **Column G (מחלקה - Department):** Populated from NP file column O (DepartmentCode)

```php
if ($originCol === 0) {
    // Column E: SupplierCode from NP column N
    if ($destCol === 'E') {
        $supplierCodeValue = $npSheet->getCell('N' . $sourceRow)->getValue();
        $newProductsSheet->setCellValue($destCol . $destRow, (int)$supplierCodeValue);
    }
    // Column G: DepartmentCode from NP column O
    elseif ($destCol === 'G') {
        $deptCodeValue = $npSheet->getCell('O' . $sourceRow)->getValue();
        $newProductsSheet->setCellValue($destCol . $destRow, (int)$deptCodeValue);
    }
}
```

**Empty Row Detection:**
- Checks key source columns: D, F, G, K (not A-D)
- Column D: Item Number/Barcode
- Column F: Item Name
- Column G: Purchase Price
- Column K: Sale Price
- Skips rows where all key columns are empty or contain only zeros

**Code Location:** [process_bopack_r.php:501-599](BOpackR/process_bopack_r.php#L501-L599)

---

### Configuration System

**File:** [shops_V2.json](configDir/shops_V2.json)

**Three Mapping Types:**

**1. Invoice_to_upload_HeadersMapping_Comax**
- Maps CL file columns to ERP upload format
- Row index auto-generated (origin=0)

**2. Price_change_list_HeadersMapping_Comax**
- Maps PC file columns to consolidated format
- Supports percentage data type ("P")
- Row index auto-generated (origin=0)

**3. New_products_list_HeadersMapping_Comax**
- Maps NP file columns to consolidated format
- Special handling for Supplier/Department codes (origin=0)
- Row index NOT auto-generated (uses source data)

**Example Shop Configuration:**
```json
{
  "ShopName": "CountryMZ",
  "Invoice_to_upload_HeadersMapping_Comax": {
    "A": ["שורה", 0, 0],
    "B": ["ברקוד", "D", "T"],
    "C": ["כמות", "F", "I"],
    "D": ["מחיר יחידה", "K", "D"]
  },
  "Price_change_list_HeadersMapping_Comax": {
    "A": ["שורה", 0, 0],
    "B": ["ברקוד", "D", "T"],
    "F": ["שינוי עלות", "J", "P"]
  },
  "New_products_list_HeadersMapping_Comax": {
    "A": ["מס פריט", "D", "T"],
    "E": ["ספק", 0, "I"],
    "G": ["מחלקה", 0, "I"]
  }
}
```

---

### Output Files Location

**Directory:** `BOpackR/output/{ShopName}_{ProcessDate}/`

**Example:** `BOpackR/output/CountryMZ_26-11-2025/`

**Files Generated:**
```
invoice_list_261125.xlsx
invoice_to_upload_1.xlsx
invoice_to_upload_2.xlsx
invoice_to_upload_3.xlsx
price_change_list_261125.xlsx
new_products_list_261125.xlsx
```

---

### Complete BOpack Workflow

**Step 1: User Input**
1. Navigate to [bopack_start.php](BOpackR/bopack_start.php)
2. Select shop from dropdown (populated from `shops_V2.json`)
3. Select process date (default: today)
4. Click "התחל עיבוד" (Start Processing)

**Step 2: File Discovery**
1. Scan `commercialLayer/commercial_invoice_files/` for matching files
2. Filter by shop name and date
3. Categorize into CL, PC, and NP groups
4. Display counts: X CL files, Y PC files, Z NP files found

**Step 3: Invoice Processing**
1. Generate `invoice_list_ddmmyy.xlsx` from all CL files
2. Generate `invoice_to_upload_X.xlsx` for each CL file
3. Apply Invoice_to_upload_HeadersMapping_Comax
4. Auto-number rows starting at 1

**Step 4: Price Change Processing**
1. Load all PC files for the date
2. Apply Price_change_list_HeadersMapping_Comax
3. Handle percentage formatting
4. Generate consolidated `price_change_list_ddmmyy.xlsx`
5. Skip empty rows

**Step 5: New Products Processing**
1. Load all NP files for the date
2. Apply New_products_list_HeadersMapping_Comax
3. Handle special origin=0 columns (Supplier, Department codes)
4. Generate consolidated `new_products_list_ddmmyy.xlsx`
5. Skip empty rows

**Step 6: Completion**
- Display success message with counts
- Show output directory path
- Provide "חזור למסך הפתיחה" (Return to start) link

---

### Key Features

**1. Configurable Column Mapping**
- No code changes needed for different ERP systems
- Shop-specific mappings in JSON config
- Support for multiple data types

**2. Intelligent File Discovery**
- Handles complex filename patterns
- Groups related files by timestamp
- Validates date formats

**3. Data Type Handling**
- Text (T): String formatting
- Double (D): Number with comma separator
- Integer (I): Whole numbers
- Percentage (P): Decimal stored as percentage

**4. Row Management**
- Auto-generated sequential row numbers
- Empty row detection and skipping
- Proper Excel formatting

**5. File Cleanup**
- Deletes existing files before creation
- Prevents duplicate/conflicting files

---

### Error Handling

**No Files Found:**
```
Error: No CL files found for shop 'ShopName' and date 'dd-mm-yyyy'
```
- Check shop name spelling
- Verify date format
- Confirm files exist in commercialLayer/commercial_invoice_files/

**Missing Mapping Configuration:**
```
Warning: Invoice_to_upload_HeadersMapping_Comax not found in shop config.
Skipping invoice upload file generation.
```
- Check shops_V2.json for shop configuration
- Verify mapping name spelling
- Ensure mapping structure is correct

**Empty Result Files:**
- Check source files have data in expected columns
- Verify empty row detection logic
- Review column mapping source columns

---

### Known Issues & Future Improvements

**Current Limitations:**
1. Products without original cost (OriginalUnitPrice) in price list:
   - Currently marked as "Not Found" in PriceDiff column
   - Excluded from PC files (floatval("Not Found") = 0)
   - Treated as New Products
   - **Note:** This mechanism may need adjustment based on testing

**Future Enhancements:**
1. Batch processing for multiple shops/dates
2. Progress indicators for large file sets
3. Validation reports (data quality checks)
4. Direct ERP system integration
5. Historical tracking and comparison
6. Error recovery mechanisms

---

### Troubleshooting

**Issue:** Files found but categorized incorrectly
- **Cause:** PC/NP files contain "_CL_" in names and matched CL pattern first
- **Solution:** Check PC and NP patterns BEFORE CL pattern in regex matching

**Issue:** Date format mismatch in file discovery
- **Cause:** CL files use ddmmyyyy (8 digits), PC/NP use ddmmyy (6 digits)
- **Solution:** Use correct regex patterns: `\d{8}` for CL, `\d{6}` for PC/NP

**Issue:** New products list showing empty rows with zeros
- **Cause:** Empty row detection checking wrong columns (A-D instead of D,F,G,K)
- **Solution:** Check key source columns that actually contain product data

**Issue:** Supplier/Department codes missing in new products list
- **Cause:** origin=0 columns were being skipped entirely
- **Solution:** Special handling to populate from NP columns N and O

**Issue:** Row indexing starting at 2 instead of 1
- **Cause:** Using Excel row number directly instead of separate counter
- **Solution:** Use dedicated $rowIndex counter starting at 1

---

**Last Updated:** December 15, 2025
**Status:** ✅ BOpack Process Complete - Ready for Testing
**Next Phase:** Testing with real data, potential adjustments to price change mechanism

---

## Stage 1: Invoice Pre-Process (PP) - PreProcess2 System

**Location:** `C:\xampp\htdocs\website\PreProcess2\`
**Version:** 2.0 (Beta Stage - Retailomatics System)
**Created:** November 23, 2025
**Status:** ✅ Complete

### Overview

PreProcess2 is a redesigned invoice pre-processing system that combines multi-page PDF invoices into single files with proper naming conventions. This system is the **first stage** in the Retailomatics Beta pipeline and prepares invoices for OCR analysis.

**Key Innovation:** Unlike the legacy PreProcess system, PreProcess2 **does not send emails**. All processed files remain in `preProcessDir` for the next stage (OA - OCR and Analysis).

### System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│           RETAILOMATICS BETA SYSTEM - STAGE 1                │
└─────────────────────────────────────────────────────────────┘

Input: Raw PDF scans in preProcessDir
    ↓
ProcessInvoices.php
- Display all PDFs with embedded preview
- User inputs: Supplier, Letter, Page Number, Date
- User can discard unwanted pages
    ↓
AppendAndRename.php
- Groups PDFs by Supplier + Letter + Date
- Merges pages using three-tier fallback (FPDI → PDFtk → Ghostscript)
- Renames: "{Supplier} {dd-mm-yy} {Letter}.pdf"
- Moves original pages to TempPP
- Keeps merged PDFs in preProcessDir
    ↓
Output: Properly named merged PDFs ready for Stage 2 (OA)
```

### Files

**[ProcessInvoices.php](PreProcess2/ProcessInvoices.php)**
- Entry point for the pre-processing workflow
- Displays all PDF files from `../preProcessDir`
- Visual PDF preview with embedded viewer
- Form inputs: Supplier (Hebrew autocomplete), Letter (A-J), Page Number (1-20), Date, Discard toggle

**[AppendAndRename.php](PreProcess2/AppendAndRename.php)**
- Processes submitted form data
- Groups PDFs by Supplier + Letter + Date
- Merges multi-page invoices using three fallback methods:
  1. **FPDI** (primary - PHP library, fast)
  2. **PDFtk** (fallback - handles compressed PDFs)
  3. **Ghostscript** (second fallback - handles complex operations)
- Outputs merged PDFs to `preProcessDir` with naming: `{Supplier} {dd-mm-yy} {Letter}.pdf`
- Moves original page files to `TempPP` directory
- Displays comprehensive results page

**[README.md](PreProcess2/README.md)**
- Complete system documentation
- Usage instructions
- Error handling guide
- Integration notes for Retailomatics Beta System

### File Naming Convention

**Input:** Any PDF filename (e.g., `scan001.pdf`, `IMG_2023.pdf`)

**Output:** `{SupplierEnglish} {dd-mm-yy} {Letter}.pdf`
- Example: `Tayari 15-11-25 A.pdf`
- Example: `FarmaDeal 23-11-25 B.pdf`

**Extraction Logic:**
1. User selects Supplier (Hebrew name from autocomplete)
2. System maps Hebrew name → English name using `suppliers.json`
3. User selects Letter (A-J) to distinguish multiple invoices on same date
4. User selects Date (defaults to today)
5. System formats date as dd-mm-yy

### Directory Structure

```
PreProcess2/
├── ProcessInvoices.php          # Entry point - display PDFs
├── AppendAndRename.php           # Processing logic - merge PDFs
└── README.md                     # Complete documentation

../preProcessDir/                 # Source and destination directory
├── [input PDFs]                  # Unprocessed PDF pages
├── [merged PDFs]                 # Output: "Supplier dd-mm-yy L.pdf"
└── TempPP/                       # Archived original page files
```

### PDF Merging Methods (Three-Tier Fallback)

**1. FPDI (Primary)**
- PHP library (setasign/fpdi)
- Fast, no external dependencies
- Cannot handle compressed PDFs

**2. PDFtk (Fallback)**
- Command-line tool
- Handles all PDF types including compressed
- Requires installation

**3. Ghostscript (Second Fallback)**
- Command-line tool
- Industry standard, very robust
- Requires installation

**Automatic Fallback Chain:**
- Try FPDI → If fails (compressed PDF) → Try PDFtk → If fails → Try Ghostscript → If all fail → Show error with installation links

### Results Reporting

After processing, the system displays:
- **Success items:** Document name, page count, page numbers used, method used (FPDI/PDFtk/Ghostscript)
- **Error items:** Document name, all error messages (FPDI/PDFtk/Ghostscript), installation links
- **Discarded files:** List of files moved to TempPP
- Link to process more invoices

### Example Workflow

**Scenario:** Processing 3 pages of a Tayari invoice from November 15, 2025

**Input Files:**
- `scan001.pdf`
- `scan002.pdf`
- `scan003.pdf`

**User Actions:**
| File | Supplier | Letter | Page | Date | Discard |
|------|----------|--------|------|------|---------|
| scan001.pdf | טיארי | A | 1 | 2025-11-15 | No |
| scan002.pdf | טיארי | A | 2 | 2025-11-15 | No |
| scan003.pdf | טיארי | A | 3 | 2025-11-15 | No |

**Processing:**
1. System groups all 3 files (same Supplier + Letter + Date)
2. Sorts by Page Number (1, 2, 3)
3. Merges using FPDI
4. Outputs: `Tayari 15-11-25 A.pdf` (3 pages)
5. Moves originals to `TempPP/`

**Result:**
- `preProcessDir/Tayari 15-11-25 A.pdf` ✅ (ready for OCR stage)
- `preProcessDir/TempPP/scan001.pdf` (archived)
- `preProcessDir/TempPP/scan002.pdf` (archived)
- `preProcessDir/TempPP/scan003.pdf` (archived)

### Key Differences from Legacy PreProcess

| Feature | Legacy (preProcess) | New (PreProcess2) |
|---------|---------------------|-------------------|
| **Email Sending** | Yes (via sendToOCR.php) | No - files stay in preProcessDir |
| **Session Variables** | Sets OCR email variables | No session variables |
| **Redirect** | Opens sendToOCR.php in new tab | Shows results page, link to process more |
| **Dependencies** | Gmail API, email templates | None - standalone system |
| **File Destination** | Emailed to OCR service | Remains in preProcessDir |
| **Complexity** | High (email integration) | Low (simple file processing) |

### Integration with Retailomatics Beta System

**Current Stage: Invoice Pre-Process (PP)** ✅
- Purpose: Combine and rename PDF invoices
- Input: Raw PDF scans from preProcessDir
- Output: Properly named merged PDFs in preProcessDir
- Status: Complete

**Next Stage: Invoice OCR and Analysis (OA)** 🔄
- Purpose: Extract invoice data using AI/OCR
- Input: Merged PDFs from preProcessDir
- Output: Structured JSON/Excel files
- Status: To be implemented

**Future Stage: Invoice ERP Packing (EP)** 📅
- Purpose: Package invoice data for ERP upload
- Input: Verified invoice data (JSON/Excel)
- Output: ERP-compatible files (Comax, Priority, etc.)
- Status: To be implemented

---

## Future Enhancements for PreProcess2

### 1. PDF Splitting Functionality

**Purpose:** Split large multi-page PDFs into individual pages for easier pre-processing

**Use Case:**
- User has a 20-page PDF containing multiple invoices
- Need to split it into individual pages before grouping and merging
- Each page can then be assigned to different suppliers/dates

**Planned Implementation:**
- New file: `SplitPDF.php`
- Allow user to upload large PDF
- Automatically split into individual pages
- Save pages to preProcessDir with sequential names
- User can then process pages through normal workflow

**Technical Approach:**
- Use FPDI to extract individual pages
- Alternative: PDFtk command `pdftk input.pdf burst output page_%02d.pdf`
- Alternative: Ghostscript extraction

### 2. JPG to PDF Conversion

**Purpose:** Convert scanned JPG images into PDF format before processing

**Use Case:**
- User has invoice scans as JPG files
- Need to convert to PDF before pre-processing
- Support batch conversion of multiple JPG files

**Planned Implementation:**
- New file: `ConvertJPGtoPDF.php`
- Allow user to upload JPG files (single or batch)
- Convert each JPG to individual PDF page
- Save converted PDFs to preProcessDir
- User can then process through normal workflow

**Technical Approach:**
- Use FPDF/FPDI to create PDF with embedded image
- Alternative: ImageMagick `convert image.jpg output.pdf`
- Alternative: Ghostscript conversion
- Maintain image quality and aspect ratio

**Additional Features:**
- Image rotation (90°, 180°, 270°)
- Image cropping/trimming
- Quality adjustment
- Batch processing with preview

---

## Harmonized Invoice Processing Flow

**Status:** ✅ Complete - End-to-end automated workflow

### Overview

The harmonized flow integrates all invoice processing stages into a single seamless workflow that maintains context throughout the entire process using PHP session management.

```
PDF Selection → Crop Marking → AI OCR → Sanity Verification → Commercial Layer → PC/NP Generation → Results
```

### Key Features

- **Automatic file detection**: System automatically finds and loads latest files at each stage
- **Session persistence**: All data maintained across steps without manual file selection
- **Sequence identifiers**: Support for multiple invoices per supplier/date (A-Z)
- **Smart price list selection**: Automatically finds matching price list by shop name
- **Completion scenarios**: Handles all flow outcomes (PC+NP, PC only, NP only, neither)

### File Naming Convention

**Input PDF:** `XXXX dd-mm-yy Z.pdf` (e.g., `Tnuva 23-11-25 A.pdf`)

**Generated Files:**
- OCRjson: `OCRjson_XXXX_dd-mm-yyyy_Z_ddmmyy_hhmmss.json`
- OCRsanity: `OCRsanity_XXXX_dd-mm-yyyy_Z_ddmmyy_hhmmss.xlsx`
- Commercial Layer: `OCRsanity_XXXX_dd-mm-yyyy_Z_ddmmyy_hhmmss_CL_ddmmyy_hhmmss.xlsx`
- Price Changes: `..._CL_ddmmyy_hhmmss_PRICE-CHANGE_ddmmyy_hhmmss.xlsx`
- New Products: `..._CL_ddmmyy_hhmmss_NEW-PRODUCTS_ddmmyy_hhmmss.xlsx`

### Processing Steps

1. **Step 1**: Start flow - PDF & shop selection with validation
2. **Step 2**: Parse metadata & launch crop tool (PDF auto-loaded)
3. **Step 3**: OCR processing with OpenAI (auto-detect latest crops)
4. **Step 4**: Create OCRsanity file & verify
5. **Step 5**: Commercial Layer processing (auto-select price list)
6. **Step 6**: Generate PC/NP files (if applicable)
7. **Completion**: Show results and "Continue to next invoice" button

### Session Data Structure

```php
$_SESSION['harmonizedFlow'] = [
    'pdfFile', 'pdfPath', 'shopName', 'supplierName',
    'processDate', 'processDateFull', 'sequenceIdentifier',
    'step', 'startTime', 'shopConfig',
    'ocrJsonFile', 'ocrJsonPath',
    'ocrSanityFile', 'ocrSanityPath',
    'clFile', 'clPath',
    'pcFile', 'npFile' // if applicable
];
```

### Entry Point

Navigate to: `http://localhost/website/harmonizedFlow/start_harmonized_flow.php`

### Completion Screens

All completion screens now have a single "Continue to next invoice" button that redirects to the harmonized flow start page:

- `cl_complete.php` - CL stage complete without PC/NP
- `price_change_complete.php` - PC file generated
- `new_products_complete.php` - NP file generated (or no PC/NP)

### OCRjson Viewer Feature

**Location:** OCR Sanity verification screen (`OCRsanity/verify_ocrsanity.php`)

**Purpose:** Provides access to raw OCR data when supplier mapping doesn't match OpenAI response

**How it works:**
- "Check OCRjson file" button opens popup (75% screen size) with editable Handsontable
- Shows original OCRjson data structure: invoice metadata + table rows
- Backend: `get_ocrjson_data.php` loads from session (harmonized/original flow)
- Users can copy values manually when jsonToOcrSanity mapping is incorrect

### Cleanup Feature

**Location:** All completion screens (`commercialLayer/*_complete.php`)

**Purpose:** Organizes files after harmonized flow completion

**How it works:**
- Button: "Cleanup and continue to next invoice" (replaces old "Continue" button)
- Backend: `harmonizedFlow/cleanup_process.php` moves files:
  - PDF invoice: `preProcessDir/` → `uploads/`
  - OCRjson: `AIocr/ocr_extracted/` → `uploads/`
  - CL/PC/NP: Stay in `commercialLayer/commercial_invoice_files/`
- Results screen: `cleanup_results.php` shows moved files + staying files
- 2-second countdown, then redirects to `start_harmonized_flow.php`

### Resume from OCR Sanity File Feature

**Added:** December 16, 2025
**Status:** ✅ Complete - Resume Workflow Operational

This feature allows users to restart the harmonized flow from an existing OCR Sanity file, enabling testing, corrections, and data validation outside of Retailomatics.

**Use Cases:**
- Testing commercial layer with existing sanity files
- Correcting sanity data externally and re-processing
- Skipping OCR stage when sanity file already exists
- Debugging commercial layer without re-running OCR

**Workflow:**

1. **Selection Screen** (`start_harmonized_flow.php`)
   - Radio button: "New Invoice" or "Resume from Existing Sanity File"
   - When "Resume" selected:
     - Shop selection dropdown (required)
     - Sanity file list from `OCRsanity/sanity_files/`
     - Files sorted by modification time (newest first)
     - Format validation with ✅/⚠️ indicators

2. **File Format Validation**
   - Expected: `OCRsanity_XXXX_dd-mm-yyyy_Z_ddmmyyyy_hhmmss.xlsx`
   - Where:
     - `XXXX` = Supplier name (any letters/numbers)
     - `dd-mm-yyyy` = Process date
     - `Z` = Invoice identifier (A-Z)
     - `ddmmyyyy_hhmmss` = Timestamp (8 digits date + 6 digits time)
   - Example: `OCRsanity_Tayari_15-11-2025_A_15112025_143022.xlsx`

3. **PDF Selection** (`select_pdf_for_sanity.php`)
   - Directory browser starting at `uploads/`
   - Navigate parent/subdirectories
   - Recursive PDF scan (max depth 2)
   - Displays: file size, modification date, directory
   - Options: "Continue with Selected PDF" or "Skip PDF"
   - No naming convention enforced - any PDF file allowed

4. **Session Creation** (`resume_from_sanity.php`)
   - Parses sanity filename for metadata extraction
   - Creates `$_SESSION['harmonizedFlow']` with:
     - `shopName`, `supplierName`, `processDate`, `invoiceId`
     - `sanityFileName`, `sanityFilePath`
     - `pdfFile`, `pdfPath` (if selected)
     - `resumedFromFile: true` (flag for resume detection)
     - `currentStep: 'sanity_verify'`

5. **Flow Continuation**
   - Redirects to `step4_verify_ocr.php`
   - Detects `resumedFromFile` flag → skips OCR creation
   - Loads existing sanity file into verification screen
   - Continues to commercial layer after verification

**Files Involved:**
- `harmonizedFlow/start_harmonized_flow.php` - Selection interface
- `harmonizedFlow/resume_from_sanity.php` - Session handler
- `harmonizedFlow/select_pdf_for_sanity.php` - PDF browser
- `harmonizedFlow/process_pdf_selection.php` - PDF selection handler
- `harmonizedFlow/step4_verify_ocr.php` - Resume detection logic

**Session Key Standardization:**
- All harmonized flow files now use `$_SESSION['harmonizedFlow']` (capital F)
- Previously had inconsistency between `harmonized_flow` and `harmonizedFlow`
- Resume flow now matches normal flow session structure

---

### Recent Bug Fixes & Improvements

**Date:** December 16, 2025

#### 1. Session Key Inconsistency Fix

**Problem:** Resume flow used `$_SESSION['harmonized_flow']` (lowercase) while normal flow used `$_SESSION['harmonizedFlow']` (capital F), causing "Harmonized flow session not found" errors.

**Solution:** Standardized all session keys to `$_SESSION['harmonizedFlow']` across:
- `resume_from_sanity.php`
- `step4_verify_ocr.php`
- `select_pdf_for_sanity.php`
- `process_pdf_selection.php`
- `step5_commercial_layer.php`

**Impact:** Resume workflow now functions correctly end-to-end.

---

#### 2. File Lock Error Handling

**Problem:** When Excel files were open in Excel or another program, PhpSpreadsheet threw cryptic `Unable to identify a reader for this file` errors that didn't indicate the actual problem.

**Root Cause:** Windows file locking prevents reading files that are currently open in Excel or other applications.

**Solution:** Added try-catch blocks with user-friendly error messages for all `IOFactory::load()` calls in recently created files:

**Files Updated:**
- `commercialLayer/process_commercial_layer.php`
  - OCR Sanity file loading
  - Price List file loading
- `commercialLayer/generate_pc_np_files.php`
  - CL file loading
- `BOpackR/process_bopack_r.php`
  - CL file loading (Invoice List)
  - CL file loading (Invoice to Upload)
  - PC file loading
  - NP file loading

**Error Message Format:**
```
❌ Error: Unable to read [File Type] file

📄 File: filename.xlsx

⚠️ Possible causes:
• The file is currently open in Excel or another program
• The file is corrupted or not a valid Excel file
• The file is locked by another process

💡 Solution: Please close the file in Excel and try again.

🔧 Technical details: [Original PhpSpreadsheet error message]
```

**Impact:** Users now get clear, actionable error messages when files are locked, instead of confusing technical errors.

---

#### 3. Optional PDF Handling in Resume Flow

**Problem:** When resuming from sanity file and user skips PDF selection, `step5_commercial_layer.php` expected `pdfPath` and `pdfFile` to exist in session, causing errors.

**Solution:** Made PDF optional in `step5_commercial_layer.php`:
- Used null coalescing operator: `$invoicePdfPath = $flowData['pdfPath'] ?? null`
- Conditional file verification: only check if `$invoicePdfPath` is set
- Conditional `$_FILES['invoice']` setup:
  - If PDF exists: set `UPLOAD_ERR_OK` with valid file data
  - If no PDF: set `UPLOAD_ERR_NO_FILE` with empty data

**Impact:** Resume flow works correctly whether user selects a PDF or skips it.

---

#### 4. Sanity File Timestamp Regex Fix

**Problem:** Warning icons appeared for all sanity files in resume selection screen, even though filenames matched the documented format.

**Root Cause:** Regex pattern expected 6 digits for timestamp date (`\d{6}`) but actual format uses 8 digits (`\d{8}`).

**Incorrect Pattern:**
```php
preg_match('/^OCRsanity_(.+?)_(\d{2}-\d{2}-\d{4})_([A-Z])_(\d{6})_(\d{6})\.xlsx$/i', ...)
```

**Correct Pattern:**
```php
preg_match('/^OCRsanity_(.+?)_(\d{2}-\d{2}-\d{4})_([A-Z])_(\d{8})_(\d{6})\.xlsx$/i', ...)
```

**Files Fixed:**
- `harmonizedFlow/start_harmonized_flow.php`
- `harmonizedFlow/resume_from_sanity.php`

**Impact:** Sanity files now display ✅ status when format is valid.

---

#### 5. BOpack Empty Row Detection Fix

**Problem:** `new_products_list` generation included rows with only zeros (inconsistent empty row detection).

**Root Cause:** Empty row detection checked columns A-D, but actual NP data is in columns D, F, G, K. Also, columns E and G (supplier/department codes) with `origin=0` were being skipped instead of populated from source columns N and O.

**Solution in `process_bopack_r.php`:**
- Changed empty row detection to check key source columns: D (Item), F (Name), G (Cost), K (Sale Price)
- Added special handling for `origin=0` columns:
  - Column E (SupplierCode): populate from NP column N
  - Column G (DepartmentCode): populate from NP column O

**Impact:** New products list now generates correctly without empty zero rows, and supplier/department codes are properly populated.

---

#### 6. Price Change "Change Reason" Column

**Added:** Column R "Change reason" to Price Change files

**Values:**
1. `"Price increase. To meet LMB"` - Preliminary Price below Low Margin Border
2. `"Price decrease. To meet HMB"` - Preliminary Price above High Margin Border
3. `"Cost change. Within margin threshold"` - PP within acceptable range
4. `"No price change. Too close to LMB"` - Price change < PCPT, capped at LMB
5. `"No price change. Too close to HMB"` - Price change < PCPT, capped at HMB

**Terminology Fix:** Changed "Cost increase/decrease" to "Price increase/decrease" (customer-facing pricing, not supplier cost).

**Rec Mrgn Display:** Now calculated and displayed in ALL cases (including "NO. slim difference" recommendations).

**Files Updated:**
- `commercialLayer/generate_pc_np_files.php`

---

#### 7. New Products List Column Fixes

**Column J Header:** Changed from "שם מחלקה" (Department Name) to "שם ספק" (Supplier Name)

**Column K Added:** "שם מחלקה" (Department Name) - maps from NP column I

**Files Updated:**
- `configDir/shops_V2.json`

---

---

#### 8. Commercial Layer & BO Pack Bug Fixes (December 18, 2025)

**File Locking & Error Handling:**
- Added try-catch error handling for Excel file save operations (BOpackR)
- Added safe file deletion with error handling before overwriting files
- User-friendly error messages when files are open or locked

**Missing Cost Price Handling:**
- "No Cost in PL" rows now properly included in PC files with orange highlighting
- Skips margin calculations and marks as "NO - Missing Cost"
- Department margin recommendations now apply before missing cost checks

**Visual Highlighting Improvements:**
- Thick borders on barcode cells with "Not Found" status for easy identification
- Border highlights now persist and refresh correctly after data retrieval
- Created helper endpoint `get_cell_styles.php` for AJAX style reloading

**UX Enhancements:**
- Converted CHP search forms to AJAX (no page reload)
- Converted recalculate margins to AJAX (preserves page state)
- LineTotal Diff Check feature added to OCR Sanity verification (color-coded discrepancy detection)

**Data Integrity:**
- DepartmentCode (column O) auto-fills in New Products based on DepartmentName
- Fixed CountryMZ shop column mappings in shops_V2.json
- Fixed barcode border clearing/reapplication logic in retrieve flow

**Files Updated:**
- `BOpackR/process_bopack_r.php`
- `OCRsanity/verify_ocrsanity.php`
- `commercialLayer/generate_pc_np_files.php`
- `commercialLayer/process_commercial_layer.php`
- `commercialLayer/retrieve_commercial_layer.php`
- `commercialLayer/verify_commercial_layer.php`
- `commercialLayer/verify_new_products.php`
- `commercialLayer/verify_price_changes.php`
- `commercialLayer/get_cell_styles.php` (NEW)
- `configDir/shops_V2.json`

---

---

#### 9. AI Enhancer (AIE) - Experimental LLM OCR Testing Tool (December 28, 2025)

**Purpose:**
A completely separate experimental testing tool for improving OCR accuracy using OpenAI's GPT models. Allows rapid iteration on prompts, models, and approaches without affecting the production harmonized OCR process.

**Key Features:**

**Complete Isolation:**
- Separate directory: `AIocr/AIE/`
- Own configuration: `AIE_config.json` (editable prompts & model settings)
- Separate data directories:
  - `invoice_pdf/` - Test PDF invoices
  - `crops/` - PNG crop files
  - `ocr_extracted/` - JSON output files
- All AIE directories added to `.gitignore` (lines 5-7)
- Changes to AIE do NOT affect production harmonized process

**Two Input Methods:**

1. **PDF Upload & Crop:**
   - Upload PDF or select from existing `invoice_pdf/` directory
   - Interactive crop viewer (`aie_crop_viewer.html`) - draw rectangles on PDF
   - Auto-labeled crops: InvoiceNo, Date, Table1-TableN, Total
   - Saves crops to `AIE/crops/` directory

2. **Pre-Cropped PNG Upload:**
   - Upload ready-made PNG crop files (minimum 4 files)
   - Files must contain keywords: InvoiceNo, Date, Table1, Total
   - Selected files displayed in blue info box with filenames list
   - Example naming: `Osem_22-12-25_A_InvoiceNo.png`

**Processing Features:**

**Unified Workflow:**
- Both PDF and PNG methods redirect to same processing page
- Identical UX and results display
- Loading screen with animated spinner and live timer
- Shows progress while waiting for OpenAI response (20-30 seconds)

**Comprehensive Metrics Display:**
- Total Processing Time
- OpenAI Response Time
- Model Used (configurable in AIE_config.json)
- Crops Processed count
- Tables Found count
- **Prompt Tokens** (sent to OpenAI)
- **Completion Tokens** (received from OpenAI)
- **Total Tokens** (for cost tracking)
- API errors/warnings section (if any)

**Output:**
- JSON files saved to: `AIE/ocr_extracted/`
- Filename format: `AIE_OCRjson_{basename}_{timestamp}.json`
  - Example: `AIE_OCRjson_Osem_22-12-25_A_27122025_143022.json`
- **Hebrew text properly displayed** using `JSON_UNESCAPED_UNICODE` flag
- Downloadable JSON with download button
- Pretty-printed JSON displayed on screen

**Configuration (`AIE_config.json`):**
```json
{
  "model_settings": {
    "model": "gpt-4.1-mini",
    "max_tokens": 16000,
    "temperature": 0,
    "top_p": 1.0,
    "store": true,
    "response_format": {"type": "json_object"}
  },
  "prompt_config": {
    "system_prompt_part1": "...",
    "system_prompt_table_single": "...",
    "system_prompt_table_multiple_prefix": "...",
    "system_prompt_table_multiple_suffix": "...",
    "system_prompt_part2": "..."
  }
}
```

**Technical Implementation:**

**File Structure:**
- `enhancer_ai.php` - Main interface with dual input modes
- `crop_tool.php` - PDF cropping wrapper page
- `aie_crop_viewer.html` - Interactive crop drawing tool
- `save_crops_aie.php` - Crop save handler for PDF workflow
- `process_crops_from_pdf.php` - Unified processing & results page
- `process_with_openai.php` - Legacy reference (no longer used)
- `AIE_config.json` - Editable configuration file
- `README.md` - AIE documentation

**Key Technical Features:**
- **Header redirect fix**: PNG upload handling moved before HTML output (lines 18-75 in enhancer_ai.php)
- **PostMessage API**: Iframe-to-parent communication for loading overlay
- **Session management**: Stores crop files, names, and basename between pages
- **Loading overlay**: JavaScript timer with animated spinner and dots
- **File validation**: Checks for minimum 4 PNG files with required keywords
- **Token extraction**: Parses OpenAI response for usage metrics
- **API timing**: Measures total processing time and OpenAI response time
- **Error handling**: Displays API errors in dedicated warning section

**Usage Workflow:**

**Testing New Prompts:**
1. Edit `AIE_config.json` with new prompt/model settings
2. Process test invoice through AIE (PDF or PNG method)
3. Review JSON output and metrics
4. Check token usage and response time
5. Iterate on prompt until satisfied
6. **Then** implement changes in production harmonized process

**File Naming Conventions:**

**PDF Files (Recommended):**
- Format: `XXXX dd-mm-yy Z.pdf`
- Example: `Osem 22-12-25 A.pdf`
- Naming preserved in all generated files

**PNG Crop Files:**
- Must contain: InvoiceNo, Date, Table1, Total
- Recommended: `XXXX_dd-mm-yy_Z_InvoiceNo.png`
- Minimum: `anything_InvoiceNo.png`

**Use Cases:**
- Test new prompt strategies for better OCR accuracy
- Experiment with different OpenAI models (GPT-4, GPT-4-mini, etc.)
- Debug OCR accuracy issues with specific suppliers
- Compare results with different configurations
- Test table extraction improvements for multi-page invoices
- Measure token costs before production deployment

**Important Notes:**
- Uses same OpenAI API key from `AIocr/config.json`
- No supplier configuration required (testing only)
- All test data ignored by Git
- Completely separate from production harmonized process
- Perfect for rapid prompt iteration and experimentation

**Files Updated:**
- `AIocr/AIE/enhancer_ai.php` (main interface)
- `AIocr/AIE/crop_tool.php` (PDF wrapper)
- `AIocr/AIE/aie_crop_viewer.html` (crop viewer)
- `AIocr/AIE/save_crops_aie.php` (crop saver)
- `AIocr/AIE/process_crops_from_pdf.php` (unified processor)
- `AIocr/AIE/README.md` (documentation)
- `.gitignore` (added AIE directories)

---

## PDF Rectangle Crop Viewer Enhancements (January 26, 2026)

Added zoom in/out buttons (25%-400% range) to the PDF crop viewer. Fixed mouse coordinate mapping for all rotation orientations (0°, 90°, 180°, 270°) so rectangles draw exactly where the mouse pointer is positioned.

**Files Updated:** `AIocr/multi_rectangle_crop_viewer.html`

---

## Commercial Layer - ERP Barcode Replacement (January 26, 2026)

Added configurable `ReplaceBarcodeToERP` parameter in shops_V2.json. When set to "YES", short invoice barcodes are automatically replaced with full ERP barcodes from the price list (when matched via suffix rules), and the cell is highlighted with light brown background (#D2B48C).

**Files Updated:** `configDir/shops_V2.json`, `commercialLayer/process_commercial_layer.php`, `commercialLayer/retrieve_commercial_layer.php`

---

**Last Updated:** January 26, 2026
**Status:** ✅ Phase 0 Complete - PreProcess2 Ready | ✅ Phase 1 Complete - OCR Sanity Ready | ✅ Phase 2 Complete - Commercial Layer Ready | ✅ Harmonized Flow Complete | ✅ Resume from Sanity Feature Complete | ✅ AI Enhancer Tool Ready

---

## TOE Module - Tesseract OCR Environment (February–March 2026)

Experimental module integrating Tesseract OCR in a separate environment alongside the existing AIE environment. Implemented in two phases (Feb 26, Mar 2). Considered experimental — not part of the main production flow.

---

## New Modules: NP Barcode Reader, CHP Fetch, NP Classifier (March 2026)

Three new modules added to support the New Products operation:

- **NP Barcode Reader** (`NPbarcode/`) — reads barcodes from new product images.
- **CHP Fetch** (`CHPfetch/`) — fetches product data from an external source based on barcode.
- **NP Classifier** (`NPclassify/`) — classifies new products into departments/categories.

These modules work together as a pipeline in the NP workflow. Tmp directories for all three are excluded from git via inner `.gitignore` files.

---

## New Products (NP) Workflow (March–April 2026)

Full workflow built out for managing new product intake:

### Directory Structure
Each NP operation creates a dated directory under the customer's archive:
```
{CustomerRoot}/NewProducts/{YYYY}/{MM_MonthName}/{DD-MM-YY NP}/
```
A pre-formatted Excel file (`new_products_list_{date}_to_upload.xlsx`) with Hebrew headers is auto-created in the daily directory.

### Scripts
- **`open_new_NP_directory.ps1`** — original script for creating the NP directory.
- **`open_new_NP_directory_V2.ps1`** — improved version with a GUI form (customer dropdown + date picker), auto-creates the full directory tree and Excel file, opens the folder in Explorer, and pins it to Windows Quick Access.

### NP Directory for Employee
A dedicated NP directory version was created for Michael (employee 1) — March 23, 2026.

### Root Path
All customer archives are stored under:
`Z:\RetailomaticsCloud\RetailomaticsArchive\`

---

## JSON Column Auto-Detection Module (April 2026)

### Overview
Because OpenAI returns invoice table columns in a non-consistent order between runs, a heuristic auto-detection module was built to identify columns by their data characteristics rather than fixed position numbers.

**File:** `OCRsanity/detect_json_mapping.php`  
**Entry point:** `detectJsonMapping(array $tableData, string $sanityMethod, array &$notices, ?array $fallbackMapping): array`

### Toggle per Supplier
Controlled by `"autoDetectMapping"` in `suppliers.json`:
- Omit the field or set `true` → auto-detect enabled (default)
- Set `false` → use only the manual `jsonToOcrSanity` mapping

### Detection Steps (all steps always run; each failure is noted independently)

| Step | Field(s) | Method |
|------|----------|--------|
| 1 | ItemName | Unicode letter fraction × avg string length. Column with ≥30% letter content and highest score wins. |
| 2 | Barcode | Longest pure-digit run per value (strips surrounding `*` or other OCR artifacts). Requires 6–14 digit run, ≥30% row hit-rate. Longest average run wins when multiple columns qualify. |
| 3 | Discount1/2 | Only for Discount1/Discount2 methods. Score = 0.5×(zero fraction) + 0.3×(0–100 range fraction) + 0.2×(% sign fraction). |
| 4 | Qty, UnitPrice (Simple) | Most integer-like column = Qty; next numeric column = UnitPrice (optional). |
| 5 | Qty, UnitPrice, LineTotal | Tries all column permutations for `LineTotal ≈ Qty × UnitPrice` (20% tolerance). Pre-filters columns with <20% decimal-point values as code/sequence columns. After finding the triple, disambiguates Qty vs UnitPrice: more integer-like column = Qty; if both ≤50% integer-like, smaller mean = Qty. |

### Partial Fallback
When a step fails, the module falls back to the corresponding field in the supplier's `jsonToOcrSanity` mapping (if configured) **for that field only**. Auto-detected fields from earlier steps are kept. This means:
- A supplier with no `jsonToOcrSanity` at all will get a fully auto-detected mapping (or fail if detection cannot complete).
- A supplier with a partial or full `jsonToOcrSanity` gets the best of both: auto where possible, fallback only where needed.

### Popup Notification
After processing, `verify_ocrsanity.php` shows a popup listing the result of each field:
- `✅ ItemName: auto-detected (column 3)`
- `⚠️ Barcode: not auto-detected — using fallback (column 1)`
- `❌ Qty: not auto-detected and no fallback configured`

### Key Fixes Applied During Development
- **Barcode with `*` delimiters** (`*5588983*`): uses longest digit-run extraction, not full-value match.
- **Hebrew unit markers** (`"20.00 יח'"`, `"12.00 ש"ח"`): changed `is_numeric()` gate to regex `preg_match('/^-?\d/', ...)` — PHP's `(float)` cast already handles trailing Hebrew text correctly in the multiplicative check.
- **Qty vs UnitPrice swap**: added post-triple disambiguation by integer-like fraction before the final field assignment.

---

**Last Updated:** April 27, 2026
**Status:** ✅ Phase 0 Complete - PreProcess2 Ready | ✅ Phase 1 Complete - OCR Sanity Ready | ✅ Phase 2 Complete - Commercial Layer Ready | ✅ Harmonized Flow Complete | ✅ Resume from Sanity Feature Complete | ✅ AI Enhancer Tool Ready | ✅ NP Workflow Ready | ✅ JSON Column Auto-Detection Ready
**Next Phase:** PDF Split & JPG Conversion, then ERP integration, database migration, reporting dashboard
