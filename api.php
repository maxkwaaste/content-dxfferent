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
            format TEXT NOT NULL,
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
    $derStmt = $db->prepare('INSERT INTO derivatives (submission_id, format, title) VALUES (?, ?, ?)');
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
    id INTEGER PRIMARY KEY, submission_id INTEGER NOT NULL, format TEXT NOT NULL,
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
            if (isset($_GET['view']) && $_GET['view'] === 'kanban') {
                $sql = 'SELECT t.id, t.title, t.hook, t.category, t.domain, t.subdomain, t.status, t.claimed_by, t.created_at,
                  (SELECT GROUP_CONCAT(c.status) FROM calendar c WHERE c.topic_id = t.id AND c.archived_at IS NULL) as calendar_statuses
                FROM topics t';
            } else {
                $sql = 'SELECT t.id, t.title, t.hook, t.category, t.domain, t.subdomain, t.status, t.claimed_by, t.created_at FROM topics t';
            }
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY t.id';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond($stmt->fetchAll());

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
            $topic = patchRecord($db, 'topics', $id, $input,
                ['status', 'claimed_by', 'title', 'hook'],
                ['status' => ['open', 'claimed', 'review', 'written']]
            );
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
            $sql = 'SELECT * FROM submissions WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY created_at DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respond($stmt->fetchAll());

        } elseif ($method === 'POST') {
            if (empty($input['author']) || !isset($input['raw_content'])) {
                respondError('author and raw_content required');
            }
            $topicId = $input['topic_id'] ?? null;
            if ($topicId !== null) {
                $check = $db->prepare('SELECT id FROM topics WHERE id = ?');
                $check->execute([$topicId]);
                if (!$check->fetch()) respondError('Topic not found', 404);
            }
            $stmt = $db->prepare('INSERT INTO submissions (topic_id, author, raw_content, category, domain) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $topicId,
                $input['author'],
                $input['raw_content'],
                $input['category'] ?? null,
                $input['domain'] ?? null,
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
                ['raw_content', 'category', 'domain', 'updated_at']
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
                SELECT format, COUNT(*) as count
                FROM derivatives WHERE archived_at IS NULL
                GROUP BY format ORDER BY count DESC
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
            // Nested tree: topic -> submissions -> derivatives
            $topicId = $_GET['topic_id'] ?? null;
            if (!$topicId) respondError('topic_id required');
            $stmt = $db->prepare('SELECT s.id as submission_id, s.author, s.raw_content, d.id as derivative_id, d.format, d.title, d.status as derivative_status
                FROM submissions s LEFT JOIN derivatives d ON s.id = d.submission_id AND d.archived_at IS NULL
                WHERE s.topic_id = ? AND s.archived_at IS NULL ORDER BY s.id, d.id');
            $stmt->execute([$topicId]);
            $rows = $stmt->fetchAll();
            $tree = [];
            foreach ($rows as $r) {
                $sid = $r['submission_id'];
                if (!isset($tree[$sid])) {
                    $tree[$sid] = [
                        'submission_id' => (int)$sid,
                        'author' => $r['author'],
                        'derivatives' => []
                    ];
                }
                if ($r['derivative_id']) {
                    $tree[$sid]['derivatives'][] = [
                        'id' => (int)$r['derivative_id'],
                        'format' => $r['format'],
                        'title' => $r['title'],
                        'status' => $r['derivative_status']
                    ];
                }
            }
            respond(array_values($tree));

        } elseif ($method === 'GET' && $action === 'stats') {
            $stats = $db->query('SELECT format, COUNT(*) as count FROM derivatives WHERE archived_at IS NULL GROUP BY format ORDER BY count DESC')->fetchAll();
            respond($stats);

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

    default:
        respondError('Not found', 404);
}
