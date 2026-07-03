<?php
// Dashboard "Yenile" butonu: login korumali elle senkron tetikleyici.
// Banka API'sinden yeni hareketleri ceker ve dashboard'a geri doner.
require __DIR__ . '/_guard.php';

use Garanti\Http\CurlHttpClient;
use Garanti\Auth\TokenManager;
use Garanti\Api\GarantiClient;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\BalanceRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;

set_time_limit(300);
$cfg = config();

try {
    $http = new CurlHttpClient();
    $tm = new TokenManager($http, $cfg['oauth']);
    $client = new GarantiClient($http, $tm, $cfg['api'], require __DIR__ . '/../config/field_map.php');
    $pdo = Database::connect($cfg['db']);
    $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo),
        $pdo, new BalanceRepository($pdo));
    $days = (int)($cfg['sync']['lookback_days'] ?? 2);
    $added = $job->run((string)($cfg['api']['default_currency'] ?? 'TL'), $days, date('Y-m-d'));
    header('Location: index.php?synced=' . $added);
} catch (\Throwable $e) {
    header('Location: index.php?syncerr=' . urlencode(substr($e->getMessage(), 0, 150)));
}
exit;
