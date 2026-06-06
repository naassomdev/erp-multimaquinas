<?php
use App\Core\View;

/** @var array<string, mixed> $listagem */
/** @var array<int, string> $statusOptions */
/** @var array<int, string> $paymentOptions */

$filtros = is_array($listagem['filtros'] ?? null) ? $listagem['filtros'] : [];
$paginacao = is_array($listagem['paginacao'] ?? null) ? $listagem['paginacao'] : [];
$operadores = is_array($listagem['operadores'] ?? null) ? $listagem['operadores'] : [];
$vendas = is_array($listagem['vendas'] ?? null) ? $listagem['vendas'] : [];
$jsVer = substr(md5_file(BASE_PATH . '/public/assets/js/pdv.js'), 0, 8);

$page = max(1, (int)($paginacao['page'] ?? 1));
$pages = max(1, (int)($paginacao['pages'] ?? 1));
$limit = max(1, (int)($paginacao['limit'] ?? 20));
$total = max(0, (int)($paginacao['total'] ?? 0));

$buildPageUrl = static function (int $targetPage) use ($filtros, $limit): string {
    $query = array_filter([
        'date_from' => $filtros['date_from'] ?? '',
        'date_to' => $filtros['date_to'] ?? '',
        'status_venda' => $filtros['status_venda'] ?? '',
        'forma_pagamento' => $filtros['forma_pagamento'] ?? '',
        'operador_id' => $filtros['operador_id'] ?? '',
        'q' => $filtros['q'] ?? '',
        'limit' => $limit,
        'page' => $targetPage,
    ], static fn($value) => $value !== null && $value !== '');

    return '/pdv/vendas?' . http_build_query($query);
};

$badgeClass = static function (string $status): string {
    return match ($status) {
        'finalizado' => 'bg-success-subtle text-success-emphasis',
        'cancelado' => 'bg-secondary-subtle text-secondary-emphasis',
        'estornado' => 'bg-danger-subtle text-danger-emphasis',
        'rascunho' => 'bg-warning-subtle text-warning-emphasis',
        default => 'bg-light text-dark',
    };
};
?>

<style>
    .pdv-sales-hero {
        background:
            radial-gradient(circle at top right, rgba(28, 107, 74, 0.14), transparent 28%),
            linear-gradient(135deg, rgba(28, 107, 74, 0.08), rgba(255, 255, 255, 0));
        border: 1px solid rgba(28, 107, 74, 0.12);
        border-radius: 1.25rem;
        padding: 1.5rem;
    }
    .pdv-sales-card {
        border-radius: 1rem;
    }
    .pdv-sales-toolbar {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .pdv-sales-table td,
    .pdv-sales-table th {
        vertical-align: middle;
        white-space: nowrap;
    }
    .pdv-sales-table td.wrap {
        white-space: normal;
    }
    .pdv-sales-actions {
        display: flex;
        gap: .4rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .pdv-fiscal-manual-feedback {
        min-height: 1.5rem;
    }
</style>

<div class="d-flex flex-column gap-4">
    <div class="pdv-sales-hero">
        <div class="pdv-sales-toolbar">
            <div>
                <h1 class="page-header__title mb-1">Vendas PDV</h1>
                <p class="page-header__subtitle mb-0">
                    Listagem administrativa somente leitura para acompanhamento das vendas de balcão.
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="/pdv" class="btn btn-outline-secondary btn-sm">Voltar ao PDV</a>
                <a href="/pdv/vendas" class="btn btn-outline-primary btn-sm">Limpar filtros</a>
            </div>
        </div>
    </div>

    <div class="card shadow-sm pdv-sales-card">
        <div class="card-header fw-semibold">Filtros</div>
        <div class="card-body">
            <form method="get" action="/pdv/vendas" class="row g-3">
                <div class="col-12 col-md-3">
                    <label for="pdv-sales-date-from" class="form-label small">Data inicial</label>
                    <input id="pdv-sales-date-from" type="date" name="date_from" class="form-control" value="<?= View::e((string)($filtros['date_from'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label for="pdv-sales-date-to" class="form-label small">Data final</label>
                    <input id="pdv-sales-date-to" type="date" name="date_to" class="form-control" value="<?= View::e((string)($filtros['date_to'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-2">
                    <label for="pdv-sales-status" class="form-label small">Status</label>
                    <select id="pdv-sales-status" name="status_venda" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?= View::e($status) ?>"<?= ($filtros['status_venda'] ?? '') === $status ? ' selected' : '' ?>><?= View::e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="pdv-sales-payment" class="form-label small">Pagamento</label>
                    <select id="pdv-sales-payment" name="forma_pagamento" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($paymentOptions as $paymentType): ?>
                            <option value="<?= View::e($paymentType) ?>"<?= ($filtros['forma_pagamento'] ?? '') === $paymentType ? ' selected' : '' ?>><?= View::e($paymentType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="pdv-sales-operator" class="form-label small">Operador</label>
                    <select id="pdv-sales-operator" name="operador_id" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($operadores as $operador): ?>
                            <option value="<?= View::e((string)($operador['id'] ?? '')) ?>"<?= (string)($filtros['operador_id'] ?? '') === (string)($operador['id'] ?? '') ? ' selected' : '' ?>>
                                <?= View::e((string)($operador['nome'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-8">
                    <label for="pdv-sales-term" class="form-label small">Busca livre</label>
                    <input id="pdv-sales-term" type="search" name="q" class="form-control" value="<?= View::e((string)($filtros['q'] ?? '')) ?>" placeholder="ID, número, cliente ou observação">
                </div>
                <div class="col-12 col-md-2">
                    <label for="pdv-sales-limit" class="form-label small">Limite</label>
                    <select id="pdv-sales-limit" name="limit" class="form-select">
                        <?php foreach ([10, 20, 50, 100] as $option): ?>
                            <option value="<?= $option ?>"<?= $limit === $option ? ' selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Aplicar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm pdv-sales-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold">Resultados</span>
            <span class="small text-body-secondary">
                <?= number_format($total, 0, ',', '.') ?> venda(s) encontrada(s)
            </span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle pdv-sales-table mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Criada em</th>
                            <th>Status</th>
                            <th>Fiscal</th>
                            <th>Pagamento</th>
                            <th>Total</th>
                            <th>Operador</th>
                            <th>Cliente</th>
                            <th>Itens</th>
                            <th>Financeiro</th>
                            <th>Estoque</th>
                            <th>Observações</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$vendas): ?>
                            <tr>
                                <td colspan="13" class="text-center text-body-secondary py-4">Nenhuma venda PDV encontrada com os filtros atuais.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vendas as $venda): ?>
                                <tr>
                                    <td class="fw-semibold">#<?= View::e((string)$venda['id']) ?></td>
                                    <td><?= View::e((string)$venda['created_at']) ?></td>
                                    <td><span class="badge rounded-pill <?= $badgeClass((string)$venda['status_venda']) ?>"><?= View::e((string)$venda['status_venda']) ?></span></td>
                                    <td>
                                        <span class="badge rounded-pill <?= $badgeClass((string)$venda['status_fiscal']) ?>"><?= View::e((string)$venda['status_fiscal']) ?></span>
                                        <?php if ((int)($venda['documentos_fiscais_count'] ?? 0) > 0): ?>
                                            <div class="small text-success mt-1">
                                                Fiscal vinculado:
                                                <?= View::e(strtoupper((string)($venda['fiscal_tipo_documento'] ?? ''))) ?>
                                                <?= View::e((string)($venda['fiscal_modelo'] ?? '')) ?>
                                                nº <?= View::e((string)($venda['fiscal_numero'] ?? '')) ?>
                                                série <?= View::e((string)($venda['fiscal_serie'] ?? '')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= View::e((string)($venda['forma_pagamento'] ?: '—')) ?></td>
                                    <td class="fw-semibold">R$ <?= number_format((float)$venda['total_liquido'], 2, ',', '.') ?></td>
                                    <td><?= View::e((string)($venda['operador_nome'] ?: '—')) ?></td>
                                    <td><?= View::e((string)($venda['cliente_nome'] ?: 'Sem cliente')) ?></td>
                                    <td><?= View::e((string)$venda['itens_count']) ?></td>
                                    <td><?= View::e((string)($venda['financeiro_status'] ?: '—')) ?></td>
                                    <td><?= !empty($venda['tem_estoque']) ? 'Sim' : 'Não' ?></td>
                                    <td class="wrap small text-body-secondary"><?= View::e((string)($venda['observacoes'] ?: '—')) ?></td>
                                    <td>
                                        <?php
                                        $saleId = (int)$venda['id'];
                                        $saleNumber = (string)($saleId > 0 ? str_pad((string)$saleId, 6, '0', STR_PAD_LEFT) : '');
                                        $reciboPath = '/pdv/vendas/' . $saleId . '/recibo';
                                        $reciboUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/') . $reciboPath;
                                        ?>
                                        <div class="pdv-sales-actions">
                                            <a href="/pdv?venda=<?= View::e((string)$venda['id']) ?>" class="btn btn-sm btn-outline-primary">Detalhes</a>
                                            <a href="/pdv/vendas/<?= View::e((string)$venda['id']) ?>/recibo" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">Recibo</a>
                                            <div class="d-inline-flex gap-1 flex-wrap" data-pdv-share-receipt-url="<?= View::e($reciboUrl) ?>" data-pdv-share-sale-number="<?= View::e($saleNumber) ?>">
                                                <a href="<?= View::e($reciboUrl) ?>" class="btn btn-sm btn-outline-success" target="_blank" rel="noopener" data-pdv-share-action="whatsapp">WhatsApp</a>
                                                <a href="<?= View::e($reciboUrl) ?>" class="btn btn-sm btn-outline-secondary" data-pdv-share-action="email">E-mail</a>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-pdv-share-action="copy">Copiar link</button>
                                            </div>
                                            <?php if ((string)$venda['status_venda'] === 'finalizado'): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-dark"
                                                    data-pdv-fiscal-manual-open="1"
                                                    data-venda-id="<?= View::e((string)$venda['id']) ?>"
                                                    data-venda-total="<?= View::e((string)$venda['total_liquido']) ?>"
                                                >Vincular nota/cupom</button>
                                                <a href="/pdv?venda=<?= View::e((string)$venda['id']) ?>" class="btn btn-sm btn-outline-danger">Estornar no PDV</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                <div class="small text-body-secondary">
                    Página <?= $page ?> de <?= $pages ?>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= View::e($buildPageUrl(max(1, $page - 1))) ?>" class="btn btn-sm btn-outline-secondary<?= $page <= 1 ? ' disabled' : '' ?>">Anterior</a>
                    <a href="<?= View::e($buildPageUrl(min($pages, $page + 1))) ?>" class="btn btn-sm btn-outline-secondary<?= $page >= $pages ? ' disabled' : '' ?>">Próxima</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pdv-fiscal-manual-modal" tabindex="-1" aria-labelledby="pdv-fiscal-manual-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="pdv-fiscal-manual-form">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="pdv-fiscal-manual-title">Vincular nota/cupom emitido externamente</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="venda_id" id="pdv-fiscal-manual-venda-id">
                    <div class="alert alert-warning small">
                        Esta ação apenas registra documento fiscal emitido externamente. Não transmite para SEFAZ, não emite cupom e não gera XML/PDF.
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="pdv-fiscal-manual-tipo" class="form-label">Tipo</label>
                            <select id="pdv-fiscal-manual-tipo" name="tipo_documento" class="form-select" required>
                                <option value="nfe">NF-e</option>
                                <option value="nfce">NFC-e</option>
                                <option value="nfse">NFS-e</option>
                                <option value="cupom_fiscal">Cupom fiscal</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="pdv-fiscal-manual-modelo" class="form-label">Modelo</label>
                            <input id="pdv-fiscal-manual-modelo" name="modelo" class="form-control" maxlength="10" value="55" placeholder="55, 65, nfse, cupom">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="pdv-fiscal-manual-valor" class="form-label">Valor</label>
                            <input id="pdv-fiscal-manual-valor" name="valor" class="form-control" type="number" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="pdv-fiscal-manual-numero" class="form-label">Número</label>
                            <input id="pdv-fiscal-manual-numero" name="numero" class="form-control" maxlength="30">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="pdv-fiscal-manual-serie" class="form-label">Série</label>
                            <input id="pdv-fiscal-manual-serie" name="serie" class="form-control" maxlength="10">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="pdv-fiscal-manual-data" class="form-label">Data de emissão</label>
                            <input id="pdv-fiscal-manual-data" name="data_emissao" class="form-control" type="datetime-local">
                        </div>
                        <div class="col-12">
                            <label for="pdv-fiscal-manual-chave" class="form-label">Chave de acesso</label>
                            <input id="pdv-fiscal-manual-chave" name="chave_acesso" class="form-control" maxlength="60" inputmode="numeric" placeholder="44 dígitos para NF-e/NFC-e">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="pdv-fiscal-manual-protocolo" class="form-label">Protocolo</label>
                            <input id="pdv-fiscal-manual-protocolo" name="protocolo" class="form-control" maxlength="100">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="pdv-fiscal-manual-link" class="form-label">Link de consulta</label>
                            <input id="pdv-fiscal-manual-link" name="link_consulta" class="form-control" maxlength="500" type="url">
                        </div>
                        <div class="col-12">
                            <label for="pdv-fiscal-manual-observacoes" class="form-label">Observações</label>
                            <textarea id="pdv-fiscal-manual-observacoes" name="observacoes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input id="pdv-fiscal-manual-confirmar-divergencia" name="confirmar_valor_divergente" class="form-check-input" type="checkbox" value="1">
                                <label for="pdv-fiscal-manual-confirmar-divergencia" class="form-check-label">
                                    Confirmo registrar mesmo se o valor divergir do total líquido da venda.
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="pdv-fiscal-manual-feedback small mt-3" id="pdv-fiscal-manual-feedback" aria-live="polite"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="pdv-fiscal-manual-submit">Registrar vínculo manual</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/pdv.js?v=<?= $jsVer ?>" defer></script>
