<?php
namespace Garanti\Auth;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login(string $user, string $pass, array $cfg): bool
    {
        self::start();
        if ($user === ($cfg['username'] ?? '') && password_verify($pass, $cfg['password_hash'] ?? '')) {
            $_SESSION['garanti_auth'] = true;
            return true;
        }
        return false;
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['garanti_auth']);
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['garanti_auth']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }
}
