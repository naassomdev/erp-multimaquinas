<?php
use App\Core\View;

/** @var list<array<string, mixed>> $registros */
/** @var int $total */
/** @var array<string, int|string> $kpis */
/** @var list<string> $fabricantes */
/** @var array<string, string> $filtros */
/** @var int $pagina */
/** @var int $totalPaginas */
/** @var bool $temFiltro */

$statusEquipLabels = [
    'aberta'     => 'Aberta',
    'andamento'  => 'Em andamento',
    'montagem'   => 'Montagem',
    'pronto'     => 'Pronto',
    'retirado'   => 'Retirado',
    'devolvido'  => 'Devolvido',
    'descartado' => 'Descartado',
    'cancelado'  => 'Cancelado',
];

$statusEquipBadge = [
    'aberta'     => 'status-badge--neutral',
    'andamento'  => 'status-badge--info',
    'montagem'   => 'status-badge--info',
    'pronto'     => 'status-badge--success',
    'retirado'   => 'status-badge--success',
    'devolvido'  => 'status-badge--neutral',
    'descartado' => 'status-badge--neutral',
    'cancelado'  => 'status-badge--danger',
];

$orcStatusLabels = [
    'rascunho'  => 'Rascunho',
    'enviado'   => 'Enviado',
    'aprovado'  => 'Aprovado',
    'cancelado' => 'Cancelado',
];

$orcStatusBadge = [
    'rascunho'  => 'status-badge--neutral',
    'enviado'   => 'status-badge--info',
    'aprovado'  => 'status-badge--success',
    'cancelado' => 'status-badge--danger',
];

$motivoLabels = [
    'garantia_fabricante' => 'Garantia fab.',
    'cortesia'            => 'Cortesia',
];

$fmtData = static function (?string $d): string {
    if ($d === null || $d === '') return '—';
    $ts = strtotime($d);
    return $ts !== false ? date('d/m/Y', $ts) : $d;
};
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabeçalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-shield-check me-2 text-primary"></i>Garantias de fabricante
            </h1>
            <p class="page-header__subtitle">
                Equipamentos atendidos em garantia de fabricante
                <?= $total > 0 ? "— <strong>{$total}</strong> registro(s)" : '' ?>
            </p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/relatorios/garantias-fabricante" class="row g-3 align-items-end">

                <div class="col-lg-2">
                    <label class="form-label">De (abertura OS)</label>
                    <input type="date" name="de" value="<?= View::e($filtros['de']) ?>" class="form-control">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Ate</label>
                    <input type="date" name="ate" value="<?= View::e($filtros['ate']) ?>" class="form-control">
                </div>

                <div class="col-lg-2">
                    <label class="form-label">Fabricante</label>
                    <select name="fabricante" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($fabricantes as $fab): ?>
                            <option value="<?= View::e($fab) ?>"
                                <?= $filtros['fabricante'] === $fab ? 'selected' : '' ?>>
                                <?= View::e($fab) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2">
                    <label class="form-label">Status fisico</label>
                    <select name="status_equip" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($statusEquipLabels as $val => $lbl): ?>
                            <option value="<?= $val ?>"
                                <?= $filtros['status_equip'] === $val ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2">
                    <label class="form-label">Motivo gratuidade</label>
                    <select name="motivo_gratuidade" class="form-select">
                        <option value="">Todos</option>
                        <option value="garantia_fabricante"
                            <?= $filtros['motivo_gratuidade'] === 'garantia_fabricante' ? 'selected' : '' ?>>
                            Garantia fabricante
                        </option>
                        <option value="cortesia"
                            <?= $filtros['motivo_gratuidade'] === 'cortesia' ? 'selected' : '' ?>>
                            Cortesia
                        </option>
                        <option value="nao_informado"
                            <?= $filtros['motivo_gratuidade'] === 'nao_informado' ? 'selected' : '' ?>>
                            Nao informado
                        </option>
                    </select>
                </div>

                <div class="col-lg-2">
                    <label class="form-label">Autorizacao / RMA</label>
                    <select name="autorizacao" class="form-select">
                        <option value="">Todos</option>
                        <option value="com" <?= $filtros['autorizacao'] === 'com' ? 'selected' : '' ?>>
                            Com autorizacao
                        </option>
                        <option value="sem" <?= $filtros['autorizacao'] === 'sem' ? 'selected' : '' ?>>
                            Sem autorizacao
                        </option>
                    </select>
                </div>

                <div class="col-lg-auto d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-funnel me-1"></i> Filtrar
                    </button>
                    <?php if ($temFiltro): ?>
                        <a href="/relatorios/garantias-fabricante" class="btn btn-outline-secondary">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                    <?php
                    $exportParams = array_filter($filtros, static fn($v) => $v !== '');
                    $exportUrl = '/relatorios/garantias-fabricante/exportar'
                        . ($exportParams !== [] ? '?' . http_build_query($exportParams) : '');
                    ?>
                    <a href="<?= View::e($exportUrl) ?>" class="btn btn-outline-success">
                        <i class="ph ph-file-csv me-1"></i> Exportar CSV
                    </a>
                </div>

            </form>
        </div>
    </div>

    <!-- KPIs -->
    <?php if (!empty($kpis)): ?>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-6 g-3">
        <div class="col">
            <div class="kpi-card">
                <div class="kpi-card__label">Total garantia fab.</div>
                <div class="kpi-card__value"><?= (int) ($kpis['total'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__label">Retirados / concluidos</div>
                <div class="kpi-card__value"><?= (int) ($kpis['retirados'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__label">Em aberto / andamento</div>
                <div class="kpi-card__value"><?= (int) ($kpis['em_aberto'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card <?= ((int) ($kpis['sem_autorizacao'] ?? 0)) > 0 ? 'kpi-card--warning' : '' ?>">
                <div class="kpi-card__label">Sem autorizacao / RMA</div>
                <div class="kpi-card__value"><?= (int) ($kpis['sem_autorizacao'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card">
                <div class="kpi-card__label">Orcamento R$ 0</div>
                <div class="kpi-card__value"><?= (int) ($kpis['total_zero'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__label">Motivo: garantia fab.</div>
                <div class="kpi-card__value"><?= (int) ($kpis['motivo_garantia_fab'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabela -->
    <?php if (empty($registros)): ?>
        <div class="empty-state">
            <i class="ph ph-shield-check empty-state__icon"></i>
            <p class="empty-state__text">Nenhum equipamento em garantia de fabricante encontrado.</p>
            <?php if ($temFiltro): ?>
                <a href="/relatorios/garantias-fabricante" class="btn btn-outline-secondary btn-sm">
                    <i class="ph ph-x me-1"></i> Limpar filtros
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="data-table">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>OS</th>
                        <th>Equipamento</th>
                        <th>Cliente</th>
                        <th>Fabricante</th>
                        <th>Autorizacao / RMA</th>
                        <th>Status fisico</th>
                        <th class="text-center">Orcamento</th>
                        <th class="text-end">Total</th>
                        <th>Motivo gratuidade</th>
                        <th>Retirada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $r): ?>
                    <?php
                    $se     = (string) ($r['status_equip'] ?? '');
                    $orcSt  = (string) ($r['orc_status']  ?? '');
                    $motivo = (string) ($r['motivo_gratuidade'] ?? '');
                    $total  = $r['orc_total'] !== null ? (float) $r['orc_total'] : null;
                    ?>
                    <tr>
                        <td class="text-mono small text-nowrap">
                            <a href="/os/<?= View::e((string) $r['os_id']) ?>"
                               class="text-decoration-none fw-medium text-primary">
                                <?= View::e((string) $r['os_id']) ?>
                            </a>
                            <div class="text-body-secondary small"><?= $fmtData((string) ($r['os_criado_em'] ?? '')) ?></div>
                        </td>
                        <td>
                            <div class="fw-medium"><?= View::e((string) $r['equip_nome']) ?></div>
                            <?php if ((int) $r['equip_idx'] > 0): ?>
                                <div class="text-body-secondary small">Equip. #<?= (int) $r['equip_idx'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= View::e((string) $r['nome_cliente']) ?></div>
                            <?php if (!empty($r['telefone'])): ?>
                                <div class="text-body-secondary small text-mono"><?= View::e((string) $r['telefone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-mono small"><?= View::e((string) $r['fabricante']) ?></td>
                        <td>
                            <?php if (!empty($r['garantia_autorizacao'])): ?>
                                <span class="status-badge status-badge--secondary text-mono">
                                    <i class="ph ph-hash me-1"></i><?= View::e((string) $r['garantia_autorizacao']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-body-secondary small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <span class="status-badge <?= $statusEquipBadge[$se] ?? 'status-badge--neutral' ?>">
                                <?= View::e($statusEquipLabels[$se] ?? $se) ?>
                            </span>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php if ($orcSt !== ''): ?>
                                <span class="status-badge <?= $orcStatusBadge[$orcSt] ?? 'status-badge--neutral' ?>">
                                    <?= View::e($orcStatusLabels[$orcSt] ?? $orcSt) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-body-secondary small">sem orc.</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-mono text-nowrap">
                            <?php if ($total !== null): ?>
                                <?php if ($total == 0.0): ?>
                                    <span class="text-success fw-medium">R$ 0,00</span>
                                <?php else: ?>
                                    R$ <?= number_format($total, 2, ',', '.') ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-body-secondary small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($motivo !== '' && isset($motivoLabels[$motivo])): ?>
                                <span class="status-badge <?= $motivo === 'garantia_fabricante' ? 'status-badge--info' : 'status-badge--neutral' ?>">
                                    <i class="ph ph-tag me-1"></i><?= View::e($motivoLabels[$motivo]) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-body-secondary small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap small text-body-secondary">
                            <?= $fmtData((string) ($r['data_retirada'] ?? '')) ?>
                            <?php if (!empty($r['retirado_por'])): ?>
                                <div><?= View::e((string) $r['retirado_por']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap" onclick="event.stopPropagation()">
                            <a href="/os/<?= View::e((string) $r['os_id']) ?>"
                               class="btn-icon" title="Abrir OS">
                                <i class="ph ph-file-text"></i>
                            </a>
                            <?php if (!empty($r['orc_id'])): ?>
                                <a href="/orcamento/<?= View::e((string) $r['os_id']) ?>"
                                   class="btn-icon" title="Abrir orcamento">
                                    <i class="ph ph-currency-dollar"></i>
                                </a>
                            <?php endif; ?>
                            <a href="/tecnico/os/<?= View::e((string) $r['os_id']) ?>/equipamento/<?= (int) $r['equip_idx'] ?>"
                               class="btn-icon" title="Painel tecnico">
                                <i class="ph ph-wrench"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginacao -->
            <?php if ($totalPaginas > 1): ?>
            <?php
            $buildUrl = static function (int $p) use ($filtros): string {
                $q = array_filter($filtros, static fn($v) => $v !== '');
                $q['p'] = $p;
                return '/relatorios/garantias-fabricante?' . http_build_query($q);
            };
            ?>
            <div class="data-table__footer">
                <div>Pagina <strong><?= $pagina ?></strong> de <strong><?= $totalPaginas ?></strong>
                    &nbsp;·&nbsp; <?= $total ?> registro(s)</div>
                <nav aria-label="Paginacao">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= View::e($buildUrl($pagina - 1)) ?>">
                                <i class="ph ph-caret-left"></i>
                            </a>
                        </li>
                        <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): ?>
                            <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                                <a class="page-link" href="<?= View::e($buildUrl($i)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= View::e($buildUrl($pagina + 1)) ?>">
                                <i class="ph ph-caret-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

</div>
