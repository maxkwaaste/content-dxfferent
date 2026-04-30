<?php
/**
 * Deterministic level mapping for topics based on domain + category heuristics.
 * Assigns journey level (1-5: Reactive, Proactive, Managed, Partner, Success)
 * to each topic. Idempotent: skips topics that already have a level.
 *
 * Usage: php scripts/ai-map-topics.php
 */

$dbPath = __DIR__ . '/../data/content.db';
if (!file_exists($dbPath)) {
    echo "ERROR: Database not found at $dbPath\n";
    echo "Start the API server first to create the database.\n";
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$topics = $db->query('SELECT id, title, hook, category, domain, subdomain FROM topics WHERE level IS NULL ORDER BY id')->fetchAll();

if (empty($topics)) {
    echo "All topics already have levels assigned. Nothing to do.\n";
    exit(0);
}

$levelNames = [1 => 'Reactive', 2 => 'Proactive', 3 => 'Managed', 4 => 'Partner', 5 => 'Success'];

// Base level ranges per domain
$domainBase = [
    'Identity'      => [3, 5],
    'Organisation'  => [2, 4],
    'GRC'           => [3, 5],
    'Positioning'   => [3, 5],
    'Portfolio'     => [3, 5],
    'Technology'    => [2, 4],
    'Blueprint'     => [4, 5],
    'Data driven'   => [3, 4],
];

// Category adjustments (offset from base)
$categoryAdjust = [
    'bewijs'    =>  0,   // evidence: stays at domain base
    'educatie'  => -1,   // educational: lower maturity audience
    'markt'     =>  1,   // market vision: higher maturity
    'contrair'  =>  1,   // contrarian: challenges assumptions, higher maturity
    'eigen'     =>  0,   // own story: mid-range
    'tools'     => -1,   // tools: practical, lower maturity
];

// Subdomain fine-tuning for spread
$subdomainAdjust = [
    'overkoepelend'         =>  0,
    'Analyse'               =>  0,
    'Data Mining'           =>  1,
    'Datakwaliteit'         => -1,
    'PSA'                   =>  0,
    'RMM'                   => -1,
    'Contracten & Billing'  =>  1,
    'Automatisering'        =>  1,
    'AI intern'             =>  1,
    'Personeelsplanning'    =>  0,
    'Strategisch Plan'      =>  1,
    'Proxuma'               =>  0,
    'MSP'                   =>  1,
];

$csvLines = [['id', 'title', 'category', 'domain', 'assigned_level', 'rationale']];
$updateStmt = $db->prepare('UPDATE topics SET level = ? WHERE id = ?');
$mapped = 0;

foreach ($topics as $t) {
    $domain = $t['domain'];
    $category = $t['category'];
    $subdomain = $t['subdomain'] ?? '';

    // Get base range for domain
    $range = $domainBase[$domain] ?? [2, 3];
    $base = $range[0];

    // Apply category adjustment
    $catAdj = $categoryAdjust[$category] ?? 0;
    $level = $base + $catAdj;

    // Apply subdomain fine-tuning
    $subAdj = $subdomainAdjust[$subdomain] ?? 0;
    $level += $subAdj;

    // Use topic ID for additional spread within the valid range
    // This ensures not all topics with the same domain+category land on the same level
    $idSpread = ($t['id'] % 3) - 1; // -1, 0, or 1
    $level += $idSpread;

    // Clamp to 1-5
    $level = max(1, min(5, $level));

    // Build rationale
    $rationale = "domain=$domain(base {$range[0]}-{$range[1]}), cat=$category(adj $catAdj), sub=$subdomain(adj $subAdj), id-spread=$idSpread";

    $updateStmt->execute([$level, $t['id']]);
    $mapped++;

    $levelName = $levelNames[$level];
    echo "Mapped topic #{$t['id']}: '{$t['title']}' -> Level $level ($levelName)\n";

    $csvLines[] = [$t['id'], $t['title'], $category, $domain, $level, $rationale];
}

// Write CSV
$csvPath = __DIR__ . '/../data/level-mapping.csv';
$fp = fopen($csvPath, 'w');
foreach ($csvLines as $line) {
    fputcsv($fp, $line, ',', '"', '');
}
fclose($fp);

echo "\nMapped $mapped topics.\n";
echo "CSV written to $csvPath\n";

// Verify distribution
$dist = $db->query('SELECT level, COUNT(*) as cnt FROM topics GROUP BY level ORDER BY level')->fetchAll();
echo "\nLevel distribution:\n";
foreach ($dist as $row) {
    $name = $levelNames[$row['level']] ?? '?';
    echo "  Level {$row['level']} ($name): {$row['cnt']} topics\n";
}

$nullCount = (int)$db->query('SELECT COUNT(*) FROM topics WHERE level IS NULL')->fetchColumn();
if ($nullCount > 0) {
    echo "\nWARNING: $nullCount topics still have NULL level!\n";
    exit(1);
}

exit(0);
