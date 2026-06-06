<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repository completo de Produtos/Estoque.
 * Complementa o ProdutoRepository (autocomplete para orçamento)
 * com CRUD, paginação, filtros, alertas de estoque e movimentação.
 */
final class ProdutoFullRepository
{
    /**
     * Lista produtos com filtros e paginação.
     */
    public function listar(array $filtros, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT id, codigo, ean, descricao, categoria, marca, ncm, unidade,
                       valor, valor_oferta, preco_custo, margem_lucro, valor_venda_calculado,
                       estoque_qty, estoque_min, ativo, controla_estoque, created_at
                FROM produtos
                {$whereStr}
                ORDER BY descricao ASC
                LIMIT :limit OFFSET :offset";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Conta total com filtros.
     */
    public function contar(array $filtros): int
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) FROM produtos {$whereStr}";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca por ID.
     */
    public function buscarPorId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM produtos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca por código exato.
     */
    public function buscarPorCodigo(string $codigo): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM produtos WHERE codigo = ? LIMIT 1');
        $stmt->execute([trim($codigo)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria novo produto.
     */
    public function criar(array $dados): int
    {
        $campos = $this->camposPermitidos();
        $sets = [];
        $params = [];

        foreach ($campos as $c) {
            if (array_key_exists($c, $dados)) {
                $sets[] = $c;
                $params[":{$c}"] = $dados[$c];
            }
        }

        $cols = implode(', ', $sets);
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $sets));

        $sql = "INSERT INTO produtos ({$cols}) VALUES ({$placeholders})";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Atualiza produto existente.
     */
    public function atualizar(int $id, array $dados): void
    {
        $campos = $this->camposPermitidos();
        $sets = [];
        $params = [':id' => $id];

        foreach ($campos as $c) {
            if (array_key_exists($c, $dados) && $dados[$c] !== null) {
                $sets[] = "{$c} = :{$c}";
                $params[":{$c}"] = $dados[$c];
            }
        }

        if (empty($sets)) return;

        $sql = "UPDATE produtos SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
    }

    // ──────────────────────────────────────────────────────────────────
    // Códigos antigos / alternativos (tabela produto_codigos)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Lista os códigos antigos/alternativos de um produto (para edição).
     */
    public function listarCodigosAlternativos(int $produtoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, codigo, tipo, fornecedor_id, observacao
               FROM produto_codigos
              WHERE produto_id = ?
              ORDER BY codigo ASC'
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unicidade CRUZADA de um código alternativo (duas tabelas — não dá para
     * impor só com UNIQUE). Retorna uma frase descrevendo o conflito, ou null
     * se o código estiver livre.
     *
     * @param int $produtoIdAtual produto dono — ignora os alternativos dele mesmo.
     */
    public function conflitoDeCodigo(string $codigo, int $produtoIdAtual): ?string
    {
        $codigo = trim($codigo);
        if ($codigo === '') return null;

        // 1. Colide com o código PRINCIPAL de algum produto?
        $stmt = Database::pdo()->prepare(
            'SELECT id, descricao FROM produtos WHERE codigo = ? LIMIT 1'
        );
        $stmt->execute([$codigo]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p !== false) {
            return 'já é o código principal do produto #' . $p['id'] . ' (' . $p['descricao'] . ')';
        }

        // 2. Colide com o alternativo de OUTRO produto?
        $stmt = Database::pdo()->prepare(
            'SELECT produto_id FROM produto_codigos WHERE codigo = ? AND produto_id <> ? LIMIT 1'
        );
        $stmt->execute([$codigo, $produtoIdAtual]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($a !== false) {
            return 'já é código alternativo do produto #' . $a['produto_id'];
        }

        return null;
    }

    /**
     * Substitui o conjunto de códigos alternativos de um produto (delete-all +
     * reinsert, em transação). Assume que os códigos já foram validados via
     * conflitoDeCodigo() na camada de aplicação.
     *
     * @param array $codigos lista de ['codigo','tipo','fornecedor_id','observacao']
     */
    public function sincronizarCodigosAlternativos(int $produtoId, array $codigos): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM produto_codigos WHERE produto_id = ?')->execute([$produtoId]);

            if (!empty($codigos)) {
                $ins = $pdo->prepare(
                    'INSERT INTO produto_codigos (produto_id, codigo, tipo, fornecedor_id, observacao)
                     VALUES (:pid, :codigo, :tipo, :fornecedor_id, :obs)'
                );
                $tiposValidos = ['antigo', 'fornecedor', 'fabricante', 'outro'];
                foreach ($codigos as $c) {
                    $codigo = trim((string) ($c['codigo'] ?? ''));
                    if ($codigo === '') continue;
                    $tipo = in_array($c['tipo'] ?? '', $tiposValidos, true) ? $c['tipo'] : 'antigo';
                    $forn = (int) ($c['fornecedor_id'] ?? 0);
                    $ins->execute([
                        ':pid'           => $produtoId,
                        ':codigo'        => $codigo,
                        ':tipo'          => $tipo,
                        ':fornecedor_id' => $forn > 0 ? $forn : null,
                        ':obs'           => trim((string) ($c['observacao'] ?? '')),
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Exclui (desativa) um produto.
     */
    public function desativar(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE produtos SET ativo = 0 WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    }

    /**
     * Reativa um produto.
     */
    public function reativar(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE produtos SET ativo = 1 WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    }

    /**
     * Retorna produtos abaixo do estoque mínimo.
     */
    public function listarEstoqueBaixo(int $limit = 50): array
    {
        $sql = "SELECT id, codigo, descricao, marca, estoque_qty, estoque_min, unidade
                FROM produtos
                WHERE ativo = 1 AND estoque_qty <= estoque_min AND estoque_min > 0
                ORDER BY (estoque_min - estoque_qty) DESC
                LIMIT ?";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza quantidade em estoque (entrada ou saída).
     * Permite saldo negativo — o admin lida com compras de reposição.
     */
    public function atualizarEstoque(int $id, float $qty, string $operacao): void
    {
        $op = ($operacao === 'entrada') ? '+' : '-';
        $sql = "UPDATE produtos SET estoque_qty = estoque_qty {$op} ? WHERE id = ? LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$qty, $id]);
    }

    /**
     * Lista categorias distintas.
     */
    public function listarCategorias(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT DISTINCT categoria FROM produtos WHERE ativo = 1 AND categoria != '' ORDER BY categoria ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Lista marcas distintas.
     */
    public function listarMarcas(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT DISTINCT marca FROM produtos WHERE ativo = 1 AND marca != '' ORDER BY marca ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Lista todos os produtos com filtros (sem paginação).
     */
    public function listarTodos(array $filtros): array
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT id, codigo, ean, descricao, categoria, marca, ncm, unidade,
                       valor, valor_oferta, preco_custo, margem_lucro, valor_venda_calculado,
                       estoque_qty, estoque_min, ativo, controla_estoque, created_at
                FROM produtos
                {$whereStr}
                ORDER BY descricao ASC";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Campos permitidos para INSERT/UPDATE.
     */
    private function camposPermitidos(): array
    {
        return [
            'codigo', 'ean', 'descricao', 'categoria', 'marca', 'ncm',
            'unidade', 'valor', 'valor_oferta', 'preco_custo',
            'margem_lucro', 'valor_venda_calculado',
            'estoque_qty', 'estoque_min', 'ativo', 'controla_estoque',
        ];
    }

    /**
     * Aplica filtros ao WHERE.
     */
    private function aplicarFiltros(array $filtros, array &$where, array &$params): void
    {
        $busca = trim($filtros['busca'] ?? '');
        if ($busca !== '') {
            $like = "%{$busca}%";
            $where[] = '(codigo LIKE :b1 OR descricao LIKE :b2 OR ean LIKE :b3 OR marca LIKE :b4)';
            $params[':b1'] = $like;
            $params[':b2'] = $like;
            $params[':b3'] = $like;
            $params[':b4'] = $like;
        }

        $categoria = trim($filtros['categoria'] ?? '');
        if ($categoria !== '') {
            $where[] = 'categoria = :cat';
            $params[':cat'] = $categoria;
        }

        $marca = trim($filtros['marca'] ?? '');
        if ($marca !== '') {
            $where[] = 'marca = :marca';
            $params[':marca'] = $marca;
        }

        $estoqueBaixo = $filtros['estoque_baixo'] ?? '';
        if ($estoqueBaixo === '1') {
            $where[] = '(estoque_qty <= estoque_min AND estoque_min > 0)';
        }

        $ativo = $filtros['ativo'] ?? '';
        if ($ativo === '1') {
            $where[] = 'ativo = 1';
        } elseif ($ativo === '0') {
            $where[] = 'ativo = 0';
        }
        // default: mostrar todos
    }
}
