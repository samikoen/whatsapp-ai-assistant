<?php
namespace Garanti\Db;

class AccountRepository
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function ensure(string $iban, string $para): int
    {
        $sel = $this->pdo->prepare("SELECT id FROM accounts WHERE iban = ?");
        $sel->execute([$iban]);
        $id = $sel->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $ins = $this->pdo->prepare("INSERT INTO accounts (iban, para_birimi) VALUES (?, ?)");
        $ins->execute([$iban, $para]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<int,array> aktif hesaplar */
    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM accounts WHERE aktif = 1 ORDER BY id")
                         ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
