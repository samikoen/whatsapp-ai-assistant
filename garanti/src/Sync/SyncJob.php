<?php
namespace Garanti\Sync;

use Garanti\Api\GarantiClient;
use Garanti\Db\AccountRepository;
use Garanti\Db\BalanceRepository;
use Garanti\Db\TransactionRepository;

class SyncJob
{
    private GarantiClient $client;
    private AccountRepository $accounts;
    private TransactionRepository $tx;
    private \PDO $pdo;
    private ?BalanceRepository $balances;

    public function __construct(GarantiClient $client, AccountRepository $accounts,
                                TransactionRepository $tx, \PDO $pdo,
                                ?BalanceRepository $balances = null)
    {
        $this->client = $client;
        $this->accounts = $accounts;
        $this->tx = $tx;
        $this->pdo = $pdo;
        $this->balances = $balances;
    }

    /**
     * Consent kapsamindaki tum hareketleri tek cagriyla ceker, IBAN'a gore gruplayip
     * her hesabi (yoksa) olusturarak idempotent kaydeder. Hesaplar consent'ten otomatik dogar.
     *
     * @param string $para para birimi fallback'i (hareket kendi para_birimi'ni tasimazsa)
     * @return int toplam eklenen kayit
     */
    public function run(string $para, int $lookbackDays, string $today): int
    {
        $from = date('Y-m-d', strtotime($today . " -{$lookbackDays} days"));
        $start = date('Y-m-d H:i:s');
        $total = 0;
        try {
            $rows = $this->client->getTransactions($from, $today);

            // Hesaba (IBAN) gore grupla
            $byIban = [];
            foreach ($rows as $r) {
                $byIban[$r['iban']][] = $r;
            }
            foreach ($byIban as $iban => $group) {
                if ($iban === '') {
                    continue;
                }
                $accPara = !empty($group[0]['para_birimi']) ? $group[0]['para_birimi'] : $para;
                $accId = $this->accounts->ensure($iban, $accPara);
                $total += $this->tx->save($accId, $group);
            }

            // Bakiyeler (Account Information) — hata verirse hareket sync'ini bozmasin
            $note = null;
            if ($this->balances !== null) {
                try {
                    foreach ($this->client->getAccountInformation() as $acc) {
                        if ($acc['iban'] === '') {
                            continue;
                        }
                        $accPara = $acc['para_birimi'] !== '' ? $acc['para_birimi'] : $para;
                        $accId = $this->accounts->ensure($acc['iban'], $accPara);
                        $this->balances->upsert($accId, $today, $acc['bakiye'], $accPara);
                    }
                } catch (\Throwable $e) {
                    $note = 'bakiye hatasi: ' . substr($e->getMessage(), 0, 200);
                }
            }
            $this->log($start, 'ok', $total, $note);
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
