<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
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
            $fields = [];
            $params = [];
            foreach (['status', 'claimed_by', 'title', 'hook'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            if (!$fields) respondError('No fields to update');
            $params[] = $id;
            $stmt = $db->prepare('UPDATE topics SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) respondError('Topic not found', 404);
            $stmt = $db->prepare('SELECT * FROM topics WHERE id = ?');
            $stmt->execute([$id]);
            $topic = $stmt->fetch();
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
            $fields = [];
            $params = [];
            foreach (['raw_content', 'category', 'domain'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            if (!$fields) respondError('No fields to update');
            $fields[] = "updated_at = datetime('now')";
            $params[] = $id;
            $stmt = $db->prepare('UPDATE submissions SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) respondError('Submission not found', 404);
            $stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
            $stmt->execute([$id]);
            respond($stmt->fetch());

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
            respond(['id' => $db->lastInsertId(), 'name' => $input['name']], 201);

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

    default:
        respondError('Not found', 404);
}
