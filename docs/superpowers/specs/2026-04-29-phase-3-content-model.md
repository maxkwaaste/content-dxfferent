# Phase 3: Content Model Restructure

## Problem

Topics currently bundle four concerns into one record: the subject matter brief (domain/subdomain/context), a pre-written article headline (title), a content format (hook), and a content type (category). In practice, hooks and categories are interchangeable across any topic, and titles should be written by the consultant, not pre-assigned. The current model limits how consultants discover and compose content.

## Design

### Data Model: Three Independent Layers

**Layer 1: Topics (the WHAT)**

A topic is a pure content brief. It describes a problem space in the MSP world. Nothing else.

```
topics (
  id INTEGER PRIMARY KEY,
  domain TEXT,          -- "Data Driven", "Technology", "Organisation", "Markt", "Algemeen"
  subdomain TEXT,       -- "PSA", "Contracten & Billing", "Analyse", etc.
  context JSON,         -- { problemen: [], frustraties: [], vragen: [] }
  status TEXT,          -- "open", "claimed", "written"
  claimed_by TEXT,
  created_at DATETIME
)
```

Removed from topics: `title`, `hook`, `category`. These no longer belong here.

Card identity on the board comes from `context.problemen[0]`, truncated to ~80 characters. This is always concrete and specific ("Autotask voor 30-40% ingericht"), never a finished headline.

**Layer 2: Formats (the HOW)**

The 40 hooks become a standalone library of proven content formulas. Stored as a JSON array in seed.json under a `"formats"` key, loaded once on page init.

```json
{
  "formats": [
    {
      "id": 1,
      "template": "Case study: 1 probleem, 1 oplossing, 1 resultaat",
      "style": "storytelling",
      "description": "Vertel het verhaal van een specifiek probleem en hoe het werd opgelost. Concreet, herkenbaar, met meetbaar resultaat."
    },
    {
      "id": 2,
      "template": "Ik analyseerde [X] en vond [Y]",
      "style": "data-driven",
      "description": "Presenteer jezelf als analist. Deel een ontdekking op basis van data of ervaring."
    }
  ]
}
```

Format styles (for grouping in the gallery): `storytelling`, `data-driven`, `opinion`, `practical`, `personal`.

Each format gets a short `description` (1-2 sentences) explaining when and why to use it. These descriptions are the SEO knowledge made explicit.

**Layer 3: Submission Choices (writer decisions)**

All strategic choices move to the submission. The writer picks these when they start writing.

```
submissions (
  id INTEGER PRIMARY KEY,
  topic_id INTEGER,          -- FK to topics (nullable for free-form)
  author TEXT,
  raw_content TEXT,
  title TEXT,                -- the writer's own headline (NEW)
  format_id INTEGER,         -- which content formula they chose
  content_type TEXT,         -- bewijs/educatie/markt/contrair/eigen/tools
  oml_levels TEXT,           -- JSON array, e.g. [1,2] or [3,4]
  created_at DATETIME,
  updated_at DATETIME
)
```

New columns: `title`, `format_id`, `content_type`, `oml_levels`.
Removed from submissions: `category`, `domain` (these were redundant with the topic).

### Seed Data Migration

Current `seed.json` structure:
```json
[{ "title": "...", "hook": "...", "category": "...", "domain": "...", "subdomain": "...", "context": {} }]
```

New `seed.json` structure:
```json
{
  "topics": [
    { "domain": "...", "subdomain": "...", "context": {} }
  ],
  "formats": [
    { "id": 1, "template": "...", "style": "...", "description": "..." }
  ]
}
```

The 64 topics lose `title`, `hook`, and `category`. The 40 unique hooks become the formats array with added `style` and `description` fields.

### UI Changes

#### Tab Structure

Current: Board | Dashboard | Kalender (3 tabs)
New: Board | Matrix | Formules | Dashboard | Kalender (5 tabs)

#### Board Tab (modified)

**Filters change:**
- Remove: category pills (bewijs, educatie, etc.)
- Keep: domain pills (Data Driven, Technology, etc.)
- Add: subdomain pills (contextual -- show only subdomains present in the selected domain, or all if domain is "Alles")

**Card display changes:**
- Primary text: `context.problemen[0]` truncated to ~80 chars (replaces title)
- Badge: subdomain (replaces category badge)
- Meta line: `domain` (replaces `domain / subdomain` since subdomain is now the badge)
- Card color/accent: by domain (5 domain colors replace 6 category colors)
- Remove: hook tag from meta line
- Search: searches across `context.problemen`, `context.frustraties`, `context.vragen` text (replaces title search)

Domain color mapping:
| Domain | Color | Hex |
|--------|-------|-----|
| Data Driven | Teal | #0f766e |
| Technology | Blue | #2563eb |
| Organisation | Purple | #7c3aed |
| Markt | Green | #16a34a |
| Algemeen | Warm gray | #78716c |

**Kanban cards** follow the same pattern: show first problem instead of title, subdomain badge instead of category badge, domain color accent.

#### Matrix Tab (NEW)

A coverage heatmap showing subdomain (rows) x content type (columns). Each cell shows how many written submissions exist for that combination.

Layout:
```
                 bewijs  educatie  markt  contrair  eigen  tools
PSA              [  3  ] [  1   ] [  0 ] [   0   ] [ 0  ] [ 1 ]
Contracten       [  2  ] [  0   ] [  0 ] [   0   ] [ 0  ] [ 0 ]
Analyse          [  1  ] [  2   ] [  1 ] [   0   ] [ 0  ] [ 0 ]
...
```

Cell styling:
- 0 submissions: dark/empty cell with subtle dashed border (the gap)
- 1-2 submissions: partial fill, muted color
- 3+ submissions: full fill, bright color

Interaction:
- Hover a cell: tooltip showing the submission titles in that slot
- Click an empty cell: opens the board filtered to that subdomain, with content_type pre-suggested in the next submission for that area
- Click a filled cell: shows the list of submissions in that slot

Header row has the 6 content type labels with their jewel-tone colors. Left column has subdomain names grouped by domain (with domain headers as separators).

A summary row at the bottom shows totals per content type. A summary column on the right shows totals per subdomain.

The matrix reads from submission data (content_type field), not from topics. Empty cells represent content gaps -- opportunities for consultants.

#### Format Gallery Tab (NEW)

A browsable showcase of the 40 content formulas, grouped by style.

Layout per style group:
```
── Storytelling ──────────────────────────
┌─────────────────────┐ ┌─────────────────────┐
│ Case study:         │ │ Hoe [klant]         │
│ 1 probleem,         │ │ [resultaat] bereikte │
│ 1 oplossing,        │ │ met [aanpak]         │
│ 1 resultaat         │ │                      │
│                     │ │ Vertel hoe een klant │
│ Vertel het verhaal  │ │ een specifiek doel   │
│ van een specifiek   │ │ bereikte...          │
│ probleem...         │ │                      │
│            [Kies →] │ │            [Kies →] │
└─────────────────────┘ └─────────────────────┘
```

Each format card shows:
- Template text (the hook pattern, prominent)
- Description (1-2 sentences explaining when to use it)
- "Kies" button that navigates to the board with this format pre-selected for the next submission

Style groups (5):
- **Storytelling**: case studies, experiments, client journeys
- **Data-driven**: analyses, research, audits, comparisons
- **Opinion**: contrarian takes, convictions, "stop doing X"
- **Practical**: how-tos, templates, tools, step-by-step guides
- **Personal**: "what I would do", "what I wish I knew", reflections

A search/filter bar at the top allows text search across template patterns.

#### Editor (modified)

The sidebar currently shows the hook template and category badge. This changes to three pickers above the writing area.

**New editor layout:**

```
┌──────────────────────────────────────────────────────┐
│ ← Terug                                    [Auteur] │
├──────────────────────────────────────────────────────┤
│                                                      │
│  Titel (jouw kop)                                    │
│  ┌────────────────────────────────────────────────┐  │
│  │ _____________________________________________  │  │
│  └────────────────────────────────────────────────┘  │
│                                                      │
│  ┌─────────────┐ ┌──────────────┐ ┌──────────────┐  │
│  │ Formule  ▾  │ │ Type      ▾  │ │ OML       ▾  │  │
│  │ Case study  │ │ bewijs       │ │ OML 1-2      │  │
│  └─────────────┘ └──────────────┘ └──────────────┘  │
│                                                      │
├──────────────────────────────────────────────────────┤
│                                                      │
│  ┌─────────────────────┐ ┌────────────────────────┐  │
│  │                     │ │  MSP Journey context   │  │
│  │                     │ │                        │  │
│  │  [Writing area]     │ │  Problemen:            │  │
│  │                     │ │  - item 1              │  │
│  │                     │ │  - item 2              │  │
│  │                     │ │                        │  │
│  │                     │ │  Frustraties:           │  │
│  │                     │ │  - item 1              │  │
│  │                     │ │                        │  │
│  │                     │ │  Vragen:               │  │
│  │                     │ │  - item 1              │  │
│  │                     │ │                        │  │
│  └─────────────────────┘ └────────────────────────┘  │
│                                                      │
│  [Markeer als geschreven]  [Naar review]             │
└──────────────────────────────────────────────────────┘
```

**Title input** (new): text field at the top for the consultant's own headline. Saved on the submission, not the topic.

**Three pickers** (new):
- **Formule**: dropdown showing all 40 format templates, grouped by style. Selecting one shows the template pattern below the picker as writing guidance.
- **Type**: dropdown with 6 content types (bewijs, educatie, markt, contrair, eigen, tools) using their jewel-tone colors.
- **OML**: multi-select for maturity levels 1-5. Each level has a short label:
  - OML 1: Ad hoc / Chaotisch
  - OML 2: Basis op orde
  - OML 3: Gestandaardiseerd
  - OML 4: Geoptimaliseerd
  - OML 5: Innovatief

All three pickers are optional. The writer can fill them in at any point during writing. They save to the submission record.

**Sidebar** stays for the MSP Journey context (problemen, frustraties, vragen). The hook template and category badge are removed from the sidebar since they're now writer choices via the pickers.

When a format is selected, the chosen template text appears as a subtle guide below the title input: "Schrijf volgens: Case study: 1 probleem, 1 oplossing, 1 resultaat".

#### Dashboard (modified)

Current dashboard shows stats by category (pie/progress bars). Since category moves off topics:

- **Topic progress** stays: total/written/open/claimed counts by domain and subdomain
- **Content type distribution**: reads from submissions (how many bewijs vs educatie pieces have been written) instead of topic counts
- **OML coverage**: new stat showing how many pieces target each maturity level
- **Format usage**: which content formulas are most/least used
- **Leaderboard** stays: counts by author

The fake performance data from Phase 2.5 (impressions, clicks, engagement) stays unchanged -- it reads from calendar entries, not from topic metadata.

#### Calendar (minor changes)

- Topic picker in the calendar modal: shows `context.problemen[0]` instead of title
- Calendar entry title: stays as its own field (already independent of topic title)
- No other calendar changes needed

#### Export (minor changes)

CSV and Google Sheets export gain the new submission columns: `title`, `format_id` (resolved to template text), `content_type`, `oml_levels`. These appear as additional columns in the export output.

### API Changes

**Modified endpoints:**

`GET /api/topics` -- response no longer includes `title`, `hook`, `category`. Includes `context` as JSON.

`GET /api/topics/:id` -- same change. Submissions in response now include `title`, `format_id`, `content_type`, `oml_levels`.

`PATCH /api/submissions/:id` -- accepts new fields: `title`, `format_id`, `content_type`, `oml_levels`.

`POST /api/submissions` -- accepts new fields: `title`, `format_id`, `content_type`, `oml_levels`.

**New endpoints:**

`GET /api/formats` -- returns the full formats library.

`GET /api/matrix` -- returns the coverage matrix data: counts of submissions grouped by subdomain x content_type.

`GET /api/dashboard` -- modified to include OML coverage stats and format usage stats.

### Database Migrations

For existing databases with data, use the standard ALTER TABLE + PRAGMA pattern:

```sql
-- Add new columns to submissions
ALTER TABLE submissions ADD COLUMN title TEXT;
ALTER TABLE submissions ADD COLUMN format_id INTEGER;
ALTER TABLE submissions ADD COLUMN content_type TEXT;
ALTER TABLE submissions ADD COLUMN oml_levels TEXT;  -- JSON array

-- Remove columns from topics (SQLite doesn't support DROP COLUMN before 3.35)
-- Instead: leave title/hook/category columns in place, stop reading them.
-- The seed re-creation path handles clean schema for new databases.
```

For the topics table: SQLite on SiteGround may not support DROP COLUMN. The old `title`, `hook`, and `category` columns stay in the table but are ignored by the API. New databases created from seed get the clean schema without these columns.

### OML Level Definitions

For the OML picker, these short descriptions help consultants target correctly:

| Level | Label | One-liner |
|-------|-------|-----------|
| 1 | Ad hoc | Geen processen, alles handmatig, brandjes blussen |
| 2 | Basis op orde | Ticketing en facturatie draaien, rest niet |
| 3 | Gestandaardiseerd | Processen vastgelegd, KPIs gedefinieerd, eerste dashboards |
| 4 | Geoptimaliseerd | Data-driven beslissingen, geautomatiseerde workflows |
| 5 | Innovatief | Voorspellende analyses, AI-integratie, marktleider |

### Format Style Classification

Map the 40 hooks to 5 style groups. Approximate distribution:

- **Storytelling** (~10): case studies, client journeys, experiments, "wat ik zou doen", interviews
- **Data-driven** (~8): analyses, audits, vergelijkingen, vertical onderzoek, prijsoverzichten
- **Opinion** (~10): contraire meningen, overtuigingen, "stop met", "waarom X niet klopt", "throw stones"
- **Practical** (~8): how-tos, tools, templates, stappenplannen, frameworks, overzichten
- **Personal** (~4): eigen experiment, reflecties, "wat ik nooit meer zou doen", "wat ik zou willen dat..."

Exact mapping happens during seed data migration. Some hooks could fit multiple styles -- pick the primary intent.

### Future: Claude Integration (Phase 4)

The data model is ready for AI-assisted content creation. When Claude connects via API subscription:

- **Auto-suggest format + type + OML**: given a topic's context, Claude recommends the best format, content type, and target OML level
- **Gap-driven suggestions**: Claude reads the coverage matrix and suggests which cells to fill next
- **Content transformation**: take raw_content and transform it into a polished post using the selected format template
- **Title generation**: suggest headlines based on the content and chosen format

These capabilities use the same submission fields (format_id, content_type, oml_levels, title). The only difference is who fills them in: the writer manually, or Claude via API.

No schema changes needed for Phase 4. The infrastructure built in Phase 3 supports both manual and AI-driven workflows.
