<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';
use Garanti\Auth\Session;

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Session::login($_POST['user'] ?? '', $_POST['pass'] ?? '', config('dashboard'))) {
        header('Location: index.php');
        exit;
    }
    $err = 'Hatali kullanici adi veya sifre.';
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title>Garanti Hesap Takip — Giris</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="login">
<form method="post" class="login-box">
  <h1>Hesap Takip</h1>
  <?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  <input name="user" placeholder="Kullanici adi" autofocus>
  <input name="pass" type="password" placeholder="Sifre">
  <button type="submit">Giris</button>
</form></body></html>
