<?php
use App\Core\View;
use App\Core\Auth;
/** @var array $usuario */
/** @var int   $totalClientes */
/** @var int   $totalOsAbertas */
/** @var int   $totalOrcamentos */
/** @var int   $osHoje */
/** @var int   $retiradasHoje */
/** @var float $receitaHoje */
/** @var float $receitaMes */
/** @var float $aReceber */
/** @var int   $necessidadesPendentes */
/** @var array $ultimasOs */

$nivel  = $usuario['nivel_acesso'] ?? '';
$fmtBrl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$fmtOsId = static function (mixed $id): string {
    $id = trim((string) $id);
    if ($id === '') {
        return 'OS';
    }

    return preg_match('/^\d+$/', $id) === 1
        ? 'OS-' . str_pad($id, 5, '0', STR_PAD_LEFT)
        : $id;
};

/**
 * Renderiza um card KPI padronizado com BEM.
 */
$kpi = function (string $href, string $label, string $value, string $icon, string $tone, string $hint = ''): string {
    $variants = [
        'primary' => 'kpi-card--brand',
        'success' => 'kpi-card--success',
        'warning' => 'kpi-card--warning',
        'neutral' => 'kpi-card--info',
    ];
    $cls = $variants[$tone] ?? 'kpi-card--brand';
    return sprintf(
        '<div class="col"><a href="%1$s" class="kpi-card %2$s text-decoration-none">
            <div class="d-flex align-items-start justify-content-between mb-3">
                <span class="kpi-card__label">%3$s</span>
                <span class="kpi-card__icon"><i class="%4$s"></i></span>
            </div>
            <div class="kpi-card__value">%5$s</div>
            <div class="kpi-card__hint">%6$s</div>
        </a></div>',
        htmlspecialchars($href, ENT_QUOTES),
        $cls,
        htmlspecialchars($label),
        htmlspecialchars($icon, ENT_QUOTES),
        htmlspecialchars($value),
        htmlspecialchars($hint),
    );
};
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Visao Geral</h1>
            <p class="page-header__subtitle">
                Ola, <strong><?= View::e($usuario['nome'] ?? '') ?></strong> —
                resumo das atividades e metricas do sistema.
            </p>
        </div>
        <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
        <div class="page-header__actions">
            <a href="/clientes/novo" class="btn btn-outline-secondary">
                <i class="ph ph-user-plus me-1"></i> Novo Cliente
            </a>
            <a href="/os/nova" class="btn btn-primary">
                <i class="ph ph-plus me-1"></i> Nova OS
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- KPIs -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 g-3">
        <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
            <?= $kpi('/os', 'OS Abertas',
                number_format($totalOsAbertas ?? 0, 0, ',', '.'),
                'ph ph-file-text', 'primary', 'Ordens em andamento') ?>

            <?= $kpi('/os', 'OS Hoje',
                number_format($osHoje ?? 0, 0, ',', '.'),
                'ph ph-clipboard-text', 'neutral', 'Entradas do dia') ?>

            <?= $kpi('/os', 'Retiradas Hoje',
                number_format($retiradasHoje ?? 0, 0, ',', '.'),
                'ph ph-package', 'success', 'Aparelhos entregues') ?>
        <?php endif; ?>

        <?php if ($nivel === 'admin'): ?>
            <?= $kpi('/financeiro/receber', 'Receita Hoje',
                $fmtBrl((float)($receitaHoje ?? 0)),
                'ph ph-currency-dollar', 'success', 'Valor recebido') ?>

            <?= $kpi('/financeiro/receber', 'Receita do Mes',
                $fmtBrl((float)($receitaMes ?? 0)),
                'ph ph-trend-up', 'neutral', 'Acumulado no periodo') ?>

            <?= $kpi('/financeiro/receber', 'A Receber',
                $fmtBrl((float)($aReceber ?? 0)),
                'ph ph-clock', 'warning', 'Faturas em aberto') ?>
        <?php endif; ?>

        <?= $kpi('/orcamento', 'Orcamentos Pendentes',
            number_format($totalOrcamentos ?? 0, 0, ',', '.'),
            'ph ph-file-text', 'primary', 'Aguardando aprovacao') ?>

        <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
            <?= $kpi('/clientes', 'Clientes Ativos',
                number_format($totalClientes ?? 0, 0, ',', '.'),
                'ph ph-users', 'neutral', 'Cadastrados no sistema') ?>
        <?php endif; ?>

        <?php if ($nivel === 'admin' && ($necessidadesPendentes ?? 0) > 0): ?>
            <div class="col">
                <a href="/estoque/importar" class="kpi-card kpi-card--danger text-decoration-none">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <span class="kpi-card__label">Pecas sob Encomenda</span>
                        <span class="kpi-card__icon"><i class="ph ph-shopping-cart"></i></span>
                    </div>
                    <div class="kpi-card__value"><?= number_format($necessidadesPendentes, 0, ',', '.') ?></div>
                    <div class="kpi-card__hint text-danger fw-medium">Requer atencao</div>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Ultimas OS -->
    <div class="card shadow-sm">
        <div class="card-header">
            <i class="ph ph-list"></i>
            <span class="flex-grow-1">Ultimas Ordens de Servico</span>
            <a href="/os" class="text-decoration-none small fw-medium">
                Ver todas <i class="ph ph-arrow-right"></i>
            </a>
        </div>

        <div class="d-md-none mobile-records p-3">
            <?php if (empty($ultimasOs)): ?>
                <div class="empty-state py-4">
                    <div class="empty-state__icon"><i class="ph ph-tray"></i></div>
                    <h3 class="empty-state__title">Nenhuma OS encontrada</h3>
                </div>
            <?php else: ?>
                <?php foreach ($ultimasOs as $os): ?>
                    <?php
                    $status = $os['status'] ?? '';
                    $badgeCls = match ($status) {
                        'aberta'                              => 'status-badge--info',
                        'andamento'                           => 'status-badge--warning',
                        'aguardando_peca'                     => 'status-badge--brand',
                        'concluida', 'pronto_para_retirada'   => 'status-badge--success',
                        'entregue', 'retirado'                => 'status-badge--neutral',
                        'cancelada'                           => 'status-badge--danger',
                        default                               => 'status-badge--neutral',
                    };
                    ?>
                    <a href="/os/<?= rawurlencode((string) $os['id']) ?>" class="mobile-record-card">
                        <div class="mobile-record-card__top">
                            <div>
                                <div class="mobile-record-card__title"><?= View::e($fmtOsId($os['id'])) ?></div>
                                <div class="mobile-record-card__subtitle text-mono"><?= date('d/m/Y', strtotime($os['created_at'])) ?></div>
                            </div>
                            <i class="ph ph-caret-right mobile-record-card__arrow"></i>
                        </div>

                        <div class="mobile-record-card__body">
                            <div class="mobile-record-card__section">
                                <span class="mobile-record-card__label">Cliente</span>
                                <span class="mobile-record-card__value fw-semibold"><?= View::e($os['cliente'] ?? 'Cliente nao info.') ?></span>
                            </div>
                            <div class="mobile-record-card__section">
                                <span class="mobile-record-card__label">Equipamento</span>
                                <span class="mobile-record-card__value small"><?= View::e($os['equipamento'] ?? 'N/D') ?></span>
                            </div>
                        </div>

                        <div class="mobile-record-card__footer">
                            <span class="status-badge <?= $badgeCls ?>">
                                <?= strtoupper(str_replace('_', ' ', $status)) ?>
                            </span>
                            <span class="mobile-record-card__hint fw-semibold"><?= $fmtBrl((float)($os['valor_total'] ?? 0)) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>No OS</th>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th class="text-end">Valor</th>
                        <th class="text-center">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ultimasOs)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="empty-state__icon"><i class="ph ph-tray"></i></div>
                                    <h3 class="empty-state__title">Nenhuma OS encontrada</h3>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ultimasOs as $os): ?>
                            <?php
                            $status = $os['status'] ?? '';
                            $badgeCls = match ($status) {
                                'aberta'                              => 'status-badge--info',
                                'andamento'                           => 'status-badge--warning',
                                'aguardando_peca'                     => 'status-badge--brand',
                                'concluida', 'pronto_para_retirada'   => 'status-badge--success',
                                'entregue', 'retirado'                => 'status-badge--neutral',
                                'cancelada'                           => 'status-badge--danger',
                                default                               => 'status-badge--neutral',
                            };
                            ?>
                            <tr class="cursor-pointer" onclick="window.location='/os/<?= rawurlencode((string) $os['id']) ?>'">
                                <td class="fw-medium text-nowrap">
                                    <a href="/os/<?= rawurlencode((string) $os['id']) ?>" class="text-decoration-none">
                                        <?= View::e($fmtOsId($os['id'])) ?>
                                    </a>
                                </td>
                                <td class="text-truncate" style="max-width:220px">
                                    <?= View::e($os['cliente'] ?? 'Cliente nao info.') ?>
                                </td>
                                <td class="text-body-secondary text-truncate" style="max-width:220px">
                                    <?= View::e($os['equipamento'] ?? 'N/D') ?>
                                </td>
                                <td class="text-nowrap">
                                    <span class="status-badge <?= $badgeCls ?>">
                                        <?= strtoupper(str_replace('_', ' ', $status)) ?>
                                    </span>
                                </td>
                                <td class="text-body-secondary text-nowrap text-mono small">
                                    <?= date('d/m/Y', strtotime($os['created_at'])) ?>
                                </td>
                                <td class="text-end fw-medium text-nowrap">
                                    <?= $fmtBrl((float)($os['valor_total'] ?? 0)) ?>
                                </td>
                                <td class="text-center" onclick="event.stopPropagation()">
                                    <a href="/os/<?= rawurlencode((string) $os['id']) ?>" class="btn-icon" title="Ver detalhes">
                                        <i class="ph ph-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
