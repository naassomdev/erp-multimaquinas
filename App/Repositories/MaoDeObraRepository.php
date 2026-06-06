<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class MaoDeObraRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarTudo(): array
    {
        $sql = "SELECT id, categoria, nome, valor_padrao 
                  FROM tabela_mao_obra 
                 ORDER BY 
                    CASE categoria
                        WHEN 'maquina' THEN 1
                        WHEN 'motor' THEN 2
                        WHEN 'bomba' THEN 3
                        ELSE 4
                    END,
                    nome ASC";
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listarAgrupadoPorCategoria(): array
    {
        $todos = $this->listarTudo();
        $agrupado = [
            'maquina' => [],
            'motor'   => [],
            'bomba'   => [],
            'servico' => [],
        ];
        foreach ($todos as $item) {
            $agrupado[$item['categoria']][] = $item;
        }
        return $agrupado;
    }

    public function buscar(int $id): ?array
    {
        $sql = "SELECT id, categoria, nome, valor_padrao FROM tabela_mao_obra WHERE id = ?";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function criar(array $dados): int
    {
        $sql = "INSERT INTO tabela_mao_obra (categoria, nome, valor_padrao) VALUES (?, ?, ?)";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $dados['categoria'],
            trim(strtoupper((string) $dados['nome'])),
            (float) $dados['valor_padrao']
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sql = "UPDATE tabela_mao_obra SET categoria = ?, nome = ?, valor_padrao = ? WHERE id = ?";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            $dados['categoria'],
            trim(strtoupper((string) $dados['nome'])),
            (float) $dados['valor_padrao'],
            $id
        ]);
    }

    public function deletar(int $id): void
    {
        $sql = "DELETE FROM tabela_mao_obra WHERE id = ?";
        Database::pdo()->prepare($sql)->execute([$id]);
    }

    // ── Sugestão por nome (token-based) ──────────────────────────────────

    /**
     * Divide o texto em tokens por whitespace (maiúsculas).
     * Preserva tokens compostos como "7,5", "1/4", "9\"" como unidades únicas.
     *
     * @return string[]
     */
    private function tokenizar(string $texto): array
    {
        $texto = mb_strtoupper(trim($texto), 'UTF-8');
        $partes = preg_split('/\s+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
        return $partes === false ? [] : $partes;
    }

    /**
     * Verifica se todos os tokens do nome do item aparecem exatamente no conjunto
     * de tokens do equipamento. Retorna o número de tokens combinados (score) ou 0.
     *
     * @param string[] $tokensEquip
     * @param string[] $tokensNome
     */
    private function pontuarMatchMo(array $tokensEquip, array $tokensNome): int
    {
        $setEquip = array_flip($tokensEquip);
        foreach ($tokensNome as $t) {
            if (!isset($setEquip[$t])) {
                return 0;
            }
        }
        return count($tokensNome);
    }

    /**
     * Tenta encontrar o item de mão de obra mais adequado para o equipamento.
     * Usa correspondência por tokens inteiros (split em whitespace) — evita falsos
     * positivos como "5 CV" combinando com "25 CV" via substring.
     *
     * Ranking: maior score (mais tokens combinados) vence; em empate de score,
     * vence o nome mais longo; em empate de comprimento, retorna null (ambíguo).
     *
     * @return array<string,mixed>|null  Item da tabela ou null se não encontrado / ambíguo.
     */
    public function sugerirPorNome(string $equipamento): ?array
    {
        $tokensEquip = $this->tokenizar($equipamento);
        if (empty($tokensEquip)) {
            return null;
        }

        $todas     = $this->listarTudo();
        $filtradas = array_filter($todas, fn($item) => $item['categoria'] !== 'servico');

        $melhorScore   = 0;
        $melhorNomeLen = 0;
        $melhorItem    = null;
        $empate        = false;

        foreach ($filtradas as $item) {
            $tokensNome = $this->tokenizar((string) $item['nome']);
            if (empty($tokensNome)) {
                continue;
            }
            $score = $this->pontuarMatchMo($tokensEquip, $tokensNome);
            if ($score === 0) {
                continue;
            }
            $nomeLen = mb_strlen((string) $item['nome'], 'UTF-8');

            if ($score > $melhorScore
                || ($score === $melhorScore && $nomeLen > $melhorNomeLen)
            ) {
                $melhorScore   = $score;
                $melhorNomeLen = $nomeLen;
                $melhorItem    = $item;
                $empate        = false;
            } elseif ($score === $melhorScore && $nomeLen === $melhorNomeLen) {
                $empate = true;
            }
        }

        return ($empate || $melhorItem === null) ? null : $melhorItem;
    }
}
