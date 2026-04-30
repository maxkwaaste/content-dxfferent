<?php
/**
 * Extract unique hook templates from seed.json and classify them by applicable content types.
 * Writes to data/hook-templates.json.
 *
 * Usage: php scripts/extract-hooks.php
 */

$seedPath = __DIR__ . '/../seed.json';
$outPath = __DIR__ . '/../data/hook-templates.json';

if (!file_exists($seedPath)) {
    echo "ERROR: seed.json not found at $seedPath\n";
    exit(1);
}

$topics = json_decode(file_get_contents($seedPath), true);
if (!$topics) {
    echo "ERROR: Could not parse seed.json\n";
    exit(1);
}

// Collect unique hooks with their source categories
$hookMap = [];
foreach ($topics as $t) {
    $hook = $t['hook'] ?? null;
    if (!$hook) continue;
    if (!isset($hookMap[$hook])) {
        $hookMap[$hook] = [];
    }
    $cat = $t['category'] ?? 'unknown';
    if (!in_array($cat, $hookMap[$hook])) {
        $hookMap[$hook][] = $cat;
    }
}

// Classify each hook with applicable_types
function classifyHook(string $text): array {
    // Case study hooks
    if (stripos($text, 'case study') !== false || stripos($text, 'Anonieme case study') !== false) {
        return ['blog', 'case-study', 'article'];
    }
    // Opinion/poll hooks
    if (stripos($text, 'Stelling:') !== false
        || stripos($text, 'overtuiging') !== false
        || stripos($text, 'Brutal truth') !== false
        || stripos($text, 'Onpopulair') !== false) {
        return ['post', 'poll'];
    }
    // Analysis hooks
    if (stripos($text, 'analyseerde') !== false
        || stripos($text, 'Audit') !== false
        || stripos($text, 'data van') !== false) {
        return ['blog', 'article'];
    }
    // Template/tool hooks
    if (stripos($text, 'Gratis template') !== false
        || stripos($text, 'calculator') !== false
        || stripos($text, 'tool') !== false) {
        return ['post', 'article'];
    }
    // Video hooks
    if (stripos($text, 'Kijk dit') !== false
        || stripos($text, 'video') !== false) {
        return ['video', 'post'];
    }
    // Default
    return ['post', 'article', 'blog'];
}

$hooks = [];
foreach ($hookMap as $text => $categories) {
    sort($categories);
    $hooks[] = [
        'text' => $text,
        'source_categories' => $categories,
        'applicable_types' => classifyHook($text),
    ];
}

// Sort by text for deterministic output
usort($hooks, fn($a, $b) => strcmp($a['text'], $b['text']));

if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}

file_put_contents($outPath, json_encode($hooks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

echo "Extracted " . count($hooks) . " unique hook templates\n";
echo "Source categories: " . implode(', ', array_unique(array_merge(...array_column($hooks, 'source_categories')))) . "\n";

$allTypes = array_unique(array_merge(...array_column($hooks, 'applicable_types')));
sort($allTypes);
echo "Applicable types: " . implode(', ', $allTypes) . "\n";
echo "Written to: $outPath\n";
