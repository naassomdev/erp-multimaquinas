<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Repositories\UsuarioRepository;

final class AuthService
{
    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
    ) {}

    public function tentarLogin(string $email, string $senha): ?array
    {
        usleep(random_int(150_000, 350_000));

        $email = trim(strtolower($email));
        if ($email === '' || $senha === '') return null;

        $usuario = $this->usuarios->buscarPorEmail($email);
        if ($usuario === null) {
            password_verify($senha, '$2y$12$invalidhashinvalidhashinvalidhashinvalidhashinvalidhashin');
            return null;
        }

        if ((int) $usuario['status'] !== 1) return null;

        if (!password_verify($senha, $usuario['senha'])) return null;

        if (password_needs_rehash($usuario['senha'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $novoHash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->usuarios->atualizarSenha((int) $usuario['id'], $novoHash);
        }

        unset($usuario['senha']);
        Auth::login($usuario);
        return $usuario;
    }

    public function logout(): void
    {
        Auth::logout();
    }
}
