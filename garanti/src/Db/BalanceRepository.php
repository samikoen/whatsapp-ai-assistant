<?php
namespace Garanti\Db;

class BalanceRepository
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Hesabin gun sonu bakiyesini yazar/gunceller (account_id + tarih UNIQUE).
     * Portatif upsert: SELECT sonra INSERT/UPDATE (SQLite + MySQL).
     */
    public function upsert(int $accountId, string $tarih, ?float $kapanis, string $para): void
    {
        $sel = $this->pdo->prepare("SELECT id FROM balances WHERE account_id = ? AND tarih = ?");
        $sel->execute([$accountId, $tarih]);
        $id = $sel->fetchColumn();
        if ($id !== false) {
            $upd = $this->pdo->prepare(
                "UPDATE balances SET kapanis_bakiye = ?, para_birimi = ? WHERE id = ?");
            $upd->execute([$kapanis, $para, (int)$id]);
            return;
        }
        $ins = $this->pdo->prepare(
            "INSERT INTO balances (account_id, tarih, kapanis_bakiye, para_birimi) VALUES (?,?,?,?)");
        $ins->execute([$accountId, $tarih, $kapanis, $para]);
    }
}
