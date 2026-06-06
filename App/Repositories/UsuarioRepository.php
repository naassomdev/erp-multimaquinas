<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UsuarioRepository
{
    public function buscarPorEmail(string $email): ?array
    {
        $sql = "SELECT id, nome, email, senha, nivel_acesso, status
                  FROM usuarios
                 WHERE email = :email
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $sql = "SELECT id, nome, email, nivel_acesso, status
                  FROM usuarios
                 WHERE id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retorna mapa { id => nome } para um conjunto de IDs.
     * Uso: enriquecer exibição de "registrado por" sem N+1 queries.
     *
     * @param  int[] $ids
     * @return array<int,string>
     */
    public function buscarMapaPorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $i) => $i > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT id, nome FROM usuarios WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapa[(int) $row['id']] = $row['nome'];
        }
        return $mapa;
    }

    public function atualizarSenha(int $id, string $hash): void
    {
        $sql = "UPDATE usuarios SET senha = :senha WHERE id = :id";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':senha' => $hash, ':id' => $id]);
    }

    /**
     * Lista usuários com filtros opcionais.
     * Nunca retorna a coluna senha.
     *
     * @param array{q?:string, nivel?:string, status?:string} $filtros
     * @return list<array<string,mixed>>
     */
    public function listar(array $filtros = []): array
    {
        $where  = [];
        $params = [];

        if (($filtros['q'] ?? '') !== '') {
            $where[]        = '(nome LIKE :q OR email LIKE :q)';
            $params[':q']   = '%' . $filtros['q'] . '%';
        }

        if (($filtros['nivel'] ?? '') !== '') {
            $where[]          = 'nivel_acesso = :nivel';
            $params[':nivel'] = $filtros['nivel'];
        }

        if (($filtros['status'] ?? '') !== '') {
            $where[]           = 'status = :status';
            $params[':status'] = (int) $filtros['status'];
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql  = "SELECT id, nome, email, nivel_acesso, status, criado_em
                   FROM usuarios
                   {$whereClause}
                  ORDER BY nome ASC";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insere novo usuário. A senha já deve chegar como hash.
     *
     * @param array{nome:string, email:string, senha:string, nivel_acesso:string, status:int} $dados
     */
    public function criar(array $dados): int
    {
        $sql = "INSERT INTO usuarios (nome, email, senha, nivel_acesso, status)
                VALUES (:nome, :email, :senha, :nivel, :status)";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':nome'   => $dados['nome'],
            ':email'  => $dados['email'],
            ':senha'  => $dados['senha'],
            ':nivel'  => $dados['nivel_acesso'],
            ':status' => (int) $dados['status'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Atualiza campos (sem senha) de um usuário.
     *
     * @param array{nome?:string, email?:string, nivel_acesso?:string, status?:int} $dados
     */
    public function atualizar(int $id, array $dados): void
    {
        $sets   = [];
        $params = [':id' => $id];

        foreach (['nome', 'email', 'nivel_acesso', 'status'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $key          = ':' . $campo;
                $sets[]       = "{$campo} = {$key}";
                $params[$key] = $dados[$campo];
            }
        }

        if ($sets === []) {
            return;
        }

        $sql  = 'UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Verifica se já existe um usuário com o e-mail dado,
     * opcionalmente ignorando um ID (para edições).
     */
    public function emailExiste(string $email, ?int $ignorarId = null): bool
    {
        if ($ignorarId !== null) {
            $stmt = Database::pdo()->prepare(
                'SELECT 1 FROM usuarios WHERE email = :email AND id != :id LIMIT 1'
            );
            $stmt->execute([':email' => $email, ':id' => $ignorarId]);
        } else {
            $stmt = Database::pdo()->prepare(
                'SELECT 1 FROM usuarios WHERE email = :email LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
        }
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Conta quantos administradores ativos existem.
     * Usado para impedir que o último admin seja removido/inativado.
     */
    public function contarAdminsAtivos(): int
    {
        $stmt = Database::pdo()->query(
            "SELECT COUNT(*) FROM usuarios WHERE nivel_acesso = 'admin' AND status = 1"
        );
        return (int) $stmt->fetchColumn();
    }
}
