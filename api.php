<?php
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (topic_id) REFERENCES topics(id)
        )
    ');
}

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
                $where[] = 'category = ?';
                $params[] = $_GET['category'];
            }
            if (!empty($_GET['domain'])) {
                $where[] = 'domain = ?';
                $params[] = $_GET['domain'];
            }
            if (!empty($_GET['status'])) {
                $where[] = 'status = ?';
                $params[] = $_GET['status'];
            }
            $sql = 'SELECT id, title, hook, category, domain, subdomain, status, claimed_by, created_at FROM topics';
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY id';
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
            $subStmt = $db->prepare('SELECT * FROM submissions WHERE topic_id = ? ORDER BY created_at DESC');
            $subStmt->execute([$id]);
            $topic['submissions'] = $subStmt->fetchAll();
            respond($topic);

        } elseif ($method === 'PATCH' && $id !== null) {
            $topic = patchRecord($db, 'topics', $id, $input,
                ['status', 'claimed_by', 'title', 'hook'],
                ['status' => ['open', 'claimed', 'written']]
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
            $where = [];
            $params = [];
            if (!empty($_GET['topic_id'])) {
                $where[] = 'topic_id = ?';
                $params[] = $_GET['topic_id'];
            }
            if (!empty($_GET['author'])) {
                $where[] = 'author = ?';
                $params[] = $_GET['author'];
            }
            $sql = 'SELECT * FROM submissions';
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
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
            $stmt = $db->prepare('DELETE FROM submissions WHERE id = ?');
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
            $stmt = $db->prepare('SELECT c.*, t.category as topic_category, t.domain as topic_domain FROM calendar c LEFT JOIN topics t ON c.topic_id = t.id WHERE c.scheduled_date BETWEEN ? AND ? ORDER BY c.scheduled_date, c.id');
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
            $stmt = $db->prepare('DELETE FROM calendar WHERE id = ?');
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
                FROM submissions WHERE author != '' GROUP BY author ORDER BY submissions DESC
            ")->fetchAll();

            $recentActivity = $db->query("
                SELECT s.id, s.author, s.created_at, s.topic_id, t.title as topic_title, t.category
                FROM submissions s LEFT JOIN topics t ON s.topic_id = t.id
                ORDER BY s.created_at DESC LIMIT 10
            ")->fetchAll();

            $calendarStats = $db->query("
                SELECT
                    COUNT(*) as total_planned,
                    SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted,
                    SUM(CASE WHEN status = 'planned' AND scheduled_date >= date('now') THEN 1 ELSE 0 END) as upcoming
                FROM calendar
            ")->fetch();

            respond([
                'by_domain' => $byDomain,
                'by_category' => $byCat,
                'leaderboard' => $leaderboard,
                'recent_activity' => $recentActivity,
                'calendar_stats' => $calendarStats,
            ]);
        } else {
            respondError('Method not allowed', 405);
        }
        break;

    default:
        respondError('Not found', 404);
}
