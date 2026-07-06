<?php
// Cron: her 30 dk. Ornek crontab:  */30 * * * * php /path/garanti/bin/sync.php >> /path/garanti/sync.out 2>&1
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';

use Garanti\Sync\SyncRunner;

$added = SyncRunner::run(config());
echo date('c') . " — {$added} yeni hareket.\n";
