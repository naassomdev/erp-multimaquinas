<?php
use App\Core\View;
/** @var array<string, mixed> $usuario */
/** @var array<string, mixed> $pdv */
/** @var int|null $vendaInicialId */

$jsVer = substr(md5_file(BASE_PATH . '/public/assets/js/pdv.js'), 0, 8);
?>

<style>
    .pdv-shell { --pdv-accent: #1c6b4a; --pdv-soft: #e9f5ef; --pdv-warn: #b45f06; }
    .pdv-hero {
        background:
            radial-gradient(circle at top right, rgba(28, 107, 74, 0.14), transparent 28%),
            linear-gradient(135deg, rgba(28, 107, 74, 0.08), rgba(255, 255, 255, 0));
        border: 1px solid rgba(28, 107, 74, 0.12);
        border-radius: 1.25rem;
        padding: 1.5rem;
    }
    .pdv-hero__badge {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .45rem .8rem;
        border-radius: 999px;
        background: var(--pdv-soft);
        color: var(--pdv-accent);
        font-size: .82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .pdv-hero__warning {
        border-left: 4px solid var(--pdv-warn);
        background: rgba(255, 243, 205, .65);
        border-radius: .9rem;
        padding: 1rem 1.1rem;
    }
    .pdv-panel { border-radius: 1.1rem; overflow: hidden; }
    .pdv-panel .card-header {
        background: linear-gradient(180deg, rgba(28, 107, 74, 0.06), rgba(28, 107, 74, 0));
        font-weight: 700;
    }
    .pdv-selected-card {
        min-height: 110px;
        border: 1px dashed rgba(108, 117, 125, .4);
        border-radius: 1rem;
        background: rgba(248, 249, 250, .7);
    }
    .pdv-result-item { border-radius: .9rem; margin-bottom: .55rem; }
    .pdv-result-item__meta { font-size: .82rem; color: var(--bs-secondary-color); }
    .pdv-results {
        max-height: 320px;
        overflow: auto;
        padding-right: .15rem;
    }
    .pdv-stock-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        border-radius: 999px;
        padding: .2rem .55rem;
        font-size: .78rem;
        font-weight: 700;
    }
    .pdv-stock-pill--ok { background: rgba(25, 135, 84, .12); color: #146c43; }
    .pdv-stock-pill--low { background: rgba(255, 193, 7, .18); color: #9a6700; }
    .pdv-stock-pill--zero { background: rgba(220, 53, 69, .12); color: #b02a37; }
    .pdv-cart-table td, .pdv-cart-table th { vertical-align: middle; }
    .pdv-cart-summary { border: 1px solid rgba(0,0,0,.06); border-radius: 1rem; background: rgba(248,249,250,.75); }
    .pdv-drafts-list {
        max-height: 280px;
        overflow: auto;
    }
    .pdv-draft-card {
        border: 1px solid rgba(0, 0, 0, .06);
        border-radius: .95rem;
        padding: .9rem 1rem;
        background: rgba(255,255,255,.72);
    }
    .pdv-draft-card.is-active {
        border-color: rgba(28, 107, 74, .38);
        box-shadow: inset 0 0 0 1px rgba(28, 107, 74, .2);
        background: rgba(233, 245, 239, .7);
    }
    .pdv-draft-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        border-radius: 999px;
        padding: .2rem .55rem;
        font-size: .75rem;
        font-weight: 700;
        background: rgba(28, 107, 74, .1);
        color: var(--pdv-accent);
    }
    .pdv-draft-detail {
        border: 1px dashed rgba(28, 107, 74, .24);
        border-radius: 1rem;
        background: rgba(248,249,250,.8);
    }
    .pdv-cart-discount {
        min-width: 170px;
    }
    .pdv-cart-discount .form-select,
    .pdv-cart-discount .form-control {
        font-size: .82rem;
    }
    .pdv-cart-line-total {
        min-width: 145px;
    }
    .pdv-summary-inputs {
        border: 1px dashed rgba(108, 117, 125, .35);
        border-radius: .9rem;
        background: rgba(255,255,255,.55);
    }
    .pdv-summary-note {
        font-size: .8rem;
        color: var(--bs-secondary-color);
    }
    .pdv-pay-btn.active { box-shadow: inset 0 0 0 1px rgba(255,255,255,.35); }
    .pdv-disabled-hint { font-size: .78rem; color: var(--bs-secondary-color); }
    @media (max-width: 991.98px) {
        .pdv-hero { padding: 1.1rem; }
    }
</style>

<div
    class="d-flex flex-column gap-4"
    data-pdv-root="1"
    data-pdv-readonly="1"
    data-pdv-mode="<?= View::e((string)($pdv['mode'] ?? 'off')) ?>"
    data-pdv-user-role="<?= View::e((string)($usuario['nivel_acesso'] ?? '')) ?>"
    data-pdv-initial-venda-id="<?= View::e((string)($vendaInicialId ?? '')) ?>"
>
    <div class="pdv-hero">
        <div class="d-flex flex-column flex-lg-row gap-3 justify-content-between align-items-start">
            <div class="d-flex flex-column gap-2">
                <span class="pdv-hero__badge" id="pdv-status-badge">
                    <i class="ph ph-flask"></i>
                    Modo teste / somente leitura
                </span>
                <div>
                    <h1 class="page-header__title mb-1">PDV de Vendas</h1>
                    <p class="page-header__subtitle mb-0">
                        Navegação visual do módulo de balcão. Usuário atual:
                        <strong><?= View::e($usuario['nome'] ?? '') ?></strong>
                    </p>
                </div>
            </div>
            <div class="pdv-hero__warning">
                <strong id="pdv-write-warning-title">Nenhuma venda será gravada neste modo.</strong>
                <div class="small mt-1" id="pdv-write-warning-detail">
                    Escrita, estoque, financeiro, recibos e emissão fiscal permanecem bloqueados.
                </div>
                <?php if (($usuario['nivel_acesso'] ?? '') === 'admin'): ?>
                    <div class="mt-2">
                        <a href="/pdv/vendas" class="btn btn-sm btn-outline-secondary">Abrir listagem de vendas PDV</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm pdv-panel">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="ph ph-stack me-2"></i>Rascunhos PDV</span>
                    <span class="small text-body-secondary" id="pdv-drafts-mode-hint">Somente leitura em modo shadow</span>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-12 col-xl-5">
                            <div class="pdv-drafts-list d-flex flex-column gap-2" id="pdv-rascunhos-lista">
                                <div class="text-body-secondary small">Carregando rascunhos...</div>
                            </div>
                        </div>
                        <div class="col-12 col-xl-7">
                            <div class="pdv-draft-detail p-3 h-100" id="pdv-rascunho-detalhe">
                                <div class="text-body-secondary small">Selecione um rascunho para visualizar os detalhes.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm h-100 pdv-panel">
                <div class="card-header">
                    <i class="ph ph-user-circle me-2"></i>Cliente
                </div>
                <div class="card-body">
                    <label for="pdv-cliente-busca" class="form-label small">Buscar cliente</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ph ph-magnifying-glass"></i></span>
                        <input id="pdv-cliente-busca" type="search" class="form-control" placeholder="Digite nome ou documento">
                    </div>
                    <div class="form-text">Consulta por GET, em modo leitura, com no mínimo 2 caracteres.</div>

                    <div class="list-group mt-3 pdv-results" id="pdv-clientes-lista" aria-live="polite"></div>

                    <div class="mt-3 p-3 pdv-selected-card d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="small text-body-secondary mb-1">Cliente selecionado</div>
                                <div id="pdv-cliente-selecionado" class="fw-semibold">Nenhum cliente selecionado</div>
                                <div id="pdv-cliente-documento" class="small text-body-secondary mt-1">Sem documento selecionado</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="pdv-cliente-limpar">
                                Limpar
                            </button>
                        </div>
                        <div class="small text-body-secondary">
                            O cliente é usado apenas para visualização nesta etapa.
                        </div>
                        <div>
                            <label for="pdv-observacoes-venda" class="form-label small mb-1">Observações da venda persistida</label>
                            <textarea id="pdv-observacoes-venda" class="form-control form-control-sm" rows="2" placeholder="Ex.: TESTE CONTROLADO PDV - ETAPA 5G"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm h-100 pdv-panel">
                <div class="card-header">
                    <i class="ph ph-package me-2"></i>Peças e carrinho local
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-lg-8">
                            <label for="pdv-produto-busca" class="form-label small">Buscar produto/peça</label>
                            <input id="pdv-produto-busca" type="search" class="form-control" placeholder="Código, descrição ou marca">
                            <div class="form-text">Busca somente leitura, com no mínimo 2 caracteres.</div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label small">Modo</label>
                            <div class="form-control bg-body-tertiary">Carrinho apenas no navegador</div>
                        </div>
                    </div>

                    <div class="list-group mt-3 pdv-results" id="pdv-produtos-lista" aria-live="polite"></div>

                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="pdv-persistir-carrinho-btn" disabled title="Modo de teste: nada será gravado.">
                            Criar/usar rascunho e persistir carrinho
                        </button>
                        <div class="pdv-summary-note" id="pdv-persistencia-status">
                            Modo de teste: nada será gravado.
                        </div>
                    </div>

                    <div class="table-responsive mt-4">
                        <table class="table table-sm align-middle mb-0 pdv-cart-table">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th style="width:130px">Qtd</th>
                                    <th style="width:120px">Unitário</th>
                                    <th style="width:220px">Desconto</th>
                                    <th style="width:160px">Totais</th>
                                    <th style="width:60px"></th>
                                </tr>
                            </thead>
                            <tbody id="pdv-carrinho-body">
                                <tr data-empty-row>
                                    <td colspan="6" class="text-center text-body-secondary py-4">
                                        Nenhum item no carrinho local.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100 pdv-panel">
                <div class="card-header">
                    <i class="ph ph-credit-card me-2"></i>Pagamento visual
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4">
                            <button type="button" class="btn btn-outline-secondary w-100 pdv-pay-btn active" data-pay="dinheiro">Dinheiro</button>
                        </div>
                        <div class="col-6 col-md-4">
                            <button type="button" class="btn btn-outline-secondary w-100 pdv-pay-btn" data-pay="cartao">Cartão</button>
                        </div>
                        <div class="col-6 col-md-4">
                            <button type="button" class="btn btn-outline-secondary w-100 pdv-pay-btn" data-pay="pix">Pix</button>
                        </div>
                        <div class="col-6 col-md-4">
                            <button type="button" class="btn btn-outline-secondary w-100 pdv-pay-btn" data-pay="boleto">Boleto</button>
                        </div>
                        <div class="col-6 col-md-4">
                            <button type="button" class="btn btn-outline-secondary w-100 pdv-pay-btn" data-pay="faturado">Faturado</button>
                        </div>
                    </div>

                    <div class="small text-body-secondary mt-3">
                        Seleção apenas visual. Nenhum pagamento é salvo neste modo.
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="pdv-registrar-pagamento-btn" disabled title="Modo de teste: nada será gravado.">
                            Registrar pagamento no rascunho
                        </button>
                        <div class="pdv-summary-note" id="pdv-pagamento-sync-status">
                            Modo de teste: nada será gravado.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100 pdv-panel">
                <div class="card-header">
                    <i class="ph ph-calculator me-2"></i>Totalizador local
                </div>
                <div class="card-body">
                    <div class="pdv-summary-inputs p-3 mb-3">
                        <label class="form-label small mb-2" for="pdv-desconto-geral-valor">Desconto geral da venda</label>
                        <div class="input-group input-group-sm">
                            <select class="form-select" id="pdv-desconto-geral-tipo" style="max-width:90px">
                                <option value="valor">R$</option>
                                <option value="percentual">%</option>
                            </select>
                            <input id="pdv-desconto-geral-valor" type="number" min="0" step="0.01" class="form-control" value="0" placeholder="0,00">
                        </div>
                        <div class="pdv-summary-note mt-2" id="pdv-desconto-geral-resumo">
                            Nenhum desconto geral aplicado.
                        </div>
                        <div class="d-grid gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="pdv-desconto-geral-aplicar" disabled title="Desconto geral apenas simulado neste modo">
                                Aplicar desconto geral ao rascunho selecionado
                            </button>
                            <div class="pdv-summary-note" id="pdv-desconto-geral-sync-status">
                                Desconto geral apenas simulado neste modo.
                            </div>
                        </div>
                    </div>

                    <div class="pdv-cart-summary p-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-body-secondary">Subtotal dos itens</span>
                            <strong id="pdv-total-subtotal">R$ 0,00</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-body-secondary">Descontos nos itens</span>
                            <strong id="pdv-total-desconto-itens">R$ 0,00</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-body-secondary">Desconto geral</span>
                            <strong id="pdv-total-desconto-geral">R$ 0,00</strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center border-top pt-3">
                            <span class="fs-5 fw-semibold">Total final simulado</span>
                            <strong class="fs-4" id="pdv-total-geral">R$ 0,00</strong>
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button type="button" class="btn btn-primary" id="pdv-finalizar-venda-btn" disabled title="Finalização indisponível em modo de teste.">Finalizar venda</button>
                        <button type="button" class="btn btn-outline-secondary" disabled title="Indisponível no modo somente leitura">Gerar recibo</button>
                        <button type="button" class="btn btn-outline-secondary" disabled title="Indisponível no modo somente leitura">Emitir fiscal/cupom</button>
                        <div class="pdv-disabled-hint text-center" id="pdv-finalizacao-hint">
                            Indisponível no modo somente leitura.
                        </div>
                        <div class="pdv-disabled-hint text-center">
                            Descontos são apenas simulação visual neste modo.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/pdv.js?v=<?= $jsVer ?>" defer></script>
