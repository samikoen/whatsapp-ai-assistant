<?php
// Garanti portal Callback URL hedefi. client_credentials akisinda tarayici
// yonlendirmesi beklenmez; bu uc yalnizca portal dogrulamasi/olasi kod donusu icindir.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
$code = $_GET['code'] ?? null;
if ($code !== null) {
    // Ileride authorization_code akisi gerekirse burada islenecek. Simdilik logla.
    error_log('[garanti-callback] code alindi: ' . substr($code, 0, 8) . '...');
}
echo 'OK';
