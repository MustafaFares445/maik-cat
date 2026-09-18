<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/storage/app/reports/ecotrade_catalog_audit/browser_observations.jsonl';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['payload'])) { file_put_contents($path, (string) $_GET['payload'] . PHP_EOL, FILE_APPEND | LOCK_EX); header('Content-Type: text/plain'); echo 'OK'; exit; }
header('Content-Type: text/plain'); echo 'ready';
