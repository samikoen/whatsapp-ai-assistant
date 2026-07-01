<?php
namespace Garanti\Sync;

use Garanti\Api\GarantiClient;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;

class SyncJob
{
    private GarantiClient $client;
    private AccountRepository $accounts;
    private TransactionRepository $tx;
    private \PDO $pdo;

    public function __construct(GarantiClient $client, AccountRepository $accounts,
                                TransactionRepository $tx, \PDO $pdo)
    {
        $this->client = $client;
        $this->accounts = $accounts;
        $this->tx = $tx;
        $this->pdo = $pdo;
    }

    /** @param string[] $ibans @return int toplam eklenen kayit */
    public function run(array $ibans, string $para, int $lookbackDays, string $today): int
    {
        $from = date('Y-m-d', strtotime($today . " -{$lookbackDays} days"));
        $start = date('Y-m-d H:i:s');
        $total = 0;
        try {
            foreach ($ibans as $iban) {
                $rows = $this->client->getTransactions($iban, $from, $today);
                $accPara = (!empty($rows) && !empty($rows[0]['para_birimi'])) ? $rows[0]['para_birimi'] : $para;
                $accId = $this->accounts->ensure($iban, $accPara);
                $total += $this->tx->save($accId, $rows);
            }
            $this->log($start, 'ok', $total, null);
        } catch (\Throwable $e) {
            $this->log($start, 'error', $total, substr($e->getMessage(), 0, 500));
            throw $e;
        }
        return $total;
    }

    private function log(string $start, string $durum, int $count, ?string $msg): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sync_log (baslangic, bitis, durum, cekilen_kayit, mesaj)
             VALUES (?,?,?,?,?)");
        $stmt->execute([$start, date('Y-m-d H:i:s'), $durum, $count, $msg]);
    }
}
