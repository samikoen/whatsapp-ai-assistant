<?php
namespace Garanti\Tests;

use Garanti\Db\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function test_sqlite_migrate_creates_tables(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        Database::migrateSqlite($pdo);
        $count = $pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
        $this->assertSame(0, (int)$count);
    }
}
