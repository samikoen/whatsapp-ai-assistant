<?php
namespace Garanti\Tests;

use Garanti\Api\GarantiClient;
use Garanti\Auth\TokenManager;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\BalanceRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;
use PHPUnit\Framework\TestCase;

class SyncJobTest extends TestCase
{
    public function test_run_persists_transactions_balances_and_logs(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::migrateSqlite($pdo);

        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_information.json'));
        $tm = new TokenManager($http, ['token_url' => 'https://x/t', 'client_id' => 'c',
            'client_secret' => 's', 'redirect_uri' => 'https://x/cb']);
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://x', 'consent_id' => 'C1', 'default_currency' => 'TL'], $fieldMap);

        $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo),
            $pdo, new BalanceRepository($pdo));
        // IBAN'lar yanittaki her hareketten gelir (consent bazli); tarih araligi <= 30 gun
        $added = $job->run('TL', 7, '2026-06-30');

        $this->assertSame(2, $added);
        $log = $pdo->query("SELECT durum, cekilen_kayit FROM sync_log")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ok', $log['durum']);
        $this->assertSame(2, (int)$log['cekilen_kayit']);
        // Iki hareket ayni IBAN'da -> tek hesap otomatik olusmali
        $accCount = $pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
        $this->assertSame(1, (int)$accCount);
        // Account Information'dan gunun bakiyesi yazilmis olmali
        $bal = $pdo->query("SELECT kapanis_bakiye, para_birimi, tarih FROM balances")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(24700.0, (float)$bal['kapanis_bakiye']);
        $this->assertSame('TL', $bal['para_birimi']);
        $this->assertSame('2026-06-30', $bal['tarih']);
    }

    public function test_balance_failure_does_not_break_transaction_sync(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::migrateSqlite($pdo);

        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $http->push(500, '{"result":{"code":500,"info":"Internal Server Error"}}'); // bakiye cagrisi patlar
        $tm = new TokenManager($http, ['token_url' => 'https://x/t', 'client_id' => 'c',
            'client_secret' => 's', 'redirect_uri' => 'https://x/cb']);
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://x', 'consent_id' => 'C1', 'default_currency' => 'TL'], $fieldMap);

        $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo),
            $pdo, new BalanceRepository($pdo));
        $added = $job->run('TL', 7, '2026-06-30');

        $this->assertSame(2, $added); // hareketler yine kaydedildi
        $log = $pdo->query("SELECT durum, mesaj FROM sync_log")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ok', $log['durum']);
        $this->assertStringContainsString('bakiye hatasi', (string)$log['mesaj']);
    }
}
