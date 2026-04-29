# Dxfferent Content Platform

Internal content library for the Dxfferent MSP Journey. Team members browse topic ideas, write raw content against hook templates, and schedule posts to LinkedIn/Dxfferent via a calendar planner.

**Live:** https://content.dxfferent.com  
**Hosting:** SiteGround (shared PHP), deployed via SCP

## Stack

| Layer | Tech | File |
|-------|------|------|
| Frontend | Vanilla JS SPA (single file) | `index.html` |
| API | PHP REST, single file | `api.php` |
| Database | SQLite (WAL mode) | `data/content.db` (gitignored, auto-created from seed) |
| Seed data | 100+ MSP Journey topics | `seed.json` |
| Routing | Apache mod_rewrite | `.htaccess` |

## Architecture

Everything runs from two files. No build step, no dependencies, no node_modules.

### Frontend (`index.html`)

Single HTML file containing all CSS (inline `<style>`) and JS (inline `<script>`). Three tabs:

- **Board** -- topic cards with domain/category filters, click to open editor
- **Dashboard** -- stats, progress bars per domain/category, leaderboard, activity feed
- **Kalender** -- 5-column Mon-Fri weekly calendar with drag-to-schedule and auto-planner

The editor view lets users write raw content against a topic's hook template with auto-save (30s interval + on blur). A modal handles calendar entry CRUD.

### Design System (V2 Dark Theme)

Dark-first design aligned with dxfferent.com brand identity:

- **Fonts:** DM Sans (body), PP Monument Extended (headings, labels, buttons) with Archivo Black fallback
- **Colors:** Dark surfaces (`#030303` bg, `#1F1F1F` cards), red-to-orange gradient (`#ED4C35` to `#FF6927`)
- **Corners:** Sharp, 2-4px radius
- **Category jewel tones:** bewijs (blue), educatie (purple), markt (green), contrair (orange), eigen (pink), tools (gold)
- **Signature elements:** Trapezoid clip-path on stat cards and leaderboard ranks, gradient accent bars on hover

All design tokens are CSS custom properties on `:root`. See the top of the `<style>` block.

### API (`api.php`)

REST endpoints, all under `/api/`:

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/topics` | List topics (filterable by `?category=`, `?domain=`, `?status=`) |
| GET | `/api/topics/:id` | Single topic with submissions |
| PATCH | `/api/topics/:id` | Update status, claimed_by, title, hook |
| GET | `/api/submissions` | List submissions (filterable by `?topic_id=`, `?author=`) |
| POST | `/api/submissions` | Create submission (`author`, `raw_content`, optional `topic_id`) |
| PATCH | `/api/submissions/:id` | Update submission content |
| DELETE | `/api/submissions/:id` | Delete submission |
| GET | `/api/authors` | List authors |
| POST | `/api/authors` | Create author |
| GET | `/api/stats` | Aggregated stats |
| GET | `/api/calendar?from=&to=` | Calendar entries in date range |
| POST | `/api/calendar` | Create calendar entry |
| PATCH | `/api/calendar/:id` | Update calendar entry |
| DELETE | `/api/calendar/:id` | Delete calendar entry |
| POST | `/api/calendar/auto-plan?weeks=2` | Auto-schedule unplanned topics |
| GET | `/api/dashboard` | Dashboard aggregates (domain/category progress, leaderboard, activity) |

### Database Schema

```sql
topics (id, title, hook, category, domain, subdomain, context JSON, status, claimed_by, created_at)
submissions (id, topic_id FK, author, raw_content, category, domain, created_at, updated_at)
authors (id, name UNIQUE, created_at)
calendar (id, scheduled_date, topic_id FK, title, platform, account, content_type, status, created_at)
```

Topic statuses: `open`, `claimed`, `written`  
Calendar statuses: `planned`, `posted`, `skipped`  
Categories: `bewijs`, `educatie`, `markt`, `contrair`, `eigen`, `tools`

## Key CSS Class Names

The JS references these classes directly. Do not rename without updating JS:

- Tabs: `.tab-nav`, `.tab-btn`, `.active`
- Views: `.view`, `.active`, `#boardView`, `#editorView`, `#dashboardView`, `#calendarView`
- Filters: `.pill`, `.pill-{category}`, `.active`
- Cards: `.card`, `.card-written`, `.card-top`, `.card-title`, `.cat-badge`, `.cat-{category}`
- Editor: `.author-input`, `.content-area`, `.btn-save`, `.btn-done`, `.is-done`
- Dashboard: `.dash-stat`, `.dash-stat-num`, `.dash-stat-label`, `.dash-card`, `.progress-row`, `.leader-row`
- Calendar: `.cal-grid`, `.cal-day`, `.today`, `.past`, `.cal-entry`, `.cal-status-posted`, `.cal-platform`
- Modal: `.modal-overlay`, `.open`, `.modal`, `.modal-field`, `.modal-actions`
- Toast: `.toast`, `.show`

## Deployment

```bash
# Deploy single file (most common)
scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 \
  index.html \
  u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/index.html

# Deploy API (rare)
scp -i ~/.ssh/id_ed25519_siteground_dxfferent -P 18765 \
  api.php \
  u110-srp2wn2ngthp@ssh.dxfferent.com:~/www/content.dxfferent.com/public_html/api.php

# Cache bust: open https://content.dxfferent.com/?v=$(date +%s)
```

## Local Development

```bash
cd ~/ClaudeCode/content-platform
php -S localhost:8888
# Open http://localhost:8888
```

The SQLite DB is auto-created from `seed.json` on first API call if `data/content.db` does not exist. Delete the DB to re-seed.

## Rules

- Never modify `seed.json` for feature work (it is reference data)
- The `data/` directory is gitignored (contains user data)
- All CSS is inline in `index.html`, no external stylesheets
- No build tools, bundlers, or package managers
- The frontend is a single HTML file on purpose. Do not split it.
