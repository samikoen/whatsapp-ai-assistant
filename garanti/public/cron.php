<?php
// Senkron tetikleyici. Cagri: cron.php?key=<gizli>&days=<opsiyonel, varsayilan config>
// Plesk zamanlanmis gorev veya harici ping servisi ile her 30 dk cagrilir.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';

use Garanti\Sync\SyncRunner;

header('Content-Type: application/json; charset=utf-8');
$cronKey = (string)(config('cron')['key'] ?? '');
if ($cronKey === '' || !hash_equals($cronKey, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
set_time_limit(600);

$cfg = config();
$days = (int)($_GET['days'] ?? $cfg['sync']['lookback_days'] ?? 7);
$days = max(1, min($days, 365)); // guvenlik siniri; 30+ gun pencerelere bolunur

try {
    $added = SyncRunner::run($cfg, $days);
    echo json_encode(['ok' => true, 'yeni_hareket' => $added, 'gun' => $days, 'zaman' => date('c')]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'hata' => $e->getMessage()]);
}
