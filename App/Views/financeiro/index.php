<?php
use App\Core\View;
/**
 * @var array $resumo
 * @var array $aVencer
 * @var array $vencidas
 */
$rea = $resumo['realizado'];
$prv = $resumo['previsto'];
$mes = $resumo['mes_referencia'];

$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt    = fn(string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Financeiro</h1>
            <p class="page-header__subtitle">Resumo do mes <?= View::e($mes) ?> e contas em aberto.</p>
        </div>
        <div class="page-header__actions">
            <a href="/financeiro/receber" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-circle-down me-1"></i> A receber
            </a>
            <a href="/financeiro/pagar" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-circle-up me-1"></i> A pagar
            </a>
            <a href="/financeiro/faturamento" class="btn btn-outline-secondary">
                <i class="ph ph-receipt me-1"></i> Faturamento B2B
            </a>
            <a href="/financeiro/fluxo" class="btn btn-primary">
                <i class="ph ph-chart-line-up me-1"></i> Fluxo de caixa
            </a>
        </div>
    </div>

    <!-- Cards do mes -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
        <div class="col">
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__label">Recebido (mes)</div>
                <div class="kpi-card__value"><?= $money($rea['entradas']) ?></div>
                <div class="kpi-card__sub">Previsto: <?= $money($prv['entradas']) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--danger">
                <div class="kpi-card__label">Pago (mes)</div>
                <div class="kpi-card__value"><?= $money($rea['saidas']) ?></div>
                <div class="kpi-card__sub">Previsto: <?= $money($prv['saidas']) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card <?= $rea['saldo'] >= 0 ? 'kpi-card--info' : 'kpi-card--danger' ?>">
                <div class="kpi-card__label">Saldo realizado</div>
                <div class="kpi-card__value"><?= $money($rea['saldo']) ?></div>
                <div class="kpi-card__sub">Previsto: <?= $money($prv['saldo']) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__label">Contas vencidas</div>
                <div class="kpi-card__value"><?= count($vencidas) ?></div>
                <div class="kpi-card__sub">A vencer (30d): <?= count($aVencer) ?></div>
            </div>
        </div>
    </div>

    <!-- Vencidas -->
    <div class="card shadow-sm">
        <div class="card-header bg-danger-subtle text-danger-emphasis">
            <i class="ph ph-warning me-1"></i> Contas vencidas
        </div>
        <?php if (empty($vencidas)): ?>
            <div class="card-body text-center text-body-secondary py-4">
                Nenhuma conta em atraso.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descricao</th>
                            <th>Contraparte</th>
                            <th>Vencimento</th>
                            <th class="text-center">Atraso</th>
                            <th class="text-end">Valor</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($vencidas, 0, 15) as $v): ?>
                        <tr class="cursor-pointer" onclick="window.location='/financeiro/<?= View::e($v['tipo']) ?>/<?= (int)$v['id'] ?>'">
                            <td class="text-nowrap">
                                <span class="status-badge <?= $v['tipo'] === 'receber' ? 'status-badge--success' : 'status-badge--danger' ?>">
                                    <?= $v['tipo'] === 'receber' ? 'Receber' : 'Pagar' ?>
                                </span>
                            </td>
                            <td class="fw-medium"><?= View::e($v['descricao']) ?></td>
                            <td class="text-body-secondary"><?= View::e($v['contraparte'] ?? '—') ?></td>
                            <td class="text-mono small text-body-secondary text-nowrap"><?= $dt($v['vencimento']) ?></td>
                            <td class="text-center fw-semibold text-danger"><?= (int)$v['dias_atraso'] ?>d</td>
                            <td class="text-end text-mono fw-semibold text-nowrap"><?= $money((float)$v['valor']) ?></td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <a href="/financeiro/<?= View::e($v['tipo']) ?>/<?= (int)$v['id'] ?>" class="btn-icon" title="Ver">
                                    <i class="ph ph-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($vencidas) > 15): ?>
                <div class="card-footer text-body-secondary small">... e mais <?= count($vencidas) - 15 ?> conta(s).</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- A vencer -->
    <div class="card shadow-sm">
        <div class="card-header bg-info-subtle text-info-emphasis">
            <i class="ph ph-calendar me-1"></i> A vencer nos proximos 30 dias
        </div>
        <?php if (empty($aVencer)): ?>
            <div class="card-body text-center text-body-secondary py-4">
                Nenhuma conta com vencimento nos proximos 30 dias.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descricao</th>
                            <th>Contraparte</th>
                            <th>Vencimento</th>
                            <th class="text-end">Valor</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($aVencer, 0, 15) as $v): ?>
                        <tr class="cursor-pointer" onclick="window.location='/financeiro/<?= View::e($v['tipo']) ?>/<?= (int)$v['id'] ?>'">
                            <td class="text-nowrap">
                                <span class="status-badge <?= $v['tipo'] === 'receber' ? 'status-badge--success' : 'status-badge--danger' ?>">
                                    <?= $v['tipo'] === 'receber' ? 'Receber' : 'Pagar' ?>
                                </span>
                            </td>
                            <td class="fw-medium"><?= View::e($v['descricao']) ?></td>
                            <td class="text-body-secondary"><?= View::e($v['contraparte'] ?? '—') ?></td>
                            <td class="text-mono small text-body-secondary text-nowrap"><?= $dt($v['vencimento']) ?></td>
                            <td class="text-end text-mono fw-semibold text-nowrap"><?= $money((float)$v['valor']) ?></td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <a href="/financeiro/<?= View::e($v['tipo']) ?>/<?= (int)$v['id'] ?>" class="btn-icon" title="Ver">
                                    <i class="ph ph-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($aVencer) > 15): ?>
                <div class="card-footer text-body-secondary small">... e mais <?= count($aVencer) - 15 ?> conta(s).</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
