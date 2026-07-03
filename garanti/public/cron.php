<?php
// Senkron tetikleyici. Cagri: cron.php?key=<gizli>&days=<opsiyonel, varsayilan config>
// Plesk zamanlanmis gorev veya harici ping servisi ile her 30 dk cagrilir.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';

use Garanti\Http\CurlHttpClient;
use Garanti\Auth\TokenManager;
use Garanti\Api\GarantiClient;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\BalanceRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;

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
    $http = new CurlHttpClient();
    $tm = new TokenManager($http, $cfg['oauth']);
    $client = new GarantiClient($http, $tm, $cfg['api'], require __DIR__ . '/../config/field_map.php');
    $pdo = Database::connect($cfg['db']);
    $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo),
        $pdo, new BalanceRepository($pdo));
    $added = $job->run((string)($cfg['api']['default_currency'] ?? 'TL'), $days, date('Y-m-d'));
    echo json_encode(['ok' => true, 'yeni_hareket' => $added, 'gun' => $days, 'zaman' => date('c')]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'hata' => $e->getMessage()]);
}
