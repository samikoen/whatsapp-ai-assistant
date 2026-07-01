<?php
namespace Garanti\Db;

class TransactionRepository
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    /** @return int yeni eklenen kayit sayisi (banka_ref cakisanlar atlanir) */
    public function save(int $accountId, array $rows): int
    {
        $sql = "INSERT INTO transactions
            (account_id, banka_ref, tarih, valor_tarihi, tutar, borc_alacak,
             para_birimi, aciklama, karsi_taraf, bakiye_sonrasi, ham_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $this->pdo->prepare($sql);
        $added = 0;
        foreach ($rows as $r) {
            $exists = $this->pdo->prepare("SELECT 1 FROM transactions WHERE banka_ref = ?");
            $exists->execute([$r['banka_ref']]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $stmt->execute([
                $accountId, $r['banka_ref'], $r['tarih'], $r['valor_tarihi'] ?? null,
                $r['tutar'], $r['borc_alacak'], $r['para_birimi'],
                $r['aciklama'] ?? null, $r['karsi_taraf'] ?? null,
                $r['bakiye_sonrasi'] ?? null, $r['ham_json'] ?? null,
            ]);
            $added++;
        }
        return $added;
    }
}
