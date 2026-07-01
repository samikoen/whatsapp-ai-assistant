<?php
require __DIR__ . '/_guard.php';
use Garanti\Db\Database;

$pdo = Database::connect(config('db'));

// Filtreler
$acc   = $_GET['acc']  ?? '';
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-d');
$q     = trim($_GET['q'] ?? '');

$accounts = $pdo->query("SELECT * FROM accounts WHERE aktif = 1 ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);

$where = ["t.tarih BETWEEN :from AND :to"];
$params = [':from' => $from, ':to' => $to];
if ($acc !== '')  { $where[] = "t.account_id = :acc"; $params[':acc'] = (int)$acc; }
if ($q !== '')    { $where[] = "(t.aciklama LIKE :q OR t.karsi_taraf LIKE :q)"; $params[':q'] = "%$q%"; }
$sql = "SELECT t.*, a.iban, a.ad FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        WHERE " . implode(' AND ', $where) . " ORDER BY t.tarih DESC, t.id DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Para birimine gore toplam bakiye (her hesabin en guncel balances kaydi)
$balSql = "SELECT para_birimi, SUM(kapanis_bakiye) toplam FROM (
             SELECT b.account_id, b.para_birimi, b.kapanis_bakiye,
                    ROW_NUMBER() OVER (PARTITION BY b.account_id ORDER BY b.tarih DESC) rn
             FROM balances b
           ) x WHERE rn = 1 GROUP BY para_birimi";
$balances = [];
try { $balances = $pdo->query($balSql)->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) { /* window fn yoksa bos */ }
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title>Garanti Hesap Takip</title><link rel="stylesheet" href="assets/app.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script></head><body>
<header><h1>Hesap Takip</h1><a href="logout.php">Cikis</a></header>

<section class="summary">
  <?php foreach ($balances as $b): ?>
    <div class="card"><span><?= htmlspecialchars($b['para_birimi']) ?></span>
      <strong><?= number_format((float)$b['toplam'], 2, ',', '.') ?></strong></div>
  <?php endforeach; ?>
</section>

<form class="filters" method="get">
  <select name="acc"><option value="">Tum hesaplar</option>
    <?php foreach ($accounts as $a): ?>
      <option value="<?= htmlspecialchars((string)$a['id']) ?>" <?= $acc == $a['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($a['iban']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
  <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
  <input type="text" name="q" placeholder="Ara (aciklama/karsi taraf)" value="<?= htmlspecialchars($q) ?>">
  <button type="submit">Filtrele</button>
  <a class="btn" href="export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">Excel/CSV</a>
</form>

<canvas id="trend" height="80"></canvas>

<table class="tx"><thead><tr>
  <th>Tarih</th><th>Hesap</th><th>Aciklama</th><th>Karsi Taraf</th>
  <th class="num">Tutar</th><th>B/A</th><th class="num">Bakiye</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr class="<?= $r['borc_alacak'] === 'D' ? 'debit' : 'credit' ?>">
    <td><?= htmlspecialchars($r['tarih']) ?></td>
    <td><?= htmlspecialchars($r['iban']) ?></td>
    <td><?= htmlspecialchars((string)$r['aciklama']) ?></td>
    <td><?= htmlspecialchars((string)$r['karsi_taraf']) ?></td>
    <td class="num"><?= number_format((float)$r['tutar'], 2, ',', '.') ?></td>
    <td><?= $r['borc_alacak'] === 'D' ? 'Borc' : 'Alacak' ?></td>
    <td class="num"><?= $r['bakiye_sonrasi'] !== null ? number_format((float)$r['bakiye_sonrasi'], 2, ',', '.') : '' ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<script>window.TX = <?= json_encode(array_map(function($r){
  return ['tarih'=>$r['tarih'],'tutar'=>(float)$r['tutar'],'ba'=>$r['borc_alacak']];
}, $rows), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js"></script>
</body></html>
