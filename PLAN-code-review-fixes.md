---
plan: Content Platform Code Review Fixes
files: api.php, index.html
do-not-touch: seed.json, CSS (visual design)
---

# Code Review Fix Plan

Apply all fixes below in order. Each fix is self-contained. After all fixes, test by loading the site and exercising: Board filters, open/edit/save a topic, switch tabs, calendar modal new + edit, auto-plan.

## api.php fixes

### Fix 1: CORS — restrict to actual domain (line 4)

Replace:
```
header('Access-Control-Allow-Origin: *');
```
With:
```
$allowedOrigin = 'https://content.dxfferent.com';
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
} else {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
```

### Fix 2: Move calendar DDL inside seed block + add indexes (lines 74-87)

Delete the standalone `CREATE TABLE IF NOT EXISTS calendar` block (lines 74-87).

After the topic seeding loop (after line 71, before the closing `}` of `if ($needsSeed)`), add:
```php
    $db->exec('
        CREATE TABLE calendar (
            id INTEGER PRIMARY KEY,
            scheduled_date TEXT NOT NULL,
            topic_id INTEGER,
            title TEXT NOT NULL,
            platform TEXT NOT NULL DEFAULT "linkedin",
            account TEXT NOT NULL,
            content_type TEXT NOT NULL DEFAULT "post",
            status TEXT NOT NULL DEFAULT "planned",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (topic_id) REFERENCES topics(id)
        )
    ');
```

Then AFTER the `if ($needsSeed)` closing brace, add migration for existing DBs:
```php
$db->exec('CREATE TABLE IF NOT EXISTS calendar (
    id INTEGER PRIMARY KEY,
    scheduled_date TEXT NOT NULL,
    topic_id INTEGER,
    title TEXT NOT NULL,
    platform TEXT NOT NULL DEFAULT "linkedin",
    account TEXT NOT NULL,
    content_type TEXT NOT NULL DEFAULT "post",
    status TEXT NOT NULL DEFAULT "planned",
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES topics(id)
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar(scheduled_date)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_submissions_topic ON submissions(topic_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_submissions_author ON submissions(author)');
```

### Fix 3: Add patchRecord helper function (before the switch statement, after line 108)

```php
function patchRecord($db, $table, $id, $input, $allowedFields, $validators = []) {
    $fields = [];
    $params = [];
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            if (isset($validators[$field]) && !in_array($input[$field], $validators[$field])) {
                respondError("Invalid value for $field. Allowed: " . implode(', ', $validators[$field]));
            }
            $fields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    if (!$fields) respondError('No fields to update');
    $params[] = $id;
    $stmt = $db->prepare("UPDATE $table SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) respondError(ucfirst($table) . ' not found', 404);
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}
```

### Fix 4: Replace topics PATCH handler (lines 148-168)

Replace the entire `elseif ($method === 'PATCH' && $id !== null)` block with:
```php
} elseif ($method === 'PATCH' && $id !== null) {
    $topic = patchRecord($db, 'topics', $id, $input,
        ['status', 'claimed_by', 'title', 'hook'],
        ['status' => ['open', 'claimed', 'written']]
    );
    if ($topic['context']) {
        $topic['context'] = json_decode($topic['context'], true);
    }
    respond($topic);
```

### Fix 5: Replace submissions PATCH handler (lines 223-240)

Replace with:
```php
} elseif ($method === 'PATCH' && $id !== null) {
    $input['updated_at'] = date('Y-m-d H:i:s');
    $sub = patchRecord($db, 'submissions', $id, $input,
        ['raw_content', 'category', 'domain', 'updated_at']
    );
    respond($sub);
```

### Fix 6: Fix author POST returning wrong ID (lines 258-262)

Replace:
```php
} elseif ($method === 'POST') {
    if (empty($input['name'])) respondError('name required');
    $stmt = $db->prepare('INSERT OR IGNORE INTO authors (name) VALUES (?)');
    $stmt->execute([$input['name']]);
    respond(['id' => $db->lastInsertId(), 'name' => $input['name']], 201);
```
With:
```php
} elseif ($method === 'POST') {
    if (empty($input['name'])) respondError('name required');
    $stmt = $db->prepare('INSERT OR IGNORE INTO authors (name) VALUES (?)');
    $stmt->execute([$input['name']]);
    $isNew = (bool)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM authors WHERE name = ?');
    $stmt->execute([$input['name']]);
    respond($stmt->fetch(), $isNew ? 201 : 200);
```

### Fix 7: Change auto-plan from GET to POST (line 290)

Replace:
```php
if ($method === 'GET' && $action === 'auto-plan') {
```
With:
```php
if ($method === 'POST' && $action === 'auto-plan') {
```

### Fix 8: Replace calendar PATCH handler (lines 409-426)

Replace with:
```php
} elseif ($method === 'PATCH' && $action !== null) {
    $calId = $action;
    $entry = patchRecord($db, 'calendar', $calId, $input,
        ['scheduled_date', 'title', 'platform', 'account', 'content_type', 'status', 'topic_id'],
        ['status' => ['planned', 'posted', 'skipped']]
    );
    respond($entry);
```

---

## index.html fixes (JS only, do not touch CSS)

### Fix 9: Replace apiCall function (lines 356-363)

Replace entire function with:
```js
async function apiCall(path, opts = {}) {
  let res;
  try {
    res = await fetch(API + path, {
      headers: { 'Content-Type': 'application/json' },
      ...opts,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
  } catch (e) {
    toast('Netwerkfout — probeer opnieuw');
    throw e;
  }
  const data = await res.json();
  if (!res.ok) {
    toast(data.error || 'Er ging iets mis');
    throw new Error(data.error || res.statusText);
  }
  return data;
}
```

### Fix 10: Fix fmtDate UTC/local mismatch (lines 388-390)

Replace:
```js
function fmtDate(d) {
  return d.toISOString().slice(0, 10);
}
```
With:
```js
function fmtDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}
```

### Fix 11: Stop auto-save on tab switch (lines 398-417)

Add `stopAutoSave();` as the first line inside `switchTab`:
```js
function switchTab(tab) {
  stopAutoSave();
  currentTab = tab;
  // ... rest unchanged
```

### Fix 12: Add staleness check to loadTopics (lines 421-426)

Add `let topicsLoadedAt = 0;` after the global variables (after line 354).

Replace loadTopics:
```js
async function loadTopics(force = false) {
  if (!force && topics.length && Date.now() - topicsLoadedAt < 10000) {
    renderCards(); renderStats(); renderFilters(); return;
  }
  topics = await apiCall('/topics');
  topicsLoadedAt = Date.now();
  renderFilters();
  renderCards();
  renderStats();
}
```

### Fix 13: Fix XSS in renderFilters (lines 441-453)

In the domain pill line, change to:
```js
domainHtml += `<span class="pill ${activeDomain === d ? 'active' : ''}" data-val="${esc(d)}" onclick="setDomain(this.dataset.val)">${esc(d)} (${count})</span>`;
```

In the category pill line, change to:
```js
catHtml += `<span class="pill pill-${key} ${activeCat === key ? 'active' : ''}" data-val="${esc(key)}" onclick="setCat(this.dataset.val)">${label} (${count})</span>`;
```

### Fix 14: Add isSaving guard + try/catch to saveSubmission (lines 546-567)

Add `let isSaving = false;` after the global variables.

Replace entire saveSubmission:
```js
async function saveSubmission() {
  if (isSaving) return;
  const author = document.getElementById('authorInput').value.trim();
  const content = document.getElementById('contentArea').value.trim();
  if (!author) { toast('Vul je naam in'); return; }
  if (!content) { toast('Schrijf eerst iets'); return; }
  localStorage.setItem('author', author);
  const btn = document.getElementById('saveBtn');
  btn.disabled = true; btn.textContent = 'Opslaan...';
  isSaving = true;
  try {
    if (currentSubmission) {
      await apiCall('/submissions/' + currentSubmission.id, { method: 'PATCH', body: { raw_content: content } });
    } else {
      const res = await apiCall('/submissions', { method: 'POST', body: { topic_id: currentTopic ? currentTopic.id : null, author, raw_content: content } });
      currentSubmission = res;
    }
    if (currentTopic && currentTopic.status === 'open') {
      await apiCall('/topics/' + currentTopic.id, { method: 'PATCH', body: { status: 'claimed', claimed_by: author } });
      currentTopic.status = 'claimed'; currentTopic.claimed_by = author;
    }
    isDirty = false;
    document.getElementById('saveStatus').textContent = 'Opgeslagen om ' + new Date().toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
    toast('Opgeslagen');
  } catch (e) {
    document.getElementById('saveStatus').textContent = 'Opslaan mislukt';
  } finally {
    isSaving = false;
    btn.disabled = false; btn.textContent = 'Opslaan';
  }
}
```

### Fix 15: Make closeEditor async (lines 583-591)

Replace:
```js
function closeEditor() {
  stopAutoSave();
  if (isDirty) {
    const author = document.getElementById('authorInput').value.trim();
    const content = document.getElementById('contentArea').value.trim();
    if (author && content) saveSubmission();
  }
  switchTab('board');
}
```
With:
```js
async function closeEditor() {
  stopAutoSave();
  if (isDirty) {
    const author = document.getElementById('authorInput').value.trim();
    const content = document.getElementById('contentArea').value.trim();
    if (author && content) await saveSubmission();
  }
  switchTab('board');
}
```

### Fix 16: Fix event listener leak in startAutoSave (lines 593-606)

Add `let autoSaveInputHandler = null;` after the global variables.

Replace startAutoSave:
```js
function startAutoSave() {
  const area = document.getElementById('contentArea');
  if (autoSaveInputHandler) area.removeEventListener('input', autoSaveInputHandler);
  autoSaveInputHandler = () => { isDirty = true; };
  area.addEventListener('input', autoSaveInputHandler);
  stopAutoSave();
  autoSaveTimer = setInterval(() => {
    if (isDirty && !isSaving) {
      const author = document.getElementById('authorInput').value.trim();
      const content = document.getElementById('contentArea').value.trim();
      if (author && content) saveSubmission();
    }
  }, 30000);
}
```

### Fix 17: Clear topic picker state on new modal open (lines 744-752)

In the `else` branch of openCalModal (the "new entry" path), add two lines after `document.getElementById('calTitle').value = '';`:
```js
document.getElementById('calTitle').dataset.topicId = '';
document.getElementById('topicSearch').value = '';
```

### Fix 18: Change autoPlan to POST (line 824)

Replace:
```js
const res = await apiCall('/calendar/auto-plan?weeks=2', { method: 'GET' });
```
With:
```js
const res = await apiCall('/calendar/auto-plan?weeks=2', { method: 'POST' });
```

---

## Verification checklist

After applying all fixes:

1. Delete `data/content.db` to force re-seed (tests indexes + calendar table creation)
2. Load the Board tab — filters should work, cards render
3. Click a topic card — editor opens, sidebar shows context
4. Type content, wait 30s — auto-save fires once (check network tab for single request)
5. Click "Terug" — should save then return to board
6. Switch between all 3 tabs rapidly — no console errors
7. Open calendar modal, pick a topic, close, open new modal — title and topic_id should be empty
8. Click "Auto-plan 2 weken" — should POST, not GET
9. Edit a calendar entry, change status to garbage value via devtools fetch — should get validation error
10. Check console for any JS errors throughout
