<?php
use App\Core\View;
/**
 * @var array  $relatorios
 * @var string $statusFiltro
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt    = fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';

$totalRascunho   = 0;
$totalFinalizado = 0;
$valorRascunho   = 0.0;
$valorFinalizado = 0.0;
foreach ($relatorios as $r) {
    if (($r['status'] ?? '') === 'finalizado') {
        $totalFinalizado++;
        $valorFinalizado += (float) ($r['valor_total'] ?? 0);
    } else {
        $totalRascunho++;
        $valorRascunho += (float) ($r['valor_total'] ?? 0);
    }
}
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Faturamento B2B</h1>
            <p class="page-header__subtitle">
                <?= count($relatorios) ?> relatório(s) — agrupamento de OSs faturadas para emissão de NFS-e.
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/financeiro" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="/financeiro/faturamento/novo" class="btn btn-primary">
                <i class="ph ph-plus me-1"></i> Novo relatório
            </a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row row-cols-1 row-cols-sm-3 g-3">
        <div class="col">
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__label">Em rascunho</div>
                <div class="kpi-card__value"><?= $totalRascunho ?></div>
                <div class="kpi-card__sub">Soma: <span class="text-mono"><?= $money($valorRascunho) ?></span></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__label">Finalizados</div>
                <div class="kpi-card__value"><?= $totalFinalizado ?></div>
                <div class="kpi-card__sub">Soma: <span class="text-mono"><?= $money($valorFinalizado) ?></span></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__label">Total faturado</div>
                <div class="kpi-card__value text-mono"><?= $money($valorRascunho + $valorFinalizado) ?></div>
                <div class="kpi-card__sub"><?= count($relatorios) ?> relatório(s)</div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/financeiro/faturamento" class="row g-3 align-items-end">
                <div class="col-lg-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value=""           <?= $statusFiltro === ''           ? 'selected' : '' ?>>Todos</option>
                        <option value="rascunho"   <?= $statusFiltro === 'rascunho'   ? 'selected' : '' ?>>Em rascunho</option>
                        <option value="finalizado" <?= $statusFiltro === 'finalizado' ? 'selected' : '' ?>>Finalizado</option>
                    </select>
                </div>
                <div class="col-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-funnel me-1"></i> Filtrar
                    </button>
                    <?php if ($statusFiltro !== ''): ?>
                        <a href="/financeiro/faturamento" class="btn btn-outline-secondary">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela / vazio -->
    <?php if (empty($relatorios)): ?>
        <div class="card shadow-sm">
            <div class="card-body py-5">
                <div class="empty-state">
                    <div class="empty-state__icon">
                        <i class="ph ph-receipt"></i>
                    </div>
                    <h3 class="empty-state__title">Nenhum relatório encontrado</h3>
                    <p class="empty-state__desc">
                        <?= $statusFiltro !== '' ? 'Não há relatórios com o status selecionado.' : 'Comece agrupando OSs faturadas em um novo relatório.' ?>
                    </p>
                    <a href="/financeiro/faturamento/novo" class="btn btn-primary mt-3">
                        <i class="ph ph-plus me-1"></i> Criar primeiro relatório
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>N.o</th>
                            <th>Cliente</th>
                            <th>PO / Pedido</th>
                            <th>Criado em</th>
                            <th class="text-center">OSs</th>
                            <th class="text-end">Valor total</th>
                            <th class="text-center">Status</th>
                            <th style="width:48px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($relatorios as $r):
                        $finalizado = ($r['status'] ?? '') === 'finalizado';
                        $badgeCls = $finalizado ? 'status-badge--success' : 'status-badge--warning';
                        $badgeTxt = $finalizado ? 'Finalizado' : 'Rascunho';
                        $badgeIcon = $finalizado ? 'check-circle' : 'file-text';
                    ?>
                        <tr>
                            <td class="text-mono small fw-semibold text-nowrap">
                                <a href="/financeiro/faturamento/<?= (int)$r['id'] ?>" class="text-decoration-none">
                                    #<?= (int)$r['id'] ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-medium"><?= View::e($r['cliente_nome'] ?? '—') ?></div>
                                <div class="small text-body-secondary">Cliente #<?= (int)($r['cliente_id'] ?? 0) ?></div>
                            </td>
                            <td class="text-mono text-nowrap">
                                <?= View::e($r['numero_po'] ?? '—') ?>
                            </td>
                            <td class="small text-mono text-body-secondary text-nowrap">
                                <?= $dt($r['criado_em'] ?? null) ?>
                            </td>
                            <td class="text-center">
                                <span class="status-badge status-badge--info">
                                    <?= (int)($r['qtd_os'] ?? 0) ?>
                                </span>
                            </td>
                            <td class="text-end text-mono fw-medium text-nowrap">
                                <?= $money((float)($r['valor_total'] ?? 0)) ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <span class="status-badge <?= $badgeCls ?>">
                                    <i class="ph ph-<?= $badgeIcon ?>"></i>
                                    <?= $badgeTxt ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="/financeiro/faturamento/<?= (int)$r['id'] ?>"
                                   class="btn-icon" title="Ver relatório">
                                    <i class="ph ph-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
