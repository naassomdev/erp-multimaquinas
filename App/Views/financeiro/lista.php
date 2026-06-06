<?php
use App\Core\View;
/**
 * @var string $tipo
 * @var array  $lista
 * @var array  $filtros
 * @var array  $resumo
 * @var array  $paginacao
 */
$ehReceber = $tipo === 'receber';
$titulo    = $ehReceber ? 'Contas a Receber' : 'Contas a Pagar';
$basePath  = '/financeiro/' . $tipo;
$money     = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt        = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

$q        = $filtros['busca']     ?? '';
$st       = $filtros['status']    ?? '';
$de       = $filtros['de']        ?? '';
$ate      = $filtros['ate']       ?? '';
$ven      = $filtros['vencidas']  ?? '';
$osId     = $filtros['os_id']     ?? '';
$formaPag = $filtros['forma_pag'] ?? '';
$temFiltro = ($q !== '' || $st !== '' || $de !== '' || $ate !== '' || $ven === '1' || $osId !== '' || $formaPag !== '');

$pg   = $paginacao;
$hoje = date('Y-m-d');

$formasPagamento = [
    'dinheiro'       => 'Dinheiro',
    'pix'            => 'PIX',
    'cartao_debito'  => 'Cartão Débito',
    'cartao_credito' => 'Cartão Crédito',
    'boleto'         => 'Boleto',
    'transferencia'  => 'Transferência',
    'cheque'         => 'Cheque',
];
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-<?= $ehReceber ? 'arrow-circle-down text-success' : 'arrow-circle-up text-danger' ?> me-2"></i>
                <?= $titulo ?>
            </h1>
            <p class="page-header__subtitle"><?= number_format($pg['total'], 0, ',', '.') ?> lancamento(s)</p>
        </div>
        <div class="page-header__actions">
            <a href="/financeiro" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="<?= $basePath ?>/novo" class="btn btn-primary">
                <i class="ph ph-plus me-1"></i> Novo lancamento
            </a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row row-cols-1 row-cols-sm-<?= $ehReceber ? '4' : '3' ?> g-3">
        <div class="col">
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__label">Em aberto</div>
                <div class="kpi-card__value"><?= $money((float)$resumo['aberto']['valor_total']) ?></div>
                <div class="kpi-card__sub"><?= (int)$resumo['aberto']['qtd'] ?> conta(s)</div>
            </div>
        </div>
        <?php if ($ehReceber): ?>
        <div class="col">
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__label">Aguardando NFS-e</div>
                <div class="kpi-card__value"><?= $money((float)($resumo['aguardando_fatura']['valor_total'] ?? 0)) ?></div>
                <div class="kpi-card__sub"><?= (int)($resumo['aguardando_fatura']['qtd'] ?? 0) ?> conta(s)</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col">
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__label"><?= $ehReceber ? 'Recebido' : 'Pago' ?></div>
                <div class="kpi-card__value"><?= $money((float)$resumo['pago']['valor_pago_total']) ?></div>
                <div class="kpi-card__sub"><?= (int)$resumo['pago']['qtd'] ?> conta(s)</div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card">
                <div class="kpi-card__label">Cancelado</div>
                <div class="kpi-card__value"><?= $money((float)$resumo['cancelado']['valor_total']) ?></div>
                <div class="kpi-card__sub"><?= (int)$resumo['cancelado']['qtd'] ?> conta(s)</div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="<?= $basePath ?>" class="row g-3 align-items-end">
                <div class="col-lg">
                    <label class="form-label">Buscar</label>
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" name="q" value="<?= View::e($q) ?>"
                               placeholder="Descricao, <?= $ehReceber ? 'cliente, CPF/CNPJ' : 'fornecedor, CNPJ, chave NF-e' ?>..."
                               class="form-control">
                    </div>
                </div>
                <?php if ($ehReceber): ?>
                <div class="col-lg-2">
                    <label class="form-label">OS</label>
                    <input type="text" name="os_id" value="<?= View::e($osId) ?>"
                           placeholder="Nº da OS" class="form-control text-mono">
                </div>
                <?php endif; ?>
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="aberto"    <?= $st === 'aberto'    ? 'selected' : '' ?>>Em aberto</option>
                        <?php if ($ehReceber): ?>
                        <option value="aguardando_fatura" <?= $st === 'aguardando_fatura' ? 'selected' : '' ?>>Aguardando NFS-e</option>
                        <?php endif; ?>
                        <option value="pago"      <?= $st === 'pago'      ? 'selected' : '' ?>>Pago</option>
                        <option value="cancelado" <?= $st === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>
                <?php if ($ehReceber): ?>
                <div class="col-lg-2">
                    <label class="form-label">Forma pgto</label>
                    <select name="forma_pag" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($formasPagamento as $val => $label): ?>
                        <option value="<?= View::e($val) ?>" <?= $formaPag === $val ? 'selected' : '' ?>>
                            <?= View::e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-lg-2">
                    <label class="form-label">De</label>
                    <input type="date" name="de" value="<?= View::e($de) ?>" class="form-control">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Ate</label>
                    <input type="date" name="ate" value="<?= View::e($ate) ?>" class="form-control">
                </div>
                <div class="col-lg-auto d-flex align-items-center pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="vencidas" value="1" <?= $ven === '1' ? 'checked' : '' ?>
                               class="form-check-input" id="chkVencidas">
                        <label class="form-check-label" for="chkVencidas">So vencidas</label>
                    </div>
                </div>
                <div class="col-lg-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-funnel me-1"></i> Filtrar
                    </button>
                    <?php if ($temFiltro): ?>
                        <a href="<?= $basePath ?>" class="btn btn-outline-secondary">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela -->
    <?php if (empty($lista)): ?>
        <div class="card shadow-sm">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-folder-open"></i></div>
                <h3 class="empty-state__title">Nenhum lancamento encontrado</h3>
                <p class="empty-state__desc">
                    <?= $temFiltro ? 'Ajuste os filtros ou ' : 'Comece ' ?>
                    <a href="<?= $basePath ?>/novo">criando um novo lancamento</a>.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-table">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Descricao</th>
                        <th><?= $ehReceber ? 'Cliente' : 'Fornecedor' ?></th>
                        <th>Vencimento</th>
                        <th class="text-end">Valor</th>
                        <th class="text-end">Pago</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lista as $l):
                    $venc     = $l['vencimento'] ?? '';
                    $atrasado = ($l['status'] === 'aberto' && $venc !== '' && $venc < $hoje);

                    if ($l['status'] === 'pago') {
                        $badgeCls = 'status-badge--success';
                        $badgeTxt = 'Pago';
                    } elseif ($l['status'] === 'aguardando_fatura') {
                        $badgeCls = 'status-badge--info';
                        $badgeTxt = 'Ag. NFS-e';
                    } elseif ($l['status'] === 'cancelado') {
                        $badgeCls = 'status-badge--neutral';
                        $badgeTxt = 'Cancelado';
                    } elseif ($atrasado) {
                        $badgeCls = 'status-badge--danger';
                        $badgeTxt = 'Vencido';
                    } else {
                        $badgeCls = 'status-badge--warning';
                        $badgeTxt = 'Em aberto';
                    }

                    $equipInfo = '';
                    if ($ehReceber && !empty($l['equip_idx'])) {
                        $equipInfo = 'Equip. ' . (int)$l['equip_idx'];
                        if (!empty($l['equip_nome'])) {
                            $equipInfo .= ' — ' . $l['equip_nome'];
                        }
                    }
                    $formaPagLabel = $formasPagamento[$l['forma_pagamento'] ?? ''] ?? ($l['forma_pagamento'] ?? '');
                ?>
                    <tr class="cursor-pointer <?= $l['status'] === 'cancelado' ? 'opacity-50' : '' ?>"
                        onclick="window.location='<?= $basePath ?>/<?= (int)$l['id'] ?>'">
                        <td class="text-mono small fw-semibold text-nowrap">#<?= (int)$l['id'] ?></td>
                        <td>
                            <div class="fw-medium"><?= View::e($l['descricao']) ?></div>
                            <?php if ($ehReceber && !empty($l['os_id'])): ?>
                                <div class="text-body-secondary small">
                                    <a href="/os/<?= rawurlencode((string)$l['os_id']) ?>"
                                       class="text-mono text-decoration-none"
                                       onclick="event.stopPropagation()">OS #<?= View::e((string)$l['os_id']) ?></a>
                                    <?php if (!empty($l['orcamento_id'])): ?>
                                        <span class="ms-1 text-body-tertiary">· Orc. #<?= (int)$l['orcamento_id'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($equipInfo !== ''): ?>
                                    <div class="text-body-secondary small text-truncate" style="max-width:260px"><?= View::e($equipInfo) ?></div>
                                <?php endif; ?>
                            <?php elseif (!$ehReceber && !empty($l['chave_nfe'])): ?>
                                <div class="text-mono text-body-secondary small"><?= View::e(substr((string)$l['chave_nfe'], 0, 12)) ?>...</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-truncate" style="max-width:200px"><?= View::e($l['contraparte_nome'] ?? '—') ?></td>
                        <td class="text-mono small text-nowrap <?= $atrasado ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">
                            <?= $dt($venc) ?>
                        </td>
                        <td class="text-end text-mono fw-medium text-nowrap"><?= $money((float)$l['valor']) ?></td>
                        <td class="text-end text-nowrap">
                            <div class="text-mono <?= $l['valor_pago'] !== null ? 'text-success fw-semibold' : 'text-body-secondary' ?>">
                                <?= $l['valor_pago'] !== null ? $money((float)$l['valor_pago']) : '—' ?>
                            </div>
                            <?php if ($ehReceber && (float)($l['desconto_valor'] ?? 0) > 0): ?>
                                <div class="text-danger small text-mono">desc. <?= $money((float)$l['desconto_valor']) ?></div>
                            <?php endif; ?>
                            <?php if ($ehReceber && !empty($l['forma_pagamento'])): ?>
                                <div class="text-body-secondary small"><?= View::e($formaPagLabel) ?></div>
                            <?php endif; ?>
                            <?php if ($ehReceber && !empty($l['data_pagamento'])): ?>
                                <div class="text-body-secondary small text-mono"><?= $dt($l['data_pagamento']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <span class="status-badge <?= $badgeCls ?>"><?= $badgeTxt ?></span>
                        </td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <a href="<?= $basePath ?>/<?= (int)$l['id'] ?>" class="btn-icon" title="Ver detalhes">
                                <i class="ph ph-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginacao -->
            <?php if (($pg['total_pages'] ?? 1) > 1): ?>
                <?php
                $p    = $pg['page'];
                $tp   = $pg['total_pages'];
                $qs   = http_build_query(array_filter([
                    'q'         => $q,
                    'status'    => $st,
                    'de'        => $de,
                    'ate'       => $ate,
                    'vencidas'  => $ven,
                    'os_id'     => $osId,
                    'forma_pag' => $formaPag,
                ]));
                $base = $basePath . ($qs ? "?{$qs}&" : '?');
                ?>
                <div class="data-table__footer">
                    <div>
                        Pagina <strong><?= $p ?></strong> de <strong><?= $tp ?></strong>
                        <span class="d-none d-sm-inline">&middot; <?= number_format($pg['total'] ?? 0, 0, ',', '.') ?> resultados</span>
                    </div>
                    <nav aria-label="Paginacao">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base ?>p=<?= max(1, $p - 1) ?>"><i class="ph ph-caret-left"></i></a>
                            </li>
                            <?php for ($i = max(1, $p - 2); $i <= min($tp, $p + 2); $i++): ?>
                                <li class="page-item <?= $i === $p ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $base ?>p=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base ?>p=<?= min($tp, $p + 1) ?>"><i class="ph ph-caret-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
