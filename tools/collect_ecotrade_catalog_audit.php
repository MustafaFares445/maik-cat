<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dir = $root . '/storage/app/reports/ecotrade_catalog_audit';
$targets = [];
$h = fopen($dir . '/targets.csv', 'rb');
$head = fgetcsv($h, 0, ',', '"', '\\');
while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
    if (count($row) === count($head)) $targets[] = array_combine($head, $row);
}
fclose($h);

$checkpointPath = $dir . '/checkpoint.json';
$checkpoint = json_decode((string) file_get_contents($checkpointPath), true) ?: ['processed' => [], 'updated_at' => null];
$done = [];
foreach (($checkpoint['processed'] ?? []) as $item) $done[(string) ($item['family_key'] ?? '')] = $item;
$limit = (int) ($argv[1] ?? 100);
$batch = [];
foreach ($targets as $target) {
    if (isset($done[$target['family_key']])) continue;
    $batch[] = $target;
    if (count($batch) >= $limit) break;
}

function fetchPage(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 35, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => 'Mozilla/5.0 (audit evidence collection)']);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? $body : '', $error];
}

function fetchMany(array $urls): array {
    $multi = curl_multi_init(); $handles = []; $results = [];
    foreach (array_unique($urls) as $url) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'Mozilla/5.0 (audit evidence collection)']);
        curl_multi_add_handle($multi, $ch); $handles[(int) $ch] = [$ch, $url];
    }
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1.0); } while ($running);
    foreach ($handles as [$ch, $url]) { $results[$url] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) curl_multi_getcontent($ch), (string) curl_error($ch)]; curl_multi_remove_handle($multi, $ch); curl_close($ch); }
    curl_multi_close($multi); return $results;
}

function absoluteUrl(string $href): string {
    if (str_starts_with($href, 'http')) return $href;
    return 'https://www.ecotradegroup.com' . (str_starts_with($href, '/') ? $href : '/' . $href);
}

function parseProduct(string $html, string $url, string $group, string $serial): array {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);
    $result = ['ecotrade_url' => $url, 'visible_product_name' => '', 'product_type' => '', 'classification' => 'UNKNOWN', 'visible_price_label' => '', 'ecotrade_price_raw' => '', 'ecotrade_currency' => '', 'price_unit' => '', 'weight_if_visible' => '', 'details_or_oem_refs' => '', 'maker_if_visible' => '', 'years_if_visible' => '', 'ecotrade_product_ref' => '', 'browser_status' => 'FOUND'];
    $h2 = $xp->query('//h1|//h2');
    foreach ($h2 as $node) { $text = trim(preg_replace('/\s+/', ' ', $node->textContent)); if (preg_match('/\b' . preg_quote($serial, '/') . '\b/i', $text)) { $result['visible_product_name'] = $text; break; } }
    foreach ($xp->query('//tr') as $tr) {
        $cells = $xp->query('./th|./td', $tr);
        if ($cells->length < 2) continue;
        $key = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
        $value = trim(preg_replace('/\s+/', ' ', $cells->item(1)->textContent));
        if ($key === 'Brand') $result['requested_brand'] = $value;
        elseif ($key === 'Product Type') $result['product_type'] = $value;
        elseif ($key === 'Maker') $result['maker_if_visible'] = $value;
        elseif ($key === 'Ref') $result['ecotrade_product_ref'] = $value;
        elseif ($key === 'Details') $result['details_or_oem_refs'] = $value;
        elseif ($key === 'Price') { $result['visible_price_label'] = 'Price ' . $value; if (preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*([^0-9\s]+)?/', $value, $m)) { $result['ecotrade_price_raw'] = str_replace(',', '.', $m[1]); $result['ecotrade_currency'] = $m[2] ?? ''; $result['price_unit'] = $result['ecotrade_currency'] !== '' ? 'per item' : ''; } }
    }
    $bodyText = trim(preg_replace('/\s+/', ' ', $dom->textContent));
    if ($result['visible_product_name'] === '') { $title = $xp->query('//title')->item(0); $result['visible_product_name'] = $title ? trim(preg_replace('/\s+/', ' ', $title->textContent)) : ''; }
    $type = strtolower($result['product_type'] . ' ' . $bodyText);
    if (str_contains($type, 'dpf') && str_contains($type, 'ceramic')) $result['classification'] = 'FILTER_PLUS_CATALYST';
    elseif (str_contains($type, 'dpf')) $result['classification'] = 'DPF_ONLY';
    elseif (str_contains($type, 'metal')) $result['classification'] = 'METALLIC';
    elseif (str_contains($type, 'ceramic')) $result['classification'] = 'CATALYST_ONLY';
    if ($result['ecotrade_product_ref'] === '') $result['ecotrade_product_ref'] = $serial;
    return $result;
}

$csvPath = $dir . '/family_evidence.csv';
$csv = fopen($csvPath, 'ab');
$jsonPath = $dir . '/family_evidence.json';
$evidence = json_decode((string) file_get_contents($jsonPath), true) ?: [];
$now = gmdate('c');
$initialUrls = [];
foreach ($batch as $target) { $direct = trim((explode('|', $target['source_urls'])[0] ?? '')); $initialUrls[] = $direct !== '' ? $direct : 'https://www.ecotradegroup.com/en/search?search%5Bkeyword%5D=' . rawurlencode($target['serial']); }
$initial = fetchMany($initialUrls); $followupUrls = [];
foreach ($batch as $target) { $direct = trim((explode('|', $target['source_urls'])[0] ?? '')); if ($direct === '') { $s = $initial['https://www.ecotradegroup.com/en/search?search%5Bkeyword%5D=' . rawurlencode($target['serial'])] ?? [0,'','']; preg_match_all('~href=["\'](/en/product/[^"\']+)["\']~i', $s[1], $m); if (count(array_unique($m[1] ?? [])) === 1) $followupUrls[] = absoluteUrl($m[1][0]); } }
$followup = fetchMany($followupUrls);
foreach ($batch as $target) {
    $direct = trim((explode('|', $target['source_urls'])[0] ?? ''));
    $search = 'https://www.ecotradegroup.com/en/search?search%5Bkeyword%5D=' . rawurlencode($target['serial']);
    $url = $direct !== '' ? $direct : $search;
    [$status, $html, $error] = $initial[$url] ?? [0, '', 'missing fetch result'];
    $foundUrl = $direct;
    if ($direct === '' && $status === 200) {
        preg_match_all('~href=["\'](/en/product/[^"\']+)["\']~i', $html, $matches);
        $urls = array_values(array_unique(array_map('absoluteUrl', $matches[1] ?? [])));
        if (count($urls) === 1) { $foundUrl = $urls[0]; [$status, $html, $error] = $followup[$foundUrl] ?? [0, '', 'missing follow-up fetch result']; }
        elseif (count($urls) > 1) { $done[] = ['family_key'=>$target['family_key'],'status'=>'AMBIGUOUS','attempted_at'=>$now,'ecotrade_url'=>'','retry_count'=>0,'last_error'=>'Multiple EcoTrade result pages']; fputcsv($csv, [$target['family_key'],$target['group'],$target['serial'],$target['serial'],'','','','','','','','','','','','','','AMBIGUOUS','Multiple EcoTrade result pages',$now,'OK','Search results: ' . implode('|', $urls)]); continue; }
    }
    if ($status !== 200 || $foundUrl === '') {
        $state = $status === 401 || $status === 403 ? 'LOGIN_REQUIRED' : ($error !== '' ? 'BROWSER_ERROR' : 'NOT_FOUND');
        $done[] = ['family_key'=>$target['family_key'],'status'=>$state,'attempted_at'=>$now,'ecotrade_url'=>$foundUrl,'retry_count'=>0,'last_error'=>$error !== '' ? $error : 'HTTP ' . $status];
        fputcsv($csv, [$target['family_key'],$target['group'],$target['serial'],$target['serial'],'',$foundUrl,'','','','','','','','','','','','NONE',$state,$now,$state,$error !== '' ? $error : 'HTTP ' . $status]);
        continue;
    }
    $p = parseProduct($html, $foundUrl, $target['group'], $target['serial']);
    $confidence = $direct !== '' ? 'EXACT' : ((strcasecmp((string) $p['ecotrade_product_ref'], (string) $target['serial']) === 0) ? 'EXACT' : 'SUPPORTED');
    $matchReason = $direct !== '' ? 'Exact local source URL was opened.' : 'Unique EcoTrade search result opened; visible reference/details captured.';
    $statusName = $p['ecotrade_price_raw'] !== '' ? 'FOUND_' . $confidence : 'NO_PRICE_VISIBLE';
    $row = [$target['family_key'],$target['group'],$target['serial'],$target['serial'],$p['ecotrade_product_ref'],$foundUrl,$p['visible_product_name'],$p['product_type'],$p['classification'],$p['visible_price_label'],$p['ecotrade_price_raw'],$p['ecotrade_currency'],$p['price_unit'],$p['weight_if_visible'],$p['details_or_oem_refs'],$p['maker_if_visible'],$p['years_if_visible'],$confidence,$matchReason,$now,'OK','Visible product table parsed from EcoTrade page.'];
    fputcsv($csv, $row); $evidence[] = array_combine(['family_key','requested_group','requested_serial','search_query_used','ecotrade_product_ref','ecotrade_url','visible_product_name','product_type','classification','visible_price_label','ecotrade_price_raw','ecotrade_currency','price_unit','weight_if_visible','details_or_oem_refs','maker_if_visible','years_if_visible','match_confidence','match_reason','observed_at','browser_status','notes'], $row);
    $done[] = ['family_key'=>$target['family_key'],'status'=>$statusName,'attempted_at'=>$now,'ecotrade_url'=>$foundUrl,'retry_count'=>0,'last_error'=>''];
}
fclose($csv);
$checkpoint['processed'] = array_values($done); $checkpoint['updated_at'] = $now; file_put_contents($checkpointPath, json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($jsonPath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['batch' => count($batch), 'processed_total' => count($done), 'remaining' => count($targets) - count($done)], JSON_PRETTY_PRINT) . PHP_EOL;
