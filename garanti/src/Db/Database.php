<?php
namespace Garanti\Db;

class Database
{
    public static function connect(array $cfg): \PDO
    {
        $pdo = new \PDO(
            $cfg['dsn'],
            $cfg['user'] ?? null,
            $cfg['pass'] ?? null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    }

    /** Testler icin: SQLite in-memory sema (MySQL semasinin sadelestirilmis hali). */
    public static function migrateSqlite(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT,
            iban TEXT UNIQUE, hesap_no TEXT, sube TEXT, para_birimi TEXT NOT NULL,
            ad TEXT, aktif INTEGER NOT NULL DEFAULT 1, olusturma TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL, banka_ref TEXT NOT NULL UNIQUE, tarih TEXT NOT NULL,
            valor_tarihi TEXT, tutar REAL NOT NULL, borc_alacak TEXT NOT NULL,
            para_birimi TEXT NOT NULL, aciklama TEXT, karsi_taraf TEXT,
            bakiye_sonrasi REAL, ham_json TEXT, olusturma TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE balances (id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL, tarih TEXT NOT NULL, acilis_bakiye REAL,
            kapanis_bakiye REAL, para_birimi TEXT NOT NULL, guncelleme TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(account_id, tarih))");
        $pdo->exec("CREATE TABLE sync_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
            baslangic TEXT NOT NULL, bitis TEXT, durum TEXT NOT NULL,
            cekilen_kayit INTEGER NOT NULL DEFAULT 0, mesaj TEXT)");
    }
}
