<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repositório de produtos com busca multi-camada:
 *
 *  Camada 1 — Full-Text Search (BOOLEAN MODE)
 *    Usa o índice FULLTEXT `ft_busca` (codigo, descricao, marca).
 *    Cada token do query vira "+token*" → todos os termos devem estar presentes
 *    e aceita prefixos (ex.: "induz" encontra "induzido").
 *    Mínimo InnoDB FTS: 3 caracteres por token.
 *
 *  Camada 2 — Busca Tokenizada LIKE (fallback quando tokens curtos)
 *    Divide a query em tokens e exige que CADA token apareça em
 *    (codigo OR descricao OR marca) — interseção real de conjuntos.
 *    Ex.: "ro 1751" → codigo/descricao/marca contém "ro" E "1751".
 *
 *  Camada 3 — LIKE simples (último recurso, 1 termo curto)
 *    Busca por código, descrição ou EAN com LIKE '%q%'.
 *
 *  Ranking (ORDER BY):
 *    0 — código exato
 *    1 — código começa com a query
 *    2 — descrição começa com a query (ou com o 1º token)
 *    3 — FTS / LIKE genérico
 *    Dentro de cada nível: descrição ASC
 */
final class ProdutoRepository
{
    private const COLS = 'id, codigo, descricao, marca, unidade,
                          valor, valor_oferta, valor_venda_calculado,
                          estoque_qty, estoque_min, controla_estoque';

    // ──────────────────────────────────────────────────────────────────
    // API pública
    // ──────────────────────────────────────────────────────────────────

    /**
     * Busca genérica (código, descrição ou EAN).
     * Usado pelo módulo de OS/Orçamento (autocomplete geral).
     */
    public function buscarPorTermo(string $termo, int $limit = 20): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 2) return [];

        return $this->buscarInteligente($termo, 'geral', $limit);
    }

    /**
     * Busca prioritária por CÓDIGO (prefixo + FTS).
     * Gatilho: campo "Código" digitado pelo técnico.
     */
    public function buscarPorCodigoParcial(string $codigo, int $limit = 20): array
    {
        $codigo = trim($codigo);
        if (mb_strlen($codigo) < 2) return [];

        return $this->buscarInteligente($codigo, 'codigo', $limit);
    }

    /**
     * Busca prioritária por DESCRIÇÃO com FTS + tokenização.
     * Gatilho: campo "Descrição" digitado pelo técnico.
     */
    public function buscarPorDescricaoParcial(string $descricao, int $limit = 20): array
    {
        $descricao = trim($descricao);
        if (mb_strlen($descricao) < 3) return [];

        return $this->buscarInteligente($descricao, 'descricao', $limit);
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, codigo, descricao, marca, unidade,
                    valor, valor_venda_calculado, estoque_qty, controla_estoque
               FROM produtos
              WHERE id = :id AND ativo = 1
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function buscarPorCodigoExato(string $codigo): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM produtos
              WHERE codigo = :codigo AND ativo = 1
              LIMIT 1'
        );
        $stmt->execute([':codigo' => $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }

        // Fallback: o código digitado pode ser um código ANTIGO/alternativo.
        // Resolve para o produto ATUAL, sinalizando via_codigo_alternativo.
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::colsComPrefixo('p') . ",
                    pc.codigo AS via_codigo_alternativo,
                    pc.tipo   AS via_codigo_tipo
               FROM produto_codigos pc
               JOIN produtos p ON p.id = pc.produto_id AND p.ativo = 1
              WHERE pc.codigo = :codigo
              LIMIT 1"
        );
        $stmt->execute([':codigo' => $codigo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Motor de busca inteligente (uso interno)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Orquestra as três camadas de busca.
     *
     * @param string $mode  'codigo' | 'descricao' | 'geral'
     */
    private function buscarInteligente(string $query, string $mode, int $limit): array
    {
        $rows = $this->buscarNasCamadas($query, $mode, $limit);

        // Códigos antigos/alternativos: aplica-se à busca por código e à geral.
        // (No modo 'descricao' não faz sentido resolver por código alternativo.)
        if ($mode === 'codigo' || $mode === 'geral') {
            $rows = $this->mesclarCodigosAlternativos($rows, $query, $limit);
        }

        return $rows;
    }

    /**
     * Orquestra as três camadas de busca em `produtos`.
     */
    private function buscarNasCamadas(string $query, string $mode, int $limit): array
    {
        $tokens = $this->tokenizar($query);

        // Camada 1: FTS quando todos os tokens têm ≥ 3 chars
        $tokensCurtos = array_filter($tokens, fn(string $t) => mb_strlen($t) < 3);
        if (empty($tokensCurtos) && !empty($tokens)) {
            $rows = $this->buscarFTS($tokens, $mode, $limit);
            if (!empty($rows)) return $rows;
        }

        // Camada 2: LIKE tokenizado (cada token deve aparecer em algum campo)
        if (count($tokens) > 1 || !empty($tokensCurtos)) {
            $rows = $this->buscarTokenizado($tokens, $query, $mode, $limit);
            if (!empty($rows)) return $rows;
        }

        // Camada 3: LIKE simples (fallback final)
        return $this->buscarLikeSimples($query, $mode, $limit);
    }

    /**
     * Mescla, sem duplicar, os produtos cujo CÓDIGO ANTIGO/alternativo casa
     * com a query. Produtos já achados pelo código atual têm prioridade; os
     * resolvidos via código alternativo são anexados ao final, marcados com
     * via_codigo_alternativo / via_codigo_tipo.
     */
    private function mesclarCodigosAlternativos(array $rows, string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') return $rows;

        $alternativos = $this->buscarPorCodigoAlternativo($query, $limit);
        if (empty($alternativos)) return $rows;

        $idsPresentes = array_flip(array_column($rows, 'id'));
        foreach ($alternativos as $alt) {
            if (!isset($idsPresentes[$alt['id']])) {
                $rows[] = $alt;
                $idsPresentes[$alt['id']] = true;
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Busca produtos cujo código antigo/alternativo casa com a query
     * (igualdade exata primeiro, depois prefixo). Retorna o produto ATUAL,
     * marcado com o código alternativo que casou.
     */
    private function buscarPorCodigoAlternativo(string $query, int $limit): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::colsComPrefixo('p') . ",
                    pc.codigo AS via_codigo_alternativo,
                    pc.tipo   AS via_codigo_tipo
               FROM produto_codigos pc
               JOIN produtos p ON p.id = pc.produto_id AND p.ativo = 1
              WHERE pc.codigo = :exato OR pc.codigo LIKE :prefix
              ORDER BY (pc.codigo = :exato2) DESC, p.codigo ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':exato',  $query);
        $stmt->bindValue(':exato2', $query);
        $stmt->bindValue(':prefix', $query . '%');
        $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ──────────────────────────────────────────────────────────────────
    // Camada 1 — Full-Text Search (BOOLEAN MODE)
    // ──────────────────────────────────────────────────────────────────

    private function buscarFTS(array $tokens, string $mode, int $limit): array
    {
        // Monta query FTS: cada token vira "+token*"
        // Escapa caracteres especiais do FTS: + - > < ( ) ~ * " @ space
        $ftsQuery = implode(' ', array_map(
            fn(string $t) => '+' . preg_replace('/[+\-><()\~*"@]+/', '', $t) . '*',
            $tokens
        ));

        if (trim($ftsQuery, '+ ') === '') return [];

        $firstToken = $tokens[0] ?? '';

        if ($mode === 'codigo') {
            // No modo código: prioriza match no campo codigo, mas usa FTS completo
            $sql = 'SELECT ' . self::COLS . ",
                           MATCH(codigo, descricao, marca) AGAINST (:fts IN BOOLEAN MODE) AS _score
                      FROM produtos
                     WHERE ativo = 1
                       AND MATCH(codigo, descricao, marca) AGAINST (:fts2 IN BOOLEAN MODE)
                     ORDER BY
                       CASE WHEN codigo = :exact    THEN 0
                            WHEN codigo LIKE :prefix THEN 1
                            ELSE 2 END,
                       _score DESC,
                       codigo ASC
                     LIMIT :lim";

            $stmt = Database::pdo()->prepare($sql);
            $stmt->bindValue(':fts',    $ftsQuery);
            $stmt->bindValue(':fts2',   $ftsQuery);
            $stmt->bindValue(':exact',  $firstToken);
            $stmt->bindValue(':prefix', $firstToken . '%');
            $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);

        } elseif ($mode === 'descricao') {
            $sql = 'SELECT ' . self::COLS . ",
                           MATCH(codigo, descricao, marca) AGAINST (:fts IN BOOLEAN MODE) AS _score
                      FROM produtos
                     WHERE ativo = 1
                       AND MATCH(codigo, descricao, marca) AGAINST (:fts2 IN BOOLEAN MODE)
                     ORDER BY
                       CASE WHEN descricao LIKE :prefix THEN 0
                            ELSE 1 END,
                       _score DESC,
                       descricao ASC
                     LIMIT :lim";

            $stmt = Database::pdo()->prepare($sql);
            $stmt->bindValue(':fts',    $ftsQuery);
            $stmt->bindValue(':fts2',   $ftsQuery);
            $stmt->bindValue(':prefix', $firstToken . '%');
            $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);

        } else {
            // geral
            $sql = 'SELECT ' . self::COLS . ",
                           MATCH(codigo, descricao, marca) AGAINST (:fts IN BOOLEAN MODE) AS _score
                      FROM produtos
                     WHERE ativo = 1
                       AND MATCH(codigo, descricao, marca) AGAINST (:fts2 IN BOOLEAN MODE)
                     ORDER BY
                       CASE WHEN codigo = :exact    THEN 0
                            WHEN codigo LIKE :prefix THEN 1
                            WHEN descricao LIKE :dpfx THEN 2
                            ELSE 3 END,
                       _score DESC,
                       descricao ASC
                     LIMIT :lim";

            $stmt = Database::pdo()->prepare($sql);
            $stmt->bindValue(':fts',    $ftsQuery);
            $stmt->bindValue(':fts2',   $ftsQuery);
            $stmt->bindValue(':exact',  $firstToken);
            $stmt->bindValue(':prefix', $firstToken . '%');
            $stmt->bindValue(':dpfx',   $firstToken . '%');
            $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ──────────────────────────────────────────────────────────────────
    // Camada 2 — Busca tokenizada LIKE (multi-termos com tokens curtos)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Cada token deve aparecer em pelo menos um dos campos
     * (codigo OR descricao OR marca) — intersecção real.
     *
     * Ex.: "1751 ind" → registro precisa ter "1751" EM ALGUM CAMPO
     *                    E "ind" EM ALGUM CAMPO.
     */
    /**
     * Camada 2: cada token deve aparecer em pelo menos um campo (interseção real).
     *
     * Regra de campo por modo:
     *   codigo    → WHERE restringe a `codigo`; ORDER BY prioriza codigo exato/prefixo.
     *   descricao → WHERE pesquisa todos os campos (10E-1: numero pode estar no codigo);
     *                ORDER BY prioriza descricao.
     *   geral     → WHERE pesquisa todos os campos; ORDER BY prioriza codigo, depois descricao.
     *
     * IMPORTANTE: cada param é adicionado SOMENTE quando referenciado na SQL gerada.
     * bindValue() lança HY093 se receber param que não existe na query.
     */
    private function buscarTokenizado(array $tokens, string $originalQuery, string $mode, int $limit): array
    {
        $pdo    = Database::pdo();
        $params = [':lim' => $limit];
        $where  = ['ativo = 1'];
        $i      = 0;

        foreach ($tokens as $token) {
            $likeValue = '%' . $token . '%';

            if ($mode === 'codigo') {
                $key = ':codigo_like_' . $i;
                $params[$key] = $likeValue;
                $where[] = "(codigo LIKE $key)";
            } else {
                // 10E-1: descricao e geral buscam em todos os campos.
                $kCod = ':codigo_like_'    . $i;
                $kDsc = ':descricao_like_' . $i;
                $kMrc = ':marca_like_'     . $i;
                $kEan = ':ean_like_'       . $i;
                $params[$kCod] = $likeValue;
                $params[$kDsc] = $likeValue;
                $params[$kMrc] = $likeValue;
                $params[$kEan] = $likeValue;
                $where[] = "(codigo LIKE $kCod OR descricao LIKE $kDsc OR marca LIKE $kMrc OR ean LIKE $kEan)";
            }

            $i++;
        }

        $firstToken = $tokens[0] ?? $originalQuery;
        $whereStr   = implode(' AND ', $where);

        // ORDER BY — params adicionados apenas para os placeholders presentes no ORDER BY.
        if ($mode === 'codigo') {
            $params[':exact']  = $firstToken;
            $params[':prefix'] = $firstToken . '%';
            $orderBy = "CASE WHEN codigo = :exact
                             THEN 0
                             WHEN codigo LIKE :prefix
                             THEN 1
                             ELSE 2 END, codigo ASC";
        } elseif ($mode === 'descricao') {
            $params[':dpfx'] = $firstToken . '%';
            $orderBy = "CASE WHEN descricao LIKE :dpfx THEN 0 ELSE 1 END, descricao ASC";
        } else {
            $params[':exact']  = $firstToken;
            $params[':prefix'] = $firstToken . '%';
            $params[':dpfx']   = $firstToken . '%';
            $orderBy = "CASE WHEN codigo = :exact    THEN 0
                             WHEN codigo LIKE :prefix THEN 1
                             WHEN descricao LIKE :dpfx THEN 2
                             ELSE 3 END, descricao ASC";
        }

        $sql  = 'SELECT ' . self::COLS . " FROM produtos WHERE $whereStr ORDER BY $orderBy LIMIT :lim";
        $stmt = $pdo->prepare($sql);

        foreach ($params as $k => $v) {
            $type = ($k === ':lim') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($k, $v, $type);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ──────────────────────────────────────────────────────────────────
    // Camada 3 — LIKE simples (fallback final)
    // ──────────────────────────────────────────────────────────────────

    private function buscarLikeSimples(string $query, string $mode, int $limit): array
    {
        $like   = '%' . $query . '%';
        $prefix = $query . '%';
        $pdo    = Database::pdo();

        if ($mode === 'codigo') {
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLS . '
                   FROM produtos
                  WHERE ativo = 1 AND codigo LIKE :like
                  ORDER BY
                    CASE WHEN codigo = :exact THEN 0
                         WHEN codigo LIKE :prefix THEN 1
                         ELSE 2 END, codigo ASC
                  LIMIT :lim'
            );
            $stmt->bindValue(':like',   $like);
            $stmt->bindValue(':exact',  $query);
            $stmt->bindValue(':prefix', $prefix);
            $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);

        } elseif ($mode === 'descricao') {
            // 10E-1: inclui codigo, marca e ean para não descartar itens onde o número
            // digitado está no código e o texto na descrição. ORDER BY mantém descricao primeiro.
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLS . '
                   FROM produtos
                  WHERE ativo = 1
                    AND (codigo LIKE :c_like OR descricao LIKE :d_like OR marca LIKE :m_like OR ean LIKE :e_like)
                  ORDER BY
                    CASE WHEN descricao LIKE :prefix THEN 0
                         WHEN codigo    LIKE :cpfx   THEN 1
                         ELSE 2 END,
                    descricao ASC
                  LIMIT :lim'
            );
            $stmt->bindValue(':c_like', $like);
            $stmt->bindValue(':d_like', $like);
            $stmt->bindValue(':m_like', $like);
            $stmt->bindValue(':e_like', $like);
            $stmt->bindValue(':prefix', $prefix);
            $stmt->bindValue(':cpfx',   $prefix);
            $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);

        } else {
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLS . '
                   FROM produtos
                  WHERE ativo = 1
                    AND (codigo LIKE :p_like OR descricao LIKE :d_like OR ean LIKE :e_like)
                  ORDER BY
                    CASE WHEN codigo = :exact     THEN 0
                         WHEN codigo LIKE :prefix THEN 1
                         WHEN descricao LIKE :dpfx THEN 2
                         ELSE 3 END,
                    descricao ASC
                  LIMIT :lim'
            );
            $stmt->bindValue(':p_like', $like);
            $stmt->bindValue(':d_like', $like);
            $stmt->bindValue(':e_like', $like);
            $stmt->bindValue(':exact',  $query);
            $stmt->bindValue(':prefix', $prefix);
            $stmt->bindValue(':dpfx',   $prefix);
            $stmt->bindValue(':lim',    $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ──────────────────────────────────────────────────────────────────
    // Utilitário: tokenização
    // ──────────────────────────────────────────────────────────────────

    /**
     * Lista de colunas de `produtos` (self::COLS) qualificada com um alias.
     * Ex.: colsComPrefixo('p') => 'p.id, p.codigo, p.descricao, ...'
     * Usada quando a query faz JOIN com produto_codigos.
     */
    private static function colsComPrefixo(string $alias): string
    {
        $cols = array_map('trim', explode(',', self::COLS));
        return implode(', ', array_map(static fn(string $c) => $alias . '.' . $c, $cols));
    }

    /**
     * Divide a query em tokens normalizados:
     * - Converte para minúsculas
     * - Remove caracteres especiais perigosos para LIKE/FTS
     * - Descarta tokens vazios
     * - Limita a 6 tokens para evitar queries gigantes
     *
     * Ex.: "Induzido 220v bosch" → ['induzido', '220v', 'bosch']
     */
    private function tokenizar(string $query): array
    {
        // Normaliza: lowercase, remove chars perigosos para SQL/FTS
        $normalizado = mb_strtolower(trim($query));
        $normalizado = preg_replace('/[%_\\\\]+/', '', $normalizado);   // chars LIKE especiais
        $normalizado = preg_replace('/\s+/', ' ', $normalizado);

        $tokens = array_filter(
            explode(' ', $normalizado),
            fn(string $t) => mb_strlen($t) >= 1
        );

        // Máximo 6 tokens (evita abuso)
        return array_values(array_slice($tokens, 0, 6));
    }
}
