<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const KEY = '_user';

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION[self::KEY] = [
            'id'            => (int) $user['id'],
            'nome'          => (string) $user['nome'],
            'email'         => (string) $user['email'],
            'nivel_acesso'  => (string) $user['nivel_acesso'],
        ];

        $_SESSION['usuario_id']    = (int) $user['id'];
        $_SESSION['usuario_nome']  = (string) $user['nome'];
        $_SESSION['nivel_acesso']  = (string) $user['nivel_acesso'];
        $_SESSION['filial_ativa']  = $_SESSION['filial_ativa'] ?? 1;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION[self::KEY]['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION[self::KEY] ?? null;
    }

    public static function id(): ?int
    {
        return $_SESSION[self::KEY]['id'] ?? null;
    }

    public static function nivel(): ?string
    {
        return $_SESSION[self::KEY]['nivel_acesso'] ?? null;
    }

    public static function temNivel(string ...$niveis): bool
    {
        $atual = self::nivel();
        return $atual !== null && in_array($atual, $niveis, true);
    }
}
