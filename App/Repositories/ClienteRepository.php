<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class ClienteRepository
{
    /**
     * Lista clientes com filtros e paginação.
     * Busca por nome, CPF/CNPJ, telefone, email ou cidade.
     */
    public function listar(array $filtros, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT id, nome, nome_fantasia, cpf_cnpj, telefone, telefone2,
                       celular, whatsapp, email, cidade, uf, created_at
                FROM clientes
                {$whereStr}
                ORDER BY nome ASC
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
     * Conta total de clientes com filtros (para paginação).
     */
    public function contar(array $filtros): int
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) FROM clientes {$whereStr}";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca cliente por ID.
     */
    public function buscarPorId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca por CPF/CNPJ (somente dígitos).
     */
    public function buscarPorCpfCnpj(string $doc): ?array
    {
        $doc = preg_replace('/\D/', '', $doc);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM clientes WHERE ' . $this->documentoNumericoExpr('cpf_cnpj') . ' = ? LIMIT 1'
        );
        $stmt->execute([$doc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca clientes por telefone (parcial).
     */
    public function buscarPorTelefone(string $telefone): array
    {
        $dig = preg_replace('/\D/', '', $telefone);
        $stmt = Database::pdo()->prepare(
            "SELECT id, nome, telefone, celular, whatsapp, cpf_cnpj
             FROM clientes
             WHERE REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', '') LIKE ?
                OR REPLACE(REPLACE(REPLACE(telefone2, '(', ''), ')', ''), '-', '') LIKE ?
                OR REPLACE(REPLACE(REPLACE(celular, '(', ''), ')', ''), '-', '') LIKE ?
                OR whatsapp LIKE ?
             ORDER BY nome ASC
             LIMIT 20"
        );
        $like = "%{$dig}%";
        $stmt->execute([$like, $like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca rápida para autocomplete (retorna poucos campos).
     *
     * Performance:
     *  - Frontend aplica Debounce (~350ms) + AbortController, então o volume
     *    de requests é controlado (~1 req por "parada de digitação").
     *  - A query usa LIKE '%termo%' com prepared statements (seguro contra SQL injection).
     *  - Se o termo não contém dígitos, ignora busca por telefone/CPF (evita LIKE '%%').
     *
     * Recomendação: INDEX (nome) ou FULLTEXT (nome) para bases > 5k registros.
     */
    public function buscarAutocomplete(string $termo, int $limit = 10): array
    {
        $like = "%{$termo}%";
        $dig  = preg_replace('/\D/', '', $termo);
        $temDigitos = $dig !== '';

        // Monta WHERE dinâmico para evitar clauses inúteis
        $conditions = ['nome LIKE :t_nome', 'email LIKE :t_email', 'whatsapp LIKE :t_whatsapp'];
        $params = [
            ':t_nome'  => $like,
            ':t_email' => $like,
            ':t_whatsapp' => $like,
        ];

        if ($temDigitos) {
            $digLike = "%{$dig}%";
            $conditions[] = $this->documentoNumericoExpr('cpf_cnpj') . ' LIKE :t_doc';
            $conditions[] = 'telefone LIKE :t_tel';
            $conditions[] = 'celular LIKE :t_cel';
            $conditions[] = 'whatsapp LIKE :t_wa_dig';
            $params[':t_doc'] = $digLike;
            $params[':t_tel'] = $digLike;
            $params[':t_cel'] = $digLike;
            $params[':t_wa_dig'] = $digLike;
        }

        $where = implode(' OR ', $conditions);

        // 10F-2: inclui nome_fantasia para que o JS exiba e diferencie empresa/pessoa.
        $sql = "SELECT id, nome, nome_fantasia, cpf_cnpj, telefone, celular, whatsapp, cidade, uf
                FROM clientes
                WHERE {$where}
                ORDER BY nome ASC
                LIMIT :lim";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cria um novo cliente. Retorna o ID inserido.
     */
    public function criar(array $dados): int
    {
        $campos = [
            'nome', 'nome_fantasia', 'data_nascimento', 'telefone', 'telefone2',
            'email', 'cpf_cnpj', 'rg_ie', 'fone', 'celular', 'whatsapp',
            'endereco', 'numero', 'complemento', 'bairro',
            'cod_cidade', 'cidade', 'uf', 'cep', 'obs',
        ];

        $sets = [];
        $params = [];
        foreach ($campos as $c) {
            if (array_key_exists($c, $dados)) {
                $sets[] = $c;
                $val = $dados[$c];
                if ($c === 'data_nascimento' && empty($val)) {
                    $params[":{$c}"] = null;
                } else {
                    $params[":{$c}"] = $val ?? '';
                }
            }
        }

        $cols = implode(', ', $sets);
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $sets));

        $sql = "INSERT INTO clientes ({$cols}) VALUES ({$placeholders})";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Atualiza um cliente existente.
     */
    public function atualizar(int $id, array $dados): void
    {
        $campos = [
            'nome', 'nome_fantasia', 'data_nascimento', 'telefone', 'telefone2',
            'email', 'cpf_cnpj', 'rg_ie', 'fone', 'celular', 'whatsapp',
            'endereco', 'numero', 'complemento', 'bairro',
            'cod_cidade', 'cidade', 'uf', 'cep', 'obs',
        ];

        $sets = [];
        $params = [':id' => $id];
        foreach ($campos as $c) {
            if (array_key_exists($c, $dados)) {
                $sets[] = "{$c} = :{$c}";
                $val = $dados[$c];
                if ($c === 'data_nascimento' && empty($val)) {
                    $params[":{$c}"] = null;
                } else {
                    $params[":{$c}"] = $val ?? '';
                }
            }
        }

        if (empty($sets)) return;

        $sql = "UPDATE clientes SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Exclui um cliente (somente se não tiver OS vinculadas).
     */
    public function excluir(int $id): bool
    {
        // Verifica vínculos
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM ordem_servico WHERE cliente_id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false; // Tem OS vinculada
        }

        $stmt = Database::pdo()->prepare('DELETE FROM clientes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Lista OS vinculadas a um cliente (para o detalhe).
     */
    public function listarOsDoCliente(int $clienteId, int $limit = 50): array
    {
        $sql = "SELECT os.id, os.data_entrada, os.status, os.nome_cliente, os.telefone,
                       COUNT(eq.id) as total_equipamentos
                FROM ordem_servico os
                LEFT JOIN os_equipamento eq ON eq.os_id = os.id
                WHERE os.cliente_id = ?
                GROUP BY os.id
                ORDER BY os.created_at DESC
                LIMIT ?";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(1, $clienteId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aplica filtros de busca ao WHERE.
     */
    private function aplicarFiltros(array $filtros, array &$where, array &$params): void
    {
        $busca = trim($filtros['busca'] ?? '');
        if ($busca !== '') {
            $dig = preg_replace('/\D/', '', $busca);
            $like = "%{$busca}%";

            $conditions = [
                'nome LIKE :busca_nome',
                'email LIKE :busca_email',
                'cidade LIKE :busca_cidade',
                'whatsapp LIKE :busca_whatsapp',
            ];
            $params[':busca_nome'] = $like;
            $params[':busca_email'] = $like;
            $params[':busca_cidade'] = $like;
            $params[':busca_whatsapp'] = $like;

            if ($dig !== '') {
                $conditions[] = $this->documentoNumericoExpr('cpf_cnpj') . ' LIKE :busca_doc';
                $conditions[] = 'telefone LIKE :busca_tel';
                $conditions[] = 'celular LIKE :busca_cel';
                $conditions[] = 'whatsapp LIKE :busca_wa_digits';
                $params[':busca_doc'] = "%{$dig}%";
                $params[':busca_tel'] = "%{$dig}%";
                $params[':busca_cel'] = "%{$dig}%";
                $params[':busca_wa_digits'] = "%{$dig}%";
            }

            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        $uf = trim($filtros['uf'] ?? '');
        if ($uf !== '') {
            $where[] = 'uf = :uf';
            $params[':uf'] = strtoupper($uf);
        }
    }

    // ── 11B-1/11B-3: Mesclagem de clientes ────────────────────────────────

    /**
     * Busca cliente por ID incluindo campos de mesclagem (11B-1).
     */
    public function buscarParaMesclagem(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, nome, nome_fantasia, cpf_cnpj, telefone, telefone2,
                    celular, whatsapp, fone, email, endereco, numero, complemento,
                    bairro, cidade, uf, cep, obs,
                    ativo, merged_into_id, merged_at, merged_by, created_at
               FROM clientes WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Executa mesclagem segura: origem → destino em transação.
     *
     * O que faz:
     *   - Transfere cliente_id em: ordem_servico, lancamentos_receber,
     *     notas_fiscais, vendas_balcao, relatorios_faturamento.
     *   - Copia campos vazios do destino a partir da origem, se $camposACopiar fornecido.
     *   - Marca origem como ativo=0, merged_into_id=destino, merged_at=NOW().
     *   - Registra em logs_auditoria.
     *
     * O que NÃO faz:
     *   - Não altera nome_cliente/telefone das OS (snapshots históricos).
     *   - Não exclui fisicamente o cliente origem.
     *   - Não altera dados do destino além dos campos explicitamente selecionados.
     *
     * @param  array<string> $camposACopiar  Campos do cliente a copiar da origem para o destino (apenas se destino estiver vazio)
     * @return array{os:int, lancamentos:int, vendas:int, notas:int, relatorios:int}  Linhas afetadas por tabela
     */
    public function mesclar(
        int   $origemId,
        int   $destinoId,
        int   $operadorId,
        array $camposACopiar = [],
    ): array {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            // ── 1. Travar ambos os clientes (FOR UPDATE) ──────────────────
            $stA = $pdo->prepare("SELECT id, ativo, merged_into_id FROM clientes WHERE id = ? LIMIT 1 FOR UPDATE");
            $stA->execute([$origemId]);
            $origem = $stA->fetch(PDO::FETCH_ASSOC);

            $stB = $pdo->prepare("SELECT id, ativo, merged_into_id FROM clientes WHERE id = ? LIMIT 1 FOR UPDATE");
            $stB->execute([$destinoId]);
            $destino = $stB->fetch(PDO::FETCH_ASSOC);

            if (!$origem) throw new \DomainException("Cliente origem #{$origemId} não encontrado.");
            if (!$destino) throw new \DomainException("Cliente destino #{$destinoId} não encontrado.");
            if ($origemId === $destinoId) throw new \DomainException("Origem e destino são o mesmo cliente.");
            if ((int)$origem['ativo'] === 0) throw new \DomainException("Cliente origem #{$origemId} já está inativo.");
            if ((int)$destino['ativo'] === 0) throw new \DomainException("Cliente destino #{$destinoId} já está inativo.");
            if ($origem['merged_into_id'] !== null) throw new \DomainException("Cliente origem #{$origemId} já foi mesclado.");
            if ($destino['merged_into_id'] !== null) throw new \DomainException("Cliente destino #{$destinoId} já foi mesclado.");

            // ── 2. Copiar campos selecionados (origem → destino, só se destino vazio) ──
            $camposPermitidos = ['nome', 'nome_fantasia', 'cpf_cnpj', 'telefone', 'telefone2',
                                 'celular', 'whatsapp', 'fone', 'email', 'endereco', 'numero', 'complemento',
                                 'bairro', 'cidade', 'uf', 'cep'];
            $camposValidos = array_intersect($camposACopiar, $camposPermitidos);
            if (!empty($camposValidos)) {
                $stOrig = $pdo->prepare("SELECT " . implode(',', $camposValidos) . " FROM clientes WHERE id = ? LIMIT 1");
                $stOrig->execute([$origemId]);
                $dadosOrigem = $stOrig->fetch(PDO::FETCH_ASSOC) ?: [];

                $sets = []; $params = [];
                foreach ($camposValidos as $campo) {
                    if (!empty($dadosOrigem[$campo])) {
                        $sets[]   = "{$campo} = COALESCE(NULLIF(TRIM({$campo}),''), :{$campo})";
                        $params[":{$campo}"] = $dadosOrigem[$campo];
                    }
                }
                if (!empty($sets)) {
                    $params[':id'] = $destinoId;
                    $pdo->prepare("UPDATE clientes SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1")
                        ->execute($params);
                }
            }

            // ── 3. Transferir vínculos ─────────────────────────────────────
            $tabelas = [
                'ordem_servico'        => 'os',
                'lancamentos_receber'  => 'lancamentos',
                'notas_fiscais'        => 'notas',
                'vendas_balcao'        => 'vendas',
                'relatorios_faturamento' => 'relatorios',
            ];
            $afetados = [];
            foreach ($tabelas as $tabela => $key) {
                $st = $pdo->prepare("UPDATE {$tabela} SET cliente_id = ? WHERE cliente_id = ?");
                $st->execute([$destinoId, $origemId]);
                $afetados[$key] = $st->rowCount();
            }

            // ── 4. Marcar origem como mesclada ─────────────────────────────
            $pdo->prepare(
                "UPDATE clientes
                    SET ativo = 0, merged_into_id = ?, merged_at = NOW(), merged_by = ?,
                        obs = CONCAT(COALESCE(obs,''), IF(obs IS NULL OR obs='','','  '),
                                     'MESCLADO → #', ?, ' em ', DATE_FORMAT(NOW(),'%d/%m/%Y'))
                  WHERE id = ? LIMIT 1"
            )->execute([$destinoId, $operadorId, $destinoId, $origemId]);

            // ── 5. Registrar em logs_auditoria ─────────────────────────────
            $logDados = json_encode([
                'origem_id'   => $origemId,
                'destino_id'  => $destinoId,
                'campos_copiados' => $camposValidos,
                'afetados'    => $afetados,
            ]);
            $pdo->prepare(
                "INSERT INTO logs_auditoria
                   (data_hora, usuario_id, tabela, registro_id, acao, dados_json, filial_id)
                 VALUES (NOW(), ?, 'clientes', ?, 'MESCLAR', ?, 1)"
            )->execute([$operadorId, $origemId, $logDados]);

            $pdo->commit();

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return $afetados;
    }

    // ── 11B-2: Detecção de duplicados ─────────────────────────────────────

    /**
     * Lista pares de clientes suspeitos de duplicidade.
     *
     * Critérios (em ordem de força):
     *   1. CPF/CNPJ numérico idêntico
     *   2. E-mail idêntico
     *   3. Celular/telefone normalizado idêntico (cruzado entre os campos)
     *
     * Apenas clientes ativos e não mesclados (ativo=1, merged_into_id IS NULL).
     * O cliente com mais OS ou maior ID é sugerido como canônico.
     *
     * @return array<int, array{
     *   id_a:int, nome_a:string, fantasia_a:string, cpf_a:string, email_a:string, telefone_a:string, celular_a:string, os_a:int, created_a:string,
     *   id_b:int, nome_b:string, fantasia_b:string, cpf_b:string, email_b:string, telefone_b:string, celular_b:string, os_b:int, created_b:string,
     *   motivo:string
     * }>
     */
    public function listarCandidatosDuplicados(): array
    {
        $pdo = Database::pdo();
        $resultados = [];
        $paresVistos = [];

        // ── 1. Por CPF/CNPJ ───────────────────────────────────────────────
        $sqlCpf = "
            SELECT
              a.id AS id_a, a.nome AS nome_a, a.nome_fantasia AS fantasia_a,
              a.cpf_cnpj AS cpf_a, a.email AS email_a, a.telefone AS telefone_a, a.celular AS celular_a, a.created_at AS created_a,
              b.id AS id_b, b.nome AS nome_b, b.nome_fantasia AS fantasia_b,
              b.cpf_cnpj AS cpf_b, b.email AS email_b, b.telefone AS telefone_b, b.celular AS celular_b, b.created_at AS created_b,
              'CPF/CNPJ igual' AS motivo
            FROM clientes a
            JOIN clientes b ON a.id < b.id
              AND REGEXP_REPLACE(a.cpf_cnpj,'[^0-9]','') = REGEXP_REPLACE(b.cpf_cnpj,'[^0-9]','')
              AND a.cpf_cnpj != ''
            WHERE a.ativo = 1 AND b.ativo = 1
              AND a.merged_into_id IS NULL AND b.merged_into_id IS NULL
            ORDER BY a.id
        ";
        $rows = $pdo->query($sqlCpf)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = min($r['id_a'], $r['id_b']) . '_' . max($r['id_a'], $r['id_b']);
            $paresVistos[$key] = true;
            $resultados[] = $r;
        }

        // ── 2. Por e-mail ─────────────────────────────────────────────────
        $sqlEmail = "
            SELECT
              a.id AS id_a, a.nome AS nome_a, a.nome_fantasia AS fantasia_a,
              a.cpf_cnpj AS cpf_a, a.email AS email_a, a.telefone AS telefone_a, a.celular AS celular_a, a.created_at AS created_a,
              b.id AS id_b, b.nome AS nome_b, b.nome_fantasia AS fantasia_b,
              b.cpf_cnpj AS cpf_b, b.email AS email_b, b.telefone AS telefone_b, b.celular AS celular_b, b.created_at AS created_b,
              'E-mail igual' AS motivo
            FROM clientes a
            JOIN clientes b ON a.id < b.id AND a.email = b.email AND a.email != ''
            WHERE a.ativo = 1 AND b.ativo = 1
              AND a.merged_into_id IS NULL AND b.merged_into_id IS NULL
            ORDER BY a.id
        ";
        $rows = $pdo->query($sqlEmail)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = min($r['id_a'], $r['id_b']) . '_' . max($r['id_a'], $r['id_b']);
            if (isset($paresVistos[$key])) continue; // já listado por CPF
            $paresVistos[$key] = true;
            $resultados[] = $r;
        }

        // ── 3. Por telefone/celular normalizado (cruzado) ─────────────────
        // Compara últimos 9 dígitos para tolerar DDD com ou sem prefixo 0.
        $sqlFone = "
            SELECT
              a.id AS id_a, a.nome AS nome_a, a.nome_fantasia AS fantasia_a,
              a.cpf_cnpj AS cpf_a, a.email AS email_a, a.telefone AS telefone_a, a.celular AS celular_a, a.created_at AS created_a,
              b.id AS id_b, b.nome AS nome_b, b.nome_fantasia AS fantasia_b,
              b.cpf_cnpj AS cpf_b, b.email AS email_b, b.telefone AS telefone_b, b.celular AS celular_b, b.created_at AS created_b,
              'Telefone/celular igual' AS motivo
            FROM clientes a
            JOIN clientes b ON a.id < b.id
            WHERE a.ativo = 1 AND b.ativo = 1
              AND a.merged_into_id IS NULL AND b.merged_into_id IS NULL
              AND (
                (a.celular_digits != '' AND LENGTH(a.celular_digits) >= 9
                  AND (a.celular_digits = b.celular_digits OR a.celular_digits = b.telefone_digits)
                )
                OR
                (a.telefone_digits != '' AND LENGTH(a.telefone_digits) >= 9
                  AND (a.telefone_digits = b.celular_digits OR a.telefone_digits = b.telefone_digits)
                )
              )
            ORDER BY a.id
            LIMIT 200
        ";
        $rows = $pdo->query($sqlFone)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = min($r['id_a'], $r['id_b']) . '_' . max($r['id_a'], $r['id_b']);
            if (isset($paresVistos[$key])) continue;
            $paresVistos[$key] = true;
            $resultados[] = $r;
        }

        // Enriquecer com contagem de OS por cliente
        if (!empty($resultados)) {
            $ids = [];
            foreach ($resultados as $r) {
                $ids[$r['id_a']] = true;
                $ids[$r['id_b']] = true;
            }
            $idList = implode(',', array_map('intval', array_keys($ids)));
            $counts = [];
            if ($idList !== '') {
                $stmt = $pdo->query(
                    "SELECT cliente_id, COUNT(*) AS total
                       FROM ordem_servico
                      WHERE cliente_id IN ({$idList})
                      GROUP BY cliente_id"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $counts[(int)$c['cliente_id']] = (int)$c['total'];
                }
            }
            foreach ($resultados as &$r) {
                $r['os_a'] = $counts[$r['id_a']] ?? 0;
                $r['os_b'] = $counts[$r['id_b']] ?? 0;
            }
            unset($r);
        }

        return $resultados;
    }

    /**
     * Busca clientes possíveis duplicados para alertar no momento do cadastro.
     * Critérios: CPF/CNPJ, E-mail, ou Telefones iguais.
     * Retorna no máximo 5 sugestões.
     */
    public function buscarPossiveisDuplicadosCadastro(array $dados, ?int $ignorarId = null): array
    {
        $pdo = Database::pdo();
        
        $cpfCnpj = preg_replace('/\D/', '', $dados['cpf_cnpj'] ?? '');
        $email = trim(strtolower($dados['email'] ?? ''));
        
        $telefonesRaw = [
            (string) ($dados['telefone'] ?? ''),
            (string) ($dados['telefone2'] ?? ''),
            (string) ($dados['celular'] ?? ''),
            (string) ($dados['fone'] ?? ''),
        ];
        $whatsappRaw = (string) ($dados['whatsapp'] ?? '');
        if ($whatsappRaw !== '' && !str_contains($whatsappRaw, '@')) {
            $telefonesRaw[] = $whatsappRaw;
        }

        $telefones = array_map(
            static fn(string $telefone): string => preg_replace('/\D/', '', $telefone) ?? '',
            $telefonesRaw
        );
        $telefones = array_filter($telefones, fn($t) => strlen($t) >= 9); // Apenas números consistentes (considerando apenas a parte local + ddd)
        
        // Se nenhum campo preenchido, não há o que buscar
        if ($cpfCnpj === '' && $email === '' && empty($telefones)) {
            return [];
        }

        $condicoes = [];
        $params = [];

        if ($cpfCnpj !== '') {
            $condicoes[] = $this->documentoNumericoExpr('cpf_cnpj') . ' = :cpf_cnpj';
            $params[':cpf_cnpj'] = $cpfCnpj;
        }

        if ($email !== '') {
            $condicoes[] = 'email = :email';
            $params[':email'] = $email;
        }

        if (!empty($telefones)) {
            $tConds = [];
            foreach (array_values($telefones) as $i => $tel) {
                // Pegar os últimos 9 digitos para comparação
                $tel9 = substr($tel, -9);
                $p1 = ":tel_{$i}_1";
                $p2 = ":tel_{$i}_2";
                $p3 = ":tel_{$i}_3";
                $p4 = ":tel_{$i}_4";
                $p5 = ":tel_{$i}_5";
                $params[$p1] = "%{$tel9}";
                $params[$p2] = "%{$tel9}";
                $params[$p3] = "%{$tel9}";
                $params[$p4] = "%{$tel9}";
                $params[$p5] = "%{$tel9}";
                $tConds[] = "(
                    REGEXP_REPLACE(telefone, '[^0-9]', '') LIKE {$p1} OR
                    REGEXP_REPLACE(telefone2, '[^0-9]', '') LIKE {$p2} OR
                    REGEXP_REPLACE(celular, '[^0-9]', '') LIKE {$p3} OR
                    REGEXP_REPLACE(fone, '[^0-9]', '') LIKE {$p4} OR
                    REGEXP_REPLACE(whatsapp, '[^0-9]', '') LIKE {$p5}
                )";
            }
            $condicoes[] = '(' . implode(' OR ', $tConds) . ')';
        }

        $whereCond = implode(' OR ', $condicoes);

        $sql = "
            SELECT id, nome, nome_fantasia, cpf_cnpj, email, telefone, celular, created_at,
            (SELECT COUNT(*) FROM ordem_servico WHERE cliente_id = clientes.id) as qtd_os
            FROM clientes
            WHERE ativo = 1 AND merged_into_id IS NULL
              AND ({$whereCond})
        ";

        if ($ignorarId !== null) {
            $sql .= " AND id != :ignorar_id";
            $params[':ignorar_id'] = $ignorarId;
        }

        $sql .= " ORDER BY qtd_os DESC, id DESC LIMIT 5";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Identificar motivos
        foreach ($resultados as &$r) {
            $motivos = [];
            $rCpf = preg_replace('/\D/', '', $r['cpf_cnpj'] ?? '');
            if ($cpfCnpj !== '' && $rCpf === $cpfCnpj) {
                $motivos[] = 'CPF/CNPJ igual';
            }
            if ($email !== '' && strtolower($r['email'] ?? '') === $email) {
                $motivos[] = 'E-mail igual';
            }
            
            $rTels = [
                preg_replace('/\D/', '', $r['telefone'] ?? ''),
                preg_replace('/\D/', '', $r['celular'] ?? ''),
            ];
            $telIgual = false;
            foreach ($telefones as $telInput) {
                $t9 = substr($telInput, -9);
                foreach ($rTels as $rt) {
                    if (strlen($rt) >= 9 && substr($rt, -9) === $t9) {
                        $telIgual = true;
                        break 2;
                    }
                }
            }
            if ($telIgual) {
                $motivos[] = 'Telefone igual';
            }
            
            $r['motivos'] = empty($motivos) ? ['Dados similares'] : $motivos;
            $r['qtd_os'] = (int) $r['qtd_os'];
        }

        return $resultados;
    }

    private function documentoNumericoExpr(string $field): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE({$field}, '.', ''), '-', ''), '/', ''), ' ', '')";
    }
}
