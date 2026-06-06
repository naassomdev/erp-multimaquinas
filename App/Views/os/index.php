<?php
use App\Core\View;
/**
 * @var array $ordens
 * @var array $filtros
 * @var array $paginacao
 */
$q      = $filtros['busca']       ?? '';
$status = $filtros['status']      ?? '';
$dtIni  = $filtros['data_inicio'] ?? '';
$dtFim  = $filtros['data_fim']    ?? '';
$pg     = $paginacao ?? [];
$temFiltro = ($q !== '' || $status !== '' || $dtIni !== '' || $dtFim !== '');
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Ordens de Servico</h1>
            <p class="page-header__subtitle">
                <?= number_format($pg['total'] ?? 0, 0, ',', '.') ?> OS(s) encontrada(s)
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/os/nova" class="btn btn-primary">
                <i class="ph ph-plus me-1"></i> Nova OS
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/os" class="row g-3 align-items-end">
                <div class="col-lg">
                    <label class="form-label">Buscar</label>
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" name="q" value="<?= View::e($q) ?>"
                               placeholder="ID, cliente, telefone ou CPF/CNPJ..." autofocus
                               class="form-control">
                    </div>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="aberta"     <?= $status === 'aberta'     ? 'selected' : '' ?>>Aberta</option>
                        <option value="andamento"  <?= $status === 'andamento'  ? 'selected' : '' ?>>Em andamento</option>
                        <option value="pronto"     <?= $status === 'pronto'     ? 'selected' : '' ?>>Pronto</option>
                        <option value="retirado"   <?= $status === 'retirado'   ? 'selected' : '' ?>>Retirado (entregue)</option>
                        <option value="cancelado"  <?= $status === 'cancelado'  ? 'selected' : '' ?>>Cancelado</option>
                        <option value="descartado" <?= $status === 'descartado' ? 'selected' : '' ?>>Descartado</option>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">De</label>
                    <input type="date" name="data_inicio" value="<?= View::e($dtIni) ?>" class="form-control">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Ate</label>
                    <input type="date" name="data_fim" value="<?= View::e($dtFim) ?>" class="form-control">
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2 page-filters__actions">
                    <button type="submit" class="btn btn-primary flex-grow-1 flex-lg-grow-0">
                        <i class="ph ph-funnel me-1"></i> Filtrar
                    </button>
                    <?php if ($temFiltro): ?>
                        <a href="/os" class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela / vazio -->
    <?php if (empty($ordens)): ?>
        <div class="card shadow-sm">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-folder-open"></i></div>
                <h3 class="empty-state__title">Nenhuma OS encontrada</h3>
                <p class="empty-state__desc">
                    <?= $temFiltro ? 'Ajuste os filtros ou ' : 'Comece registrando ' ?>
                    <a href="/os/nova">uma nova ordem de servico</a>.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="d-md-none mobile-records">
            <?php foreach ($ordens as $os): ?>
                <?php
                    $st = $os['status'];
                    $badgeCls = match ($st) {
                        'aberta'     => 'status-badge--info',
                        'andamento'  => 'status-badge--warning',
                        'pronto'     => 'status-badge--success',
                        'retirado'   => 'status-badge--neutral',
                        'cancelado'  => 'status-badge--danger',
                        'descartado' => 'status-badge--warning',
                        default      => 'status-badge--neutral',
                    };
                    $stLabel = match ($st) {
                        'retirado'   => 'Encerrada',
                        'descartado' => 'Descartada',
                        default      => ucfirst($st),
                    };
                ?>
                <div class="mobile-record-card cursor-pointer" onclick="window.location='/os/<?= View::e($os['id']) ?>'">
                    <div class="mobile-record-card__top">
                        <div>
                            <div class="mobile-record-card__title text-mono">#<?= str_pad(View::e($os['id']), 5, '0', STR_PAD_LEFT) ?></div>
                            <div class="mobile-record-card__subtitle text-mono"><?= date('d/m/Y H:i', strtotime($os['created_at'])) ?></div>
                        </div>
                        <i class="ph ph-caret-right mobile-record-card__arrow"></i>
                    </div>

                    <div class="mobile-record-card__body">
                        <div class="mobile-record-card__section">
                            <span class="mobile-record-card__label">Cliente</span>
                            <span class="mobile-record-card__value fw-semibold"><?= View::e($os['nome_cliente']) ?></span>
                        </div>

                        <div class="mobile-record-card__grid">
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Telefone</span>
                                <span class="mobile-record-card__value text-mono small"><?= View::e($os['telefone'] ?: '—') ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Equipamentos</span>
                                <span class="mobile-record-card__value small"><?= (int)($os['total_equipamentos'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="mobile-record-card__footer">
                        <span class="status-badge <?= $badgeCls ?>"><?= View::e($stLabel) ?></span>
                        <div class="mobile-record-card__actions" onclick="event.stopPropagation()">
                            <a href="/os/<?= View::e($os['id']) ?>" class="btn-icon" title="Ver detalhes">
                                <i class="ph ph-eye"></i>
                            </a>
                            <?php if ($st === 'aberta' || $st === 'andamento'): ?>
                                <a href="/os/<?= View::e($os['id']) ?>/editar" class="btn-icon" title="Editar">
                                    <i class="ph ph-pencil-simple"></i>
                                </a>
                            <?php endif; ?>
                            <a href="/os/<?= View::e($os['id']) ?>/imprimir" target="_blank" class="btn-icon" title="Imprimir recibo">
                                <i class="ph ph-printer"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="data-table d-none d-md-block">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>No OS</th>
                        <th>Data/Hora</th>
                        <th>Cliente</th>
                        <th>Telefone</th>
                        <th class="text-center">Equip.</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ordens as $os): ?>
                    <?php
                        $st = $os['status'];
                        $badgeCls = match ($st) {
                            'aberta'     => 'status-badge--info',
                            'andamento'  => 'status-badge--warning',
                            'pronto'     => 'status-badge--success',
                            'retirado'   => 'status-badge--neutral',
                            'cancelado'  => 'status-badge--danger',
                            'descartado' => 'status-badge--warning',
                            default      => 'status-badge--neutral',
                        };
                        $stLabel = match ($st) {
                            'retirado'   => 'Encerrada',
                            'descartado' => 'Descartada',
                            default      => ucfirst($st),
                        };
                    ?>
                    <tr class="cursor-pointer" onclick="window.location='/os/<?= View::e($os['id']) ?>'">
                        <td class="text-nowrap">
                            <span class="text-mono fw-semibold">
                                #<?= str_pad(View::e($os['id']), 5, '0', STR_PAD_LEFT) ?>
                            </span>
                        </td>
                        <td class="text-nowrap text-mono small text-body-secondary">
                            <?= date('d/m/Y H:i', strtotime($os['created_at'])) ?>
                        </td>
                        <td>
                            <div class="fw-medium text-truncate" style="max-width:260px">
                                <?= View::e($os['nome_cliente']) ?>
                            </div>
                        </td>
                        <td class="text-nowrap text-mono small"><?= View::e($os['telefone'] ?: '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)($os['total_equipamentos'] ?? 0) ?></span>
                        </td>
                        <td class="text-center text-nowrap">
                            <span class="status-badge <?= $badgeCls ?>"><?= View::e($stLabel) ?></span>
                        </td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <div class="d-flex justify-content-end gap-1">
                                <a href="/os/<?= View::e($os['id']) ?>" class="btn-icon" title="Ver detalhes">
                                    <i class="ph ph-eye"></i>
                                </a>
                                <?php if ($st === 'aberta' || $st === 'andamento'): ?>
                                    <a href="/os/<?= View::e($os['id']) ?>/editar" class="btn-icon" title="Editar">
                                        <i class="ph ph-pencil-simple"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="/os/<?= View::e($os['id']) ?>/imprimir" target="_blank" class="btn-icon" title="Imprimir recibo">
                                    <i class="ph ph-printer"></i>
                                </a>
                            </div>
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
                $qs   = http_build_query(array_filter(['q'=>$q,'status'=>$status,'data_inicio'=>$dtIni,'data_fim'=>$dtFim]));
                $base = '/os' . ($qs ? "?{$qs}&" : '?');
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
