<?php
// Dashboard "Yenile" butonu: login korumali elle senkron tetikleyici.
// Banka API'sinden yeni hareketleri ceker ve dashboard'a geri doner.
require __DIR__ . '/_guard.php';

use Garanti\Sync\SyncRunner;

set_time_limit(300);

try {
    $added = SyncRunner::run(config());
    header('Location: index.php?synced=' . $added);
} catch (\Throwable $e) {
    header('Location: index.php?syncerr=' . urlencode(substr($e->getMessage(), 0, 150)));
}
exit;
