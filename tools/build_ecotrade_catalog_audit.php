<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$reportDir = $root . '/storage/app/reports/ecotrade_catalog_audit';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}

$source = $root . '/storage/app/reports/pricing_all_items_audit.csv';
$rows = [];
$handle = fopen($source, 'rb');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($header)) continue;
    $item = array_combine($header, $row);
    $item['normalized_serial'] = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', (string) $item['serial']));
    $key = trim((string) $item['group']) . '|' . $item['normalized_serial'];
    $rows[$key][] = $item;
}
fclose($handle);

$targets = [];
foreach ($rows as $familyKey => $items) {
    $first = $items[0];
    $urls = array_values(array_unique(array_filter(array_map(static fn(array $i): string => trim((string) ($i['source_url'] ?? '')), $items))));
    $models = array_values(array_unique(array_filter(array_map(static fn(array $i): string => trim((string) ($i['model'] ?? '')), $items))));
    $details = array_values(array_unique(array_filter(array_map(static fn(array $i): string => trim((string) ($i['details'] ?? '')), $items))));
    $weights = array_map(static fn(array $i): float => (float) $i['weight_kg'], $items);
    $prices = array_map(static fn(array $i): float => (float) $i['default_price'], $items);
    $api = array_values(array_filter($items, static fn(array $i): bool => (string) $i['api_visible'] === '1'));
    $priority = count($api) > 0 && count($urls) > 0 ? 1 : (count($api) > 0 ? 2 : 4);
    $targets[] = [
        'family_key' => $familyKey,
        'group' => $first['group'],
        'serial' => $first['serial'],
        'normalized_serial' => $first['normalized_serial'],
        'local_item_count' => count($items),
        'local_item_ids' => implode('|', array_column($items, 'item_id')),
        'api_visible_item_count' => count($api),
        'source_urls' => implode('|', $urls),
        'local_models' => implode('|', $models),
        'local_details' => implode('|', $details),
        'local_weight_min' => min($weights),
        'local_weight_max' => max($weights),
        'local_selected_price_usd_min' => min($prices),
        'local_selected_price_usd_max' => max($prices),
        'existing_review_status' => '',
        'priority' => $priority,
    ];
}
usort($targets, static fn(array $a, array $b): int => [$a['priority'], $a['family_key']] <=> [$b['priority'], $b['family_key']]);

$out = fopen($reportDir . '/targets.csv', 'wb');
fputcsv($out, array_keys($targets[0]));
foreach ($targets as $target) fputcsv($out, $target);
fclose($out);

foreach (['family_evidence.csv', 'unresolved.csv'] as $file) {
    $path = $reportDir . '/' . $file;
    if (!file_exists($path)) {
        $h = fopen($path, 'wb');
        fputcsv($h, $file === 'family_evidence.csv' ? [
            'family_key','requested_group','requested_serial','search_query_used','ecotrade_product_ref','ecotrade_url','visible_product_name','product_type','classification','visible_price_label','ecotrade_price_raw','ecotrade_currency','price_unit','weight_if_visible','details_or_oem_refs','maker_if_visible','years_if_visible','match_confidence','match_reason','observed_at','browser_status','notes'
        ] : ['family_key','status','reason','ecotrade_url','observed_at','notes']);
        fclose($h);
    }
}
foreach (['family_evidence.json' => [], 'checkpoint.json' => ['processed' => [], 'updated_at' => null], 'fx_rates.json' => []] as $file => $value) {
    $path = $reportDir . '/' . $file;
    if (!file_exists($path)) file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['families' => count($targets), 'rows' => count($rows)], JSON_PRETTY_PRINT) . PHP_EOL;
