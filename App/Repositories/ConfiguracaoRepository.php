<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use Throwable;

final class ConfiguracaoRepository
{
    /**
     * @return array<string, string>
     */
    public function listarPorPrefixo(string $prefixo): array
    {
        $st = Database::pdo()->prepare(
            'SELECT chave, valor FROM configuracoes WHERE chave LIKE ? ORDER BY chave'
        );
        $st->execute([$prefixo . '%']);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param array<string, string> $valores
     */
    public function salvarMuitos(array $valores): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $select = $pdo->prepare('SELECT 1 FROM configuracoes WHERE chave = ? LIMIT 1');
            $update = $pdo->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ? LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?)');

            foreach ($valores as $chave => $valor) {
                $select->execute([$chave]);
                if ($select->fetchColumn()) {
                    $update->execute([$valor, $chave]);
                } else {
                    $insert->execute([$chave, $valor]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
