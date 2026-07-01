<?php
function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        $cfg = file_exists($path) ? require $path : require __DIR__ . '/config.example.php';
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}
