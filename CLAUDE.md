# Dxfferent Content Platform

Internal content platform for the Dxfferent MSP Journey. Consultants use the MSP Journey matrix to find client pain points (problemen, frustraties, vragen), then write content addressing those pain points. Content flows through a kanban pipeline from idea to published.

**Live:** https://content.dxfferent.com  
**GitHub:** https://github.com/maxkwaaste/content-dxfferent  
**Docs:** `docs/handleiding.html`  
**Hosting:** SiteGround (shared PHP), deployed via SCP

## Stack

| Layer | Tech | File |
|-------|------|------|
| Frontend | Vanilla JS SPA (single file) | `index.html` |
| API | PHP REST, single file | `api.php` |
| Database | SQLite (WAL mode) | `data/content.db` (gitignored, auto-created) |
| Seed data | 64 MSP Journey topics | `seed.json` |
| Journey data | 396 problemen/frustraties/vragen | `data/journey-data.json` |
| Routing (prod) | Apache mod_rewrite | `.htaccess` |
| Routing (dev) | PHP built-in server | `router.php` |

## Architecture

Everything runs from two files. No build step, no dependencies, no node_modules.

### User Flow

1. **Matrix** (startpunt) -- 8 domains x 5 levels grid. Click a cell.
2. **Context modal** -- shows problemen/frustraties/vragen for that domain + topics in that cell. Click "Schrijf" on a topic or "Nieuw schrijven".
3. **Editor** -- hook suggestions (clickable chips), opener field, author, content textarea. Auto-save every 30s.
4. **Kanban pipeline** -- topic moves through: Idee -> Schrijven -> Review -> Gepland -> Gepost.
5. **Kalender** -- 4-week view, schedule content to LinkedIn/Dxfferent.

### Frontend (`index.html`)

Single HTML file with inline CSS and JS. Five tabs:

- **Matrix** -- 8x5 journey grid, click cell opens context modal with journey data + topics
- **Content** -- all topics with filters (domain, category, status). List view + Kanban toggle. Expandable details per card showing derivatives and performance.
- **Dashboard** -- stats, progress bars, leaderboard, activity feed, performance metrics
- **Kalender** -- 4-week Mon-Fri calendar, auto-planner, CSV export, Google Sheets sync
- **MSP Journey** -- accordion view of all journey data (problemen/frustraties/vragen per domain/subdomain), editable inline

### Design System (V2 Dark Theme)

- **Fonts:** DM Sans (body, weight 500), PP Monument Extended (headings)
- **Colors:** `#030303` bg, `#1F1F1F` cards, gradient `#ED4C35` to `#FF6927`
- **Category jewel tones:** bewijs (blue `#5a8be8`), educatie (purple `#a888d8`), markt (green `#4fb37e`), contrair (orange `#ff8c4a`), eigen (pink `#e878a3`), tools (gold `#d6b04a`)
- **Corners:** Sharp, 2-4px radius
- **Signature:** Trapezoid clip-path on stat cards and ranks

### API (`api.php`)

REST endpoints, all under `/api/`:

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/topics` | List topics (`?category=`, `?domain=`, `?status=`, `?level=`, `?view=kanban`) |
| GET | `/api/topics/:id` | Single topic with submissions |
| GET | `/api/topics/matrix` | Matrix cell counts per domain x level |
| PATCH | `/api/topics/:id` | Update status, claimed_by, title, hook, level, priority, domain |
| GET | `/api/submissions` | List (`?topic_id=`, `?author=`, `?content_type=`) |
| POST | `/api/submissions` | Create submission |
| PATCH | `/api/submissions/:id` | Update submission |
| DELETE | `/api/submissions/:id` | Soft delete |
| GET | `/api/journey` | All journey data grouped by domain > subdomain > type |
| GET | `/api/journey/domain?domain=X` | Journey data for one domain (problemen/frustraties/vragen) |
| POST | `/api/journey` | Add journey item (domain, subdomain, type, text) |
| PATCH | `/api/journey/:id` | Edit journey item text |
| DELETE | `/api/journey/:id` | Delete journey item |
| GET | `/api/hooks?category=X` | Hook templates filtered by content category |
| GET | `/api/calendar?from=&to=` | Calendar entries in date range |
| POST | `/api/calendar` | Create calendar entry |
| PATCH | `/api/calendar/:id` | Update calendar entry |
| DELETE | `/api/calendar/:id` | Soft delete |
| POST | `/api/calendar/auto-plan?weeks=2` | Auto-schedule unplanned topics |
| GET | `/api/dashboard` | Dashboard aggregates |
| GET | `/api/derivatives/tree` | Topics with submissions and derivative content |
| GET | `/api/authors` | List authors |
| GET | `/api/audit-log` | Change history |
| POST | `/api/revisions/:id/restore` | Undo a topic change |

### Database Schema

```sql
topics (id, title, hook, category, domain, subdomain, context JSON, status, claimed_by, level, priority, created_at)
submissions (id, topic_id FK, author, raw_content, category, domain, content_type, created_at, updated_at, archived_at)
journey_items (id, domain, subdomain, type, text, sort_order, created_at)
authors (id, name UNIQUE, created_at)
calendar (id, scheduled_date, topic_id FK, title, platform, account, content_type, status, impressions, clicks, engagement_rate, comments, archived_at, created_at)
derivatives (id, submission_id FK, type, title, status, live_url, likes, views, der_comments, last_updated, scheduled_for, archived_at, created_at)
audit_log (id, actor, action, target_type, target_id, before_json, after_json, created_at)
revisions (id, target_type, target_id, snapshot_json, created_by, created_at)
settings (key PK, value)
```

**Topic statuses:** `open`, `claimed`, `review`, `written`  
**Kanban mapping:** open=Idee, claimed=Schrijven, review=Review, written=Gepland/Gepost  
**Calendar statuses:** `planned`, `posted`, `skipped`  
**Categories:** `bewijs`, `educatie`, `markt`, `contrair`, `eigen`, `tools`  
**Journey item types:** `probleem`, `frustratie`, `vraag`

### Journey Data

The `journey_items` table contains all MSP pain points from the MSP Journey L2-L3 PDF. 8 domains, 43 subdomains, ~396 items. Auto-seeded from `data/journey-data.json` when the table is empty. User edits are stored in the DB and persist across deploys.

The 8 domains: Identity, Organisation, GRC, Positioning, Portfolio, Technology, Blueprint, Data driven.

Each subdomain has three types: problemen (what goes wrong), frustraties (how it feels), vragen (what the client wants to know).

## Deployment

```bash
# Deploy frontend (most common)
scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 \
  index.html \
  u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/index.html

# Deploy API
scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 \
  api.php \
  u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/api.php

# Deploy journey data (only if JSON changed, does NOT overwrite user edits in DB)
scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 \
  data/journey-data.json \
  u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/data/journey-data.json

# Cache bust
open https://content.dxfferent.com/?v=$(date +%s)
```

## Local Development

```bash
cd ~/ClaudeCode/content-platform
php -S localhost:8888 router.php
# Open http://localhost:8888
```

The SQLite DB is auto-created and seeded on first API call. Delete `data/content.db` to re-seed.

## Rules

- Never modify `seed.json` for feature work (reference data)
- `data/content.db` is gitignored (user data, auto-created)
- `data/journey-data.json` is force-tracked (seed data for journey_items)
- All CSS and JS is inline in `index.html` -- do not split
- No build tools, bundlers, or package managers
- Journey item edits go to the DB, not the JSON file
- The JSON file only seeds an empty table -- it does not overwrite existing data
