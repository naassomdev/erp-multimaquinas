<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') return false;
        $stored = $_SESSION[self::KEY] ?? null;
        if (!is_string($stored) || $stored === '') return false;
        return hash_equals($stored, $submitted);
    }

    public static function rotate(): string
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
