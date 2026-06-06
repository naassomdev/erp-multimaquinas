<?php
use App\Core\View;

/** @var array $usuario */
/** @var array<int, array<string, mixed>> $items */
/** @var array{status:string, garantia:string, busca:string} $filtros */
/** @var array{page:int, per_page:int, total:int, total_pages:int} $paginacao */
/** @var int $notif_count */

$queryParaPagina = static function (int $page) use ($filtros): string {
    return '?' . http_build_query([
        'status'   => $filtros['status'],
        'garantia' => $filtros['garantia'],
        'q'        => $filtros['busca'],
        'p'        => $page,
    ]);
};
$pg = $paginacao;
$q  = $filtros['busca'] ?? '';
$temFiltro = ($q !== '' || $filtros['status'] !== 'pendentes' || $filtros['garantia'] !== '');
$marcaModelo = static function (array $item): array {
    $partes = [];
    $fabricante = trim((string) ($item['fabricante'] ?? ''));
    $modelo = trim((string) ($item['modelo'] ?? ''));
    if ($fabricante !== '') {
        $partes[] = $fabricante;
    }
    if ($modelo !== '') {
        $partes[] = $modelo;
    }
    return $partes;
};
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Painel do Tecnico</h1>
            <p class="page-header__subtitle">
                <?= View::e($usuario['nome'] ?? '') ?>
                <?php if ($notif_count > 0): ?>
                    &middot; <span class="badge bg-info"><?= (int) $notif_count ?> notificacao(oes)</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/dashboard" class="btn btn-outline-secondary">
                <i class="ph ph-squares-four me-1"></i> Dashboard
            </a>
            <a href="/logout" class="btn btn-outline-secondary">
                <i class="ph ph-sign-out me-1"></i> Sair
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/tecnico" class="row g-3 align-items-end">
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="todos"     <?= $filtros['status'] === 'todos'     ? 'selected' : '' ?>>Todos</option>
                        <option value="pendentes" <?= $filtros['status'] === 'pendentes' ? 'selected' : '' ?>>Pendentes (aberta + andamento)</option>
                        <option value="aberta"    <?= $filtros['status'] === 'aberta'    ? 'selected' : '' ?>>Aberta</option>
                        <option value="andamento" <?= $filtros['status'] === 'andamento' ? 'selected' : '' ?>>Andamento</option>
                        <option value="montagem"  <?= $filtros['status'] === 'montagem'  ? 'selected' : '' ?>>Montagem</option>
                        <option value="pronto"    <?= $filtros['status'] === 'pronto'    ? 'selected' : '' ?>>Pronto</option>
                        <option value="cancelado" <?= $filtros['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Garantia</label>
                    <select name="garantia" class="form-select">
                        <option value=""    <?= $filtros['garantia'] === ''    ? 'selected' : '' ?>>Indiferente</option>
                        <option value="sim" <?= $filtros['garantia'] === 'sim' ? 'selected' : '' ?>>Em garantia</option>
                        <option value="nao" <?= $filtros['garantia'] === 'nao' ? 'selected' : '' ?>>Sem garantia</option>
                    </select>
                </div>
                <div class="col-lg">
                    <label class="form-label">Busca (OS, cliente, equipamento, defeito)</label>
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="search" name="q" value="<?= View::e($filtros['busca']) ?>"
                               placeholder="Ex.: OS-1234, Bosch, motor furadeira..."
                               class="form-control">
                    </div>
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2 page-filters__actions">
                    <button type="submit" class="btn btn-primary flex-grow-1 flex-lg-grow-0">
                        <i class="ph ph-funnel me-1"></i> Filtrar
                    </button>
                    <?php if ($temFiltro): ?>
                        <a href="/tecnico" class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Resultado -->
    <p class="text-body-secondary small mb-0">
        <strong><?= number_format($pg['total'], 0, ',', '.') ?></strong> equipamento(s) encontrado(s)
        <?php if ($pg['total_pages'] > 1): ?>
            &middot; pagina <?= (int) $pg['page'] ?> de <?= (int) $pg['total_pages'] ?>
        <?php endif; ?>
    </p>

    <?php if (empty($items)): ?>
        <div class="card shadow-sm">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-folder-open"></i></div>
                <h3 class="empty-state__title">Nenhum equipamento encontrado</h3>
                <p class="empty-state__desc">Ajuste os filtros para encontrar equipamentos.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="d-md-none tecnico-list">
            <?php foreach ($items as $item): ?>
                <?php
                $statusEq = (string) $item['status_equip'];
                $emGarantia = (int) $item['em_garantia'] === 1;
                $tipoGarantia = (string) ($item['tipo_garantia'] ?? '');
                $marcaModeloPartes = $marcaModelo($item);
                $badgeCls = match ($statusEq) {
                    'aberta'     => 'status-badge--info',
                    'andamento'  => 'status-badge--warning',
                    'montagem'   => 'status-badge--brand',
                    'pronto'     => 'status-badge--success',
                    'retirado'   => 'status-badge--neutral',
                    'cancelado'  => 'status-badge--danger',
                    default      => 'status-badge--neutral',
                };
                $detalheUrl = sprintf(
                    '/tecnico/os/%s/equipamento/%d',
                    rawurlencode((string) $item['os_id']),
                    (int) $item['ordem_idx']
                );
                ?>
                <a href="<?= View::e($detalheUrl) ?>" class="tecnico-card">
                    <div class="tecnico-card__topo">
                        <div>
                            <div class="tecnico-card__os text-mono">
                                <?= View::e((string) $item['os_id']) ?>
                                <span class="text-body-secondary">#<?= (int) $item['ordem_idx'] ?></span>
                            </div>
                            <div class="tecnico-card__entrada text-mono"><?= View::e((string) $item['data_entrada']) ?></div>
                        </div>
                        <i class="ph ph-caret-right tecnico-card__arrow"></i>
                    </div>

                    <div class="tecnico-card__cliente">
                        <div class="fw-semibold"><?= View::e((string) $item['nome_cliente']) ?></div>
                        <?php if (!empty($item['telefone'])): ?>
                            <div class="text-body-secondary small text-mono"><?= View::e((string) $item['telefone']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="tecnico-card__equipamento"><?= View::e((string) $item['equip_nome']) ?></div>
                    <?php if (!empty($marcaModeloPartes)): ?>
                        <div class="text-body-secondary small text-truncate">
                            <?= View::e(implode(' · ', $marcaModeloPartes)) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($item['serie']) && $item['serie'] !== 'N/I'): ?>
                        <div class="text-body-secondary small text-truncate">Série: <?= View::e((string) $item['serie']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($item['defeito'])): ?>
                        <div class="tecnico-card__defeito">
                            <span class="tecnico-card__label">Defeito</span>
                            <div><?= View::e((string) $item['defeito']) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="tecnico-card__footer">
                        <span class="status-badge <?= $badgeCls ?>"><?= ucfirst(View::e($statusEq)) ?></span>
                        <?php if ($emGarantia): ?>
                            <span class="status-badge status-badge--brand">
                                <?= $tipoGarantia !== '' ? View::e($tipoGarantia) : 'Garantia' ?>
                            </span>
                        <?php else: ?>
                            <span class="tecnico-card__garantia">Sem garantia</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="data-table d-none d-md-block">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>OS</th>
                        <th>Entrada</th>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Defeito</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Garantia</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $statusEq = (string) $item['status_equip'];
                        $emGarantia = (int) $item['em_garantia'] === 1;
                        $tipoGarantia = (string) ($item['tipo_garantia'] ?? '');
                        $marcaModeloPartes = $marcaModelo($item);
                        $badgeCls = match ($statusEq) {
                            'aberta'     => 'status-badge--info',
                            'andamento'  => 'status-badge--warning',
                            'montagem'   => 'status-badge--brand',
                            'pronto'     => 'status-badge--success',
                            'retirado'   => 'status-badge--neutral',
                            'cancelado'  => 'status-badge--danger',
                            default      => 'status-badge--neutral',
                        };
                        $detalheUrl = sprintf(
                            '/tecnico/os/%s/equipamento/%d',
                            rawurlencode((string) $item['os_id']),
                            (int) $item['ordem_idx']
                        );
                        ?>
                        <tr class="cursor-pointer" onclick="window.location='<?= View::e($detalheUrl) ?>'">
                            <td class="text-nowrap text-mono">
                                <?= View::e((string) $item['os_id']) ?>
                                <span class="text-body-secondary">#<?= (int) $item['ordem_idx'] ?></span>
                            </td>
                            <td class="text-nowrap text-mono small text-body-secondary">
                                <?= View::e((string) $item['data_entrada']) ?>
                            </td>
                            <td>
                                <div class="fw-medium"><?= View::e((string) $item['nome_cliente']) ?></div>
                                <?php if (!empty($item['telefone'])): ?>
                                    <div class="text-body-secondary small text-mono"><?= View::e((string) $item['telefone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= View::e((string) $item['equip_nome']) ?></div>
                                <?php if (!empty($marcaModeloPartes)): ?>
                                    <div class="text-body-secondary small text-truncate" style="max-width:260px">
                                        <?= View::e(implode(' · ', $marcaModeloPartes)) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['serie']) && $item['serie'] !== 'N/I'): ?>
                                    <div class="text-body-secondary small">Série: <?= View::e((string) $item['serie']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small" style="max-width:200px">
                                <div class="text-truncate"><?= View::e((string) $item['defeito']) ?></div>
                            </td>
                            <td class="text-center text-nowrap">
                                <span class="status-badge <?= $badgeCls ?>"><?= ucfirst(View::e($statusEq)) ?></span>
                            </td>
                            <td class="text-center text-nowrap">
                                <?php if ($emGarantia): ?>
                                    <span class="status-badge status-badge--brand">
                                        <?= $tipoGarantia !== '' ? View::e($tipoGarantia) : 'sim' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-body-secondary small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <a href="<?= View::e($detalheUrl) ?>" class="btn-icon" title="Ver detalhes">
                                    <i class="ph ph-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginacao -->
            <?php if ($pg['total_pages'] > 1): ?>
                <?php $p = (int) $pg['page']; $tp = (int) $pg['total_pages']; ?>
                <div class="data-table__footer">
                    <div>
                        Pagina <strong><?= $p ?></strong> de <strong><?= $tp ?></strong>
                        <span class="d-none d-sm-inline">&middot; <?= number_format($pg['total'], 0, ',', '.') ?> resultados</span>
                    </div>
                    <nav aria-label="Paginacao">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= View::e($queryParaPagina(1)) ?>"><i class="ph ph-caret-double-left"></i></a>
                            </li>
                            <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= View::e($queryParaPagina(max(1, $p - 1))) ?>"><i class="ph ph-caret-left"></i></a>
                            </li>
                            <?php for ($i = max(1, $p - 2); $i <= min($tp, $p + 2); $i++): ?>
                                <li class="page-item <?= $i === $p ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= View::e($queryParaPagina($i)) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= View::e($queryParaPagina(min($tp, $p + 1))) ?>"><i class="ph ph-caret-right"></i></a>
                            </li>
                            <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= View::e($queryParaPagina($tp)) ?>"><i class="ph ph-caret-double-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
