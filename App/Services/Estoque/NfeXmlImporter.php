<?php
declare(strict_types=1);

namespace App\Services\Estoque;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Importação de NF-e de compra em DUAS ETAPAS:
 *
 *   1) parseXml($xmlPath)
 *      Lê o XML em memória e retorna a estrutura completa (cabeçalho + itens
 *      com sugestões de match e preço sugerido pela regra dos 150% de markup).
 *      NÃO toca no banco para escrever — apenas consulta para sugerir matches.
 *
 *   2) confirmarImportacao($payload)
 *      Recebe os dados ajustados pelo usuário (vinculação manual de peças,
 *      margens corrigidas, etc.) e persiste TUDO em uma única transação:
 *      produtos (insert/update), estoque_movimentacoes, lancamentos_pagar e
 *      baixa em necessidades_compra com notificação para o técnico.
 *
 * Esse fluxo permite ao Administrativo conferir e ajustar valores antes do
 * salvamento definitivo (regra de negócio: nunca grava direto no banco).
 */
final class NfeXmlImporter
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';
    public  const MARKUP_PADRAO = 150.0;

    public function __construct(private readonly PDO $pdo) {}

    // ════════════════════════════════════════════════════════════════════
    //  PASSO 1 — Pré-visualização (read-only)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Lê o XML e devolve toda a estrutura para a tela de preview.
     * Não escreve nada no banco — apenas consulta para sugerir matches.
     *
     * @return array{
     *   chave_nfe:string, emitente:string, cnpj:string, cnpj_digitos:string,
     *   valor_total:float, data_emissao:string, ja_importada:bool,
     *   itens: array<int, array<string, mixed>>
     * }
     */
    public function parseXml(string $xmlPath): array
    {
        if (!is_readable($xmlPath)) {
            throw new RuntimeException("Arquivo XML inacessível: {$xmlPath}");
        }

        $dom = new DOMDocument();
        if (!@$dom->load($xmlPath)) {
            throw new RuntimeException('XML inválido ou malformado.');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('nfe', self::NS);

        $chave      = ltrim((string) $xp->evaluate('string(//nfe:infNFe/@Id)'), 'NFe');
        $cnpjEmit   = (string) $xp->evaluate('string(//nfe:emit/nfe:CNPJ)');
        $nomeEmit   = (string) $xp->evaluate('string(//nfe:emit/nfe:xNome)');
        $valorTotal = (float)  $xp->evaluate('string(//nfe:ICMSTot/nfe:vNF)');
        $dtEmissao  = substr((string) $xp->evaluate('string(//nfe:ide/nfe:dhEmi)'), 0, 10);

        if ($chave === '') {
            throw new RuntimeException('Chave de acesso não encontrada no XML.');
        }
        if ($dtEmissao === '') {
            $dtEmissao = date('Y-m-d');
        }

        $cnpjDigitos = preg_replace('/\D/', '', $cnpjEmit) ?? '';
        $jaImportada = $this->existeNotaImportada($chave);

        $itens = [];
        foreach ($xp->query('//nfe:det') as $det) {
            $codigo    = trim((string) $xp->evaluate('string(nfe:prod/nfe:cProd)',  $det));
            $descricao = trim((string) $xp->evaluate('string(nfe:prod/nfe:xProd)',  $det));
            $ncm       = trim((string) $xp->evaluate('string(nfe:prod/nfe:NCM)',    $det));
            $eanRaw    = trim((string) $xp->evaluate('string(nfe:prod/nfe:cEAN)',   $det));
            $qty       = (float)        $xp->evaluate('string(nfe:prod/nfe:qCom)',   $det);
            $vlrUnit   = (float)        $xp->evaluate('string(nfe:prod/nfe:vUnCom)', $det);
            $unidade   = trim((string) $xp->evaluate('string(nfe:prod/nfe:uCom)',   $det));

            $ean = ($eanRaw !== '' && strtoupper($eanRaw) !== 'SEM GTIN') ? $eanRaw : null;

            $itens[] = $this->montarItemPreview(
                $codigo, $descricao, $ncm, $ean, $qty, $vlrUnit,
                $unidade !== '' ? strtolower($unidade) : 'un',
            );
        }

        return [
            'chave_nfe'    => $chave,
            'emitente'     => $nomeEmit,
            'cnpj'         => $cnpjEmit,
            'cnpj_digitos' => $cnpjDigitos,
            'valor_total'  => $valorTotal,
            'data_emissao' => $dtEmissao,
            'ja_importada' => $jaImportada,
            'itens'        => $itens,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  PASSO 2 — Confirmação (escreve no banco em uma transação)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Persiste a importação com base nos dados ajustados pelo usuário.
     *
     * @param array $payload {
     *   chave_nfe:string, emitente:string, cnpj:string, valor_total:float,
     *   data_emissao:string,
     *   itens: array<int, {
     *     codigo:string, descricao:string, ncm:string, ean:?string,
     *     qty:float, vlr_unit:float, unidade:string,
     *     produto_id:?int,    // null/0 = criar novo produto
     *     margem:float,       // ajustada pelo Admin
     *     preco_venda:float,  // ajustado pelo Admin
     *   }>
     * }
     */
    public function confirmarImportacao(array $payload, ?int $usuarioId = null): array
    {
        $chave       = trim((string) ($payload['chave_nfe']    ?? ''));
        $cnpj        = trim((string) ($payload['cnpj']         ?? ''));
        $emitente    = trim((string) ($payload['emitente']     ?? ''));
        $valorTotal  = (float)        ($payload['valor_total'] ?? 0);
        $dtEmissao   = trim((string) ($payload['data_emissao'] ?? ''));
        $itens       = (array)        ($payload['itens']       ?? []);

        if ($chave === '' || strlen($chave) !== 44) {
            throw new InvalidArgumentException('Chave da NF-e inválida.');
        }
        if (empty($itens)) {
            throw new InvalidArgumentException('Nenhum item informado para importação.');
        }
        if ($dtEmissao === '' || strtotime($dtEmissao) === false) {
            $dtEmissao = date('Y-m-d');
        }

        $resultado = [
            'chave_nfe'          => $chave,
            'emitente'           => $emitente,
            'cnpj'               => $cnpj,
            'valor_total'        => $valorTotal,
            'inseridos'          => 0,
            'atualizados'        => 0,
            'itens'              => [],
            'produto_ids'        => [],
            'necessidades_match' => 0,
            'os_ids_notificadas' => [],
        ];

        $this->pdo->beginTransaction();
        try {
            if ($this->existeNotaImportada($chave)) {
                $this->pdo->rollBack();
                $resultado['aviso'] = 'NF-e já importada anteriormente.';
                return $resultado;
            }

            $cnpjDig      = preg_replace('/\D/', '', $cnpj) ?? '';
            $fornecedorId = $this->upsertFornecedor($cnpjDig, $emitente);

            foreach ($itens as $item) {
                $this->aplicarItem($item, $chave, $resultado, $usuarioId);
            }

            $vencimento = date('Y-m-d', strtotime($dtEmissao . ' +30 days'));
            $this->pdo->prepare(
                "INSERT INTO lancamentos_pagar
                   (fornecedor_id, chave_nfe, valor, vencimento, status, descricao, criado_em)
                 VALUES (?, ?, ?, ?, 'aberto', ?, NOW())"
            )->execute([
                $fornecedorId,
                $chave,
                $valorTotal,
                $vencimento,
                "NF-e {$chave} — {$emitente}",
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        // Após commit: tenta casar com necessidades_compra pendentes e notificar técnicos.
        $this->matchNecessidadesCompra($resultado);

        return $resultado;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Helpers — passo 1 (preview)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Tenta casar o item do XML com um produto existente (por código, depois por EAN)
     * e devolve a linha pronta para a tela de preview, com margem/preço sugeridos.
     */
    private function montarItemPreview(
        string  $codigo,
        string  $descricao,
        string  $ncm,
        ?string $ean,
        float   $qty,
        float   $vlrUnit,
        string  $unidade,
    ): array {
        $sugestao    = null;
        $matchTipo   = null;
        $margemAtual = null;
        $estoqueAtual = null;

        if ($codigo !== '') {
            $sugestao = $this->buscarProdutoPorCodigo($codigo);
            if ($sugestao !== null) $matchTipo = 'codigo';
        }
        if ($sugestao === null && $ean !== null) {
            $sugestao = $this->buscarProdutoPorEan($ean);
            if ($sugestao !== null) $matchTipo = 'ean';
        }

        if ($sugestao !== null) {
            $margemAtual  = (float) $sugestao['margem_lucro'];
            $estoqueAtual = (float) $sugestao['estoque_qty'];
        }

        // Margem sugerida: preserva a margem manual se já cadastrada > 0; senão usa 150%.
        $margemSugerida = ($margemAtual !== null && $margemAtual > 0)
            ? $margemAtual
            : self::MARKUP_PADRAO;

        $precoVendaSugerido = round($vlrUnit * (1 + $margemSugerida / 100), 2);

        // Há necessidades pendentes para esse produto?
        $necessidadesPendentes = 0;
        if ($sugestao !== null) {
            $necessidadesPendentes = $this->contarNecessidadesPendentes((int) $sugestao['id']);
        }

        return [
            'codigo'                => $codigo,
            'descricao'             => $descricao,
            'ncm'                   => $ncm,
            'ean'                   => $ean,
            'qty'                   => $qty,
            'vlr_unit'              => $vlrUnit,
            'unidade'               => $unidade,
            'sugestao_produto_id'   => $sugestao !== null ? (int) $sugestao['id'] : null,
            'sugestao_descricao'    => $sugestao['descricao'] ?? null,
            'sugestao_codigo'       => $sugestao['codigo']    ?? null,
            'sugestao_match'        => $matchTipo,
            'estoque_atual'         => $estoqueAtual,
            'margem_atual'          => $margemAtual,
            'margem_sugerida'       => $margemSugerida,
            'preco_venda_sugerido'  => $precoVendaSugerido,
            'necessidades_pendentes' => $necessidadesPendentes,
        ];
    }

    private function existeNotaImportada(string $chave): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM lancamentos_pagar WHERE chave_nfe = ? LIMIT 1'
        );
        $st->execute([$chave]);
        return $st->fetchColumn() !== false;
    }

    private function buscarProdutoPorCodigo(string $codigo): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, codigo, descricao, margem_lucro, estoque_qty
               FROM produtos WHERE codigo = ? LIMIT 1'
        );
        $st->execute([$codigo]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function buscarProdutoPorEan(string $ean): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, codigo, descricao, margem_lucro, estoque_qty
               FROM produtos WHERE ean = ? AND ean <> "" LIMIT 1'
        );
        $st->execute([$ean]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function contarNecessidadesPendentes(int $produtoId): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM necessidades_compra
              WHERE produto_id = ? AND status = 'pendente'"
        );
        $st->execute([$produtoId]);
        return (int) $st->fetchColumn();
    }

    // ════════════════════════════════════════════════════════════════════
    //  Helpers — passo 2 (confirmação)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Aplica um item da NF-e ao banco — usa o produto_id informado pelo usuário
     * (vinculação manual) ou cria um novo se for null/0.
     */
    private function aplicarItem(array $item, string $chave, array &$resultado, ?int $usuarioId): void
    {
        $codigo    = trim((string) ($item['codigo']    ?? ''));
        $descricao = trim((string) ($item['descricao'] ?? ''));
        $ncm       = trim((string) ($item['ncm']       ?? ''));
        $ean       = trim((string) ($item['ean']       ?? ''));
        $qty       = (float)        ($item['qty']      ?? 0);
        $vlrUnit   = (float)        ($item['vlr_unit'] ?? 0);
        $unidade   = trim((string) ($item['unidade']  ?? 'un')) ?: 'un';

        if ($qty <= 0)     throw new InvalidArgumentException("Quantidade inválida no item '{$descricao}'.");
        if ($vlrUnit < 0)  throw new InvalidArgumentException("Valor unitário inválido no item '{$descricao}'.");

        $produtoId = (int) ($item['produto_id'] ?? 0);
        $margem    = (float) ($item['margem']      ?? self::MARKUP_PADRAO);
        $preco     = (float) ($item['preco_venda'] ?? round($vlrUnit * (1 + $margem / 100), 2));

        if ($margem < 0)  $margem = 0.0;
        if ($preco  < 0)  $preco  = 0.0;

        if ($produtoId > 0) {
            // Atualiza produto existente (vinculação manual ou match automático aceito).
            $this->pdo->prepare(
                'UPDATE produtos
                    SET estoque_qty           = estoque_qty + ?,
                        preco_custo           = ?,
                        margem_lucro          = ?,
                        valor_venda_calculado = ?,
                        valor                 = ?,
                        ncm                   = COALESCE(NULLIF(?, ""), ncm),
                        sob_encomenda         = 0
                  WHERE id = ?
                  LIMIT 1'
            )->execute([$qty, $vlrUnit, $margem, $preco, $preco, $ncm, $produtoId]);

            $resultado['atualizados']++;
        } else {
            // Cria novo produto a partir dos dados do XML.
            $this->pdo->prepare(
                'INSERT INTO produtos
                  (codigo, descricao, ncm, ean, estoque_qty, preco_custo,
                   margem_lucro, valor_venda_calculado, valor, unidade, ativo, sob_encomenda)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)'
            )->execute([
                $codigo,
                $descricao,
                $ncm,
                $ean,
                $qty,
                $vlrUnit,
                $margem,
                $preco,
                $preco,
                $unidade,
            ]);
            $produtoId = (int) $this->pdo->lastInsertId();
            $resultado['inseridos']++;
        }

        $this->registrarMovimentacaoEntrada($produtoId, $qty, $chave, $usuarioId);

        $resultado['produto_ids'][] = $produtoId;
        $resultado['itens'][] = [
            'produto_id' => $produtoId,
            'codigo'     => $codigo,
            'descricao'  => $descricao,
            'qty'        => $qty,
            'vlr_unit'   => $vlrUnit,
            'margem'     => $margem,
            'preco'      => $preco,
        ];
    }

    private function upsertFornecedor(string $cnpj, string $nome): int
    {
        $st = $this->pdo->prepare('SELECT id FROM fornecedores WHERE cnpj = ? LIMIT 1');
        $st->execute([$cnpj]);
        $id = $st->fetchColumn();
        if ($id !== false) return (int) $id;

        $this->pdo->prepare(
            'INSERT INTO fornecedores (cnpj, nome, criado_em) VALUES (?, ?, NOW())'
        )->execute([$cnpj, $nome]);
        return (int) $this->pdo->lastInsertId();
    }

    private function registrarMovimentacaoEntrada(
        int     $produtoId,
        float   $qty,
        string  $chaveNfe,
        ?int    $usuarioId,
    ): void {
        $st = $this->pdo->prepare('SELECT estoque_qty FROM produtos WHERE id = ? LIMIT 1');
        $st->execute([$produtoId]);
        $saldoPos = (float) $st->fetchColumn();
        $saldoAnt = $saldoPos - $qty;

        $this->pdo->prepare(
            "INSERT INTO estoque_movimentacoes
               (produto_id, tipo, qtd, saldo_ant, saldo_pos, descricao, usuario_id, criado_em)
             VALUES (?, 'entrada', ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $produtoId,
            $qty,
            $saldoAnt,
            $saldoPos,
            "Entrada por NF-e {$chaveNfe}",
            $usuarioId,
        ]);
    }

    /**
     * Marca pendências de necessidades_compra como 'comprado' e dispara
     * notificação para o técnico das OS afetadas. Falhas aqui não derrubam
     * a importação que já foi commitada.
     */
    private function matchNecessidadesCompra(array &$resultado): void
    {
        $produtoIds = array_unique($resultado['produto_ids'] ?? []);
        if (empty($produtoIds)) return;

        $chave = (string) ($resultado['chave_nfe'] ?? '');

        try {
            foreach ($produtoIds as $produtoId) {
                $up = $this->pdo->prepare(
                    "UPDATE necessidades_compra
                        SET status = 'comprado', chave_nfe = ?
                      WHERE produto_id = ? AND status = 'pendente'"
                );
                $up->execute([$chave, (int) $produtoId]);
                $matched = $up->rowCount();
                if ($matched <= 0) continue;

                $resultado['necessidades_match'] += $matched;

                $sel = $this->pdo->prepare(
                    "SELECT DISTINCT os_id, equip_idx
                       FROM necessidades_compra
                      WHERE produto_id = ? AND chave_nfe = ?"
                );
                $sel->execute([(int) $produtoId, $chave]);

                foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $alvo) {
                    $osId  = (string) $alvo['os_id'];
                    $equip = (int)    $alvo['equip_idx'];
                    $msg   = "Peça encomendada chegou (NF-e {$chave}). OS {$osId} pode prosseguir.";
                    $this->pdo->prepare(
                        "INSERT INTO notificacoes_tecnico
                           (os_id, equip_idx, tipo, mensagem, lida, created_at)
                         VALUES (?, ?, 'info', ?, 0, NOW())"
                    )->execute([$osId, $equip, $msg]);
                    $resultado['os_ids_notificadas'][] = $osId;
                }
            }
        } catch (Throwable $e) {
            error_log('[NfeXmlImporter] match necessidades_compra falhou: ' . $e->getMessage());
        }
    }
}
