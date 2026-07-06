<?php
require __DIR__ . '/_guard.php';
use Garanti\Db\Database;

$pdo = Database::connect(config('db'));

$accounts = $pdo->query("SELECT * FROM accounts WHERE aktif = 1 ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);

// Filtreler
// "Tum hesaplar" secenegi yok — her zaman bir hesap secili olur (varsayilan: ilk hesap)
$acc   = $_GET['acc']  ?? (string)($accounts[0]['id'] ?? '');
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-d');
$q     = trim($_GET['q'] ?? '');
$showKesinti = isset($_GET['show_kesinti']); // varsayilan: gizli (banka masraf/kesinti kayitlari)

$secilenHesap = null;
foreach ($accounts as $a) {
    if ((string)$a['id'] === (string)$acc) { $secilenHesap = $a; break; }
}

$where = ["t.tarih BETWEEN :from AND :to"];
$params = [':from' => $from, ':to' => $to];
if ($acc !== '')  { $where[] = "t.account_id = :acc"; $params[':acc'] = (int)$acc; }
if ($q !== '')    { $where[] = "(t.aciklama LIKE :q OR t.karsi_taraf LIKE :q)"; $params[':q'] = "%$q%"; }
if (!$showKesinti){ $where[] = "t.aciklama NOT LIKE :kes"; $params[':kes'] = '%KESİNTİ VE EKLER%'; }
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

/**
 * banka_ref (transactionInstanceId) tam zaman damgasi tasir, ornek:
 * "2026-07-02T04:26:15.527794" — 'tarih' kolonu sadece gunu tuttugu icin
 * saat:dakika buradan cikarilir.
 */
function islemSaati(string $bankaRef): ?string
{
    return preg_match('/T(\d{2}:\d{2})/', $bankaRef, $m) ? $m[1] : null;
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title>Garanti Hesap Takip</title><link rel="stylesheet" href="assets/app.css">
</head><body>
<header><h1>Hesap Takip</h1>
  <nav><a class="btn" href="refresh.php">&#8635; Yenile</a> <a href="logout.php">Cikis</a></nav>
</header>

<?php if ($secilenHesap): ?>
  <p class="secili-hesap">Secili Hesap: <strong><?= htmlspecialchars($secilenHesap['iban']) ?></strong><?= $secilenHesap['ad'] ? ' — ' . htmlspecialchars($secilenHesap['ad']) : '' ?></p>
<?php endif; ?>

<?php if (isset($_GET['synced'])): ?>
  <p class="notice ok">Guncellendi — <?= (int)$_GET['synced'] ?> yeni hareket alindi.</p>
<?php elseif (isset($_GET['syncerr'])): ?>
  <p class="notice err">Guncelleme hatasi: <?= htmlspecialchars($_GET['syncerr']) ?></p>
<?php endif; ?>

<section class="summary">
  <?php foreach ($balances as $b): ?>
    <div class="card"><span><?= htmlspecialchars($b['para_birimi']) ?></span>
      <strong><?= number_format((float)$b['toplam'], 2, ',', '.') ?></strong></div>
  <?php endforeach; ?>
</section>

<form class="filters" method="get">
  <select name="acc" onchange="this.form.submit()">
    <?php foreach ($accounts as $a): ?>
      <option value="<?= htmlspecialchars((string)$a['id']) ?>" <?= $acc == $a['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($a['iban']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
  <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
  <input type="text" name="q" placeholder="Ara (aciklama/karsi taraf)" value="<?= htmlspecialchars($q) ?>">
  <label class="chk"><input type="checkbox" name="show_kesinti" value="1" onchange="this.form.submit()" <?= $showKesinti ? 'checked' : '' ?>> Kesinti ve eklerini goster</label>
  <button type="submit">Filtrele</button>
  <a class="btn" href="export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">Excel/CSV</a>
</form>

<table class="tx"><thead><tr>
  <th>Tarih</th><th>Aciklama</th><th>Karsi Taraf</th>
  <th class="num">Tutar</th><th class="num">Bakiye</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr class="<?= $r['borc_alacak'] === 'D' ? 'debit' : 'credit' ?>">
    <td><?= htmlspecialchars($r['tarih']) ?><?php if ($saat = islemSaati((string)$r['banka_ref'])): ?> <span class="saat"><?= htmlspecialchars($saat) ?></span><?php endif; ?></td>
    <td><?= htmlspecialchars((string)$r['aciklama']) ?></td>
    <td><?= htmlspecialchars((string)$r['karsi_taraf']) ?></td>
    <td class="num"><?= number_format((float)$r['tutar'], 2, ',', '.') ?></td>
    <td class="num"><?= $r['bakiye_sonrasi'] !== null ? number_format((float)$r['bakiye_sonrasi'], 2, ',', '.') : '' ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
</body></html>
