<?php
// Cron: her 30 dk. Ornek crontab:  */30 * * * * php /path/garanti/bin/sync.php >> /path/garanti/sync.out 2>&1
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

$cfg = config();
$http = new CurlHttpClient();
$tm = new TokenManager($http, $cfg['oauth']);
$fieldMap = require __DIR__ . '/../config/field_map.php';
$client = new GarantiClient($http, $tm, $cfg['api'], $fieldMap);
$pdo = Database::connect($cfg['db']);

// Hesaplar consent kapsamindan otomatik dogar; onceden IBAN listesi gerekmez.
$accounts = new AccountRepository($pdo);
$para = (string)($cfg['api']['default_currency'] ?? 'TL');

$job = new SyncJob($client, $accounts, new TransactionRepository($pdo), $pdo,
    new BalanceRepository($pdo));
$added = $job->run($para, (int)$cfg['sync']['lookback_days'], date('Y-m-d'));
echo date('c') . " — {$added} yeni hareket.\n";
