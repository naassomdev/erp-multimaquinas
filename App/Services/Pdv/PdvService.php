<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ClienteRepository;
use App\Repositories\PdvRepository;
use App\Repositories\ProdutoRepository;
use App\Repositories\VendaDocumentoRepository;
use App\Repositories\VendaPagamentoRepository;
use InvalidArgumentException;
use OutOfRangeException;
use PDO;
use RuntimeException;
use Throwable;

final class PdvService
{
    public function __construct(
        private readonly PdvSettingsService $settings = new PdvSettingsService(),
        private readonly PdvRepository $vendas = new PdvRepository(),
        private readonly ClienteRepository $clientes = new ClienteRepository(),
        private readonly ProdutoRepository $produtos = new ProdutoRepository(),
        private readonly VendaPagamentoRepository $pagamentos = new VendaPagamentoRepository(),
        private readonly VendaDocumentoRepository $documentos = new VendaDocumentoRepository(),
        private readonly PdvAuditService $audit = new PdvAuditService(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'enabled' => $this->settings->enabled(),
            'mode' => $this->settings->mode(),
            'fiscal_enabled' => $this->settings->fiscalEnabled(),
            'recibo_enabled' => $this->settings->reciboEnabled(),
            'write_enabled' => $this->settings->writeEnabled(),
            'write_admin_only' => $this->settings->writeAdminOnly(),
            'controllers_must_use_service' => true,
            'finalizacao_disponivel' => $this->finalizacaoDisponivel(),
        ];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array<string, mixed>
     */
    public function listarVendas(array $filtros, int $page = 1, int $limit = 20): array
    {
        $this->assertEnabled();
        $this->assertAdminCanListarVendas();

        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;
        $filtrosNormalizados = $this->normalizarFiltrosListagem($filtros);

        $total = $this->vendas->contarVendas($filtrosNormalizados);
        $rows = $this->vendas->listarVendas($filtrosNormalizados, $limit, $offset);
        $operadores = $this->vendas->listarOperadoresComVendas();

        return [
            'filtros' => $filtrosNormalizados,
            'paginacao' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
            'operadores' => $operadores,
            'vendas' => array_map(function (array $row): array {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'numero' => (string)($row['numero'] ?? ''),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'status_venda' => (string)($row['status_venda'] ?? ''),
                    'status_fiscal' => (string)($row['status_fiscal'] ?? ''),
                    'forma_pagamento' => (string)($row['forma_pagamento'] ?? ''),
                    'total_liquido' => (float)($row['total_liquido'] ?? 0),
                    'observacoes' => (string)($row['observacoes'] ?? ''),
                    'cliente_id' => isset($row['cliente_id']) ? (int)$row['cliente_id'] : null,
                    'cliente_nome' => (string)($row['cliente_nome'] ?? ''),
                    'operador_id' => (int)($row['operador_id'] ?? $row['created_by'] ?? 0),
                    'operador_nome' => (string)($row['operador_nome'] ?? ''),
                    'lancamento_receber_id' => isset($row['lancamento_receber_id']) ? (int)$row['lancamento_receber_id'] : null,
                    'financeiro_status' => (string)($row['financeiro_status'] ?? ''),
                    'itens_count' => (int)($row['itens_count'] ?? 0),
                    'tem_estoque' => (int)($row['itens_com_estoque'] ?? 0) > 0,
                    'documentos_fiscais_count' => (int)($row['documentos_fiscais_count'] ?? 0),
                    'fiscal_tipo_documento' => (string)($row['fiscal_tipo_documento'] ?? ''),
                    'fiscal_modelo' => (string)($row['fiscal_modelo'] ?? ''),
                    'fiscal_numero' => (string)($row['fiscal_numero'] ?? ''),
                    'fiscal_serie' => (string)($row['fiscal_serie'] ?? ''),
                ];
            }, $rows),
        ];
    }

    /**
     * @return array<int, array{id:int,nome:string}>
     */
    public function listarOperadoresDaListagem(): array
    {
        $this->assertEnabled();
        $this->assertAdminCanListarVendas();
        return $this->vendas->listarOperadoresComVendas();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buscarClientes(string $termo, int $limit = 10): array
    {
        $this->assertEnabled();
        $termo = trim($termo);
        if (mb_strlen($termo) < 2) {
            return [];
        }

        $rows = $this->clientes->buscarAutocomplete($termo, max(1, min(20, $limit)));

        return array_map(function (array $cliente): array {
            return [
                'id' => (int)($cliente['id'] ?? 0),
                'nome' => (string)($cliente['nome'] ?? ''),
                'documento' => $this->maskDocument((string)($cliente['cpf_cnpj'] ?? '')),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buscarProdutos(string $termo, int $limit = 10): array
    {
        $this->assertEnabled();
        $termo = trim($termo);
        if (mb_strlen($termo) < 2) {
            return [];
        }

        $rows = $this->produtos->buscarPorTermo($termo, max(1, min(20, $limit)));

        return array_map(static function (array $produto): array {
            $valorVenda = (float)($produto['valor_venda_calculado'] ?? 0);
            if ($valorVenda <= 0) {
                $valorVenda = (float)($produto['valor'] ?? 0);
            }

            return [
                'id' => (int)($produto['id'] ?? 0),
                'codigo' => (string)($produto['codigo'] ?? ''),
                'descricao' => (string)($produto['descricao'] ?? ''),
                'marca' => (string)($produto['marca'] ?? ''),
                'valor_venda' => round($valorVenda, 2),
                'estoque_qty' => (float)($produto['estoque_qty'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criarVendaRascunho(array $dados): array
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();

        try {
            $dados['status_venda'] = $dados['status_venda'] ?? PdvSaleStatus::RASCUNHO;
            $dados['status_fiscal'] = $dados['status_fiscal'] ?? PdvFiscalStatus::NAO_APLICAVEL;
            $dados['created_by'] = isset($dados['created_by']) ? (int)$dados['created_by'] : $actorId;
            $dados['updated_by'] = isset($dados['updated_by']) ? (int)$dados['updated_by'] : $actorId;
            $dados['operador_id'] = isset($dados['operador_id']) ? (int)$dados['operador_id'] : $actorId;

            return $this->transaction(function () use ($dados): array {
                $vendaId = $this->vendas->criarVendaRascunho($dados);
                $venda = $this->requireVenda($vendaId);

                $this->audit->registrarVendaCriada($vendaId, [
                    'venda_id' => $vendaId,
                    'numero' => $venda['numero'] ?? null,
                    'cliente_id' => $venda['cliente_id'] ?? null,
                    'os_id' => $venda['os_id'] ?? null,
                    'origem_tipo' => $venda['origem_tipo'] ?? null,
                    'status_venda' => $venda['status_venda'] ?? null,
                    'nome_cliente' => $venda['nome_cliente'] ?? null,
                    'telefone' => $venda['telefone'] ?? null,
                    'email' => $venda['email'] ?? null,
                ]);

                return $venda;
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro(null, $e, ['acao' => 'criar_venda_rascunho']);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    public function adicionarItem(int $vendaId, array $item): array
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();
        $venda = $this->requireVenda($vendaId);
        $this->assertEditable($venda);

        try {
            $item['updated_by'] = isset($item['updated_by']) ? (int)$item['updated_by'] : $actorId;
            $totaisAntes = $this->vendas->calcularTotaisDaVenda($vendaId);
            $ajustesGerais = $this->inferirAjustesGerais($venda, $totaisAntes);

            return $this->transaction(function () use ($vendaId, $item, $ajustesGerais): array {
                $itemId = $this->vendas->inserirItem($vendaId, $item);
                $totais = $this->recalcularTotais(
                    $vendaId,
                    isset($item['updated_by']) ? (int)$item['updated_by'] : null,
                    $ajustesGerais
                );

                $this->audit->registrarItemAdicionado($itemId, [
                    'venda_id' => $vendaId,
                    'item_id' => $itemId,
                    'produto_id' => $item['produto_id'] ?? null,
                    'tecnico_item_id' => $item['tecnico_item_id'] ?? null,
                    'orcamento_item_id' => $item['orcamento_item_id'] ?? null,
                    'codigo' => $item['codigo'] ?? null,
                    'descricao' => $item['descricao'] ?? null,
                    'quantidade' => $item['quantidade'] ?? ($item['qtd'] ?? null),
                    'total_liquido' => $totais['total_liquido'],
                ]);

                return $this->requireVenda($vendaId);
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'adicionar_item']);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function removerItemPersistido(int $vendaId, int $itemId): array
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();

        try {
            return $this->transaction(function () use ($vendaId, $itemId, $actorId): array {
                $venda = $this->vendas->buscarVendaPorIdForUpdate($vendaId);
                if ($venda === null) {
                    throw new RuntimeException("Venda PDV não encontrada: {$vendaId}");
                }
                $this->assertEditable($venda);

                $item = $this->vendas->buscarItemDaVendaPorIdForUpdate($vendaId, $itemId);
                if ($item === null) {
                    throw new RuntimeException("Item {$itemId} não encontrado para a venda {$vendaId}.");
                }

                if (!empty($item['estoque_movimentacao_id'])) {
                    throw new RuntimeException('Item com movimentação de estoque vinculada não pode ser removido.');
                }

                $pagamentos = $this->pagamentos->listarPorVendaForUpdate($vendaId);
                foreach ($pagamentos as $pagamento) {
                    if ((string)($pagamento['status'] ?? '') !== 'cancelado') {
                        throw new RuntimeException('Remoção de item bloqueada: a venda possui pagamento ativo.');
                    }
                }

                $this->vendas->removerItemDaVenda($vendaId, $itemId);

                $totaisItens = $this->vendas->calcularTotaisDaVenda($vendaId);
                $ajustesGerais = $this->ajustesGeraisCompativeisComTotais($venda, $totaisItens);
                $totais = $this->recalcularTotais($vendaId, $actorId, $ajustesGerais);

                $resumoPagamento = $this->pagamentos->resumirStatusPorVenda($vendaId);
                $novoStatus = $this->inferirStatusPagamento(
                    $resumoPagamento,
                    (float)$totais['total_liquido'],
                    (string)($venda['status_venda'] ?? $venda['status'] ?? '')
                );
                $this->vendas->alterarStatusVenda($vendaId, $novoStatus, $actorId);

                $this->audit->registrarItemRemovido($itemId, [
                    'venda_id' => $vendaId,
                    'item_id' => $itemId,
                    'produto_id' => $item['produto_id'] ?? null,
                    'codigo' => $item['codigo'] ?? null,
                    'descricao' => $item['descricao'] ?? null,
                    'quantidade' => $item['quantidade'] ?? null,
                    'total_bruto' => $totais['total_bruto'],
                    'total_desconto' => $totais['total_desconto'],
                    'total_acrescimo' => $totais['total_acrescimo'],
                    'total_liquido' => $totais['total_liquido'],
                    'status_venda' => $novoStatus,
                    'updated_by' => $actorId,
                ]);

                return [
                    'item_id' => $itemId,
                    'status_venda' => $novoStatus,
                    'totais' => $totais,
                    'venda' => $this->requireVenda($vendaId),
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, [
                'acao' => 'remover_item_persistido',
                'item_id' => $itemId,
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    public function atualizarTotaisRascunho(int $vendaId, array $dados): array
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();
        $venda = $this->requireVenda($vendaId);
        $this->assertEditable($venda);

        if ($this->pagamentos->contarPorVenda($vendaId) > 0) {
            throw new RuntimeException('Atualização de totais bloqueada para venda que já possui pagamentos.');
        }
        if ($this->documentos->contarPorVenda($vendaId) > 0) {
            throw new RuntimeException('Atualização de totais bloqueada para venda que já possui documentos.');
        }

        $descontoGeral = $this->normalizeMoney($dados['desconto_geral'] ?? $dados['total_desconto_geral'] ?? 0);
        $acrescimoGeral = $this->normalizeMoney($dados['acrescimo_geral'] ?? $dados['total_acrescimo_geral'] ?? 0);

        if ($descontoGeral < 0.0) {
            throw new InvalidArgumentException('desconto_geral deve ser maior ou igual a zero.');
        }
        if ($acrescimoGeral < 0.0) {
            throw new InvalidArgumentException('acrescimo_geral deve ser maior ou igual a zero.');
        }
        if ($acrescimoGeral > 0.0) {
            throw new InvalidArgumentException('acrescimo_geral ainda não é suportado nesta etapa.');
        }

        $totaisItens = $this->vendas->calcularTotaisDaVenda($vendaId);
        $baseDisponivel = round((float)$totaisItens['total_liquido'] + $acrescimoGeral, 2);
        if ($descontoGeral > $baseDisponivel + 0.0001) {
            throw new OutOfRangeException('desconto_geral maior que o saldo disponível da venda.');
        }

        $ajustesGerais = [
            'desconto_geral' => $descontoGeral,
            'acrescimo_geral' => $acrescimoGeral,
        ];

        try {
            return $this->transaction(function () use ($vendaId, $actorId, $ajustesGerais): array {
                $totais = $this->recalcularTotais($vendaId, $actorId, $ajustesGerais);
                $vendaAtualizada = $this->requireVenda($vendaId);

                $this->audit->registrarTotaisAtualizados($vendaId, [
                    'venda_id' => $vendaId,
                    'desconto_geral' => $ajustesGerais['desconto_geral'],
                    'acrescimo_geral' => $ajustesGerais['acrescimo_geral'],
                    'total_bruto' => $totais['total_bruto'],
                    'total_desconto' => $totais['total_desconto'],
                    'total_acrescimo' => $totais['total_acrescimo'],
                    'total_liquido' => $totais['total_liquido'],
                    'updated_by' => $actorId,
                ]);

                return [
                    'venda' => $vendaAtualizada,
                    'totais' => $totais,
                    'desconto_geral' => $ajustesGerais['desconto_geral'],
                    'acrescimo_geral' => $ajustesGerais['acrescimo_geral'],
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'atualizar_totais_rascunho']);
            throw $e;
        }
    }

    /**
     * @return array{pagamento_id:int,total_pago:float,status_venda:string}
     * @param array<string, mixed> $dados
     */
    public function registrarPagamentoRascunho(int $vendaId, array $dados): array
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();
        $venda = $this->requireVenda($vendaId);
        $this->assertEditable($venda);

        $forma = trim((string)($dados['forma_pagamento'] ?? ''));
        PdvPaymentType::assertValid($forma);

        try {
            $statusPagamento = trim((string)($dados['status'] ?? 'pendente'));
            $dados['forma_pagamento'] = $forma;
            $dados['status'] = $statusPagamento;
            $dados['created_by'] = isset($dados['created_by']) ? (int)$dados['created_by'] : $actorId;
            $dados['updated_by'] = isset($dados['updated_by']) ? (int)$dados['updated_by'] : $actorId;
            if ($statusPagamento === 'pago') {
                $dbNowFields = is_array($dados['db_now_fields'] ?? null) ? $dados['db_now_fields'] : [];
                $dbNowFields[] = 'pago_em';
                $dados['db_now_fields'] = array_values(array_unique($dbNowFields));
            }

            return $this->transaction(function () use ($vendaId, $dados, $forma, $venda): array {
                $pagamentoId = $this->pagamentos->inserir($vendaId, $dados);
                $resumoPagamento = $this->pagamentos->resumirStatusPorVenda($vendaId);
                $totalPago = (float)($resumoPagamento['total_pago_confirmado'] ?? 0);

                $totalVenda = (float)($venda['total_liquido'] ?? $venda['total'] ?? 0);
                $novoStatus = $this->inferirStatusPagamento(
                    $resumoPagamento,
                    $totalVenda,
                    (string)($venda['status_venda'] ?? $venda['status'] ?? '')
                );
                $this->vendas->alterarStatusVenda($vendaId, $novoStatus, isset($dados['updated_by']) ? (int)$dados['updated_by'] : null);

                $this->audit->registrarPagamento($pagamentoId, [
                    'venda_id' => $vendaId,
                    'pagamento_id' => $pagamentoId,
                    'forma_pagamento' => $forma,
                    'valor' => $dados['valor'] ?? 0,
                    'status_pagamento' => $dados['status'] ?? 'pendente',
                    'status_venda' => $novoStatus,
                ]);

                return [
                    'pagamento_id' => $pagamentoId,
                    'total_pago' => $totalPago,
                    'status_venda' => $novoStatus,
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'registrar_pagamento']);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function prepararDocumento(int $vendaId, string $tipoDocumento, array $dados = []): int
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();
        $this->requireVenda($vendaId);
        PdvDocumentType::assertValid($tipoDocumento);
        $this->assertDocumentAllowed($tipoDocumento);

        try {
            $dados['created_by'] = isset($dados['created_by']) ? (int)$dados['created_by'] : $actorId;
            $dados['updated_by'] = isset($dados['updated_by']) ? (int)$dados['updated_by'] : $actorId;

            return $this->transaction(function () use ($vendaId, $tipoDocumento, $dados): int {
                $dados['venda_id'] = $vendaId;
                $dados['tipo_documento'] = $tipoDocumento;
                $dados['categoria'] = $dados['categoria'] ?? PdvDocumentType::categoria($tipoDocumento);
                $documentoId = $tipoDocumento === PdvDocumentType::RECIBO_NAO_FISCAL
                    ? $this->documentos->registrarReciboNaoFiscal($vendaId, $dados)
                    : $this->documentos->criar($dados);

                $this->audit->registrarDocumento($documentoId, [
                    'venda_id' => $vendaId,
                    'documento_id' => $documentoId,
                    'categoria' => $dados['categoria'],
                    'tipo_documento' => $tipoDocumento,
                    'status' => $dados['status'] ?? null,
                ]);

                return $documentoId;
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'preparar_documento', 'tipo_documento' => $tipoDocumento]);
            throw $e;
        }
    }

    /**
     * Registra documento fiscal emitido externamente. Esta rotina apenas cria o vínculo manual;
     * não transmite para SEFAZ, não emite cupom e não gera XML/PDF.
     *
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    public function registrarDocumentoFiscalManual(int $vendaId, array $dados): array
    {
        $this->assertWriteAllowed();
        $this->assertAdminCanVincularDocumentoFiscal();
        $actorId = $this->currentActorId();

        try {
            return $this->transaction(function () use ($vendaId, $dados, $actorId): array {
                $venda = $this->vendas->buscarVendaPorIdForUpdate($vendaId);
                if ($venda === null) {
                    throw new RuntimeException("Venda PDV não encontrada: {$vendaId}");
                }

                if ((string)($venda['status_venda'] ?? '') !== PdvSaleStatus::FINALIZADO) {
                    throw new RuntimeException('Documento fiscal manual só pode ser vinculado a venda finalizada.');
                }

                $tipoDocumento = $this->normalizarTipoDocumentoFiscalManual($dados['tipo_documento'] ?? '');
                $modelo = $this->normalizarModeloFiscalManual($tipoDocumento, $dados['modelo'] ?? '');
                $numero = $this->normalizarCampoFiscalObrigatorio($dados['numero'] ?? null, 'numero', $tipoDocumento);
                $serie = $this->normalizarCampoFiscalObrigatorio($dados['serie'] ?? null, 'serie', $tipoDocumento);
                $chaveAcesso = $this->normalizarChaveAcessoFiscalManual($tipoDocumento, $dados['chave_acesso'] ?? null);
                $valor = $this->normalizarValorDocumentoFiscal($dados['valor'] ?? null, (float)($venda['total_liquido'] ?? 0));
                $confirmouDivergencia = $this->truthy($dados['confirmar_valor_divergente'] ?? false);
                $this->assertValorDocumentoCompativel($valor, (float)($venda['total_liquido'] ?? 0), $confirmouDivergencia);

                if ($chaveAcesso !== null) {
                    $existente = $this->documentos->buscarFiscalPorChave($chaveAcesso);
                    if ($existente !== null) {
                        throw new RuntimeException('Já existe documento fiscal registrado com esta chave de acesso.');
                    }
                }

                $payloadJson = [
                    'origem' => 'pdv_documento_fiscal_manual',
                    'emitido_externamente' => true,
                    'pdv_fiscal_enabled' => $this->settings->fiscalEnabled(),
                    'valor_divergente_confirmado' => $confirmouDivergencia,
                    'dados_informados' => [
                        'tipo_documento' => $tipoDocumento,
                        'modelo' => $modelo,
                        'numero' => $numero,
                        'serie' => $serie,
                        'valor' => $valor,
                    ],
                ];

                $documentoId = $this->documentos->criar([
                    'venda_id' => $vendaId,
                    'categoria' => PdvDocumentType::CATEGORIA_FISCAL,
                    'tipo_documento' => $tipoDocumento,
                    'modelo' => $modelo,
                    'status' => 'registrado',
                    'numero' => $numero,
                    'serie' => $serie,
                    'chave_acesso' => $chaveAcesso,
                    'protocolo' => $this->nullableString($dados['protocolo'] ?? null),
                    'valor' => $valor,
                    'link_consulta' => $this->nullableString($dados['link_consulta'] ?? null),
                    'emitido_externamente' => 1,
                    'observacoes' => $this->nullableString($dados['observacoes'] ?? null),
                    'data_emissao' => $this->normalizarDataEmissaoFiscal($dados['data_emissao'] ?? null),
                    'payload_json' => $payloadJson,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $legacyChave = in_array($tipoDocumento, [PdvDocumentType::NFE, PdvDocumentType::NFCE], true)
                    ? $chaveAcesso
                    : null;
                $legacyNumero = in_array($tipoDocumento, [PdvDocumentType::NFE, PdvDocumentType::NFCE], true)
                    ? $numero
                    : null;
                $legacySerie = in_array($tipoDocumento, [PdvDocumentType::NFE, PdvDocumentType::NFCE], true)
                    ? substr((string)$serie, 0, 3)
                    : null;

                $this->vendas->atualizarFiscalManualVenda(
                    $vendaId,
                    PdvFiscalStatus::REGISTRADO_MANUAL,
                    PdvFiscalStatus::REGISTRADO_MANUAL,
                    $legacyChave,
                    $legacyNumero,
                    $legacySerie,
                    $actorId
                );

                $this->audit->registrarDocumentoFiscalVinculado($documentoId, [
                    'venda_id' => $vendaId,
                    'documento_id' => $documentoId,
                    'tipo_documento' => $tipoDocumento,
                    'modelo' => $modelo,
                    'numero' => $numero,
                    'serie' => $serie,
                    'chave_acesso' => $chaveAcesso,
                    'valor' => $valor,
                    'emitido_externamente' => true,
                    'updated_by' => $actorId,
                ]);

                return [
                    'documento' => $this->documentos->buscarPorId($documentoId),
                    'venda' => $this->requireVenda($vendaId),
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'documento_fiscal_manual']);
            throw $e;
        }
    }

    /**
     * Cancela somente o vínculo manual de documento fiscal emitido externamente.
     * Não transmite para SEFAZ e não altera financeiro, estoque ou pagamento.
     *
     * @return array<string, mixed>
     */
    public function cancelarVinculoDocumentoFiscalManual(int $vendaId, int $documentoId, string $motivo): array
    {
        $this->assertWriteAllowed();
        $this->assertAdminCanVincularDocumentoFiscal();
        $actorId = $this->currentActorId();
        $motivoLimpo = trim($motivo);
        if ($motivoLimpo === '') {
            throw new InvalidArgumentException('motivo obrigatório para cancelar vínculo fiscal manual.');
        }

        try {
            return $this->transaction(function () use ($vendaId, $documentoId, $motivoLimpo, $actorId): array {
                $venda = $this->vendas->buscarVendaPorIdForUpdate($vendaId);
                if ($venda === null) {
                    throw new RuntimeException("Venda PDV não encontrada: {$vendaId}");
                }

                $documento = $this->documentos->buscarFiscalPorVendaDocumento($vendaId, $documentoId);
                if ($documento === null) {
                    throw new RuntimeException('Documento fiscal vinculado à venda não encontrado.');
                }

                if ((int)($documento['emitido_externamente'] ?? 0) !== 1) {
                    throw new RuntimeException('Apenas vínculo fiscal emitido externamente pode ser cancelado por esta ação manual.');
                }

                $statusAtual = (string)($documento['status'] ?? '');
                if (!$this->documentoFiscalAtivo($documento)) {
                    throw new RuntimeException('Documento fiscal manual já está cancelado, inativo ou removido.');
                }

                $observacoes = trim((string)($documento['observacoes'] ?? ''));
                $observacoesCancelamento = trim($observacoes . "\nCancelamento do vínculo manual: " . $motivoLimpo);

                $this->documentos->alterarStatus($documentoId, 'cancelado', [
                    'observacoes' => $observacoesCancelamento,
                    'data_cancelamento' => date('Y-m-d H:i:s'),
                    'updated_by' => $actorId,
                    'payload_json' => [
                        'origem' => 'pdv_documento_fiscal_cancelar_vinculo_manual',
                        'motivo' => $motivoLimpo,
                        'status_anterior' => $statusAtual,
                        'emitido_externamente' => true,
                    ],
                ]);

                if ($this->documentos->contarFiscaisAtivosPorVenda($vendaId) === 0) {
                    $this->vendas->limparFiscalManualVenda($vendaId, $actorId);
                }

                $this->audit->registrarDocumentoFiscalVinculoCancelado($documentoId, [
                    'venda_id' => $vendaId,
                    'documento_id' => $documentoId,
                    'tipo_documento' => (string)($documento['tipo_documento'] ?? ''),
                    'modelo' => (string)($documento['modelo'] ?? ''),
                    'status_anterior' => $statusAtual,
                    'status' => 'cancelado',
                    'motivo' => $motivoLimpo,
                    'updated_by' => $actorId,
                ]);

                return [
                    'documento' => $this->documentos->buscarPorId($documentoId),
                    'venda' => $this->requireVenda($vendaId),
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, [
                'acao' => 'cancelar_vinculo_documento_fiscal_manual',
                'documento_id' => $documentoId,
            ]);
            throw $e;
        }
    }

    /**
     * @return array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float}
     */
    public function recalcularTotais(int $vendaId, ?int $updatedBy = null, ?array $ajustesGerais = null): array
    {
        $this->assertWriteAllowed();
        $totaisItens = $this->vendas->calcularTotaisDaVenda($vendaId);
        if ($ajustesGerais === null) {
            $venda = $this->requireVenda($vendaId);
            $ajustesGerais = $this->inferirAjustesGerais($venda, $totaisItens);
        }

        $totais = $this->combinarTotais($totaisItens, $ajustesGerais);
        $this->vendas->atualizarTotais($vendaId, $totais, $updatedBy);
        return $totais;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarItens(int $vendaId): array
    {
        $this->assertEnabled();
        $this->requireVenda($vendaId);
        return $this->vendas->listarItens($vendaId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarRascunhos(int $limit = 20): array
    {
        $this->assertEnabled();
        $rows = $this->vendas->listarRascunhos(max(1, min(50, $limit)));

        return array_map(function (array $venda): array {
            $isTeste = (string)($venda['origem_tipo'] ?? '') === 'pdv_teste'
                || str_contains((string)($venda['observacoes'] ?? ''), 'TESTE CONTROLADO');

            return [
                'id' => (int)($venda['id'] ?? 0),
                'cliente_id' => isset($venda['cliente_id']) ? (int)$venda['cliente_id'] : null,
                'origem_tipo' => (string)($venda['origem_tipo'] ?? ''),
                'status_venda' => (string)($venda['status_venda'] ?? ''),
                'status_fiscal' => (string)($venda['status_fiscal'] ?? ''),
                'total_liquido' => (float)($venda['total_liquido'] ?? 0),
                'observacoes' => (string)($venda['observacoes'] ?? ''),
                'created_by' => isset($venda['created_by']) ? (int)$venda['created_by'] : null,
                'created_at' => (string)($venda['created_at'] ?? ''),
                'is_teste_controlado' => $isTeste,
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function detalharVenda(int $vendaId): array
    {
        $this->assertEnabled();
        $venda = $this->requireVenda($vendaId);
        $itens = $this->vendas->listarItens($vendaId);
        $pagamentos = $this->pagamentos->listarPorVenda($vendaId);
        $documentos = $this->documentos->listarPorVenda($vendaId);
        $cancelamento = $this->avaliarCancelamento($venda, $itens, $pagamentos, $documentos);

        return [
            'venda' => [
                'id' => (int)($venda['id'] ?? 0),
                'numero' => (string)($venda['numero'] ?? ''),
                'cliente_id' => isset($venda['cliente_id']) ? (int)$venda['cliente_id'] : null,
                'origem_tipo' => (string)($venda['origem_tipo'] ?? ''),
                'status_venda' => (string)($venda['status_venda'] ?? ''),
                'status_fiscal' => (string)($venda['status_fiscal'] ?? ''),
                'total_bruto' => (float)($venda['total_bruto'] ?? 0),
                'total_desconto' => (float)($venda['total_desconto'] ?? 0),
                'total_acrescimo' => (float)($venda['total_acrescimo'] ?? 0),
                'total_liquido' => (float)($venda['total_liquido'] ?? 0),
                'desconto_geral' => $this->inferirAjustesGerais($venda, $this->vendas->calcularTotaisDaVenda($vendaId))['desconto_geral'],
                'observacoes' => (string)($venda['observacoes'] ?? ''),
                'created_by' => isset($venda['created_by']) ? (int)$venda['created_by'] : null,
                'created_at' => (string)($venda['created_at'] ?? ''),
                'cancelled_at' => (string)($venda['cancelled_at'] ?? ''),
                'cancel_reason' => (string)($venda['cancel_reason'] ?? ''),
                'is_teste_controlado' => (string)($venda['origem_tipo'] ?? '') === 'pdv_teste'
                    || str_contains((string)($venda['observacoes'] ?? ''), 'TESTE CONTROLADO'),
            ],
            'itens' => $itens,
            'pagamentos' => $pagamentos,
            'documentos' => $documentos,
            'cancelamento' => $cancelamento,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function visualizarReciboNaoFiscal(int $vendaId): array
    {
        $this->assertEnabled();

        if (!$this->settings->reciboEnabled()) {
            throw new RuntimeException('Recibo não fiscal do PDV desativado.');
        }

        $venda = $this->requireVenda($vendaId);
        $itens = $this->vendas->listarItens($vendaId);
        $pagamentos = $this->pagamentos->listarPorVenda($vendaId);
        $documentosFiscais = $this->documentos->listarFiscaisPorVenda($vendaId);
        $totaisItens = $this->vendas->calcularTotaisDaVenda($vendaId);
        $ajustesGerais = $this->inferirAjustesGerais($venda, $totaisItens);
        $cliente = $this->resolverClienteRecibo($venda);
        $operadorId = isset($venda['operador_id']) ? (int)$venda['operador_id'] : 0;
        if ($operadorId <= 0) {
            $operadorId = isset($venda['created_by']) ? (int)$venda['created_by'] : 0;
        }
        $operador = $operadorId > 0 ? $this->vendas->buscarUsuarioPorId($operadorId) : null;
        $statusVenda = (string)($venda['status_venda'] ?? '');
        $isCancelada = $statusVenda === PdvSaleStatus::CANCELADO;
        $isEstornada = $statusVenda === PdvSaleStatus::ESTORNADO;

        return [
            'empresa' => [
                'nome' => trim((string)($_ENV['APP_NAME'] ?? 'ERP Multimáquinas')),
                'cnpj' => $this->maskDocument((string)($_ENV['NFSE_PRESTADOR_CNPJ'] ?? '')),
            ],
            'venda' => [
                'id' => (int)($venda['id'] ?? 0),
                'numero' => (string)($venda['numero'] ?? ''),
                'status_venda' => (string)($venda['status_venda'] ?? ''),
                'status_fiscal' => (string)($venda['status_fiscal'] ?? ''),
                'total_bruto' => (float)($venda['total_bruto'] ?? 0),
                'total_desconto' => (float)($venda['total_desconto'] ?? 0),
                'total_acrescimo' => (float)($venda['total_acrescimo'] ?? 0),
                'total_liquido' => (float)($venda['total_liquido'] ?? 0),
                'desconto_geral' => $ajustesGerais['desconto_geral'],
                'acrescimo_geral' => $ajustesGerais['acrescimo_geral'],
                'observacoes' => (string)($venda['observacoes'] ?? ''),
                'created_at' => (string)($venda['created_at'] ?? ''),
                'cancelled_at' => (string)($venda['cancelled_at'] ?? ''),
                'cancel_reason' => (string)($venda['cancel_reason'] ?? ''),
                'is_cancelada' => $isCancelada,
                'is_estornada' => $isEstornada,
            ],
            'cliente' => $cliente,
            'operador' => [
                'id' => $operadorId > 0 ? $operadorId : null,
                'nome' => (string)($operador['nome'] ?? ''),
            ],
            'itens' => array_map(static function (array $item): array {
                return [
                    'id' => (int)($item['id'] ?? 0),
                    'codigo' => (string)($item['codigo'] ?? ''),
                    'descricao' => (string)($item['descricao'] ?? ''),
                    'quantidade' => (float)($item['quantidade'] ?? 0),
                    'valor_unitario' => (float)($item['valor_unitario'] ?? 0),
                    'subtotal' => (float)($item['subtotal'] ?? 0),
                    'desconto' => (float)($item['desconto'] ?? ($item['desconto_item'] ?? 0)),
                    'acrescimo' => (float)($item['acrescimo'] ?? 0),
                    'total_liquido' => (float)($item['total_liquido'] ?? 0),
                ];
            }, $itens),
            'pagamentos' => array_map(static function (array $pagamento): array {
                return [
                    'id' => (int)($pagamento['id'] ?? 0),
                    'forma_pagamento' => (string)($pagamento['forma_pagamento'] ?? ''),
                    'status' => (string)($pagamento['status'] ?? ''),
                    'valor' => (float)($pagamento['valor'] ?? 0),
                    'pago_em' => (string)($pagamento['pago_em'] ?? ''),
                    'cancelled_at' => (string)($pagamento['cancelled_at'] ?? ''),
                ];
            }, $pagamentos),
            'documentos_fiscais' => array_map(static function (array $documento): array {
                return [
                    'id' => (int)($documento['id'] ?? 0),
                    'tipo_documento' => (string)($documento['tipo_documento'] ?? ''),
                    'modelo' => (string)($documento['modelo'] ?? ''),
                    'status' => (string)($documento['status'] ?? ''),
                    'numero' => (string)($documento['numero'] ?? ''),
                    'serie' => (string)($documento['serie'] ?? ''),
                    'chave_acesso' => (string)($documento['chave_acesso'] ?? ''),
                    'protocolo' => (string)($documento['protocolo'] ?? ''),
                    'valor' => (float)($documento['valor'] ?? 0),
                    'link_consulta' => (string)($documento['link_consulta'] ?? ''),
                    'emitido_externamente' => (int)($documento['emitido_externamente'] ?? 1) === 1,
                    'data_emissao' => (string)($documento['data_emissao'] ?? ''),
                ];
            }, $documentosFiscais),
        ];
    }

    public function cancelarRascunho(int $vendaId, string $motivo = ''): void
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();
        $venda = $this->requireVenda($vendaId);
        $itens = $this->vendas->listarItens($vendaId);
        $pagamentos = $this->pagamentos->listarPorVenda($vendaId);
        $documentos = $this->documentos->listarPorVenda($vendaId);
        $cancelamento = $this->avaliarCancelamento($venda, $itens, $pagamentos, $documentos);

        if (!$cancelamento['permitido']) {
            throw new RuntimeException((string)$cancelamento['motivo']);
        }

        $motivoLimpo = trim($motivo) !== '' ? trim($motivo) : 'Cancelado manualmente.';

        try {
            $this->transaction(function () use ($vendaId, $motivoLimpo, $pagamentos, $actorId): void {
                foreach ($pagamentos as $pagamento) {
                    if ((string)($pagamento['status'] ?? '') === 'cancelado') {
                        continue;
                    }

                    $this->pagamentos->alterarStatus((int)$pagamento['id'], 'cancelado', [
                        'updated_by' => $actorId,
                    ], ['cancelled_at']);
                }

                $this->vendas->cancelarRascunho($vendaId, $motivoLimpo, $actorId);
                $this->audit->registrarVendaCancelada($vendaId, [
                    'venda_id' => $vendaId,
                    'cancel_reason' => $motivoLimpo,
                    'updated_by' => $actorId,
                ]);
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'cancelar_rascunho']);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function finalizarVenda(int $vendaId): array
    {
        $this->assertWriteAllowed();
        $actorId = $this->currentActorId();

        try {
            return $this->transaction(function () use ($vendaId, $actorId): array {
                $venda = $this->vendas->buscarVendaPorIdForUpdate($vendaId);
                if ($venda === null) {
                    throw new RuntimeException("Venda PDV não encontrada: {$vendaId}");
                }

                $itens = $this->vendas->listarItensPorVendaForUpdate($vendaId);
                $pagamentos = $this->pagamentos->listarPorVenda($vendaId);

                $this->assertVendaFinalizavel($venda, $itens);
                $planoFinanceiro = $this->planejarFinanceiroFinalizacao($venda, $pagamentos);
                $movimentacoesEstoque = $this->processarEstoqueFinalizacao($venda, $itens, $actorId);
                $lancamentoReceberId = $this->processarFinanceiroFinalizacao($venda, $planoFinanceiro, $actorId);

                $this->vendas->vincularLancamentoReceberNaVenda((int)$venda['id'], $lancamentoReceberId, $actorId);
                $this->vendas->atualizarFormaPagamentoLegada(
                    (int)$venda['id'],
                    $planoFinanceiro['forma_pagamento_lancamento'],
                    $actorId
                );
                $this->vendas->marcarVendaFinalizada((int)$venda['id'], $actorId);

                foreach ($movimentacoesEstoque as $movimentacao) {
                    $this->audit->registrarEstoqueSaida((int)$movimentacao['movimentacao_id'], [
                        'venda_id' => (int)$venda['id'],
                        'venda_item_id' => (int)$movimentacao['venda_item_id'],
                        'produto_id' => (int)$movimentacao['produto_id'],
                        'quantidade' => (float)$movimentacao['quantidade'],
                        'saldo_ant' => (float)$movimentacao['saldo_ant'],
                        'saldo_pos' => (float)$movimentacao['saldo_pos'],
                    ]);
                }

                $this->audit->registrarFinanceiroCriado($lancamentoReceberId, [
                    'venda_id' => (int)$venda['id'],
                    'lancamento_receber_id' => $lancamentoReceberId,
                    'status' => $planoFinanceiro['status_lancamento'],
                    'forma_pagamento' => $planoFinanceiro['forma_pagamento_lancamento'],
                    'valor' => $planoFinanceiro['valor'],
                    'valor_pago' => $planoFinanceiro['valor_pago'],
                ]);

                $this->audit->registrarVendaFinalizada((int)$venda['id'], [
                    'venda_id' => (int)$venda['id'],
                    'status_venda_anterior' => (string)($venda['status_venda'] ?? ''),
                    'status_venda_novo' => PdvSaleStatus::FINALIZADO,
                    'lancamento_receber_id' => $lancamentoReceberId,
                    'movimentacoes_estoque' => count($movimentacoesEstoque),
                    'updated_by' => $actorId,
                ]);

                $vendaFinalizada = $this->requireVenda((int)$venda['id']);

                return [
                    'venda' => $vendaFinalizada,
                    'lancamento_receber_id' => $lancamentoReceberId,
                    'movimentacoes_estoque' => $movimentacoesEstoque,
                    'financeiro' => $planoFinanceiro,
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'finalizar_venda']);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function estornarVenda(int $vendaId, string $motivo = ''): array
    {
        $this->assertWriteAllowed();
        $this->assertAdminCanEstornar();
        $actorId = $this->currentActorId();
        $motivoLimpo = trim($motivo) !== '' ? trim($motivo) : 'Estornado manualmente.';

        try {
            return $this->transaction(function () use ($vendaId, $actorId, $motivoLimpo): array {
                $venda = $this->vendas->buscarVendaPorIdForUpdate($vendaId);
                if ($venda === null) {
                    throw new RuntimeException("Venda PDV não encontrada: {$vendaId}");
                }

                $itens = $this->vendas->listarItensPorVendaForUpdate($vendaId);
                $pagamentos = $this->pagamentos->listarPorVenda($vendaId);

                $this->assertVendaEstornavel($venda, $itens, $pagamentos);

                $reversoesEstoque = $this->processarEstoqueEstorno($venda, $itens, $actorId);

                $lancamentoReceberId = (int)($venda['lancamento_receber_id'] ?? 0);
                $lancamento = $this->vendas->buscarLancamentoReceberPorIdForUpdate($lancamentoReceberId);
                if ($lancamento === null) {
                    throw new RuntimeException('Lançamento financeiro vinculado não encontrado para estorno.');
                }
                if ((string)($lancamento['status'] ?? '') === 'cancelado') {
                    throw new RuntimeException('Lançamento financeiro já está cancelado.');
                }

                $this->vendas->cancelarLancamentoReceber($lancamentoReceberId);

                foreach ($pagamentos as $pagamento) {
                    $pagamentoId = (int)($pagamento['id'] ?? 0);
                    if ($pagamentoId <= 0 || (string)($pagamento['status'] ?? '') === 'cancelado') {
                        continue;
                    }

                    $this->pagamentos->alterarStatus($pagamentoId, 'cancelado', [
                        'updated_by' => $actorId,
                    ], ['cancelled_at']);

                    $this->audit->registrarPagamentoCancelado($pagamentoId, [
                        'venda_id' => $vendaId,
                        'pagamento_id' => $pagamentoId,
                        'status_anterior' => (string)($pagamento['status'] ?? ''),
                        'status_novo' => 'cancelado',
                        'updated_by' => $actorId,
                    ]);
                }

                $this->vendas->marcarVendaEstornada($vendaId, $motivoLimpo, $actorId);

                foreach ($reversoesEstoque as $reversao) {
                    $this->audit->registrarEstoqueEstornado((int)$reversao['movimentacao_id'], [
                        'venda_id' => $vendaId,
                        'venda_item_id' => (int)$reversao['venda_item_id'],
                        'produto_id' => (int)$reversao['produto_id'],
                        'quantidade' => (float)$reversao['quantidade'],
                        'saldo_ant' => (float)$reversao['saldo_ant'],
                        'saldo_pos' => (float)$reversao['saldo_pos'],
                        'movimentacao_saida_id' => (int)$reversao['movimentacao_saida_id'],
                    ]);
                }

                $this->audit->registrarFinanceiroCancelado($lancamentoReceberId, [
                    'venda_id' => $vendaId,
                    'lancamento_receber_id' => $lancamentoReceberId,
                    'status_anterior' => (string)($lancamento['status'] ?? ''),
                    'status_novo' => 'cancelado',
                    'updated_by' => $actorId,
                ]);

                $this->audit->registrarVendaEstornada($vendaId, [
                    'venda_id' => $vendaId,
                    'status_venda_anterior' => (string)($venda['status_venda'] ?? ''),
                    'status_venda_novo' => PdvSaleStatus::ESTORNADO,
                    'lancamento_receber_id' => $lancamentoReceberId,
                    'reversoes_estoque' => count($reversoesEstoque),
                    'cancel_reason' => $motivoLimpo,
                    'updated_by' => $actorId,
                ]);

                return [
                    'venda' => $this->requireVenda($vendaId),
                    'lancamento_receber_id' => $lancamentoReceberId,
                    'reversoes_estoque' => $reversoesEstoque,
                ];
            });
        } catch (Throwable $e) {
            $this->audit->registrarErro($vendaId, $e, ['acao' => 'estornar_venda']);
            throw $e;
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->settings->enabled() || $this->settings->mode() === 'off') {
            throw new RuntimeException('PDV interno indisponível: feature flag desligada.');
        }
    }

    private function finalizacaoDisponivel(): bool
    {
        if (!$this->settings->enabled()) {
            return false;
        }

        if ($this->settings->mode() !== 'live') {
            return false;
        }

        if (!$this->settings->writeEnabled()) {
            return false;
        }

        if ($this->settings->writeAdminOnly() && !Auth::temNivel('admin')) {
            return false;
        }

        return Auth::check();
    }

    private function assertWriteAllowed(): void
    {
        if (!$this->settings->enabled()) {
            throw new RuntimeException('PDV interno indisponível: feature flag desligada.');
        }

        $mode = $this->settings->mode();
        if ($mode === 'off') {
            throw new RuntimeException('PDV interno indisponível: feature flag desligada.');
        }

        if ($mode === 'shadow') {
            throw new RuntimeException('Gravações do PDV bloqueadas no modo shadow.');
        }

        if ($mode !== 'live') {
            throw new RuntimeException('Gravações do PDV indisponíveis no modo atual.');
        }

        if (!$this->settings->writeEnabled()) {
            throw new RuntimeException('Escrita do PDV desativada.');
        }

        if ($this->settings->writeAdminOnly() && !Auth::temNivel('admin')) {
            throw new RuntimeException('Escrita do PDV restrita a admin.');
        }
    }

    private function assertAdminCanEstornar(): void
    {
        if (!Auth::temNivel('admin')) {
            throw new RuntimeException('Acesso negado. Estorno de venda PDV disponível apenas para administrador.');
        }
    }

    private function assertAdminCanListarVendas(): void
    {
        if (!Auth::temNivel('admin')) {
            throw new RuntimeException('Acesso negado. Listagem de vendas PDV disponível apenas para administrador.');
        }
    }

    private function currentActorId(): int
    {
        $actorId = Auth::id();
        if ($actorId === null || $actorId <= 0) {
            throw new RuntimeException('Usuário autenticado inválido para gravação do PDV.');
        }

        return $actorId;
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array<string, mixed>
     */
    private function normalizarFiltrosListagem(array $filtros): array
    {
        $statusVenda = trim((string)($filtros['status_venda'] ?? ''));
        if ($statusVenda !== '') {
            PdvSaleStatus::assertValid($statusVenda);
        }

        $formaPagamento = trim((string)($filtros['forma_pagamento'] ?? ''));
        if ($formaPagamento !== '') {
            PdvPaymentType::assertValid($formaPagamento);
        }

        return [
            'date_from' => trim((string)($filtros['date_from'] ?? '')),
            'date_to' => trim((string)($filtros['date_to'] ?? '')),
            'status_venda' => $statusVenda,
            'forma_pagamento' => $formaPagamento,
            'operador_id' => max(0, (int)($filtros['operador_id'] ?? 0)),
            'q' => trim((string)($filtros['q'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $venda
     */
    private function assertEditable(array $venda): void
    {
        $status = (string)($venda['status_venda'] ?? $venda['status'] ?? '');
        if ($status === PdvSaleStatus::CANCELADO) {
            throw new RuntimeException('Venda cancelada não pode ser alterada.');
        }
        if ($status === PdvSaleStatus::FINALIZADO) {
            throw new RuntimeException('Venda finalizada não pode ser alterada.');
        }
        if ($status === PdvSaleStatus::ESTORNADO) {
            throw new RuntimeException('Venda estornada não pode ser alterada.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireVenda(int $vendaId): array
    {
        $venda = $this->vendas->buscarVendaPorId($vendaId);
        if ($venda === null) {
            throw new RuntimeException("Venda PDV não encontrada: {$vendaId}");
        }
        return $venda;
    }

    /**
     * @param array{total_pago_confirmado?:float|int|string,qtd_ativos_nao_cancelados?:int|string} $resumoPagamento
     */
    private function inferirStatusPagamento(array $resumoPagamento, float $totalVenda, string $statusAtual): string
    {
        if ($statusAtual === PdvSaleStatus::CANCELADO) {
            return PdvSaleStatus::CANCELADO;
        }
        if ($statusAtual === PdvSaleStatus::FINALIZADO) {
            return PdvSaleStatus::FINALIZADO;
        }
        if ($statusAtual === PdvSaleStatus::ESTORNADO) {
            return PdvSaleStatus::ESTORNADO;
        }

        $qtdAtivos = (int)($resumoPagamento['qtd_ativos_nao_cancelados'] ?? 0);
        $totalPago = (float)($resumoPagamento['total_pago_confirmado'] ?? 0);

        if ($qtdAtivos === 0) {
            return PdvSaleStatus::RASCUNHO;
        }

        if ($totalPago <= 0.0) {
            return PdvSaleStatus::PENDENTE_PAGAMENTO;
        }
        if ($totalVenda > 0.0 && $totalPago + 0.0001 < $totalVenda) {
            return PdvSaleStatus::PARCIALMENTE_PAGO;
        }
        return PdvSaleStatus::PAGO;
    }

    private function assertDocumentAllowed(string $tipoDocumento): void
    {
        if (
            $tipoDocumento === PdvDocumentType::RECIBO_NAO_FISCAL &&
            !$this->settings->reciboEnabled()
        ) {
            throw new RuntimeException('Recibo não fiscal do PDV desativado.');
        }

        if ($this->isFiscalDocument($tipoDocumento) && !$this->settings->fiscalEnabled()) {
            throw new RuntimeException('Emissão fiscal do PDV desativada.');
        }
    }

    private function isFiscalDocument(string $tipoDocumento): bool
    {
        return in_array($tipoDocumento, [
            PdvDocumentType::NFE,
            PdvDocumentType::NFCE,
            PdvDocumentType::NFSE,
            PdvDocumentType::CUPOM_FISCAL,
            PdvDocumentType::SAT,
            PdvDocumentType::MFE,
            PdvDocumentType::ECF,
        ], true);
    }

    private function assertAdminCanVincularDocumentoFiscal(): void
    {
        if (!Auth::temNivel('admin')) {
            throw new RuntimeException('Acesso negado. Vínculo fiscal manual do PDV disponível apenas para administrador.');
        }
    }

    private function normalizarTipoDocumentoFiscalManual(mixed $value): string
    {
        $tipo = trim((string)$value);
        $permitidos = [
            PdvDocumentType::NFE,
            PdvDocumentType::NFCE,
            PdvDocumentType::NFSE,
            PdvDocumentType::CUPOM_FISCAL,
        ];

        if (!in_array($tipo, $permitidos, true)) {
            throw new InvalidArgumentException('tipo_documento deve ser nfe, nfce, nfse ou cupom_fiscal.');
        }

        return $tipo;
    }

    private function normalizarModeloFiscalManual(string $tipoDocumento, mixed $value): string
    {
        $modelo = trim((string)$value);
        $esperado = match ($tipoDocumento) {
            PdvDocumentType::NFE => '55',
            PdvDocumentType::NFCE => '65',
            PdvDocumentType::NFSE => 'nfse',
            PdvDocumentType::CUPOM_FISCAL => $modelo !== '' ? $modelo : 'cupom',
            default => '',
        };

        if ($tipoDocumento === PdvDocumentType::CUPOM_FISCAL) {
            if (!in_array($esperado, ['cupom', 'manual'], true)) {
                throw new InvalidArgumentException('modelo de cupom_fiscal deve ser cupom ou manual.');
            }
            return $esperado;
        }

        if ($modelo !== '' && $modelo !== $esperado) {
            throw new InvalidArgumentException("modelo incompatível para {$tipoDocumento}; esperado {$esperado}.");
        }

        return $esperado;
    }

    private function normalizarCampoFiscalObrigatorio(mixed $value, string $campo, string $tipoDocumento): ?string
    {
        $valor = $this->nullableString($value);
        if (in_array($tipoDocumento, [PdvDocumentType::NFE, PdvDocumentType::NFCE], true) && $valor === null) {
            throw new InvalidArgumentException("{$campo} obrigatório para NF-e/NFC-e manual.");
        }
        return $valor;
    }

    private function normalizarChaveAcessoFiscalManual(string $tipoDocumento, mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null && in_array($tipoDocumento, [PdvDocumentType::NFE, PdvDocumentType::NFCE], true)) {
            throw new InvalidArgumentException('chave_acesso obrigatória para NF-e/NFC-e manual.');
        }
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (in_array($tipoDocumento, [PdvDocumentType::NFE, PdvDocumentType::NFCE], true)) {
            if (strlen($digits) !== 44) {
                throw new InvalidArgumentException('chave_acesso de NF-e/NFC-e deve conter 44 dígitos.');
            }
            return $digits;
        }

        return $raw;
    }

    private function normalizarValorDocumentoFiscal(mixed $value, float $totalVenda): float
    {
        if ($value === null || $value === '') {
            return round($totalVenda, 2);
        }
        return $this->normalizeMoney($value);
    }

    private function assertValorDocumentoCompativel(float $valorDocumento, float $totalVenda, bool $confirmado): void
    {
        if ($valorDocumento <= 0.0) {
            throw new InvalidArgumentException('valor do documento fiscal deve ser maior que zero.');
        }

        if (abs(round($valorDocumento - $totalVenda, 2)) > 0.01 && !$confirmado) {
            throw new OutOfRangeException('Valor do documento fiscal diverge do total da venda; envie confirmar_valor_divergente=true para registrar mesmo assim.');
        }
    }

    private function normalizarDataEmissaoFiscal(mixed $value): ?string
    {
        $data = $this->nullableString($value);
        if ($data === null) {
            return null;
        }

        $timestamp = strtotime($data);
        if ($timestamp === false) {
            throw new InvalidArgumentException('data_emissao inválida.');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'sim', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $documento
     */
    private function documentoFiscalAtivo(array $documento): bool
    {
        return !in_array((string)($documento['status'] ?? ''), ['cancelado', 'inativo', 'removido'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $venda
     * @param array<int, array<string, mixed>> $itens
     * @param array<int, array<string, mixed>> $pagamentos
     * @param array<int, array<string, mixed>> $documentos
     * @return array<string, mixed>
     */
    private function avaliarCancelamento(array $venda, array $itens, array $pagamentos, array $documentos): array
    {
        $statusVenda = (string)($venda['status_venda'] ?? '');
        if (!in_array($statusVenda, [
            PdvSaleStatus::RASCUNHO,
            PdvSaleStatus::PENDENTE_PAGAMENTO,
            PdvSaleStatus::PARCIALMENTE_PAGO,
            PdvSaleStatus::PAGO,
        ], true)) {
            return ['permitido' => false, 'motivo' => 'Somente vendas PDV abertas podem ser canceladas.'];
        }

        if ($this->settings->mode() === 'shadow') {
            return ['permitido' => false, 'motivo' => 'Cancelamento bloqueado no modo shadow.'];
        }

        if (!$this->settings->writeEnabled()) {
            return ['permitido' => false, 'motivo' => 'Escrita do PDV desativada.'];
        }

        if ($this->vendas->possuiLancamentoReceberVinculado((int)$venda['id'])) {
            return ['permitido' => false, 'motivo' => 'Rascunho possui vínculo financeiro.'];
        }

        if ($this->vendas->contarMovimentacoesEstoquePorVenda((int)$venda['id']) > 0) {
            return ['permitido' => false, 'motivo' => 'Rascunho possui movimentação de estoque vinculada.'];
        }

        if ($this->vendas->contarMovimentacoesEstoquePorItens((int)$venda['id']) > 0) {
            return ['permitido' => false, 'motivo' => 'Rascunho possui item com estoque baixado.'];
        }

        if (count($documentos) > 0 || $this->documentos->contarPorVenda((int)$venda['id']) > 0) {
            return ['permitido' => false, 'motivo' => 'Rascunho possui documento vinculado.'];
        }

        foreach ($itens as $item) {
            if (!empty($item['estoque_movimentacao_id'])) {
                return ['permitido' => false, 'motivo' => 'Rascunho possui item com vínculo de estoque.'];
            }
        }

        return ['permitido' => true, 'motivo' => 'Venda PDV apta para cancelamento controlado.'];
    }

    /**
     * @param array<string, mixed> $venda
     * @param array<int, array<string, mixed>> $itens
     */
    private function assertVendaFinalizavel(array $venda, array $itens): void
    {
        $vendaId = (int)($venda['id'] ?? 0);
        $statusVenda = (string)($venda['status_venda'] ?? $venda['status'] ?? '');

        if ($vendaId <= 0) {
            throw new RuntimeException('Venda PDV inválida para finalização.');
        }
        if ($statusVenda === PdvSaleStatus::CANCELADO) {
            throw new RuntimeException('Venda cancelada não pode ser finalizada.');
        }
        if ($statusVenda === PdvSaleStatus::FINALIZADO) {
            throw new RuntimeException('Venda já finalizada.');
        }
        if ($statusVenda === PdvSaleStatus::ESTORNADO) {
            throw new RuntimeException('Venda estornada não pode ser finalizada.');
        }
        if ((float)($venda['total_liquido'] ?? 0) <= 0.0) {
            throw new RuntimeException('Venda com total_liquido inválido para finalização.');
        }
        if ($this->vendas->possuiLancamentoReceberVinculado($vendaId)) {
            throw new RuntimeException('Venda já possui vínculo financeiro.');
        }
        if ($this->vendas->contarMovimentacoesEstoquePorVenda($vendaId) > 0) {
            throw new RuntimeException('Venda já possui movimentação de estoque vinculada.');
        }
        if ($this->vendas->contarMovimentacoesEstoquePorItens($vendaId) > 0) {
            throw new RuntimeException('Venda já possui item com estoque baixado.');
        }
        if ($this->vendas->contarDocumentosFiscaisPorVenda($vendaId) > 0) {
            throw new RuntimeException('Venda possui documento fiscal vinculado.');
        }
        if (count($itens) === 0) {
            throw new RuntimeException('Venda sem itens não pode ser finalizada.');
        }

        foreach ($itens as $item) {
            if (!empty($item['estoque_movimentacao_id'])) {
                throw new RuntimeException('Venda possui item já vinculado a movimentação de estoque.');
            }
        }
    }

    /**
     * @param array<string, mixed> $venda
     * @param array<int, array<string, mixed>> $itens
     * @param array<int, array<string, mixed>> $pagamentos
     */
    private function assertVendaEstornavel(array $venda, array $itens, array $pagamentos): void
    {
        $vendaId = (int)($venda['id'] ?? 0);
        $statusVenda = (string)($venda['status_venda'] ?? $venda['status'] ?? '');

        if ($vendaId <= 0) {
            throw new RuntimeException('Venda PDV inválida para estorno.');
        }
        if ($statusVenda !== PdvSaleStatus::FINALIZADO) {
            throw new RuntimeException('Somente venda finalizada pode ser estornada.');
        }
        if ($this->documentos->contarFiscaisAtivosPorVenda($vendaId) > 0) {
            throw new RuntimeException('Venda possui documento fiscal vinculado ativo. Cancele ou trate o documento fiscal antes de estornar.');
        }
        if ((string)($venda['status_fiscal'] ?? '') !== PdvFiscalStatus::NAO_APLICAVEL) {
            throw new RuntimeException('Estorno bloqueado: venda possui status fiscal incompatível.');
        }
        if ((int)($venda['lancamento_receber_id'] ?? 0) <= 0) {
            throw new RuntimeException('Estorno bloqueado: venda não possui vínculo financeiro.');
        }
        if ($itens === []) {
            throw new RuntimeException('Estorno bloqueado: venda sem itens.');
        }
        if ($pagamentos === []) {
            throw new RuntimeException('Estorno bloqueado: venda sem pagamentos.');
        }
    }

    /**
     * @param array<string, mixed> $venda
     * @param array<int, array<string, mixed>> $pagamentos
     * @return array{
     *   tipo_fluxo:string,
     *   status_lancamento:string,
     *   forma_pagamento_lancamento:?string,
     *   valor:float,
     *   valor_pago:float,
     *   usar_vencimento_data_atual:bool,
     *   usar_data_pagamento_atual:bool
     * }
     */
    private function planejarFinanceiroFinalizacao(array $venda, array $pagamentos): array
    {
        $totalLiquido = round((float)($venda['total_liquido'] ?? 0), 2);
        $statusVenda = (string)($venda['status_venda'] ?? $venda['status'] ?? '');

        if ($totalLiquido <= 0.0) {
            throw new RuntimeException('Venda com total_liquido inválido para finalização.');
        }
        if ($pagamentos === []) {
            throw new RuntimeException('Venda sem pagamentos não pode ser finalizada.');
        }

        $ativos = array_values(array_filter($pagamentos, static fn (array $pagamento): bool => (string)($pagamento['status'] ?? '') !== 'cancelado'));
        if ($ativos === []) {
            throw new RuntimeException('Venda sem pagamentos ativos não pode ser finalizada.');
        }

        $formasImediatas = [PdvPaymentType::DINHEIRO, PdvPaymentType::PIX, PdvPaymentType::CARTAO];
        $formasDiferidas = [PdvPaymentType::BOLETO, PdvPaymentType::FATURADO];
        $formasAtivas = [];
        $formasImediatasAtivas = [];
        $formasDiferidasAtivas = [];
        $totalPago = 0.0;
        $possuiPendente = false;

        foreach ($ativos as $pagamento) {
            $forma = trim((string)($pagamento['forma_pagamento'] ?? ''));
            $status = trim((string)($pagamento['status'] ?? ''));
            $valor = round((float)($pagamento['valor'] ?? 0), 2);
            $formasAtivas[] = $forma;

            if (in_array($forma, $formasImediatas, true)) {
                $formasImediatasAtivas[] = $forma;
            }
            if (in_array($forma, $formasDiferidas, true)) {
                $formasDiferidasAtivas[] = $forma;
            }
            if ($status === 'pago') {
                $totalPago += $valor;
            } else {
                $possuiPendente = true;
            }
        }

        $formasImediatasAtivas = array_values(array_unique($formasImediatasAtivas));
        $formasDiferidasAtivas = array_values(array_unique($formasDiferidasAtivas));
        $formasAtivas = array_values(array_unique($formasAtivas));
        $totalPago = round($totalPago, 2);

        if ($formasImediatasAtivas !== [] && $formasDiferidasAtivas !== []) {
            throw new RuntimeException('Finalização bloqueada: combinação híbrida de pagamentos não suportada neste MVP.');
        }

        if ($formasDiferidasAtivas !== []) {
            if (count($formasDiferidasAtivas) > 1) {
                throw new RuntimeException('Finalização bloqueada: combinação de boleto e faturado não suportada neste MVP.');
            }
            if ($totalPago > 0.0 || $possuiPendente === false && count($ativos) > 0 && $statusVenda === PdvSaleStatus::PAGO) {
                throw new RuntimeException('Finalização bloqueada: pagamento diferido não pode ser misturado com quitação confirmada neste MVP.');
            }

            $forma = $formasDiferidasAtivas[0];

            return [
                'tipo_fluxo' => $forma,
                'status_lancamento' => $forma === PdvPaymentType::BOLETO ? 'aberto' : 'aguardando_fatura',
                'forma_pagamento_lancamento' => $forma,
                'valor' => $totalLiquido,
                'valor_pago' => 0.0,
                'usar_vencimento_data_atual' => true,
                'usar_data_pagamento_atual' => false,
            ];
        }

        if ($formasImediatasAtivas === []) {
            throw new RuntimeException('Finalização bloqueada: forma de pagamento incompatível com o MVP atual.');
        }
        if ($statusVenda !== PdvSaleStatus::PAGO) {
            throw new RuntimeException('Venda à vista só pode ser finalizada quando estiver com status_venda=pago.');
        }
        if ($possuiPendente) {
            throw new RuntimeException('Finalização bloqueada: existem pagamentos à vista ainda não confirmados.');
        }
        if (abs($totalPago - $totalLiquido) > 0.0001) {
            throw new RuntimeException('Finalização bloqueada: total dos pagamentos confirmados incompatível com o total_liquido da venda.');
        }

        return [
            'tipo_fluxo' => 'avista',
            'status_lancamento' => 'pago',
            'forma_pagamento_lancamento' => count($formasAtivas) > 1 ? 'misto' : $formasAtivas[0],
            'valor' => $totalLiquido,
            'valor_pago' => $totalLiquido,
            'usar_vencimento_data_atual' => true,
            'usar_data_pagamento_atual' => true,
        ];
    }

    /**
     * @param array<string, mixed> $venda
     * @param array<int, array<string, mixed>> $itens
     * @return array<int, array<string, mixed>>
     */
    private function processarEstoqueFinalizacao(array $venda, array $itens, int $actorId): array
    {
        $movimentacoes = [];
        $vendaId = (int)($venda['id'] ?? 0);
        $numero = (string)($venda['numero'] ?? $vendaId);

        foreach ($itens as $item) {
            $itemId = (int)($item['id'] ?? 0);
            $produtoId = (int)($item['produto_id'] ?? 0);
            $quantidade = round((float)($item['quantidade'] ?? 0), 3);

            if ($produtoId <= 0 || $quantidade <= 0.0) {
                continue;
            }

            $produto = $this->vendas->buscarProdutoPorIdForUpdate($produtoId);
            if ($produto === null) {
                throw new RuntimeException("Produto {$produtoId} não encontrado para finalização da venda {$vendaId}.");
            }

            if (!(int)($produto['controla_estoque'] ?? 1)) {
                continue;
            }

            $saldoAnt = round((float)($produto['estoque_qty'] ?? 0), 3);
            if ($saldoAnt + 0.0001 < $quantidade) {
                throw new RuntimeException("Estoque insuficiente para o produto {$produtoId} na venda {$vendaId}.");
            }

            $saldoPos = round($saldoAnt - $quantidade, 3);
            $this->vendas->atualizarSaldoProduto($produtoId, $saldoPos);

            $movimentacaoId = $this->vendas->registrarSaidaEstoquePdv(
                $vendaId,
                $itemId,
                $produtoId,
                $quantidade,
                $saldoAnt,
                $saldoPos,
                $actorId,
                "Saída por finalização da venda PDV #{$numero}"
            );

            $this->vendas->vincularEstoqueMovimentacaoNoItem($itemId, $movimentacaoId);

            $movimentacoes[] = [
                'movimentacao_id' => $movimentacaoId,
                'venda_item_id' => $itemId,
                'produto_id' => $produtoId,
                'quantidade' => $quantidade,
                'saldo_ant' => $saldoAnt,
                'saldo_pos' => $saldoPos,
            ];
        }

        return $movimentacoes;
    }

    /**
     * @param array<string, mixed> $venda
     * @param array<int, array<string, mixed>> $itens
     * @return array<int, array<string, mixed>>
     */
    private function processarEstoqueEstorno(array $venda, array $itens, int $actorId): array
    {
        $reversoes = [];
        $vendaId = (int)($venda['id'] ?? 0);
        $numero = (string)($venda['numero'] ?? $vendaId);

        foreach ($itens as $item) {
            $itemId = (int)($item['id'] ?? 0);
            $produtoId = (int)($item['produto_id'] ?? 0);
            $quantidade = round((float)($item['quantidade'] ?? 0), 3);

            if ($produtoId <= 0 || $quantidade <= 0.0) {
                continue;
            }

            $produto = $this->vendas->buscarProdutoPorIdForUpdate($produtoId);
            if ($produto === null) {
                throw new RuntimeException("Produto {$produtoId} não encontrado para estorno da venda {$vendaId}.");
            }

            if (!(int)($produto['controla_estoque'] ?? 1)) {
                continue;
            }

            $movimentacaoSaidaId = (int)($item['estoque_movimentacao_id'] ?? 0);
            if ($movimentacaoSaidaId <= 0) {
                throw new RuntimeException("Item {$itemId} sem vínculo de estoque para estorno da venda {$vendaId}.");
            }

            $movimentacaoSaida = $this->vendas->buscarMovimentacaoEstoquePorIdForUpdate($movimentacaoSaidaId);
            if ($movimentacaoSaida === null) {
                throw new RuntimeException("Movimentação de estoque {$movimentacaoSaidaId} não encontrada para estorno.");
            }
            if ((string)($movimentacaoSaida['tipo'] ?? '') !== 'saida') {
                throw new RuntimeException("Movimentação {$movimentacaoSaidaId} incompatível para estorno.");
            }
            if ((int)($movimentacaoSaida['produto_id'] ?? 0) !== $produtoId) {
                throw new RuntimeException("Produto divergente na movimentação {$movimentacaoSaidaId}.");
            }
            if ((int)($movimentacaoSaida['venda_item_id'] ?? 0) !== $itemId) {
                throw new RuntimeException("Item divergente na movimentação {$movimentacaoSaidaId}.");
            }

            $saldoAnt = round((float)($produto['estoque_qty'] ?? 0), 3);
            $saldoPos = round($saldoAnt + $quantidade, 3);
            $this->vendas->atualizarSaldoProduto($produtoId, $saldoPos);

            $movimentacaoEstornoId = $this->vendas->registrarEntradaEstoquePdvEstorno(
                $vendaId,
                $itemId,
                $produtoId,
                $quantidade,
                $saldoAnt,
                $saldoPos,
                $actorId,
                "Estorno da saída por venda PDV #{$numero}"
            );

            $reversoes[] = [
                'movimentacao_id' => $movimentacaoEstornoId,
                'movimentacao_saida_id' => $movimentacaoSaidaId,
                'venda_item_id' => $itemId,
                'produto_id' => $produtoId,
                'quantidade' => $quantidade,
                'saldo_ant' => $saldoAnt,
                'saldo_pos' => $saldoPos,
            ];
        }

        return $reversoes;
    }

    /**
     * @param array<string, mixed> $venda
     * @param array{
     *   status_lancamento:string,
     *   forma_pagamento_lancamento:?string,
     *   valor:float,
     *   valor_pago:float,
     *   usar_vencimento_data_atual:bool,
     *   usar_data_pagamento_atual:bool
     * } $planoFinanceiro
     */
    private function processarFinanceiroFinalizacao(array $venda, array $planoFinanceiro, int $actorId): int
    {
        $dbCurrentDateFields = [];
        if ($planoFinanceiro['usar_vencimento_data_atual']) {
            $dbCurrentDateFields[] = 'vencimento';
        }
        if ($planoFinanceiro['usar_data_pagamento_atual']) {
            $dbCurrentDateFields[] = 'data_pagamento';
        }

        $numero = (string)($venda['numero'] ?? $venda['id'] ?? '');
        $descricao = 'Venda PDV #' . $numero;
        $clienteId = isset($venda['cliente_id']) ? (int)$venda['cliente_id'] : null;
        if ($clienteId !== null && $clienteId > 0) {
            $descricao .= ' - cliente #' . $clienteId;
        }

        return $this->vendas->criarLancamentoReceberPdv([
            'cliente_id' => $clienteId,
            'valor' => $planoFinanceiro['valor'],
            'valor_pago' => $planoFinanceiro['valor_pago'],
            'desconto_valor' => 0.0,
            'status' => $planoFinanceiro['status_lancamento'],
            'forma_pagamento' => $planoFinanceiro['forma_pagamento_lancamento'],
            'descricao' => $descricao,
            'updated_by' => $actorId,
            'db_current_date_fields' => $dbCurrentDateFields,
        ]);
    }

    /**
     * @param array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float} $totaisItens
     * @return array{desconto_geral:float,acrescimo_geral:float}
     */
    private function inferirAjustesGerais(array $venda, array $totaisItens): array
    {
        $descontoGeral = round(max(0.0, (float)($venda['total_desconto'] ?? 0) - (float)$totaisItens['total_desconto']), 2);
        $acrescimoGeral = round(max(0.0, (float)($venda['total_acrescimo'] ?? 0) - (float)$totaisItens['total_acrescimo']), 2);

        return [
            'desconto_geral' => $descontoGeral,
            'acrescimo_geral' => $acrescimoGeral,
        ];
    }

    /**
     * @param array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float} $totaisItens
     * @return array{desconto_geral:float,acrescimo_geral:float}
     */
    private function ajustesGeraisCompativeisComTotais(array $venda, array $totaisItens): array
    {
        if ((float)$totaisItens['total_bruto'] <= 0.0) {
            return [
                'desconto_geral' => 0.0,
                'acrescimo_geral' => 0.0,
            ];
        }

        $ajustes = $this->inferirAjustesGerais($venda, $totaisItens);
        $baseDisponivel = round(
            max(0.0, (float)$totaisItens['total_liquido'] + (float)$ajustes['acrescimo_geral']),
            2
        );

        return [
            'desconto_geral' => round(min((float)$ajustes['desconto_geral'], $baseDisponivel), 2),
            'acrescimo_geral' => round(max(0.0, (float)$ajustes['acrescimo_geral']), 2),
        ];
    }

    /**
     * @param array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float} $totaisItens
     * @param array{desconto_geral?:float,acrescimo_geral?:float} $ajustesGerais
     * @return array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float}
     */
    private function combinarTotais(array $totaisItens, array $ajustesGerais): array
    {
        $descontoGeral = round(max(0.0, (float)($ajustesGerais['desconto_geral'] ?? 0.0)), 2);
        $acrescimoGeral = round(max(0.0, (float)($ajustesGerais['acrescimo_geral'] ?? 0.0)), 2);

        $totalBruto = round((float)$totaisItens['total_bruto'], 2);
        $totalDesconto = round((float)$totaisItens['total_desconto'] + $descontoGeral, 2);
        $totalAcrescimo = round((float)$totaisItens['total_acrescimo'] + $acrescimoGeral, 2);
        $totalLiquido = round($totalBruto - $totalDesconto + $totalAcrescimo, 2);

        if ($totalLiquido < 0.0) {
            throw new OutOfRangeException('total_liquido não pode ficar negativo.');
        }

        return [
            'total_bruto' => $totalBruto,
            'total_desconto' => $totalDesconto,
            'total_acrescimo' => $totalAcrescimo,
            'total_liquido' => $totalLiquido,
        ];
    }

    private function normalizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Valor monetário inválido.');
        }
        return round((float)$value, 2);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $startedHere = !$pdo->inTransaction();

        if ($startedHere) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function pdo(): PDO
    {
        return Database::pdo();
    }

    private function maskDocument(string $documento): string
    {
        $digits = preg_replace('/\D/', '', $documento) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 4) {
            return $digits;
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    /**
     * @param array<string, mixed> $venda
     * @return array<string, mixed>
     */
    private function resolverClienteRecibo(array $venda): array
    {
        $clienteId = isset($venda['cliente_id']) ? (int)$venda['cliente_id'] : 0;
        $cliente = $clienteId > 0 ? $this->clientes->buscarPorId($clienteId) : null;

        $nome = trim((string)($cliente['nome'] ?? $venda['nome_cliente'] ?? ''));
        $documento = $this->maskDocument((string)($cliente['cpf_cnpj'] ?? ''));

        return [
            'id' => $clienteId > 0 ? $clienteId : null,
            'nome' => $nome !== '' ? $nome : 'Cliente não informado',
            'documento' => $documento,
        ];
    }
}
