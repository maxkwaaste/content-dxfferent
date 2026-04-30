# Phase 2 Implementation Plan — content.dxfferent.com

**Source spec:** `~/ClaudeCode/claude-output/2026-04-30-content-platform-phase-2-decisions.html` (CLAUDE-DATA JSON)
**Stack:** SQLite + PHP REST + vanilla JS (single `index.html` + single `api.php`). No build tools, no node_modules.
**Local dev:** `cd ~/ClaudeCode/content-platform && php -S localhost:8888`
**Deploy:** `scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 <file> u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/<file>`
**Cache bust:** `open https://content.dxfferent.com/?v=$(date +%s)`

## Constraints (do not violate)

- Frontend stays a single HTML file. All CSS inline. All JS inline. Do not split.
- API stays a single PHP file. Do not split into routers/controllers.
- `seed.json` is reference data — **do not edit**. Schema changes happen at runtime via the existing PRAGMA-driven migration pattern in `api.php` (see lines 254-266).
- 5 consultants are using the live app. Each slice must keep existing flows working.
- Audit log is visible to everyone (per spec decision). No hidden fields.

## Pre-flight (run before slice 1)

1. **Backup live DB.** SSH to SiteGround and copy `~/www/content.dxfferent.com/public_html/data/content.db` → `content.db.bak.YYYYMMDD`. Verify backup file size > 0.
2. **Backup local DB.** `cp ~/ClaudeCode/content-platform/data/content.db ~/ClaudeCode/content-platform/data/content.db.bak.$(date +%Y%m%d)`
3. **Confirm level names from public matrix.** WebFetch `https://dxfferent.com/services/guide-as-a-service/the-msp-journey/` and extract the canonical 5 level names (spec only specifies endpoints "Reactive" and "Success"). Save the 5 names verbatim into `data/journey-levels.json` as `[{"id":1,"name":"Reactive"},…,{"id":5,"name":"Success"}]`. If WebFetch fails, open the URL in Chrome via `mcp__claude-in-chrome__navigate` then `read_page`. **Do not invent level names.**
4. **Snapshot current schema for diff.** `sqlite3 data/content.db ".schema" > /tmp/schema-before.sql`

---

## Slice 1 — Schema migration + AI domain/level mapping

**Goal:** All 64 topics carry a numeric `level` (1-5) and `priority` (2-5). New tables `audit_log` + `revisions` exist. `derivatives` carries perf-metric columns. No user-visible UI change yet — but the data foundation for slices 2-5 is in place.

**Files touched:** `api.php` (migration block + new endpoints), one-shot script `scripts/ai-map-topics.php` (NEW).

### Steps

1. **Add migration block in `api.php` after line 266** (existing migration pattern). Use PRAGMA-driven idempotent ALTER:

   ```php
   // topics: add level + priority (nullable, populated by ai-map script)
   $topicCols = array_column($db->query("PRAGMA table_info(topics)")->fetchAll(), 'name');
   if (!in_array('level', $topicCols)) $db->exec('ALTER TABLE topics ADD COLUMN level INTEGER');
   if (!in_array('priority', $topicCols)) $db->exec('ALTER TABLE topics ADD COLUMN priority INTEGER');

   // submissions: add content_type (used by editor type-tabs in slice 3)
   $subCols = array_column($db->query("PRAGMA table_info(submissions)")->fetchAll(), 'name');
   if (!in_array('content_type', $subCols)) $db->exec('ALTER TABLE submissions ADD COLUMN content_type TEXT');

   // derivatives: rename format → type, add live perf-metric columns
   $derCols = array_column($db->query("PRAGMA table_info(derivatives)")->fetchAll(), 'name');
   if (in_array('format', $derCols) && !in_array('type', $derCols)) {
       $db->exec('ALTER TABLE derivatives RENAME COLUMN format TO type'); // SQLite >= 3.25
   }
   foreach (['live_url'=>'TEXT','likes'=>'INTEGER','views'=>'INTEGER','der_comments'=>'INTEGER','last_updated'=>'DATETIME','scheduled_for'=>'DATETIME'] as $col=>$type) {
       if (!in_array($col, $derCols)) $db->exec("ALTER TABLE derivatives ADD COLUMN $col $type");
   }
   // Note: column is `der_comments` not `comments` to avoid collision when joined with calendar.

   // New tables
   $db->exec('CREATE TABLE IF NOT EXISTS audit_log (
       id INTEGER PRIMARY KEY,
       actor TEXT NOT NULL,
       action TEXT NOT NULL,
       target_type TEXT NOT NULL,
       target_id INTEGER NOT NULL,
       before_json TEXT,
       after_json TEXT,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP
   )');
   $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_target ON audit_log(target_type, target_id)');
   $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC)');

   $db->exec('CREATE TABLE IF NOT EXISTS revisions (
       id INTEGER PRIMARY KEY,
       target_type TEXT NOT NULL,
       target_id INTEGER NOT NULL,
       snapshot_json TEXT NOT NULL,
       created_by TEXT,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP
   )');
   $db->exec('CREATE INDEX IF NOT EXISTS idx_revisions_target ON revisions(target_type, target_id, created_at DESC)');
   ```

2. **Apply locked domain rename in `api.php` migration block.** Single UPDATE per old→new value:

   ```php
   $needsDomainRename = (int)$db->query("SELECT COUNT(*) FROM topics WHERE domain IN ('Data Driven','Algemeen','Markt')")->fetchColumn() > 0;
   if ($needsDomainRename) {
       $db->exec("UPDATE topics SET domain = 'Data driven' WHERE domain = 'Data Driven'");
       $db->exec("UPDATE topics SET domain = 'Identity'     WHERE domain = 'Algemeen'");
       $db->exec("UPDATE topics SET domain = 'Positioning'  WHERE domain = 'Markt'");
       // Technology + Organisation already match target casing.
   }
   ```

3. **Apply category→priority defaults in migration block.** One-shot, only when `priority IS NULL`:

   ```php
   $needsPriority = (int)$db->query("SELECT COUNT(*) FROM topics WHERE priority IS NULL")->fetchColumn() > 0;
   if ($needsPriority) {
       $db->exec("UPDATE topics SET priority = 5 WHERE priority IS NULL AND category IN ('bewijs','educatie')");
       $db->exec("UPDATE topics SET priority = 3 WHERE priority IS NULL AND category IN ('markt','contrair')");
       $db->exec("UPDATE topics SET priority = 2 WHERE priority IS NULL AND category IN ('eigen','tools')");
   }
   ```

4. **Create `scripts/ai-map-topics.php` (NEW).** Standalone CLI script the agent runs **once locally**. Reads each topic's `title + hook + context` JSON, calls Claude (or — agent's choice — uses inline heuristic) to assign `level` 1-5 based on the public matrix maturity model. Writes back with `UPDATE topics SET level = ? WHERE id = ?`. Also writes a CSV `data/level-mapping.csv` (id, title, category, domain, assigned_level, rationale) for review.

   Implementation note: agent runs the script via `php scripts/ai-map-topics.php`. The script connects to local SQLite (`data/content.db`), iterates topics, prints progress. **Required output**: every topic ends with non-null `level` between 1 and 5.

5. **Add new GET endpoints in `api.php`** (insert in the existing `case 'topics'` switch block around line 303 and add new `case 'audit-log'` and `case 'revisions'`):

   - `GET /api/topics?domain=X&level=N` — extend existing topics endpoint to accept `level` filter alongside existing `domain`/`category`/`status`.
   - `GET /api/topics/matrix` — returns `[{domain, level, count, max_priority, topic_ids:[...]}, ...]` for all 8×5=40 cells (including empty ones with count=0). This is the matrix tab's only backend call.
   - `GET /api/audit-log?target_type=X&target_id=N&limit=50` — list audit entries.
   - `POST /api/audit-log` — internal helper used by mutations (not called from front-end). Accepts `{actor, action, target_type, target_id, before_json, after_json}`.
   - `POST /api/revisions` — create snapshot. Accepts `{target_type, target_id, snapshot_json, created_by}`.
   - `POST /api/revisions/:id/restore` — apply snapshot back to its target_type/target_id table. For now only support `target_type='topic'` (used by slice 5 undo).

6. **Define the 8 canonical domains as an array constant in `api.php`** so endpoints can return cells for empty domains too:

   ```php
   const JOURNEY_DOMAINS = ['Identity','Organisation','GRC','Positioning','Portfolio','Technology','Blueprint','Data driven'];
   ```

### Verification (all must pass)

```bash
# 1. Migration runs idempotent
cd ~/ClaudeCode/content-platform
rm -f data/content.db   # force fresh seed
php -S localhost:8888 &
SERVER=$!; sleep 1
curl -s http://localhost:8888/api/topics | python3 -m json.tool > /dev/null && echo "API OK"
kill $SERVER

# 2. Schema correctness
sqlite3 data/content.db "PRAGMA table_info(topics);"   | grep -E 'level|priority' | wc -l   # expect 2
sqlite3 data/content.db "PRAGMA table_info(submissions);" | grep content_type | wc -l       # expect 1
sqlite3 data/content.db "PRAGMA table_info(derivatives);" | grep -E 'type|live_url|likes|views|der_comments|last_updated|scheduled_for' | wc -l  # expect 7
sqlite3 data/content.db ".schema audit_log" | grep 'CREATE TABLE' | wc -l                   # expect 1
sqlite3 data/content.db ".schema revisions" | grep 'CREATE TABLE' | wc -l                   # expect 1

# 3. Domain rename complete
sqlite3 data/content.db "SELECT DISTINCT domain FROM topics ORDER BY domain;"
# expect: Data driven, Identity, Organisation, Positioning, Technology  (no 'Data Driven', 'Algemeen', 'Markt')

# 4. Priority distribution matches spec rule
sqlite3 data/content.db "SELECT category, priority, COUNT(*) FROM topics GROUP BY category, priority ORDER BY category;"
# expect: bewijs|5|12, contrair|3|15, educatie|5|15, eigen|2|7, markt|3|9, tools|2|6

# 5. AI level mapping ran
php scripts/ai-map-topics.php
sqlite3 data/content.db "SELECT level, COUNT(*) FROM topics GROUP BY level ORDER BY level;"
# expect: every row has level NOT NULL, 5 distinct levels (1-5)
sqlite3 data/content.db "SELECT COUNT(*) FROM topics WHERE level IS NULL OR level < 1 OR level > 5;"  # expect 0

# 6. Matrix endpoint
curl -s 'http://localhost:8888/api/topics/matrix' | python3 -c "import json,sys;d=json.load(sys.stdin);print('cells:',len(d),'with topics:',sum(1 for c in d if c['count']>0))"
# expect: cells: 40, with topics: ~25 (5 active domains × ~5 levels covered)

# 7. Existing flows still work
curl -s http://localhost:8888/api/topics | python3 -c "import json,sys;d=json.load(sys.stdin);print(len(d))"  # expect 64
curl -s http://localhost:8888/api/dashboard | python3 -m json.tool > /dev/null && echo "dashboard OK"
curl -s 'http://localhost:8888/api/derivatives/tree?topic_id=1' | python3 -m json.tool > /dev/null && echo "derivatives tree OK"
```

### Out of scope for this slice
- UI changes (no new tabs, no editor changes).
- Manual override of level/priority via UI (Phase 3).
- Deploy to live — keep local until end of slice. **Skip deploy** until at least slice 2 verified.

---

## Slice 2 — Matrix tab (8×5 grid, primary library navigator)

**Goal:** New "Matrix" tab is the default landing tab. It renders an 8-domain × 5-level grid. **Every cell** carries an orange shade encoding strategic interestingness (5 = hottest writing opportunity, 1 = lowest pull). The numeral inside each cell shows topic count for that domain×level combination. No grey cells, no hook text inside cells, no "P5" labels — just colour + numeral. Click on a cell opens the existing Board view filtered to those topics.

**Design source of truth:** `docs/design-mockups/matrix-tab.html` (Claude Design export, approved 2026-04-30). Copy its markup structure, CSS variables, and selectors **verbatim** — do not rewrite from scratch. Adapt class names where needed to avoid collisions with the existing single-page CSS in `index.html`.

**Files touched:** `index.html` only.

### Steps

1. **Read the design mockup first.** Open `docs/design-mockups/matrix-tab.html` and study the entire file. Note the exact CSS variables (`--i1` … `--i5`), the grid structure (`grid-template-columns: 140px repeat(8, 1fr); grid-template-rows: 56px repeat(5, 140px)`), the row order (Success at top → Reactive at bottom, levels 5→1), and the count numeral styling (Archivo Black 64px, dark `#1F1F1F` on `.i1`/`.i2` for contrast, white on `.i3`/`.i4`/`.i5`). The implementation must visually match this mockup.

2. **Add Matrix tab button in `.tab-nav` block (`index.html` line 1086).** Insert as first tab so it becomes default landing:
   ```html
   <button class="tab-btn active" onclick="switchTab('matrix')">Matrix</button>
   <button class="tab-btn" onclick="switchTab('board')">Board</button>
   <!-- existing Dashboard/Kalender follow -->
   ```
   Remove `active` from the previous Board button.

3. **Add the matrix CSS to the inline `<style>` block.** Copy the `--i1` … `--i5` custom properties to the existing `:root` block. Copy the `.matrix`, `.matrixwrap`, `.col-head`, `.row-head`, `.corner`, `.cell`, `.count`, `.legend`, `.statrow`, `.statcard`, `.titlestrip`, `.titlewrap` rules from the mockup. **Prefix collision-prone selectors** (e.g. `.cell` is generic — rename to `.matrix-cell`, then update mockup HTML accordingly) so existing Board/Dashboard/Kalender CSS is unaffected. Verify by grep'ing for selector reuse: `grep -E '^  \.(cell|count|matrix|tab|corner)' index.html` — any existing rule with the same name needs disambiguation.

4. **Add `<section id="matrixView" class="view active">` markup** before `#boardView` in `index.html`. Use the markup from `docs/design-mockups/matrix-tab.html` between `<!-- TITLE STRIP -->` and the closing `</section>` after `<!-- LEGEND -->`. Wrap the whole thing in `<section id="matrixView" class="view active">…</section>` so the existing `switchTab()` show/hide logic (line 1359) drives it.

5. **Add the hardcoded intensity grid as a JS const** in the inline `<script>` block (per spec decision: "Hardcoded fake in front-end"):
   ```js
   const JOURNEY_DOMAINS = ['Identity','Organisation','GRC','Positioning','Portfolio','Technology','Blueprint','Data driven'];
   // Strategic interestingness 1-5 (5=hottest, 1=lowest pull). Rows = level 5..1 top→bottom.
   const MATRIX_INTENSITY = {
     // [Identity, Organisation, GRC, Positioning, Portfolio, Technology, Blueprint, Data driven]
     5: [2, 2, 1, 3, 2, 3, 1, 3],   // Success
     4: [3, 3, 2, 3, 3, 4, 2, 4],   // Strategic
     3: [4, 3, 2, 4, 3, 5, 3, 5],   // Proactive
     2: [5, 4, 3, 5, 3, 5, 3, 5],   // Stable
     1: [5, 4, 4, 5, 4, 5, 4, 5],   // Reactive
   };
   ```
   This grid mirrors the mockup. It is the strategic call from Max — do not regenerate it from `topics.priority` (which is the per-topic category-default, not the per-cell strategic value).

6. **Add `loadMatrix()` function in inline `<script>`.** Called from `switchTab('matrix')` and on initial load:
   - `fetch('/api/topics/matrix').then(r=>r.json())` — returns `[{domain, level, count, topic_ids}, …]` for all 40 cells.
   - Render rows top-to-bottom in level order **5, 4, 3, 2, 1** (Success first). For each cell, find matching API row by `(domain, level)`, apply class `i${MATRIX_INTENSITY[level][domainIndex]}` and inject `<div class="count">${row.count}</div>`. Always render the numeral, including `0`.
   - On cell click: `localStorage.setItem('boardFilter', JSON.stringify({domain, level})); switchTab('board')`. Then extend `loadBoard()` to read this entry, apply filter, then clear the localStorage entry.

7. **Add level row headers.** Use the canonical 5 names captured during pre-flight in `data/journey-levels.json` (Reactive…Success or whatever the public matrix uses). The mockup shows them as `<div class="lvlnum">N</div><div class="lvlname">NAME</div>` per row. Fetch the JSON once on init and inject the names; the numerals 5..1 are static.

8. **Update `switchTab(tab)` function (line 1359)** to support `'matrix'` as a valid tab id, and change the bootstrap call at line 1578 from `switchTab('board')` to `switchTab('matrix')` so Matrix is the default landing.

9. **Stat card row in the title strip.** The mockup shows three stat cards (Topics / Cells covered / Hottest gap). Compute live values:
   - **Topics**: total topics from `/api/topics` length (or use existing `/api/stats`).
   - **Cells covered**: number of API rows where `count > 0`, formatted as `N / 40`.
   - **Hottest gap**: the cell with the highest `MATRIX_INTENSITY` value AND `count == 0`. Tie-break by domain order. Format as `${domain} × ${levelName}`. If no gaps (everything ≥ 1 written), hide the accent card.

### Verification

```bash
# 1. Endpoint contract
curl -s 'http://localhost:8888/api/topics/matrix' | python3 -c "
import json,sys
d=json.load(sys.stdin)
assert len(d)==40, f'expected 40 cells, got {len(d)}'
assert all('domain' in c and 'level' in c and 'count' in c and 'max_priority' in c for c in d), 'missing keys'
print('OK',len(d),'cells')
"

# 2. Visual parity with the design mockup
# Open docs/design-mockups/matrix-tab.html in Chrome (reference) AND http://localhost:8888/?v=$(date +%s) (implementation), side by side.
# Confirm the implementation matches the mockup on:
#  - Matrix tab is active by default (Matrix is the landing tab, not Board)
#  - Grid shows 8 columns labelled Identity..Data driven (left to right)
#  - Grid shows 5 rows in level order top→bottom: 5 Success, 4 Strategic, 3 Proactive, 2 Stable, 1 Reactive
#  - EVERY cell has an orange shade (no grey cells anywhere — failing this means MATRIX_INTENSITY is misapplied)
#  - Every cell shows a numeral (including "0" for empty cells)
#  - Numerals are dark (#1F1F1F) on .i1 and .i2 cells, white on .i3/.i4/.i5
#  - Hover on a populated cell shows a 1px brand-red outline (white outline on .i5 for contrast)
#  - Click on any cell switches to Board filtered to that (domain, level) pair, regardless of count

# 3. JS console clean
# Use mcp__claude-in-chrome__read_console_messages with pattern "error" — expect zero matches.

# 3. Existing flows still work
# Open Dashboard tab + Kalender tab — confirm no JS console errors and same data renders.
```

### Out of scope
- Click-to-create-new-topic on empty cells (Phase 3).
- Drag-rearrangement of topics across cells.
- Filtering matrix by category.

---

## Slice 3 — Editor type-tabs + hook picker

**Goal:** When editing a topic, a horizontal tab bar at the top of the editor lets the user choose which content_type to compose for (post, article, blog, video, case-study, poll). The opener field has a picker showing the 40 hook templates filtered by the current type. Switching tabs swaps the active submission for that topic+type pair without losing the topic context. Matrix-cell clicks (slice 2) flow into this editor with `domain+level+type` prefilled.

**Files touched:** `api.php` (extend submissions endpoint + new hooks endpoint), `index.html`, NEW `data/hook-templates.json`.

### Steps

1. **Generate `data/hook-templates.json`.** One-shot script `scripts/extract-hooks.php` (NEW) reads `seed.json`, dedupes the 40 unique hooks, and for each hook writes `{text, source_categories: [list], applicable_types: [list]}`. The `applicable_types` field is filled by an inline classifier (heuristic OR a Claude pass — agent's choice; rules of thumb: hooks containing "case study" → ['blog','case-study','article']; "Hoe X bereikte Y" → ['post','article','blog']; "Stelling:" or contrarian → ['post','poll']; "Ik analyseerde" → ['blog','article']; data-quote/factoid hooks → ['post','poll']; default any-text → ['post','article','blog']). Output: 40 hooks each with non-empty `applicable_types`. Commit this file to git.

2. **Add `GET /api/hooks` endpoint in `api.php`.** Returns the JSON file contents (read once, cache in static var). Optional `?type=post` filter.

3. **Extend `case 'submissions'`** (api.php line 362) to accept and persist `content_type` on POST and PATCH. Validate against the 6-type allowlist `['post','article','blog','video','case-study','poll']`. Reject other values with 400.

4. **Add type-tab bar to editor markup in `index.html`.** Above the existing author + content textarea, add:
   ```html
   <div class="type-tabs">
     <button class="type-tab active" data-type="post">Post</button>
     <button class="type-tab" data-type="article">Article</button>
     <!-- blog, video, case-study, poll -->
   </div>
   ```

5. **Replace title/hook display with new "Opener" field + picker.** The existing topic view shows `topic.title` and `topic.hook` as static text. Replace with:
   - Static label: domain + level + topic id (read-only context strip)
   - Opener input (free text, single line)
   - "Use template" button → opens dropdown of hooks filtered by current type-tab's `applicable_types`. Click hook → fills opener field.

6. **Wire type-tab switch logic.** On click:
   - Save current submission via existing auto-save flow.
   - Fetch submission for `(topic_id, content_type=newType)` via `GET /api/submissions?topic_id=X&content_type=Y`. If none exists, blank state (creates on first save).
   - Refresh hook picker to filter hooks for new type.
   - Active type stored on `editorState.contentType` so save calls include it.

7. **Read matrix prefill on editor open.** In the editor's init function, check `localStorage.getItem('editorPrefill')` (set by matrix click in slice 2 — extend the matrix click handler in slice 2 to also write this). If present, parse `{domain, level, content_type}`, apply: switch type-tab to `content_type`, filter the topic-list to that domain+level. Clear the localStorage entry after applying.

8. **Update `loadBoard()` to honor `level` filter** from `boardFilter` localStorage entry written by slice 2.

### Verification

```bash
# 1. Hook templates file
python3 -c "
import json
d = json.load(open('data/hook-templates.json'))
assert len(d) == 40, f'expected 40 hooks, got {len(d)}'
assert all(h.get('applicable_types') for h in d), 'every hook needs applicable_types'
all_types = set(t for h in d for t in h['applicable_types'])
assert all_types <= {'post','article','blog','video','case-study','poll'}, f'unknown types: {all_types}'
print('OK', len(d), 'hooks,', len(all_types), 'distinct types')
"

# 2. API contract
curl -s 'http://localhost:8888/api/hooks?type=post' | python3 -c "
import json,sys
d=json.load(sys.stdin)
assert all('post' in h['applicable_types'] for h in d), 'filter broken'
print('post hooks:', len(d))
"

# 3. Submission round-trip with content_type
SUB_ID=$(curl -s -X POST http://localhost:8888/api/submissions -H 'Content-Type: application/json' \
  -d '{"topic_id":1,"author":"Joshua","raw_content":"test","content_type":"article"}' | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
curl -s "http://localhost:8888/api/submissions/$SUB_ID" | python3 -c "import json,sys;assert json.load(sys.stdin)['content_type']=='article';print('OK')"

# 4. Reject invalid type
curl -s -o /dev/null -w '%{http_code}' -X POST http://localhost:8888/api/submissions \
  -H 'Content-Type: application/json' \
  -d '{"topic_id":1,"author":"Joshua","raw_content":"x","content_type":"tweet"}'
# expect 400

# 5. Browser smoke (Chrome MCP)
# - Open editor for topic 1, switch type-tab from Post → Article. Confirm hook list refreshes.
# - Click a hook → confirm opener field fills.
# - From Matrix, click a Data driven cell, then a topic, confirm editor opens with correct type-tab active.
```

### Out of scope
- Cross-type content rewriting / AI-transform (Phase 2.5+).
- Adding new hook templates via UI (Phase 3).
- Drag-reorder type tabs.

---

## Slice 4 — Bibliotheek tab (source posts + derivatives boom)

**Goal:** New "Bibliotheek" tab shows a tree-view: each topic with submissions →  derivatives nested below. Each derivative row carries status badge, manual perf inputs (likes / views / der_comments / live_url / last_updated), and is filterable by `type` and status. Existing `/api/derivatives/tree` endpoint already returns the structure — extend it for new columns.

**Files touched:** `api.php` (extend derivatives endpoints to write/read perf metrics), `index.html`.

### Steps

1. **Extend `case 'derivatives'`** in `api.php` (line 682) to support:
   - `PATCH /api/derivatives/:id` — accept `{live_url, likes, views, der_comments, last_updated, status, scheduled_for}`. Use the existing `patchRecord()` helper (line 280) for validation. Update `last_updated` automatically when any perf field changes.
   - `POST /api/derivatives` — accept `{submission_id, type, title, status?}`. Reject if submission_id missing.
   - Extend `GET /api/derivatives/tree` SELECT to include the new perf columns.

2. **Add Bibliotheek tab button** between Matrix and Board in `.tab-nav`.

3. **Add `<section id="libraryView" class="view">` in `index.html`.** Layout:
   - Top filter bar: chip filters for `type` (all 6) + status (`generated`, `scheduled`, `posted`, `archived`) + author dropdown.
   - Tree list: per topic an expandable row. Topic title (left) + status badges + total derivative count (right). Expanded shows submissions then derivatives indented.
   - Each derivative row: type badge, title, status pill, inline editable inputs for `live_url` / `likes` / `views` / `der_comments`. Save on blur via PATCH.

4. **Add CSS for tree.** Indent levels: topic 0px, submission 24px, derivative 48px. Status pills reuse existing `.cat-{category}` color scheme but mapped to status.

5. **Implement `loadLibrary()` JS function.** Single fetch to `/api/derivatives/tree` per topic (or one combined endpoint — extend `/api/derivatives/tree` to accept no `topic_id` and return all). Render tree, attach blur handlers for inline edits.

6. **Add inline edit UX.** On blur of a numeric field, validate (positive int, max 9 digits), PATCH derivative, show toast "Saved" using the existing `.toast` helper.

### Verification

```bash
# 1. PATCH derivative perf metrics
DER_ID=$(sqlite3 data/content.db "SELECT id FROM derivatives LIMIT 1")
curl -s -X PATCH "http://localhost:8888/api/derivatives/$DER_ID" \
  -H 'Content-Type: application/json' \
  -d '{"likes":42,"views":1500,"der_comments":3,"live_url":"https://linkedin.com/posts/x"}' \
  | python3 -m json.tool
sqlite3 data/content.db "SELECT likes, views, der_comments, live_url, last_updated FROM derivatives WHERE id = $DER_ID"
# expect: 42 | 1500 | 3 | https://... | <recent timestamp>

# 2. Tree returns perf columns
curl -s 'http://localhost:8888/api/derivatives/tree?topic_id=1' | python3 -c "
import json,sys
d=json.load(sys.stdin)
ders = [der for sub in d for der in sub['derivatives']]
assert all('likes' in der for der in ders), 'likes missing in tree response'
print('OK',len(ders),'derivatives in topic 1')
"

# 3. Browser smoke
# - Click Bibliotheek tab. Confirm tree renders with at least 5 topics expanded once.
# - Filter by type=linkedin-post. Confirm only those derivatives show.
# - Inline-edit a likes field, blur, confirm toast appears and value persists on reload.

# 4. Existing flows still work
curl -s 'http://localhost:8888/api/derivatives?submission_id=1' | python3 -m json.tool > /dev/null && echo OK
```

### Out of scope
- LinkedIn API auto-pull of metrics (Phase 3, 2-3 weeks LI approval window per spec).
- Bulk metric import from CSV.
- Charting of metric trends.

---

## Slice 5 — MSP Journey tab + audit log + undo

**Goal:** New "MSP Journey" tab is a topic-editor view organized as the same 8×5 grid as the matrix tab, but cells are inline-editable (title, hook, level, priority, category, domain). Every PATCH to a topic writes a `revisions` snapshot before the change and an `audit_log` entry after. Below the grid: a chronological audit log feed for all topic changes. Each audit entry has an "Undo" button that restores the prior revision.

**Files touched:** `api.php` (add audit/revision hooks to topic PATCH + new restore endpoint logic), `index.html`.

### Steps

1. **Wrap topic PATCH in `case 'topics'`** (api.php around line 303). Before applying the update, INSERT into `revisions` a snapshot of the current row as JSON. After applying, INSERT into `audit_log` `{actor: $input['actor'] ?? 'unknown', action: 'topic.update', target_type: 'topic', target_id: $id, before_json, after_json}`. The actor field comes from a new optional `actor` field in the PATCH body (front-end always passes it).

2. **Implement restore handler.** `POST /api/revisions/:id/restore` reads the snapshot, PATCHes the original target back to those values, writes a new audit_log row `{action: 'topic.undo', ...}`. Idempotency: do not write a new revisions row for an undo (otherwise we get redo-stacking, which is out of scope).

3. **Add MSP Journey tab button** (last tab, after Bibliotheek).

4. **Add `<section id="journeyView" class="view">` in `index.html`.** Layout:
   - Top: same 8×5 matrix grid as slice 2 but each cell shows topic-titles list (truncated to 1 line each) instead of just count badge.
   - Per topic title: pencil icon → opens an inline edit popover with fields title / hook / domain (dropdown 8) / level (1-5) / category (6) / priority (2-5).
   - Save → PATCH `/api/topics/:id` with new values + actor name (from `localStorage.getItem('actor')`, prompt user to pick on first use — list of 5 consultants).
   - Below grid: audit log feed (latest 50 entries). Each row: timestamp, actor, "changed `<field>` from `<x>` to `<y>` on topic #N: '<title>'", undo button.

5. **Implement undo button click.** Calls `POST /api/revisions/:id/restore`, on success refreshes the grid + audit feed and shows toast "Undone: topic #N".

6. **Audit log filter.** Above the feed: filter by actor + by target_type. For MVP only `topic` is auditable so the target_type filter can be hidden until more types are added.

### Verification

```bash
# 1. Topic edit creates audit + revision rows
BEFORE_AUDIT=$(sqlite3 data/content.db "SELECT COUNT(*) FROM audit_log")
BEFORE_REV=$(sqlite3 data/content.db "SELECT COUNT(*) FROM revisions")
curl -s -X PATCH http://localhost:8888/api/topics/1 \
  -H 'Content-Type: application/json' \
  -d '{"actor":"Tom","title":"Edited title for test"}' > /dev/null
AFTER_AUDIT=$(sqlite3 data/content.db "SELECT COUNT(*) FROM audit_log")
AFTER_REV=$(sqlite3 data/content.db "SELECT COUNT(*) FROM revisions")
[ $((AFTER_AUDIT - BEFORE_AUDIT)) -eq 1 ] && echo "audit OK"
[ $((AFTER_REV - BEFORE_REV)) -eq 1 ] && echo "revision OK"

# 2. Undo round-trip
ORIG_TITLE=$(sqlite3 data/content.db "SELECT JSON_EXTRACT(snapshot_json,'$.title') FROM revisions ORDER BY id DESC LIMIT 1")
REV_ID=$(sqlite3 data/content.db "SELECT id FROM revisions ORDER BY id DESC LIMIT 1")
curl -s -X POST "http://localhost:8888/api/revisions/$REV_ID/restore" > /dev/null
NOW_TITLE=$(sqlite3 data/content.db "SELECT title FROM topics WHERE id = 1")
[ "$NOW_TITLE" = "$ORIG_TITLE" ] && echo "undo OK"

# 3. Audit log endpoint
curl -s 'http://localhost:8888/api/audit-log?target_type=topic&target_id=1' \
  | python3 -c "import json,sys;d=json.load(sys.stdin);assert len(d)>=2;print('audit entries:',len(d))"

# 4. Browser smoke
# - Open MSP Journey tab. Confirm grid renders with topic titles listed per cell.
# - Edit topic 1 title → confirm grid + audit feed update.
# - Click Undo on the audit entry → confirm title reverts and a new "topic.undo" entry appears.
# - Open the same view in a second browser tab → confirm audit log is shared (visibility for all 5 consultants).
```

### Out of scope
- Redo (stacking undo).
- Field-level conflict resolution when 2 consultants edit the same topic simultaneously (last-write-wins is acceptable for MVP).
- Audit log export.
- Editing across other entity types (submissions, derivatives) — only topics in MVP.

---

## Cross-slice acceptance criteria (final smoke before deploy)

```bash
# A. Full schema sanity
sqlite3 data/content.db "SELECT
  (SELECT COUNT(*) FROM topics WHERE level IS NULL) AS bad_levels,
  (SELECT COUNT(*) FROM topics WHERE priority IS NULL) AS bad_priorities,
  (SELECT COUNT(*) FROM topics WHERE domain NOT IN ('Identity','Organisation','GRC','Positioning','Portfolio','Technology','Blueprint','Data driven')) AS bad_domains,
  (SELECT COUNT(*) FROM derivatives WHERE last_updated IS NULL) AS unedited_ders;"
# expect: bad_levels=0, bad_priorities=0, bad_domains=0, unedited_ders may be >0

# B. All 5 tabs load without JS error (Chrome MCP read_console_messages, filter for "error")
# C. All 64 topics still listed in /api/topics with all phase-1 fields intact (id, title, hook, category, domain, status, claimed_by)
# D. Existing Board, Dashboard, Kalender flows unchanged from user perspective.
# E. ai-map-topics.php is idempotent: running it twice produces no spurious audit_log entries.

# F. Deploy
scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 \
  api.php index.html data/hook-templates.json data/journey-levels.json \
  u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/

# G. Run ai-map-topics.php remotely (via ssh) so live DB also gets levels:
ssh -i ~/.ssh/id_ed25519_siteground_dxfferent -p 18765 u110-srp2wn2ngthp@ssh.dxfferent.com \
  "cd ~/www/content.dxfferent.com/public_html && php scripts/ai-map-topics.php"

# H. Cache-busted browser check
open "https://content.dxfferent.com/?v=$(date +%s)"
# Verify: Matrix tab default, all 5 tabs work, audit log shows recent entries, derivatives show perf inputs.
```

## Per-slice deployment policy

- **Slice 1**: do not deploy alone. Schema migration is invisible to users, but no UI consumes it yet — no value to ship in isolation. Hold local until slice 2 ships with it.
- **Slice 2**: safe to deploy. Adds Matrix tab as new default; existing tabs unchanged. Coordinate via Telegram heads-up to the 5 consultants ("nieuwe Matrix tab live, oude Board werkt nog").
- **Slice 3**: safe to deploy. Editor changes are additive (existing submissions without `content_type` still load).
- **Slice 4**: safe to deploy. New tab, no existing flow touched.
- **Slice 5**: deploy last. Audit log + undo introduce new behavior on topic editing — confirm with consultants that they understand changes are now logged.

## Risk register

| Risk | Mitigation |
|------|-----------|
| `ALTER TABLE RENAME COLUMN` requires SQLite 3.25+ | SiteGround runs SQLite ≥3.34 (PHP 8). Verify with `sqlite3 --version` on server before slice 1 deploy. |
| AI-pass mismaps levels | CSV review file `data/level-mapping.csv` is generated for spot-check. Spec accepts that Joshua can later override via Phase 3 admin UI. |
| Two consultants edit same topic concurrently | Last-write-wins; audit log captures both edits; undo recovers either. Acceptable per spec. |
| Concurrent SQLite writes on shared host | Existing app already runs SQLite in WAL mode (see api.php line ~50). New tables inherit WAL. |
| Live DB migration fails halfway | Pre-flight backup `content.db.bak.YYYYMMDD` is restoration path. PRAGMA-driven migration is idempotent — safe to re-run. |

## Notes for the implementation agent

- Read `~/ClaudeCode/content-platform/CLAUDE.md` first. The "Rules" section (no build tools, single HTML file, etc.) is binding.
- Each slice fits within the 40-instruction budget per crispy-principles. If a step grows beyond that, split it into sub-steps and verify mid-slice.
- All human-facing copy (tab labels, button text, toast messages, audit log strings) follows the humanizer rules: no AI vocabulary ("Moreover", "Furthermore"), no em dashes, conversational Dutch where the existing UI is Dutch.
- Run verification commands literally — do not skip them. Every "expect" line is a hard gate.
- If a step's assumption proves wrong (e.g. `ALTER TABLE RENAME COLUMN` fails on prod SQLite), stop and report — do not invent a workaround.
