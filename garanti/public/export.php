<?php
require __DIR__ . '/_guard.php';
use Garanti\Db\Database;

$pdo = Database::connect(config('db'));
$acc  = $_GET['acc']  ?? '';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$q    = trim($_GET['q'] ?? '');

$where = ["t.tarih BETWEEN :from AND :to"];
$params = [':from' => $from, ':to' => $to];
if ($acc !== '') { $where[] = "t.account_id = :acc"; $params[':acc'] = (int)$acc; }
if ($q !== '')   { $where[] = "(t.aciklama LIKE :q OR t.karsi_taraf LIKE :q)"; $params[':q'] = "%$q%"; }
$sql = "SELECT t.tarih, a.iban, t.aciklama, t.karsi_taraf, t.tutar, t.borc_alacak,
               t.para_birimi, t.bakiye_sonrasi
        FROM transactions t JOIN accounts a ON a.id = t.account_id
        WHERE " . implode(' AND ', $where) . " ORDER BY t.tarih DESC, t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hesap_hareketleri_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel Turkce icin)
fputcsv($out, ['Tarih','IBAN','Aciklama','Karsi Taraf','Tutar','Borc/Alacak','Para Birimi','Bakiye'], ';');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $r['tarih'], $r['iban'], $r['aciklama'], $r['karsi_taraf'],
        number_format((float)$r['tutar'], 2, ',', '.'),
        $r['borc_alacak'] === 'D' ? 'Borc' : 'Alacak',
        $r['para_birimi'],
        $r['bakiye_sonrasi'] !== null ? number_format((float)$r['bakiye_sonrasi'], 2, ',', '.') : '',
    ], ';');
}
fclose($out);
