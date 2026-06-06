<?php
declare(strict_types=1);

namespace App\Core;

final class Flash
{
    private const KEY = '_flash';

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[self::KEY][$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[self::KEY][$key] ?? $default;
        unset($_SESSION[self::KEY][$key]);
        if (isset($_SESSION[self::KEY]) && empty($_SESSION[self::KEY])) {
            unset($_SESSION[self::KEY]);
        }
        return $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[self::KEY][$key]);
    }

    public static function keepOld(array $input): void
    {
        self::set('_old', $input);
    }

    public static function old(string $key, string $default = ''): string
    {
        $old = $_SESSION[self::KEY]['_old'] ?? [];
        return (string)($old[$key] ?? $default);
    }

    /**
     * Retorna o valor de _old preservando o tipo original (array, int, etc.).
     * Útil para campos repetidos (ex.: "equipamentos[][nome]") onde Flash::old()
     * — que força string — não serve.
     */
    public static function oldRaw(string $key, mixed $default = null): mixed
    {
        $old = $_SESSION[self::KEY]['_old'] ?? [];
        return $old[$key] ?? $default;
    }

    public static function clearOld(): void
    {
        unset($_SESSION[self::KEY]['_old']);
    }
}
