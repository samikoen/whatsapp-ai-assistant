<?php
// Cron: her 30 dk. Ornek crontab:  */30 * * * * php /path/garanti/bin/sync.php >> /path/garanti/sync.out 2>&1
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';

use Garanti\Http\CurlHttpClient;
use Garanti\Auth\TokenManager;
use Garanti\Api\GarantiClient;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;

$cfg = config();
$http = new CurlHttpClient();
$tm = new TokenManager($http, $cfg['oauth']);
$fieldMap = require __DIR__ . '/../config/field_map.php';
$client = new GarantiClient($http, $tm, $cfg['api'], $fieldMap);
$pdo = Database::connect($cfg['db']);

// Takip edilecek IBAN'lar: consent verilen hesaplar. Simdilik config'ten/DB'den.
$accounts = new AccountRepository($pdo);
$ibans = array_column($accounts->all(), 'iban');
if (empty($ibans)) {
    fwrite(STDERR, "Takip edilecek hesap yok. accounts tablosuna IBAN ekleyin.\n");
    exit(1);
}

$job = new SyncJob($client, $accounts, new TransactionRepository($pdo), $pdo);
$added = $job->run($ibans, 'TRY', (int)$cfg['sync']['lookback_days'], date('Y-m-d'));
echo date('c') . " — {$added} yeni hareket.\n";
