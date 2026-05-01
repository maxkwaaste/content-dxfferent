<?php
// CSV export early exit (must run before JSON content-type header)
$csvPathCheck = $_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? '';
if (str_contains($csvPathCheck, '/export/csv')) {
    $csvDbPath = __DIR__ . '/data/content.db';
    if (!file_exists($csvDbPath)) { http_response_code(404); echo 'No database'; exit; }
    $csvDb = new PDO('sqlite:' . $csvDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    $stmt = $csvDb->prepare('SELECT scheduled_date, title, platform, account, content_type, status, impressions, clicks, engagement_rate, comments FROM calendar WHERE scheduled_date BETWEEN ? AND ? AND archived_at IS NULL ORDER BY scheduled_date');
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();
    $filename = 'content-calendar-' . date('Y-m', strtotime($from)) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo "date,title,platform,account,content_type,status,impressions,clicks,engagement_rate,comments\n";
    foreach ($rows as $r) {
        echo implode(',', [
            $r['scheduled_date'],
            '"' . str_replace('"', '""', $r['title']) . '"',
            $r['platform'], $r['account'], $r['content_type'], $r['status'],
            $r['impressions'] ?? '', $r['clicks'] ?? '',
            $r['engagement_rate'] ?? '', $r['comments'] ?? ''
        ]) . "\n";
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$allowedOrigin = 'https://content.dxfferent.com';
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
} else {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dbPath = __DIR__ . '/data/content.db';
$seedPath = __DIR__ . '/seed.json';
$needsSeed = !file_exists($dbPath);

$db = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA foreign_keys=ON');

if ($needsSeed) {
    $db->exec('
        CREATE TABLE topics (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            hook TEXT,
            category TEXT NOT NULL,
            domain TEXT NOT NULL,
            subdomain TEXT,
            context TEXT,
            status TEXT DEFAULT "open",
            claimed_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $db->exec('
        CREATE TABLE submissions (
            id INTEGER PRIMARY KEY,
            topic_id INTEGER,
            author TEXT NOT NULL,
            raw_content TEXT NOT NULL,
            category TEXT,
            domain TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME,
            FOREIGN KEY (topic_id) REFERENCES topics(id)
        )
    ');
    $db->exec('
        CREATE TABLE authors (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $topics = json_decode(file_get_contents($seedPath), true);
    $stmt = $db->prepare('INSERT INTO topics (title, hook, category, domain, subdomain, context) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($topics as $t) {
        $stmt->execute([
            $t['title'],
            $t['hook'] ?? null,
            $t['category'],
            $t['domain'],
            $t['subdomain'] ?? null,
            isset($t['context']) ? json_encode($t['context'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

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
            impressions INTEGER,
            clicks INTEGER,
            engagement_rate REAL,
            comments INTEGER,
            archived_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (topic_id) REFERENCES topics(id)
        )
    ');
    $db->exec('
        CREATE TABLE derivatives (
            id INTEGER PRIMARY KEY,
            submission_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            status TEXT DEFAULT "generated",
            archived_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )
    ');
    $db->exec('CREATE INDEX idx_derivatives_submission ON derivatives(submission_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');

    // Demo submissions
    $demoSubmissions = [
        ['topic_id' => 1, 'author' => 'Joshua', 'raw_content' => 'Demo content voor topic 1 over bewijs en resultaat in MSP dienstverlening.'],
        ['topic_id' => 2, 'author' => 'Tom', 'raw_content' => 'Demo content voor topic 2 over educatie en uitleg van IT processen.'],
        ['topic_id' => 3, 'author' => 'Joshua', 'raw_content' => 'Demo content voor topic 3 over marktvisie en trends in de MSP wereld.'],
        ['topic_id' => 4, 'author' => 'Sarah', 'raw_content' => 'Demo content voor topic 4 met een contrair standpunt over IT outsourcing.'],
        ['topic_id' => 5, 'author' => 'Tom', 'raw_content' => 'Demo content voor topic 5 over eigen verhaal en bedrijfscultuur.'],
    ];
    $subStmt = $db->prepare('INSERT INTO submissions (topic_id, author, raw_content, category, domain) VALUES (?, ?, ?, (SELECT category FROM topics WHERE id = ?), (SELECT domain FROM topics WHERE id = ?))');
    foreach ($demoSubmissions as $ds) {
        $subStmt->execute([$ds['topic_id'], $ds['author'], $ds['raw_content'], $ds['topic_id'], $ds['topic_id']]);
    }
    $db->exec("INSERT OR IGNORE INTO authors (name) VALUES ('Joshua'), ('Tom'), ('Sarah')");

    // Set topic statuses
    $db->exec("UPDATE topics SET status = 'written', claimed_by = 'Joshua' WHERE id IN (1, 3)");
    $db->exec("UPDATE topics SET status = 'written', claimed_by = 'Tom' WHERE id IN (2, 5)");
    $db->exec("UPDATE topics SET status = 'written', claimed_by = 'Sarah' WHERE id = 4");
    $db->exec("UPDATE topics SET status = 'claimed', claimed_by = 'Joshua' WHERE id IN (6, 7)");
    $db->exec("UPDATE topics SET status = 'review' WHERE id IN (8, 9)");

    // Demo calendar entries
    $calAccounts = ['Joshua', 'Tom', 'Sarah'];
    $calPlatforms = ['linkedin', 'dxfferent'];
    $calTypes = ['post', 'article', 'blog', 'video', 'case-study', 'poll'];
    $baseDate = new DateTime('monday this week');
    $calStmt = $db->prepare('INSERT INTO calendar (scheduled_date, topic_id, title, platform, account, content_type, status, impressions, clicks, engagement_rate, comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    for ($i = 0; $i < 15; $i++) {
        $d = clone $baseDate;
        $d->modify('+' . ($i < 8 ? $i - 14 : $i - 5) . ' days');
        while ((int)$d->format('N') > 5) $d->modify('+1 day');
        $topicId = ($i % 10) + 1;
        $status = $i < 8 ? 'posted' : 'planned';
        $platform = $calPlatforms[$i % 2];
        $account = $calAccounts[$i % 3];
        $type = $calTypes[$i % 6];
        $imp = $cli = $eng = $com = null;
        if ($status === 'posted') {
            $seed = ($i + 1) * 2654435761;
            $imp = 1500 + ($seed % 3000);
            $cli = 40 + (($seed >> 8) % 110);
            $eng = round(3 + (($seed >> 16) % 500) / 100, 1);
            $com = 2 + (($seed >> 24) % 13);
        }
        $topicTitle = $db->query("SELECT title FROM topics WHERE id = $topicId")->fetchColumn();
        $calStmt->execute([$d->format('Y-m-d'), $topicId, $topicTitle ?: "Demo post $i", $platform, $account, $type, $status, $imp, $cli, $eng, $com]);
    }

    // Derivatives
    $derivativeRules = [
        'bewijs' => ['linkedin-post', 'blog-article', 'email-snippet', 'infographic-brief'],
        'educatie' => ['linkedin-post', 'blog-article', 'social-carousel'],
        'markt' => ['linkedin-post', 'blog-article', 'newsletter-blurb'],
        'contrair' => ['linkedin-post', 'newsletter-blurb'],
        'eigen' => ['linkedin-post', 'blog-article', 'email-snippet'],
        'tools' => ['linkedin-post', 'social-carousel'],
    ];
    $titlePrefixes = [
        'linkedin-post' => '', 'blog-article' => 'Case study: ',
        'email-snippet' => 'Samenvatting: ', 'infographic-brief' => 'Visual: ',
        'social-carousel' => 'Carousel: ', 'newsletter-blurb' => 'Nieuwsbrief: ',
    ];
    $dSubs = $db->query("SELECT s.id, s.topic_id, t.title, t.category FROM submissions s JOIN topics t ON s.topic_id = t.id")->fetchAll();
    $derStmt = $db->prepare('INSERT INTO derivatives (submission_id, type, title) VALUES (?, ?, ?)');
    foreach ($dSubs as $sub) {
        $formats = $derivativeRules[$sub['category']] ?? ['linkedin-post'];
        foreach ($formats as $fmt) {
            $prefix = $titlePrefixes[$fmt] ?? '';
            $derTitle = $prefix ? $prefix . $sub['title'] : mb_substr($sub['title'], 0, 80) . '...';
            $derStmt->execute([$sub['id'], $fmt, $derTitle]);
        }
    }
}

// Migration: add new columns to existing tables
$calCols = array_column($db->query("PRAGMA table_info(calendar)")->fetchAll(), 'name');
if (!in_array('impressions', $calCols)) {
    $db->exec('ALTER TABLE calendar ADD COLUMN impressions INTEGER');
    $db->exec('ALTER TABLE calendar ADD COLUMN clicks INTEGER');
    $db->exec('ALTER TABLE calendar ADD COLUMN engagement_rate REAL');
    $db->exec('ALTER TABLE calendar ADD COLUMN comments INTEGER');
}
if (!in_array('archived_at', $calCols)) {
    $db->exec('ALTER TABLE calendar ADD COLUMN archived_at DATETIME');
}
$subCols = array_column($db->query("PRAGMA table_info(submissions)")->fetchAll(), 'name');
if (!in_array('archived_at', $subCols)) {
    $db->exec('ALTER TABLE submissions ADD COLUMN archived_at DATETIME');
}
$db->exec('CREATE TABLE IF NOT EXISTS derivatives (
    id INTEGER PRIMARY KEY, submission_id INTEGER NOT NULL, type TEXT NOT NULL,
    title TEXT NOT NULL, status TEXT DEFAULT "generated", archived_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_derivatives_submission ON derivatives(submission_id)');
$db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');

$db->exec('CREATE TABLE IF NOT EXISTS calendar (
    id INTEGER PRIMARY KEY,
    scheduled_date TEXT NOT NULL,
    topic_id INTEGER,
    title TEXT NOT NULL,
    platform TEXT NOT NULL DEFAULT "linkedin",
    account TEXT NOT NULL,
    content_type TEXT NOT NULL DEFAULT "post",
    status TEXT NOT NULL DEFAULT "planned",
    impressions INTEGER,
    clicks INTEGER,
    engagement_rate REAL,
    comments INTEGER,
    archived_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES topics(id)
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar(scheduled_date)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_submissions_topic ON submissions(topic_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_submissions_author ON submissions(author)');

// Phase 2 migrations
$topicCols = array_column($db->query("PRAGMA table_info(topics)")->fetchAll(), 'name');
if (!in_array('level', $topicCols)) $db->exec('ALTER TABLE topics ADD COLUMN level INTEGER');
if (!in_array('priority', $topicCols)) $db->exec('ALTER TABLE topics ADD COLUMN priority INTEGER');

$subCols2 = array_column($db->query("PRAGMA table_info(submissions)")->fetchAll(), 'name');
if (!in_array('content_type', $subCols2)) $db->exec("ALTER TABLE submissions ADD COLUMN content_type TEXT");

$derCols = array_column($db->query("PRAGMA table_info(derivatives)")->fetchAll(), 'name');
if (in_array('format', $derCols) && !in_array('type', $derCols)) {
    $db->exec('ALTER TABLE derivatives RENAME COLUMN format TO type');
}
foreach (['live_url'=>'TEXT','likes'=>'INTEGER','views'=>'INTEGER','der_comments'=>'INTEGER','last_updated'=>'DATETIME','scheduled_for'=>'DATETIME'] as $col=>$type) {
    if (!in_array($col, $derCols)) $db->exec("ALTER TABLE derivatives ADD COLUMN $col $type");
}

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

$db->exec('CREATE TABLE IF NOT EXISTS journey_items (
    id INTEGER PRIMARY KEY,
    domain TEXT NOT NULL,
    subdomain TEXT NOT NULL,
    type TEXT NOT NULL,
    text TEXT NOT NULL,
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_journey_domain ON journey_items(domain, subdomain)');

// Seed journey_items from JSON if table is empty
$journeyCount = (int)$db->query('SELECT COUNT(*) FROM journey_items')->fetchColumn();
if ($journeyCount === 0) {
    $journeyFile = __DIR__ . '/data/journey-data.json';
    if (file_exists($journeyFile)) {
        $journeyData = json_decode(file_get_contents($journeyFile), true);
        $jStmt = $db->prepare('INSERT INTO journey_items (domain, subdomain, type, text, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($journeyData as $domainBlock) {
            $domainName = $domainBlock['domain'];
            if ($domainName === 'Data Driven') $domainName = 'Data driven';
            foreach ($domainBlock['subdomains'] as $sub) {
                $order = 0;
                foreach (['problemen','frustraties','vragen'] as $type) {
                    if (!empty($sub[$type])) {
                        foreach ($sub[$type] as $text) {
                            $jStmt->execute([$domainName, $sub['name'], $type === 'problemen' ? 'probleem' : ($type === 'frustraties' ? 'frustratie' : 'vraag'), $text, $order++]);
                        }
                    }
                }
            }
        }
    }
}

// Domain rename migration
$needsDomainRename = (int)$db->query("SELECT COUNT(*) FROM topics WHERE domain IN ('Data Driven','Algemeen','Markt')")->fetchColumn() > 0;
if ($needsDomainRename) {
    $db->exec("UPDATE topics SET domain = 'Data driven' WHERE domain = 'Data Driven'");
    $db->exec("UPDATE topics SET domain = 'Identity'     WHERE domain = 'Algemeen'");
    $db->exec("UPDATE topics SET domain = 'Positioning'  WHERE domain = 'Markt'");
}

// Default levels for topics without level (PDF is L2-L3)
$needsLevel = (int)$db->query("SELECT COUNT(*) FROM topics WHERE level IS NULL")->fetchColumn() > 0;
if ($needsLevel) {
    $db->exec("UPDATE topics SET level = 2 + (id % 2) WHERE level IS NULL");
}

// Category to priority defaults
$needsPriority = (int)$db->query("SELECT COUNT(*) FROM topics WHERE priority IS NULL")->fetchColumn() > 0;
if ($needsPriority) {
    $db->exec("UPDATE topics SET priority = 5 WHERE priority IS NULL AND category IN ('bewijs','educatie')");
    $db->exec("UPDATE topics SET priority = 3 WHERE priority IS NULL AND category IN ('markt','contrair')");
    $db->exec("UPDATE topics SET priority = 2 WHERE priority IS NULL AND category IN ('eigen','tools')");
}

$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? '';
$pathInfo = trim($pathInfo, '/');
$parts = $pathInfo ? explode('/', $pathInfo) : [];

$input = json_decode(file_get_contents('php://input'), true) ?? [];

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function respondError($msg, $code = 400) {
    respond(['error' => $msg], $code);
}

$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;

const JOURNEY_DOMAINS = ['Identity','Organisation','GRC','Positioning','Portfolio','Technology','Blueprint','Data driven'];

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

switch ($resource) {
    case 'topics':
        if ($method === 'GET' && $id === null) {
            $where = [];
            $params = [];
            if (!empty($_GET['category'])) {
                $where[] = 't.category = ?';
                $params[] = $_GET['category'];
            }
            if (!empty($_GET['domain'])) {
                $where[] = 't.domain = ?';
                $params[] = $_GET['domain'];
            }
            if (!empty($_GET['status'])) {
                $where[] = 't.status = ?';
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['level'])) {
                $where[] = 't.level = ?';
                $params[] = $_GET['level'];
            }
            if (isset($_GET['view']) && $_GET['view'] === 'kanban') {
                $sql = 'SELECT t.id, t.title, t.hook, t.category, t.domain, t.subdomain, t.status, t.claimed_by, t.created_at, t.level, t.priority,
                  (SELECT GROUP_CONCAT(c.status) FROM calendar c WHERE c.topic_id = t.id AND c.archived_at IS NULL) as calendar_statuses
                FROM topics t';
            } else {
                $sql = 'SELECT t.id, t.title, t.hook, t.category, t.domain, t.subdomain, t.status, t.claimed_by, t.created_at, t.level, t.priority FROM topics t';
            }
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY t.id';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond($stmt->fetchAll());

        } elseif ($method === 'GET' && $id === 'matrix') {
            $results = [];
            foreach (JOURNEY_DOMAINS as $domain) {
                for ($level = 1; $level <= 5; $level++) {
                    $stmt = $db->prepare('SELECT COUNT(*) as cnt, MAX(priority) as max_p, GROUP_CONCAT(id) as ids FROM topics WHERE domain = ? AND level = ?');
                    $stmt->execute([$domain, $level]);
                    $row = $stmt->fetch();
                    $results[] = [
                        'domain' => $domain,
                        'level' => $level,
                        'count' => (int)$row['cnt'],
                        'max_priority' => $row['max_p'] !== null ? (int)$row['max_p'] : null,
                        'topic_ids' => $row['ids'] ? array_map('intval', explode(',', $row['ids'])) : []
                    ];
                }
            }
            respond($results);

        } elseif ($method === 'GET' && $id !== null) {
            $stmt = $db->prepare('SELECT * FROM topics WHERE id = ?');
            $stmt->execute([$id]);
            $topic = $stmt->fetch();
            if (!$topic) respondError('Topic not found', 404);
            if ($topic['context']) {
                $topic['context'] = json_decode($topic['context'], true);
            }
            $subStmt = $db->prepare('SELECT * FROM submissions WHERE topic_id = ? AND archived_at IS NULL ORDER BY created_at DESC');
            $subStmt->execute([$id]);
            $topic['submissions'] = $subStmt->fetchAll();
            respond($topic);

        } elseif ($method === 'PATCH' && $id !== null) {
            // Snapshot before change
            $beforeStmt = $db->prepare('SELECT * FROM topics WHERE id = ?');
            $beforeStmt->execute([$id]);
            $before = $beforeStmt->fetch();
            if (!$before) respondError('Topic not found', 404);

            // Save revision snapshot
            $db->prepare('INSERT INTO revisions (target_type, target_id, snapshot_json, created_by) VALUES (?, ?, ?, ?)')
                ->execute(['topic', $id, json_encode($before, JSON_UNESCAPED_UNICODE), $input['actor'] ?? null]);

            $topic = patchRecord($db, 'topics', $id, $input,
                ['status', 'claimed_by', 'title', 'hook', 'level', 'priority', 'category', 'domain'],
                ['status' => ['open', 'claimed', 'review', 'written']]
            );

            // Write audit log
            $db->prepare('INSERT INTO audit_log (actor, action, target_type, target_id, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $input['actor'] ?? 'unknown',
                    'topic.update',
                    'topic',
                    $id,
                    json_encode($before, JSON_UNESCAPED_UNICODE),
                    json_encode($topic, JSON_UNESCAPED_UNICODE)
                ]);

            if ($topic['context']) {
                $topic['context'] = json_decode($topic['context'], true);
            }
            respond($topic);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'submissions':
        if ($method === 'GET' && $id === null) {
            $where = ['archived_at IS NULL'];
            $params = [];
            if (!empty($_GET['topic_id'])) {
                $where[] = 'topic_id = ?';
                $params[] = $_GET['topic_id'];
            }
            if (!empty($_GET['author'])) {
                $where[] = 'author = ?';
                $params[] = $_GET['author'];
            }
            if (!empty($_GET['content_type'])) {
                $where[] = 'content_type = ?';
                $params[] = $_GET['content_type'];
            }
            $sql = 'SELECT * FROM submissions WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY created_at DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond($stmt->fetchAll());

        } elseif ($method === 'POST') {
            if (empty($input['author']) || !isset($input['raw_content'])) {
                respondError('author and raw_content required');
            }
            $allowedContentTypes = ['post','article','blog','video','case-study','poll'];
            if (!empty($input['content_type']) && !in_array($input['content_type'], $allowedContentTypes)) {
                respondError('Invalid content_type. Allowed: ' . implode(', ', $allowedContentTypes), 400);
            }
            $topicId = $input['topic_id'] ?? null;
            if ($topicId !== null) {
                $check = $db->prepare('SELECT id FROM topics WHERE id = ?');
                $check->execute([$topicId]);
                if (!$check->fetch()) respondError('Topic not found', 404);
            }
            $stmt = $db->prepare('INSERT INTO submissions (topic_id, author, raw_content, category, domain, content_type) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $topicId,
                $input['author'],
                $input['raw_content'],
                $input['category'] ?? null,
                $input['domain'] ?? null,
                $input['content_type'] ?? null,
            ]);
            $newId = $db->lastInsertId();

            $authorStmt = $db->prepare('INSERT OR IGNORE INTO authors (name) VALUES (?)');
            $authorStmt->execute([$input['author']]);

            $stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
            $stmt->execute([$newId]);
            respond($stmt->fetch(), 201);

        } elseif ($method === 'PATCH' && $id !== null) {
            $input['updated_at'] = date('Y-m-d H:i:s');
            $sub = patchRecord($db, 'submissions', $id, $input,
                ['raw_content', 'category', 'domain', 'updated_at', 'content_type']
            );
            respond($sub);

        } elseif ($method === 'DELETE' && $id !== null) {
            $stmt = $db->prepare("UPDATE submissions SET archived_at = datetime('now') WHERE id = ? AND archived_at IS NULL");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) respondError('Submission not found', 404);
            respond(['deleted' => true]);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'authors':
        if ($method === 'GET') {
            $stmt = $db->query('SELECT * FROM authors ORDER BY name');
            respond($stmt->fetchAll());

        } elseif ($method === 'POST') {
            if (empty($input['name'])) respondError('name required');
            $stmt = $db->prepare('INSERT OR IGNORE INTO authors (name) VALUES (?)');
            $stmt->execute([$input['name']]);
            $isNew = (bool)$db->lastInsertId();
            $stmt = $db->prepare('SELECT * FROM authors WHERE name = ?');
            $stmt->execute([$input['name']]);
            respond($stmt->fetch(), $isNew ? 201 : 200);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'stats':
        if ($method === 'GET') {
            $byStatus = $db->query("SELECT status, COUNT(*) as count FROM topics GROUP BY status")->fetchAll();
            $byCat = $db->query("SELECT category, COUNT(*) as count FROM topics GROUP BY category")->fetchAll();
            $byDomain = $db->query("SELECT domain, COUNT(*) as count FROM topics GROUP BY domain ORDER BY count DESC")->fetchAll();
            $totalSubs = $db->query("SELECT COUNT(*) as count FROM submissions")->fetch();
            respond([
                'by_status' => $byStatus,
                'by_category' => $byCat,
                'by_domain' => $byDomain,
                'total_submissions' => (int)$totalSubs['count'],
            ]);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'calendar':
        $action = $parts[1] ?? null;

        if ($method === 'POST' && $action === 'auto-plan') {
            $weeks = (int)($_GET['weeks'] ?? 2);
            $accounts = $_GET['accounts'] ?? null;
            $accountList = $accounts ? explode(',', $accounts) : [];
            if (!$accountList) {
                $authorRows = $db->query('SELECT name FROM authors ORDER BY name')->fetchAll();
                $accountList = array_column($authorRows, 'name');
            }
            if (!$accountList) respondError('No accounts available. Add at least one author first.');

            $platforms = ['linkedin', 'dxfferent'];
            $startDate = new DateTime('tomorrow');
            while ((int)$startDate->format('N') > 5) {
                $startDate->modify('+1 day');
            }
            $endDate = clone $startDate;
            $endDate->modify('+' . ($weeks * 7) . ' days');

            $existingStmt = $db->prepare('SELECT scheduled_date FROM calendar WHERE scheduled_date BETWEEN ? AND ?');
            $existingStmt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            $existingDates = array_column($existingStmt->fetchAll(), 'scheduled_date');

            $scheduledTopicIds = array_column(
                $db->query('SELECT DISTINCT topic_id FROM calendar WHERE topic_id IS NOT NULL')->fetchAll(),
                'topic_id'
            );
            $placeholders = $scheduledTopicIds ? implode(',', array_fill(0, count($scheduledTopicIds), '?')) : '0';
            $topicStmt = $db->prepare("SELECT id, title, category, domain FROM topics WHERE id NOT IN ($placeholders) ORDER BY id");
            $topicStmt->execute($scheduledTopicIds ?: []);
            $availableTopics = $topicStmt->fetchAll();

            if (!$availableTopics) respondError('No unscheduled topics available.');

            $catGroups = [];
            foreach ($availableTopics as $t) {
                $catGroups[$t['category']][] = $t;
            }
            $catKeys = array_keys($catGroups);
            $catIdx = 0;

            $created = [];
            $accountIdx = 0;
            $platformIdx = 0;
            $current = clone $startDate;

            while ($current < $endDate) {
                $dow = (int)$current->format('N');
                if ($dow > 5) { $current->modify('+1 day'); continue; }
                $dateStr = $current->format('Y-m-d');
                if (in_array($dateStr, $existingDates)) { $current->modify('+1 day'); continue; }

                $topic = null;
                $tried = 0;
                while ($tried < count($catKeys)) {
                    $cat = $catKeys[$catIdx % count($catKeys)];
                    $catIdx++;
                    if (!empty($catGroups[$cat])) {
                        $topic = array_shift($catGroups[$cat]);
                        break;
                    }
                    $tried++;
                }
                if (!$topic && $availableTopics) {
                    foreach ($catGroups as &$group) {
                        if (!empty($group)) { $topic = array_shift($group); break; }
                    }
                    unset($group);
                }
                if (!$topic) break;

                $account = $accountList[$accountIdx % count($accountList)];
                $platform = $platforms[$platformIdx % count($platforms)];
                $accountIdx++;
                $platformIdx++;

                $stmt = $db->prepare('INSERT INTO calendar (scheduled_date, topic_id, title, platform, account, content_type) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$dateStr, $topic['id'], $topic['title'], $platform, $account, 'post']);
                $created[] = [
                    'id' => (int)$db->lastInsertId(),
                    'scheduled_date' => $dateStr,
                    'topic_id' => (int)$topic['id'],
                    'title' => $topic['title'],
                    'platform' => $platform,
                    'account' => $account,
                    'content_type' => 'post',
                    'status' => 'planned',
                ];

                $current->modify('+1 day');
            }

            respond(['created' => count($created), 'entries' => $created]);

        } elseif ($method === 'GET' && $action === null) {
            $from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
            $to = $_GET['to'] ?? date('Y-m-d', strtotime($from . ' +4 weeks'));
            $stmt = $db->prepare('SELECT c.*, t.category as topic_category, t.domain as topic_domain FROM calendar c LEFT JOIN topics t ON c.topic_id = t.id WHERE c.scheduled_date BETWEEN ? AND ? AND c.archived_at IS NULL ORDER BY c.scheduled_date, c.id');
            $stmt->execute([$from, $to]);
            respond($stmt->fetchAll());

        } elseif ($method === 'POST' && $action === null) {
            if (empty($input['scheduled_date']) || empty($input['title']) || empty($input['account'])) {
                respondError('scheduled_date, title, and account required');
            }
            $stmt = $db->prepare('INSERT INTO calendar (scheduled_date, topic_id, title, platform, account, content_type, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $input['scheduled_date'],
                $input['topic_id'] ?? null,
                $input['title'],
                $input['platform'] ?? 'linkedin',
                $input['account'],
                $input['content_type'] ?? 'post',
                $input['status'] ?? 'planned',
            ]);
            $newId = $db->lastInsertId();
            $stmt = $db->prepare('SELECT * FROM calendar WHERE id = ?');
            $stmt->execute([$newId]);
            respond($stmt->fetch(), 201);

        } elseif ($method === 'PATCH' && $action !== null) {
            $calId = $action;
            $entry = patchRecord($db, 'calendar', $calId, $input,
                ['scheduled_date', 'title', 'platform', 'account', 'content_type', 'status', 'topic_id'],
                ['status' => ['planned', 'posted', 'skipped']]
            );
            respond($entry);

        } elseif ($method === 'DELETE' && $action !== null) {
            $calId = $action;
            $stmt = $db->prepare("UPDATE calendar SET archived_at = datetime('now') WHERE id = ? AND archived_at IS NULL");
            $stmt->execute([$calId]);
            if ($stmt->rowCount() === 0) respondError('Calendar entry not found', 404);
            respond(['deleted' => true]);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'dashboard':
        if ($method === 'GET') {
            $byDomain = $db->query("
                SELECT domain,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'written' THEN 1 ELSE 0 END) as written
                FROM topics GROUP BY domain ORDER BY total DESC
            ")->fetchAll();

            $byCat = $db->query("
                SELECT category,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'written' THEN 1 ELSE 0 END) as written
                FROM topics GROUP BY category ORDER BY total DESC
            ")->fetchAll();

            $leaderboard = $db->query("
                SELECT author, COUNT(*) as submissions,
                    COUNT(DISTINCT topic_id) as topics_touched
                FROM submissions WHERE author != '' AND archived_at IS NULL GROUP BY author ORDER BY submissions DESC
            ")->fetchAll();

            $recentActivity = $db->query("
                SELECT s.id, s.author, s.created_at, s.topic_id, t.title as topic_title, t.category
                FROM submissions s LEFT JOIN topics t ON s.topic_id = t.id
                WHERE s.archived_at IS NULL
                ORDER BY s.created_at DESC LIMIT 10
            ")->fetchAll();

            $calendarStats = $db->query("
                SELECT
                    COUNT(*) as total_planned,
                    SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted,
                    SUM(CASE WHEN status = 'planned' AND scheduled_date >= date('now') THEN 1 ELSE 0 END) as upcoming
                FROM calendar WHERE archived_at IS NULL
            ")->fetch();

            // Performance stats
            $perfStats = $db->query("
                SELECT
                    COALESCE(AVG(impressions), 0) as avg_impressions,
                    COALESCE(AVG(clicks), 0) as avg_clicks,
                    COALESCE(AVG(engagement_rate), 0) as avg_engagement,
                    COALESCE(SUM(impressions), 0) as total_impressions,
                    COALESCE(SUM(clicks), 0) as total_clicks,
                    COUNT(*) as total_posted
                FROM calendar WHERE status = 'posted' AND archived_at IS NULL AND impressions IS NOT NULL
            ")->fetch();

            // Top performing
            $topPerforming = $db->query("
                SELECT title, platform, account, impressions, clicks, engagement_rate, comments
                FROM calendar WHERE status = 'posted' AND archived_at IS NULL AND impressions IS NOT NULL
                ORDER BY impressions DESC LIMIT 5
            ")->fetchAll();

            // Derivative stats
            $derivativeStats = $db->query("
                SELECT type, COUNT(*) as count
                FROM derivatives WHERE archived_at IS NULL
                GROUP BY type ORDER BY count DESC
            ")->fetchAll();

            respond([
                'by_domain' => $byDomain,
                'by_category' => $byCat,
                'leaderboard' => $leaderboard,
                'recent_activity' => $recentActivity,
                'calendar_stats' => $calendarStats,
                'performance' => $perfStats,
                'top_performing' => $topPerforming,
                'derivative_stats' => $derivativeStats,
            ]);
        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'derivatives':
        $action = $parts[1] ?? null;
        if ($method === 'GET' && $action === null) {
            // List derivatives for a submission
            $submissionId = $_GET['submission_id'] ?? null;
            if (!$submissionId) respondError('submission_id required');
            $stmt = $db->prepare('SELECT * FROM derivatives WHERE submission_id = ? AND archived_at IS NULL ORDER BY created_at');
            $stmt->execute([$submissionId]);
            respond($stmt->fetchAll());

        } elseif ($method === 'GET' && $action === 'tree') {
            $topicId = $_GET['topic_id'] ?? null;
            if ($topicId) {
                $stmt = $db->prepare('SELECT t.id as topic_id, t.title as topic_title, t.domain, t.category, s.id as submission_id, s.author, d.id as derivative_id, d.type, d.title as derivative_title, d.status as derivative_status, d.live_url, d.likes, d.views, d.der_comments, d.last_updated, d.scheduled_for
                    FROM topics t
                    JOIN submissions s ON s.topic_id = t.id AND s.archived_at IS NULL
                    LEFT JOIN derivatives d ON s.id = d.submission_id AND d.archived_at IS NULL
                    WHERE t.id = ?
                    ORDER BY t.id, s.id, d.id');
                $stmt->execute([$topicId]);
            } else {
                $stmt = $db->query('SELECT t.id as topic_id, t.title as topic_title, t.domain, t.category, s.id as submission_id, s.author, d.id as derivative_id, d.type, d.title as derivative_title, d.status as derivative_status, d.live_url, d.likes, d.views, d.der_comments, d.last_updated, d.scheduled_for
                    FROM topics t
                    JOIN submissions s ON s.topic_id = t.id AND s.archived_at IS NULL
                    LEFT JOIN derivatives d ON s.id = d.submission_id AND d.archived_at IS NULL
                    ORDER BY t.id, s.id, d.id');
            }
            $rows = $stmt->fetchAll();
            $tree = [];
            foreach ($rows as $r) {
                $tid = $r['topic_id'];
                $sid = $r['submission_id'];
                if (!isset($tree[$tid])) {
                    $tree[$tid] = ['topic_id' => (int)$tid, 'title' => $r['topic_title'], 'domain' => $r['domain'], 'category' => $r['category'], 'submissions' => []];
                }
                if (!isset($tree[$tid]['submissions'][$sid])) {
                    $tree[$tid]['submissions'][$sid] = ['submission_id' => (int)$sid, 'author' => $r['author'], 'derivatives' => []];
                }
                if ($r['derivative_id']) {
                    $tree[$tid]['submissions'][$sid]['derivatives'][] = [
                        'id' => (int)$r['derivative_id'], 'type' => $r['type'], 'title' => $r['derivative_title'],
                        'status' => $r['derivative_status'], 'live_url' => $r['live_url'],
                        'likes' => $r['likes'] !== null ? (int)$r['likes'] : null,
                        'views' => $r['views'] !== null ? (int)$r['views'] : null,
                        'der_comments' => $r['der_comments'] !== null ? (int)$r['der_comments'] : null,
                        'last_updated' => $r['last_updated'], 'scheduled_for' => $r['scheduled_for']
                    ];
                }
            }
            foreach ($tree as &$t) { $t['submissions'] = array_values($t['submissions']); }
            respond(array_values($tree));

        } elseif ($method === 'GET' && $action === 'stats') {
            $stats = $db->query('SELECT type, COUNT(*) as count FROM derivatives WHERE archived_at IS NULL GROUP BY type ORDER BY count DESC')->fetchAll();
            respond($stats);

        } elseif ($method === 'PATCH' && $action !== null) {
            $derId = $action;
            if (array_key_exists('likes', $input) || array_key_exists('views', $input) || array_key_exists('der_comments', $input)) {
                $input['last_updated'] = date('Y-m-d H:i:s');
            }
            $der = patchRecord($db, 'derivatives', $derId, $input,
                ['live_url', 'likes', 'views', 'der_comments', 'last_updated', 'status', 'scheduled_for', 'type', 'title'],
                ['status' => ['generated', 'scheduled', 'posted', 'archived']]
            );
            respond($der);

        } elseif ($method === 'POST' && $action === null) {
            if (empty($input['submission_id'])) respondError('submission_id required');
            $allowedTypes = ['linkedin-post','blog-article','email-snippet','infographic-brief','social-carousel','newsletter-blurb'];
            $stmt = $db->prepare('INSERT INTO derivatives (submission_id, type, title, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([$input['submission_id'], $input['type'] ?? 'linkedin-post', $input['title'] ?? 'Untitled', $input['status'] ?? 'generated']);
            $newId = $db->lastInsertId();
            $stmt = $db->prepare('SELECT * FROM derivatives WHERE id = ?');
            $stmt->execute([$newId]);
            respond($stmt->fetch(), 201);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'settings':
        $key = $parts[1] ?? null;
        if ($method === 'GET' && $key !== null) {
            $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            respond(['key' => $key, 'value' => $row ? $row['value'] : null]);

        } elseif ($method === 'POST') {
            if (empty($input['key'])) respondError('key required');
            $stmt = $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
            $stmt->execute([$input['key'], $input['value'] ?? null]);
            respond(['key' => $input['key'], 'value' => $input['value'] ?? null]);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'export':
        $action = $parts[1] ?? null;
        if ($method === 'POST' && $action === 'sheets') {
            // Sync to Google Sheets via webhook
            $webhookStmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
            $webhookStmt->execute(['sheets_webhook_url']);
            $webhookRow = $webhookStmt->fetch();
            if (!$webhookRow || !$webhookRow['value']) respondError('No webhook URL configured. Set it in Settings first.');

            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-t');
            $stmt = $db->prepare('SELECT scheduled_date, title, platform, account, content_type, status, impressions, clicks, engagement_rate, comments FROM calendar WHERE scheduled_date BETWEEN ? AND ? AND archived_at IS NULL ORDER BY scheduled_date');
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll();

            $ch = curl_init($webhookRow['value']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['rows' => $rows, 'from' => $from, 'to' => $to]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                respond(['synced' => true, 'rows' => count($rows)]);
            } else {
                respondError('Webhook failed with HTTP ' . $httpCode, 502);
            }

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'demo-data':
        $action = $parts[1] ?? null;
        if ($method === 'POST' && $action === 'clear') {
            // Null out metrics, archive derivatives
            $db->exec("UPDATE calendar SET impressions = NULL, clicks = NULL, engagement_rate = NULL, comments = NULL WHERE archived_at IS NULL");
            $db->exec("UPDATE derivatives SET archived_at = datetime('now') WHERE archived_at IS NULL");
            respond(['cleared' => true]);

        } elseif ($method === 'POST' && $action === 'generate') {
            // Fill NULL metrics on posted entries with deterministic fake data
            $posted = $db->query("SELECT id FROM calendar WHERE status = 'posted' AND impressions IS NULL AND archived_at IS NULL")->fetchAll();
            $count = 0;
            $updStmt = $db->prepare('UPDATE calendar SET impressions = ?, clicks = ?, engagement_rate = ?, comments = ? WHERE id = ?');
            foreach ($posted as $row) {
                $seed = ($row['id'] + 1) * 2654435761;
                $imp = 1500 + ($seed % 3000);
                $cli = 40 + (($seed >> 8) % 110);
                $eng = round(3 + (($seed >> 16) % 500) / 100, 1);
                $com = 2 + (($seed >> 24) % 13);
                $updStmt->execute([$imp, $cli, $eng, $com, $row['id']]);
                $count++;
            }
            respond(['generated' => $count]);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'hooks':
        if ($method === 'GET') {
            $hooksFile = __DIR__ . '/data/hook-templates.json';
            if (!file_exists($hooksFile)) respondError('Hook templates not found', 404);
            $hooks = json_decode(file_get_contents($hooksFile), true);
            if (!empty($_GET['type'])) {
                $type = $_GET['type'];
                $hooks = array_values(array_filter($hooks, fn($h) => in_array($type, $h['applicable_types'] ?? [])));
            }
            if (!empty($_GET['category'])) {
                $cat = $_GET['category'];
                $hooks = array_values(array_filter($hooks, fn($h) => in_array($cat, $h['source_categories'] ?? [])));
            }
            respond($hooks);
        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'audit-log':
        if ($method === 'GET') {
            $where = [];
            $params = [];
            if (!empty($_GET['target_type'])) { $where[] = 'target_type = ?'; $params[] = $_GET['target_type']; }
            if (!empty($_GET['target_id'])) { $where[] = 'target_id = ?'; $params[] = $_GET['target_id']; }
            $limit = min((int)($_GET['limit'] ?? 50), 200);
            $sql = 'SELECT * FROM audit_log';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond($stmt->fetchAll());
        } elseif ($method === 'POST') {
            if (empty($input['actor']) || empty($input['action']) || empty($input['target_type']) || !isset($input['target_id'])) {
                respondError('actor, action, target_type, target_id required');
            }
            $stmt = $db->prepare('INSERT INTO audit_log (actor, action, target_type, target_id, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$input['actor'], $input['action'], $input['target_type'], $input['target_id'], $input['before_json'] ?? null, $input['after_json'] ?? null]);
            respond(['id' => (int)$db->lastInsertId()], 201);
        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'revisions':
        $revAction = $parts[1] ?? null;
        $revId = $parts[1] ?? null;
        $revSubAction = $parts[2] ?? null;
        if ($method === 'POST' && $revSubAction === 'restore') {
            $stmt = $db->prepare('SELECT * FROM revisions WHERE id = ?');
            $stmt->execute([$revId]);
            $rev = $stmt->fetch();
            if (!$rev) respondError('Revision not found', 404);
            $snapshot = json_decode($rev['snapshot_json'], true);
            if (!$snapshot) respondError('Invalid snapshot', 500);
            if ($rev['target_type'] === 'topic') {
                $allowed = ['title','hook','category','domain','subdomain','status','claimed_by','level','priority'];
                $fields = []; $vals = [];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $snapshot)) { $fields[] = "$f = ?"; $vals[] = $snapshot[$f]; }
                }
                if ($fields) {
                    $vals[] = $rev['target_id'];
                    $db->prepare('UPDATE topics SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
                }
                $afterStmt = $db->prepare('SELECT * FROM topics WHERE id = ?');
                $afterStmt->execute([$rev['target_id']]);
                $after = $afterStmt->fetch();
                $db->prepare('INSERT INTO audit_log (actor, action, target_type, target_id, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)')->execute([
                    'system', 'topic.undo', 'topic', $rev['target_id'], null, json_encode($after, JSON_UNESCAPED_UNICODE)
                ]);
                respond($after);
            } else {
                respondError('Restore not supported for type: ' . $rev['target_type'], 400);
            }
        } elseif ($method === 'POST' && $revAction === null) {
            if (empty($input['target_type']) || !isset($input['target_id']) || empty($input['snapshot_json'])) {
                respondError('target_type, target_id, snapshot_json required');
            }
            $stmt = $db->prepare('INSERT INTO revisions (target_type, target_id, snapshot_json, created_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$input['target_type'], $input['target_id'], $input['snapshot_json'], $input['created_by'] ?? null]);
            respond(['id' => (int)$db->lastInsertId()], 201);
        } elseif ($method === 'GET') {
            $where = [];
            $params = [];
            if (!empty($_GET['target_type'])) { $where[] = 'target_type = ?'; $params[] = $_GET['target_type']; }
            if (!empty($_GET['target_id'])) { $where[] = 'target_id = ?'; $params[] = $_GET['target_id']; }
            $sql = 'SELECT * FROM revisions';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY created_at DESC LIMIT 50';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond($stmt->fetchAll());
        } else {
            respondError('Method not allowed', 405);
        }
        break;

    case 'journey':
        $jId = $parts[1] ?? null;
        if ($method === 'GET' && $jId === null) {
            $rows = $db->query('SELECT * FROM journey_items ORDER BY domain, subdomain, type, sort_order, id')->fetchAll();
            $grouped = [];
            foreach ($rows as $r) {
                $d = $r['domain'];
                $s = $r['subdomain'];
                if (!isset($grouped[$d])) $grouped[$d] = ['domain' => $d, 'subdomains' => []];
                if (!isset($grouped[$d]['subdomains'][$s])) $grouped[$d]['subdomains'][$s] = ['name' => $s, 'items' => []];
                $grouped[$d]['subdomains'][$s]['items'][] = [
                    'id' => (int)$r['id'],
                    'type' => $r['type'],
                    'text' => $r['text'],
                    'sort_order' => (int)$r['sort_order'],
                ];
            }
            $result = [];
            foreach (JOURNEY_DOMAINS as $dom) {
                if (isset($grouped[$dom])) {
                    $entry = $grouped[$dom];
                    $entry['subdomains'] = array_values($entry['subdomains']);
                    $result[] = $entry;
                }
            }
            respond($result);

        } elseif ($method === 'GET' && $jId === 'domain') {
            $domain = $_GET['domain'] ?? null;
            if (!$domain) respondError('domain parameter required');
            $stmt = $db->prepare('SELECT * FROM journey_items WHERE domain = ? ORDER BY subdomain, type, sort_order, id');
            $stmt->execute([$domain]);
            $rows = $stmt->fetchAll();
            $grouped = [];
            foreach ($rows as $r) {
                $s = $r['subdomain'];
                if (!isset($grouped[$s])) $grouped[$s] = ['name' => $s, 'problemen' => [], 'frustraties' => [], 'vragen' => []];
                $typeKey = $r['type'] === 'probleem' ? 'problemen' : ($r['type'] === 'frustratie' ? 'frustraties' : 'vragen');
                $grouped[$s][$typeKey][] = ['id' => (int)$r['id'], 'text' => $r['text']];
            }
            respond(['domain' => $domain, 'subdomains' => array_values($grouped)]);

        } elseif ($method === 'POST' && $jId === null) {
            if (empty($input['domain']) || empty($input['subdomain']) || empty($input['type']) || empty($input['text'])) {
                respondError('domain, subdomain, type, text required');
            }
            $allowedTypes = ['probleem','frustratie','vraag'];
            if (!in_array($input['type'], $allowedTypes)) respondError('type must be: ' . implode(', ', $allowedTypes));
            $maxOrder = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM journey_items WHERE domain = ? AND subdomain = ? AND type = ?');
            $maxOrder->execute([$input['domain'], $input['subdomain'], $input['type']]);
            $nextOrder = (int)$maxOrder->fetchColumn();
            $stmt = $db->prepare('INSERT INTO journey_items (domain, subdomain, type, text, sort_order) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$input['domain'], $input['subdomain'], $input['type'], $input['text'], $nextOrder]);
            $newId = $db->lastInsertId();
            $stmt = $db->prepare('SELECT * FROM journey_items WHERE id = ?');
            $stmt->execute([$newId]);
            respond($stmt->fetch(), 201);

        } elseif ($method === 'PATCH' && $jId !== null) {
            $item = patchRecord($db, 'journey_items', $jId, $input, ['text', 'sort_order', 'domain', 'subdomain', 'type']);
            respond($item);

        } elseif ($method === 'DELETE' && $jId !== null) {
            $stmt = $db->prepare('DELETE FROM journey_items WHERE id = ?');
            $stmt->execute([$jId]);
            if ($stmt->rowCount() === 0) respondError('Item not found', 404);
            respond(['deleted' => true]);

        } else {
            respondError('Method not allowed', 405);
        }
        break;

    default:
        respondError('Not found', 404);
}
