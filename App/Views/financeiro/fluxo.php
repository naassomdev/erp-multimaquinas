<?php
use App\Core\View;
/**
 * @var array  $fluxo
 * @var string $de
 * @var string $ate
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dias  = max(1, (int) ((strtotime($ate) - strtotime($de)) / 86400) + 1);
$saldoPositivo = $fluxo['saldo'] >= 0;
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-chart-line-up me-2 text-primary"></i> Fluxo de Caixa
            </h1>
            <p class="page-header__subtitle">
                Periodo: <span class="text-mono"><?= date('d/m/Y', strtotime($de)) ?></span>
                <i class="ph ph-arrow-right"></i>
                <span class="text-mono"><?= date('d/m/Y', strtotime($ate)) ?></span>
                <span class="text-body-secondary">&middot; <?= $dias ?> dia<?= $dias > 1 ? 's' : '' ?></span>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/financeiro" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <!-- Filtro de periodo -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/financeiro/fluxo" class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label">De</label>
                    <input type="date" name="de" value="<?= View::e($de) ?>" required class="form-control">
                </div>
                <div class="col-auto">
                    <label class="form-label">Ate</label>
                    <input type="date" name="ate" value="<?= View::e($ate) ?>" required class="form-control">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-arrows-clockwise me-1"></i> Atualizar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cards: entradas / saidas / saldo -->
    <div class="row row-cols-1 row-cols-sm-3 g-3">
        <div class="col">
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__label">Entradas (recebido)</div>
                <div class="kpi-card__value"><?= $money((float)$fluxo['entradas']) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card kpi-card--danger">
                <div class="kpi-card__label">Saidas (pago)</div>
                <div class="kpi-card__value"><?= $money((float)$fluxo['saidas']) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card <?= $saldoPositivo ? 'kpi-card--info' : 'kpi-card--danger' ?>">
                <div class="kpi-card__label">Saldo</div>
                <div class="kpi-card__value"><?= $money((float)$fluxo['saldo']) ?></div>
            </div>
        </div>
    </div>

    <!-- Nota explicativa -->
    <div class="alert alert-info d-flex gap-3">
        <i class="ph ph-info fs-5 flex-shrink-0 mt-1"></i>
        <div class="small">
            O fluxo de caixa considera apenas lancamentos com status <strong>pago</strong>
            cuja <strong>data_pagamento</strong> esteja dentro do periodo. Lancamentos em
            aberto (previstos) nao entram aqui — para eles, veja o resumo do mes na
            <a href="/financeiro" class="alert-link">pagina inicial do Financeiro</a>.
        </div>
    </div>
</div>
