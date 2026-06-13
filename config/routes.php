<?php
declare(strict_types=1);

use App\Controllers\Admin\ClienteDuplicadosController;
use App\Controllers\Admin\EmailController;
use App\Controllers\Admin\EmpresaController;
use App\Controllers\Admin\UsuarioController;
use App\Controllers\Api\CatalogApiController;
use App\Controllers\Api\ClienteApiController;
use App\Controllers\Api\ComprasApiController;
use App\Controllers\Api\NotificacaoApiController;
use App\Controllers\Api\OrcamentoApiController;
use App\Controllers\Api\OrdemServicoApiController;
use App\Controllers\Api\PdvApiController;
use App\Controllers\Api\ProdutoApiController;
use App\Controllers\Api\TecnicoApiController;
use App\Controllers\Api\EstoqueApiController;
use App\Controllers\Auth\LoginController;
use App\Controllers\AlertaRetiradaController;
use App\Controllers\ComprasController;
use App\Controllers\ClienteController;
use App\Controllers\DashboardController;
use App\Controllers\EstoqueController;
use App\Controllers\FaturamentoController;
use App\Controllers\FinanceiroController;
use App\Controllers\HomeController;
use App\Controllers\NfseController;
use App\Controllers\OrcamentoController;
use App\Controllers\OrdemServicoController;
use App\Controllers\PdvController;
use App\Controllers\Api\OrdemServicoFullApiController;
use App\Controllers\PrePedidoController;
use App\Controllers\RelatorioController;
use App\Controllers\TecnicoController;
use App\Controllers\TermoController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AdminRecepcaoMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PdvAccessMiddleware;

return [
    ['GET',  '/',     [HomeController::class, 'index']],
    ['GET',  '/ola',  [HomeController::class, 'ola']],
    ['GET',  '/ping', [HomeController::class, 'ping']],

    ['GET',  '/login',  [LoginController::class, 'show'],         [GuestMiddleware::class]],
    ['POST', '/login',  [LoginController::class, 'authenticate'], [GuestMiddleware::class]],
    ['GET',  '/logout', [LoginController::class, 'logout']],

    ['GET',  '/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]],

    // ── Clientes ──────────────────────────────────────────────────────────
    ['GET',  '/clientes',              [ClienteController::class, 'index'],      [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/clientes/novo',         [ClienteController::class, 'criar'],      [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/clientes',              [ClienteController::class, 'salvar'],     [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/clientes/configuracao-documentos',  [ClienteController::class, 'configuracaoDocumentos'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/clientes/configuracao-documentos',  [ClienteController::class, 'salvarConfiguracaoDocumentos'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/clientes/{id}',         [ClienteController::class, 'visualizar'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/clientes/{id}/editar',  [ClienteController::class, 'editar'],     [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/clientes/{id}',         [ClienteController::class, 'atualizar'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/clientes/{id}/excluir', [ClienteController::class, 'excluir'],    [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],

    // ── API: Clientes ─────────────────────────────────────────────────────
    ['GET',  '/api/clientes/busca',      [ClienteApiController::class, 'busca'],        [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/api/clientes/cep/{cep}',  [ClienteApiController::class, 'buscarPorCep'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/api/clientes/documento/{doc}', [ClienteApiController::class, 'buscarPorDocumento'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/api/clientes/verificar-duplicado', [ClienteApiController::class, 'verificarDuplicado'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],

    // ── Estoque / Produtos ────────────────────────────────────────────────
    ['GET',  '/estoque',                  [EstoqueController::class, 'index'],             [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/estoque/exportar',         [EstoqueController::class, 'exportarCsv'],       [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/estoque/novo',             [EstoqueController::class, 'criar'],             [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/estoque',                  [EstoqueController::class, 'salvar'],            [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/estoque/importar',           [EstoqueController::class, 'importarForm'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/estoque/importar/preview',   [EstoqueController::class, 'previewXml'],   [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/estoque/importar/confirmar', [EstoqueController::class, 'confirmXml'],   [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/estoque/{id}',             [EstoqueController::class, 'visualizar'],        [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/estoque/{id}/editar',      [EstoqueController::class, 'editar'],            [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/estoque/{id}',             [EstoqueController::class, 'atualizar'],         [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/estoque/{id}/desativar',   [EstoqueController::class, 'desativar'],         [AuthMiddleware::class, AdminMiddleware::class]],

    // ── API: Estoque ──────────────────────────────────────────────────────
    ['GET',  '/api/estoque/busca',           [EstoqueApiController::class, 'busca'],          [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/api/estoque/calcular-preco',  [EstoqueApiController::class, 'calcularPreco'],  [AuthMiddleware::class, AdminMiddleware::class]],

    // ── Financeiro ────────────────────────────────────────────────────────
    ['GET',  '/financeiro',                       [FinanceiroController::class, 'index'],              [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/fluxo',                 [FinanceiroController::class, 'fluxoCaixa'],         [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/receber',               [FinanceiroController::class, 'receber'],            [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/pagar',                 [FinanceiroController::class, 'pagar'],              [AuthMiddleware::class, AdminMiddleware::class]],

    // ── Faturamento B2B (DEVE vir antes das rotas /financeiro/{tipo}/...) ─
    ['GET',  '/financeiro/faturamento',                [FaturamentoController::class, 'index'],       [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/faturamento/novo',           [FaturamentoController::class, 'criarForm'],   [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/financeiro/faturamento',                [FaturamentoController::class, 'salvar'],      [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/faturamento/{id}',           [FaturamentoController::class, 'visualizar'],  [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/financeiro/faturamento/{id}/finalizar', [FaturamentoController::class, 'finalizar'],   [AuthMiddleware::class, AdminMiddleware::class]],

    ['GET',  '/financeiro/{tipo}/novo',           [FinanceiroController::class, 'novoForm'],           [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/financeiro/{tipo}',                [FinanceiroController::class, 'salvar'],             [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/{tipo}/{id}',           [FinanceiroController::class, 'visualizar'],         [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/financeiro/{tipo}/{id}/editar',    [FinanceiroController::class, 'editar'],             [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/financeiro/{tipo}/{id}',           [FinanceiroController::class, 'atualizar'],          [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/financeiro/{tipo}/{id}/pagar',     [FinanceiroController::class, 'registrarPagamento'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/financeiro/{tipo}/{id}/cancelar',  [FinanceiroController::class, 'cancelar'],           [AuthMiddleware::class, AdminMiddleware::class]],

    // ── NFS-e ─────────────────────────────────────────────────────────────
    ['GET',  '/nfse',                  [NfseController::class, 'index'],        [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/nfse/rascunho',         [NfseController::class, 'novoRascunho'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/nfse/rascunho',         [NfseController::class, 'criarRascunho'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/nfse/configuracao',     [NfseController::class, 'configuracao'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/nfse/configuracao',     [NfseController::class, 'salvarConfiguracao'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/nfse/jobs-fiscais',     [NfseController::class, 'jobsFiscais'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/nfse/jobs-fiscais/{id}/arquivar', [NfseController::class, 'arquivarJobFiscal'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/nfse/{id}/conferencia', [NfseController::class, 'conferencia'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/nfse/{id}/conferencia', [NfseController::class, 'salvarConferencia'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/nfse/{id}',             [NfseController::class, 'visualizar'],   [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/nfse/{id}/xml',         [NfseController::class, 'baixarXml'],    [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/nfse/{id}/danfse',      [NfseController::class, 'baixarDanfse'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/nfse/{id}/reemitir',    [NfseController::class, 'reemitir'],     [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/nfse/{id}/sincronizar', [NfseController::class, 'sincronizar'],  [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/nfse/{id}/cancelar',    [NfseController::class, 'cancelar'],     [AuthMiddleware::class, AdminMiddleware::class]],

    // ── PDV (protegido por feature flag) ───────────────────────────────
    ['GET',  '/pdv',                            [PdvController::class, 'index'],                [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/pdv/vendas',                     [PdvController::class, 'vendas'],               [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/pdv/vendas/{id}/recibo',         [PdvController::class, 'recibo'],               [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/api/pdv/status',                 [PdvApiController::class, 'status'],            [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/api/pdv/clientes',               [PdvApiController::class, 'buscarClientes'],    [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/api/pdv/produtos',               [PdvApiController::class, 'buscarProdutos'],    [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/api/pdv/vendas',                 [PdvApiController::class, 'listarVendas'],      [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/api/pdv/vendas/rascunhos',       [PdvApiController::class, 'listarRascunhos'],  [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['GET',  '/api/pdv/vendas/{id}',            [PdvApiController::class, 'visualizarVenda'],   [AuthMiddleware::class, PdvAccessMiddleware::class]],
    ['POST', '/api/pdv/vendas/rascunho',        [PdvApiController::class, 'criarRascunho'],     [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/itens',      [PdvApiController::class, 'adicionarItem'],     [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/itens/{item_id}/remover', [PdvApiController::class, 'removerItem'], [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/totais',     [PdvApiController::class, 'atualizarTotais'],   [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/pagamentos', [PdvApiController::class, 'registrarPagamento'],[AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/documento-fiscal-manual', [PdvApiController::class, 'registrarDocumentoFiscalManual'], [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/documentos/{documento_id}/cancelar-vinculo-manual', [PdvApiController::class, 'cancelarVinculoDocumentoFiscalManual'], [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/documentos', [PdvApiController::class, 'prepararDocumento'], [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/finalizar',  [PdvApiController::class, 'finalizarVenda'],    [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/estornar',   [PdvApiController::class, 'estornarVenda'],     [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/pdv/vendas/{id}/cancelar-rascunho', [PdvApiController::class, 'cancelarRascunho'], [AuthMiddleware::class, PdvAccessMiddleware::class, CsrfMiddleware::class]],

    // ── Ordem de Serviço ──────────────────────────────────────────────────
    ['GET',  '/os',               [OrdemServicoController::class, 'index'],     [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/os/nova',          [OrdemServicoController::class, 'criar'],     [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/os',               [OrdemServicoController::class, 'salvar'],    [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/os/{id}',          [OrdemServicoController::class, 'detalhe'],   [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/os/{id}/whatsapp', [OrdemServicoController::class, 'enviarWhatsapp'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/os/{id}/editar',   [OrdemServicoController::class, 'editar'],    [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/os/{id}',          [OrdemServicoController::class, 'atualizar'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/os/{id}/imprimir', [OrdemServicoController::class, 'imprimir'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],

    // ── API: Ordem de Serviço ─────────────────────────────────────────────
    ['PATCH', '/api/os/{id}/status',                           [OrdemServicoFullApiController::class, 'atualizarStatus'],   [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST',  '/api/os/{os_id}/equip/{equip_idx}/retirar',     [OrdemServicoFullApiController::class, 'retirarEquipamento'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/os/{os_id}/equip/{equip_idx}/desfazer-retirada', [OrdemServicoFullApiController::class, 'desfazerRetiradaEquipamento'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/os/{os_id}/equip/{equip_idx}/devolver',          [OrdemServicoFullApiController::class, 'devolverEquipamento'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/os/{os_id}/equip/{equip_idx}/autorizar-descarte', [OrdemServicoFullApiController::class, 'autorizarDescarte'],    [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/os/{os_id}/equip/{equip_idx}/confirmar-descarte', [OrdemServicoFullApiController::class, 'confirmarDescarte'],    [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/os/{os_id}/equip/{equip_idx}/pagar-antecipado',   [OrdemServicoFullApiController::class, 'pagarAntecipado'],      [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['GET',   '/api/os/busca',                                 [OrdemServicoFullApiController::class, 'buscar'],             [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],

    ['GET',  '/tecnico',                                    [TecnicoController::class, 'index'],   [AuthMiddleware::class]],
    ['GET',  '/tecnico/catalogo-fontes',                    [TecnicoController::class, 'configuracaoCatalogo'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/tecnico/catalogo-fontes',                    [TecnicoController::class, 'salvarConfiguracaoCatalogo'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/tecnico/configuracoes-sistema',              [TecnicoController::class, 'configuracoesSistema'],       [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/tecnico/configuracoes-sistema',              [TecnicoController::class, 'salvarConfiguracoesSistema'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/tecnico/os/{os_id}/equipamento/{idx}',       [TecnicoController::class, 'detalhe'], [AuthMiddleware::class]],

    // ── API: técnico — leitura ────────────────────────────────────────
    ['GET',    '/api/tecnico/equipamento/{os_id}/{idx}',                        [TecnicoApiController::class, 'buscar'],             [AuthMiddleware::class]],
    ['GET',    '/api/tecnico/equipamento/{os_id}/{idx}/itens-para-orcamento',   [TecnicoApiController::class, 'itensParaOrcamento'], [AuthMiddleware::class]],

    // ── API: técnico — escrita (CSRF obrigatório) ─────────────────────
    ['PATCH',  '/api/tecnico/equipamento/{os_id}/{idx}/status', [TecnicoApiController::class, 'atualizarStatus'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['PATCH',  '/api/tecnico/equipamento/{os_id}/{idx}/nome',   [TecnicoApiController::class, 'atualizarNome'],   [AuthMiddleware::class, CsrfMiddleware::class]],
    ['PATCH',  '/api/tecnico/equipamento/{os_id}/{idx}/dados',  [TecnicoApiController::class, 'atualizarDadosEquipamento'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/equipamento/{os_id}/{idx}/verificar-montagem', [TecnicoApiController::class, 'verificarMontagem'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['PUT',    '/api/tecnico/equipamento/{os_id}/{idx}/laudo',  [TecnicoApiController::class, 'salvarLaudo'],     [AuthMiddleware::class, CsrfMiddleware::class]],
    ['PUT',    '/api/tecnico/equipamento/{os_id}/{idx}/obs-recepcao', [TecnicoApiController::class, 'salvarObsRecepcao'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/equipamento/{os_id}/{idx}/concluir-diagnostico', [TecnicoApiController::class, 'concluirDiagnostico'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/equipamento/{os_id}/{idx}/itens',  [TecnicoApiController::class, 'adicionarItem'],   [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/itens/{id}/solicitar-compra',       [TecnicoApiController::class, 'solicitarCompra'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['DELETE', '/api/tecnico/itens/{id}',                       [TecnicoApiController::class, 'removerItem'],     [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/os/{os_id}/equipamentos/{idx}/servicos-terceiros', [TecnicoApiController::class, 'criarServicoTerceiro'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['PATCH',  '/api/tecnico/servicos-terceiros/{id}/retorno',   [TecnicoApiController::class, 'registrarRetornoServicoTerceiro'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['PATCH',  '/api/tecnico/servicos-terceiros/{id}/cancelar',  [TecnicoApiController::class, 'cancelarServicoTerceiro'], [AuthMiddleware::class, CsrfMiddleware::class]],

    // ── API: técnico — uploads ────────────────────────────────────────
    ['POST',   '/api/tecnico/equipamento/{os_id}/{idx}/fotos',  [TecnicoApiController::class, 'adicionarFoto'],   [AuthMiddleware::class, CsrfMiddleware::class]],
    ['DELETE', '/api/tecnico/equipamento/{os_id}/{idx}/fotos',  [TecnicoApiController::class, 'removerFoto'],     [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/equipamento/{os_id}/{idx}/vista',      [TecnicoApiController::class, 'setVista'],        [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',   '/api/tecnico/equipamento/{os_id}/{idx}/vista-url',  [TecnicoApiController::class, 'setVistaUrl'],     [AuthMiddleware::class, CsrfMiddleware::class]],
    ['DELETE', '/api/tecnico/equipamento/{os_id}/{idx}/vista',      [TecnicoApiController::class, 'removerVista'],    [AuthMiddleware::class, CsrfMiddleware::class]],

    // ── API: notificações ─────────────────────────────────────────────
    ['GET',   '/api/tecnico/notificacoes',             [NotificacaoApiController::class, 'listar'],     [AuthMiddleware::class]],
    ['PATCH', '/api/tecnico/notificacoes/{id}/lida',   [NotificacaoApiController::class, 'marcarLida'], [AuthMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/tecnico/notificacoes/limpar',      [NotificacaoApiController::class, 'marcarTodasComoLidas'], [AuthMiddleware::class, CsrfMiddleware::class]],

    // ── API: produtos (autocomplete) ──────────────────────────────────
    ['GET',   '/api/produtos/busca',                   [ProdutoApiController::class, 'busca'],          [AuthMiddleware::class]],

    // ── API: catálogo de vista explodida (proxy → microserviço Node) ─
    ['GET',   '/api/catalogo/health',                  [CatalogApiController::class, 'health'],         [AuthMiddleware::class]],
    ['GET',   '/api/catalogo/fontes',                  [CatalogApiController::class, 'fontes'],         [AuthMiddleware::class]],
    ['GET',   '/api/catalogo/marcas',                  [CatalogApiController::class, 'marcas'],         [AuthMiddleware::class]],
    ['GET',   '/api/catalogo/modelos',                 [CatalogApiController::class, 'modelos'],        [AuthMiddleware::class]],
    ['GET',   '/api/catalogo/produto',                 [CatalogApiController::class, 'produto'],        [AuthMiddleware::class]],
    ['GET',   '/api/catalogo/pdf',                     [CatalogApiController::class, 'pdfFelap'],       [AuthMiddleware::class]],

    // ── API: Mão de Obra (Sugestões e Catálogo) ───────────────────────
    ['GET',  '/api/mao-obra',                         [\App\Controllers\Api\MaoDeObraApiController::class, 'listarPorTipo'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/api/mao-de-obra',                      [\App\Controllers\Api\MaoDeObraApiController::class, 'catalogo'],      [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/api/mao-de-obra/sugerir',              [\App\Controllers\Api\MaoDeObraApiController::class, 'sugerir'],       [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],

    // ── Admin: Configuração de E-mail ─────────────────────────────────
    ['GET',  '/admin/email',         [EmailController::class, 'index'],  [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/admin/email',         [EmailController::class, 'salvar'], [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/admin/email/testar',  [EmailController::class, 'testar'], [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],

    // ── Admin: Dados da Empresa ───────────────────────────────────────
    ['GET',  '/admin/clientes/duplicados',                          [ClienteDuplicadosController::class, 'index'],   [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/admin/clientes/{origem}/mesclar-em/{destino}',       [ClienteDuplicadosController::class, 'comparar'], [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/admin/clientes/{origem}/mesclar-em/{destino}',       [ClienteDuplicadosController::class, 'executar'], [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],

    ['GET',  '/admin/empresa', [EmpresaController::class, 'index'],  [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/admin/empresa', [EmpresaController::class, 'salvar'], [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],

    // ── Admin: Usuários ───────────────────────────────────────────────
    // Atenção: /novo deve vir ANTES de /{id}/editar para não ser capturado como id
    ['GET',  '/admin/usuarios',              [UsuarioController::class, 'index'],    [AuthMiddleware::class, AdminMiddleware::class]],
    ['GET',  '/admin/usuarios/novo',         [UsuarioController::class, 'novo'],     [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/admin/usuarios',              [UsuarioController::class, 'criar'],    [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],
    ['GET',  '/admin/usuarios/{id}/editar',  [UsuarioController::class, 'editar'],   [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/admin/usuarios/{id}',         [UsuarioController::class, 'atualizar'],[AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],

    // ── Admin: Tabela de Preços M.O. ──────────────────────────────────
    ['GET',  '/admin/mao-de-obra',                    [\App\Controllers\MaoDeObraController::class, 'index'],           [AuthMiddleware::class, AdminMiddleware::class]],
    ['POST', '/admin/mao-de-obra',                    [\App\Controllers\MaoDeObraController::class, 'salvar'],          [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/admin/mao-de-obra/deletar/{id}',       [\App\Controllers\MaoDeObraController::class, 'deletar'],         [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],

    // ── Orçamento ─────────────────────────────────────────────────────
    ['GET',  '/orcamento',           [OrcamentoController::class, 'index'],   [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/orcamento/{id}/pdf',  [OrcamentoController::class, 'pdf'],     [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/orcamento/{os_id}',   [OrcamentoController::class, 'detalhe'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],

    // ── Pré-Pedido (Módulo Isolado) ───────────────────────────────────
    ['GET',  '/pre-pedido',                       [PrePedidoController::class, 'index'],       [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/api/pre-pedido',                   [PrePedidoController::class, 'salvar'],      [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    // Visualização pública (magic-link via slug 16-hex). Sem AuthMiddleware
    // de propósito: o cliente abre o link recebido pelo WhatsApp/e-mail.
    ['GET',  '/pre-pedido/{slug}/visualizar',     [PrePedidoController::class, 'visualizar']],

    // ── Termo de Responsabilidade (público — aceite digital) ──────────
    // Sem AuthMiddleware: o cliente abre o link recebido via WhatsApp.
    ['GET',  '/termo/{slug}',         [TermoController::class, 'visualizar']],
    ['POST', '/termo/{slug}/aceitar', [TermoController::class, 'aceitar']],

    // ── Compras e Alertas ──────────────────────────────────────────────
    ['GET',   '/compras/necessidades',                         [ComprasController::class, 'necessidades'],          [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['PATCH', '/api/compras/necessidades/{id}/status',          [ComprasApiController::class, 'atualizarStatus'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/compras/necessidades/{id}/vincular-produto', [ComprasApiController::class, 'vincularProduto'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/compras/necessidades/{id}/entrada-estoque', [ComprasApiController::class, 'entradaEstoque'],   [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],

    ['GET',  '/alertas/retirada',                          [AlertaRetiradaController::class, 'index'],       [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/api/alertas/retirada/contadores',           [AlertaRetiradaController::class, 'contadores'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET',  '/api/alertas/retirada/elegiveis-aviso',      [AlertaRetiradaController::class, 'elegiveisAviso'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/api/alertas/retirada/preview-aviso',        [AlertaRetiradaController::class, 'previewAviso'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/api/alertas/retirada/enviar-aviso',         [AlertaRetiradaController::class, 'enviarAviso'],  [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST', '/api/alertas/retirada/{os_id}/notificar',    [AlertaRetiradaController::class, 'notificar'],   [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/alertas/retirada/{os_id}/comprovante',  [AlertaRetiradaController::class, 'comprovante'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST', '/api/alertas/retirada/{os_id}/equip/{equip_idx}/descarte', [AlertaRetiradaController::class, 'descarteEquipamento'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],

    // ── API: orçamento ────────────────────────────────────────────────
    ['GET',   '/api/orcamentos/os/{os_id}',         [OrcamentoApiController::class, 'listarPorOs'],      [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['POST',  '/api/orcamentos',                    [OrcamentoApiController::class, 'salvar'],           [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['PATCH', '/api/orcamentos/{id}',               [OrcamentoApiController::class, 'atualizarParcial'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/orcamentos/{id}/reverter-cancelamento', [OrcamentoApiController::class, 'reverterCancelamento'], [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/orcamentos/{id}/retirada-sem-custo', [OrcamentoApiController::class, 'retiradaSemCusto'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/orcamentos/{id}/pecas-fornecidas-cliente', [OrcamentoApiController::class, 'pecasFornecidasCliente'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/orcamentos/pre-aprovar',        [OrcamentoApiController::class, 'preAprovar'],       [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/orcamentos/{id}/whatsapp',      [OrcamentoApiController::class, 'whatsapp'],         [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],
    ['POST',  '/api/orcamentos/{id}/email',         [OrcamentoApiController::class, 'email'],            [AuthMiddleware::class, AdminRecepcaoMiddleware::class, CsrfMiddleware::class]],

    // ── API: ordem de serviço ─────────────────────────────────────────
    ['GET',   '/api/ordens/buscar-por-telefone', [OrdemServicoApiController::class, 'buscarPorTelefone'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],

    // ── Relatórios ────────────────────────────────────────────────────
    ['GET', '/relatorios/garantias-fabricante/exportar', [RelatorioController::class, 'exportarGarantiasFabricante'], [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET', '/relatorios/garantias-fabricante',          [RelatorioController::class, 'garantiasFabricante'],         [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET', '/relatorios/saida-equipamentos',            [RelatorioController::class, 'saidaEquipamentos'],           [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
    ['GET', '/relatorios/curva-abc',                     [RelatorioController::class, 'curvaAbc'],                   [AuthMiddleware::class, AdminRecepcaoMiddleware::class]],
];
