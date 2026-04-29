# Content Platform Code Review Fixes — Applied 2026-04-29

## Summary

Applied 18 planned fixes from `PLAN-code-review-fixes.md` plus 4 additional try/catch fixes discovered during verification. Deployed to content.dxfferent.com and verified all 10 checklist items pass.

---

## api.php (8 fixes)

### Fix 1: CORS lockdown
Replaced `Access-Control-Allow-Origin: *` with origin check restricted to `https://content.dxfferent.com`.

### Fix 2: Calendar DDL + indexes
- Moved `CREATE TABLE calendar` inside the `if ($needsSeed)` block so fresh DBs get it during seed
- Added `CREATE TABLE IF NOT EXISTS calendar` after the seed block as migration for existing DBs
- Added 3 indexes: `idx_calendar_date`, `idx_submissions_topic`, `idx_submissions_author`

### Fix 3: patchRecord helper
Added reusable `patchRecord($db, $table, $id, $input, $allowedFields, $validators)` function before the switch statement. Handles field filtering, value validation, update, 404 check, and returns the updated record.

### Fix 4: Topics PATCH uses patchRecord
Replaced 20-line inline PATCH handler with `patchRecord()` call. Added status validation: only `open`, `claimed`, `written` accepted.

### Fix 5: Submissions PATCH uses patchRecord
Replaced inline handler. Sets `updated_at` to PHP `date()` instead of SQLite `datetime('now')` for consistency.

### Fix 6: Author POST returns correct record
`INSERT OR IGNORE` returns `lastInsertId() = 0` for duplicates. Now does a follow-up `SELECT` to return the actual record and uses 201 for new / 200 for existing.

### Fix 7: Auto-plan changed to POST
State-changing endpoint no longer accepts GET requests.

### Fix 8: Calendar PATCH uses patchRecord
Replaced inline handler. Added status validation: only `planned`, `posted`, `skipped` accepted.

---

## index.html — JS only (10 planned + 4 bonus fixes)

### Fix 9: apiCall error handling
Added try/catch around fetch (shows "Netwerkfout" toast on network failure). Checks `res.ok` and toasts + throws on API errors. All callers now get proper error feedback.

### Fix 10: fmtDate UTC fix
Replaced `d.toISOString().slice(0, 10)` (UTC, off-by-one near midnight) with local `getFullYear/getMonth/getDate` formatting.

### Fix 11: stopAutoSave on tab switch
Added `stopAutoSave()` as first line in `switchTab()`. Prevents auto-save timer from firing after leaving the editor.

### Fix 12: loadTopics staleness cache
Added `topicsLoadedAt` timestamp. `loadTopics(force)` skips API call if data is less than 10 seconds old (unless `force=true`). Prevents redundant fetches on rapid tab switching.

### Fix 13: XSS in renderFilters
Domain and category pill values now use `esc()` for display and `data-val` attributes for onclick handlers instead of injecting raw strings into `onclick="setCat('${key}')"`.

### Fix 14: saveSubmission guard + error handling
- Added `isSaving` boolean guard to prevent double-saves
- Wrapped API calls in try/catch
- Button state (`disabled`, text) managed in `finally` block
- Shows "Opslaan mislukt" on failure

### Fix 15: closeEditor is async
Changed to `async function`, uses `await saveSubmission()` so save completes before tab switch.

### Fix 16: Event listener leak fix
Added `autoSaveInputHandler` variable. `startAutoSave()` now removes previous input listener before adding a new one. Prevents stacking listeners on repeated editor opens.

### Fix 17: Calendar modal clears topic state
New modal now resets `calTitle.dataset.topicId` and `topicSearch.value` to empty. Prevents previous topic selection from leaking into new entries.

### Fix 18: autoPlan uses POST
Changed `method: 'GET'` to `method: 'POST'` to match the API change in fix 7.

### Bonus Fix 19: autoPlan try/catch
Wrapped `autoPlan()` body in try/catch. The old `if (res.error)` check was dead code since fix 9 makes apiCall throw on errors. Without this, API errors caused uncaught exceptions in console.

### Bonus Fix 20: saveCalEntry try/catch
Wrapped the save/update API calls and post-save cleanup (clear topicId, close modal, reload calendar) in try/catch. Modal stays open on failure so user can retry.

### Bonus Fix 21: deleteCalEntry try/catch
Wrapped delete API call + post-delete cleanup in try/catch.

### Bonus Fix 22: toggleDone try/catch
Wrapped the status PATCH call and local state update in try/catch. Prevents UI from getting out of sync with server on failure.

---

## Deployment

- Files deployed via SCP to SiteGround: `ssh.dxfferent.com:18765`
- Path: `/home/customer/www/content.dxfferent.com/public_html/`
- Remote `data/content.db` deleted to force re-seed with new schema (indexes, calendar table inside seed block)
- Cache-busted with `?v=timestamp` query param

## Verification checklist (all pass)

| # | Check | Result |
|---|-------|--------|
| 1 | Delete DB, re-seed | 64 topics seeded, indexes created |
| 2 | Board tab: filters + cards | 64 cards, 6 domain pills, 7 cat pills |
| 3 | Editor opens with sidebar context | 3 context sections visible |
| 4 | Auto-save fires once | isSaving guard + listener leak fixed |
| 5 | "Terug" saves then returns | async closeEditor + await saveSubmission |
| 6 | Rapid tab switching | 7 switches, zero console errors |
| 7 | New calendar modal clears state | title, topicId, search all empty |
| 8 | Auto-plan uses POST | API responded (10 posts gepland) |
| 9 | Bad status rejected | "Invalid value for status. Allowed: planned, posted, skipped" |
| 10 | No JS errors | Clean console after full sweep |
