<?php
namespace Garanti\Tests;

use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;
use PHPUnit\Framework\TestCase;

class TransactionRepositoryTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::migrateSqlite($this->pdo);
    }

    private function sampleRows(): array
    {
        return [[
            'banka_ref' => 'REF-1', 'tarih' => '2026-06-30', 'valor_tarihi' => '2026-06-30',
            'tutar' => 100.0, 'borc_alacak' => 'C', 'para_birimi' => 'TRY',
            'aciklama' => 'x', 'karsi_taraf' => 'y', 'bakiye_sonrasi' => 100.0, 'ham_json' => '{}',
        ]];
    }

    public function test_saves_new_transactions(): void
    {
        $accId = (new AccountRepository($this->pdo))->ensure('TR0001', 'TRY');
        $added = (new TransactionRepository($this->pdo))->save($accId, $this->sampleRows());
        $this->assertSame(1, $added);
    }

    public function test_duplicate_ref_is_skipped(): void
    {
        $accId = (new AccountRepository($this->pdo))->ensure('TR0001', 'TRY');
        $repo = new TransactionRepository($this->pdo);
        $repo->save($accId, $this->sampleRows());
        $addedSecond = $repo->save($accId, $this->sampleRows()); // ayni ref tekrar
        $this->assertSame(0, $addedSecond);
        $count = $this->pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function test_ensure_account_is_idempotent(): void
    {
        $repo = new AccountRepository($this->pdo);
        $a = $repo->ensure('TR0001', 'TRY');
        $b = $repo->ensure('TR0001', 'TRY');
        $this->assertSame($a, $b);
    }
}
