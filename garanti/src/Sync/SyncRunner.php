<?php
namespace Garanti\Sync;

use Garanti\Http\CurlHttpClient;
use Garanti\Auth\TokenManager;
use Garanti\Api\GarantiClient;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\BalanceRepository;
use Garanti\Db\TransactionRepository;

/**
 * config() dizisinden gerekli tum nesneleri kurup SyncJob'i calistirir.
 * bin/sync.php, public/cron.php, public/refresh.php ve dashboard'un
 * otomatik yenilemesi ayni kurulum kodunu tekrarlamamak icin bunu kullanir.
 */
class SyncRunner
{
    /** @return int toplam eklenen hareket sayisi */
    public static function run(array $cfg, ?int $days = null): int
    {
        $http = new CurlHttpClient();
        $tm = new TokenManager($http, $cfg['oauth']);
        $fieldMap = require __DIR__ . '/../../config/field_map.php';
        $client = new GarantiClient($http, $tm, $cfg['api'], $fieldMap);
        $pdo = Database::connect($cfg['db']);

        $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo),
            $pdo, new BalanceRepository($pdo));

        $lookback = $days ?? (int)($cfg['sync']['lookback_days'] ?? 2);
        return $job->run((string)($cfg['api']['default_currency'] ?? 'TL'), $lookback, date('Y-m-d'));
    }
}
