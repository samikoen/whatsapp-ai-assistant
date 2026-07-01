<?php
namespace Garanti\Tests;

use Garanti\Api\GarantiClient;
use Garanti\Auth\TokenManager;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;
use PHPUnit\Framework\TestCase;

class SyncJobTest extends TestCase
{
    public function test_run_persists_transactions_and_logs(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::migrateSqlite($pdo);

        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $tm = new TokenManager($http, ['token_url' => 'https://x/t', 'client_id' => 'c',
            'client_secret' => 's', 'redirect_uri' => 'https://x/cb']);
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://x', 'consent_id' => 'C1'], $fieldMap);

        $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo), $pdo);
        // IBAN artik istekten degil, yanittaki her hareketten geliyor (consent bazli).
        $added = $job->run('TRY', 7, '2026-06-30');

        $this->assertSame(2, $added);
        $log = $pdo->query("SELECT durum, cekilen_kayit FROM sync_log")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ok', $log['durum']);
        $this->assertSame(2, (int)$log['cekilen_kayit']);
        // Iki hareket ayni IBAN'da → tek hesap otomatik olusmali
        $accCount = $pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
        $this->assertSame(1, (int)$accCount);
    }
}
