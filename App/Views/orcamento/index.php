<?php
use App\Core\View;

/** @var array $usuario */
/** @var string $busca */
/** @var array<int, array<string, mixed>> $resultados */
/** @var ?string $tipo_busca */
/** @var array<int, array<string, mixed>> $pendentes */

$fmtBrl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$pendentesTotal = 0.0;
$pendentesPagos = 0;
$pendentesOs = [];
$pendentesClientes = [];
$resultadosClientes = [];
$resultadosEquipamentos = 0;
$resultadosAbertos = 0;

foreach ($pendentes as $orc) {
    $pendentesTotal += (float) ($orc['total'] ?? 0);
    if ((int) ($orc['pago'] ?? 0) === 1) {
        $pendentesPagos++;
    }
    $pendentesOs[(string) ($orc['os_id'] ?? '')] = true;
    $pendentesClientes[(string) ($orc['nome_cliente'] ?? '')] = true;
}

foreach ($resultados as $os) {
    $resultadosClientes[(string) ($os['nome_cliente'] ?? '')] = true;
    $resultadosEquipamentos += (int) ($os['qtd_equipamentos'] ?? 0);
    if (in_array(strtolower((string) ($os['status'] ?? '')), ['aberta', 'andamento'], true)) {
        $resultadosAbertos++;
    }
}
?>

<div class="orcamento-index-page d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Orcamentos</h1>
            <p class="page-header__subtitle"><?= View::e($usuario['nome'] ?? '') ?></p>
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

    <!-- Busca principal -->
    <div class="card shadow-sm">
        <div class="card-header"><i class="ph ph-magnifying-glass"></i> Buscar</div>
        <div class="card-body">
            <form method="GET" action="/orcamento" class="row g-3 align-items-end">
                <div class="col">
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="search" name="q" value="<?= View::e($busca) ?>"
                               placeholder="Telefone ou codigo da OS" autofocus autocomplete="off"
                               class="form-control">
                    </div>
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2 page-filters__actions">
                    <button type="submit" class="btn btn-primary flex-grow-1 flex-lg-grow-0">
                        <i class="ph ph-magnifying-glass me-1"></i> Buscar
                    </button>
                    <?php if ($busca !== ''): ?>
                        <a href="/orcamento" class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>
            <p class="text-body-secondary small mt-3 mb-0">
                Digite o codigo exato da OS (ex.: <code class="text-mono">OS-0001</code>) — abre direto.
                Telefone com ou sem mascara — lista todas as OSs do cliente.
            </p>
        </div>
    </div>

    <!-- Resultados da busca -->
    <?php if ($busca !== ''): ?>
        <section class="orcamento-search d-flex flex-column gap-4">
            <div class="orcamento-search__header">
                <div>
                    <span class="orcamento-search__eyebrow">Consulta atual</span>
                    <h2 class="orcamento-search__title">Resultados para "<?= View::e($busca) ?>"</h2>
                    <p class="orcamento-search__subtitle">
                        Abra a OS correta ou refine a busca quando houver mais de um atendimento do mesmo cliente.
                    </p>
                </div>
                <span class="status-badge status-badge--info"><?= number_format(count($resultados), 0, ',', '.') ?> resultado(s)</span>
            </div>

            <?php if (empty($resultados)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="ph ph-folder-open"></i></div>
                    <h3 class="empty-state__title">Nenhuma OS encontrada</h3>
                    <p class="empty-state__desc">
                        Nenhuma OS encontrada para <strong><?= View::e($busca) ?></strong>.
                        <?php if ($tipo_busca === 'desconhecida'): ?>
                            <br>Tente um numero de telefone ou codigo de OS exato.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="orcamento-search-overview">
                    <article class="orcamento-search-kpi">
                        <span class="orcamento-search-kpi__label">OS encontradas</span>
                        <strong class="orcamento-search-kpi__value"><?= number_format(count($resultados), 0, ',', '.') ?></strong>
                        <span class="orcamento-search-kpi__hint">Lista retornada pela busca atual.</span>
                    </article>
                    <article class="orcamento-search-kpi">
                        <span class="orcamento-search-kpi__label">Clientes</span>
                        <strong class="orcamento-search-kpi__value"><?= number_format(count($resultadosClientes), 0, ',', '.') ?></strong>
                        <span class="orcamento-search-kpi__hint">Ajuda quando o telefone traz mais de um cadastro.</span>
                    </article>
                    <article class="orcamento-search-kpi">
                        <span class="orcamento-search-kpi__label">Equipamentos</span>
                        <strong class="orcamento-search-kpi__value"><?= number_format($resultadosEquipamentos, 0, ',', '.') ?></strong>
                        <span class="orcamento-search-kpi__hint">Total somado das OS visíveis.</span>
                    </article>
                    <article class="orcamento-search-kpi">
                        <span class="orcamento-search-kpi__label">Em andamento</span>
                        <strong class="orcamento-search-kpi__value"><?= number_format($resultadosAbertos, 0, ',', '.') ?></strong>
                        <span class="orcamento-search-kpi__hint">OS abertas ou em execução.</span>
                    </article>
                </div>

                <div class="orcamento-search-grid">
                    <?php foreach ($resultados as $os): ?>
                        <?php
                            $st = strtolower($os['status']);
                            $badgeCls = match ($st) {
                                'aberta'     => 'status-badge--info',
                                'andamento'  => 'status-badge--warning',
                                'pronto'     => 'status-badge--success',
                                'retirado'   => 'status-badge--neutral',
                                'cancelado'  => 'status-badge--danger',
                                'descartado' => 'status-badge--warning',
                                default      => 'status-badge--neutral',
                            };
                        ?>
                        <a href="/orcamento/<?= rawurlencode((string) $os['id']) ?>" class="orcamento-search-card">
                            <div class="orcamento-search-card__top">
                                <div>
                                    <div class="orcamento-search-card__os">
                                        <span class="text-mono"><?= View::e((string) $os['id']) ?></span>
                                    </div>
                                    <div class="orcamento-search-card__date text-mono"><?= View::e((string) $os['data_entrada']) ?></div>
                                </div>
                                <i class="ph ph-arrow-up-right orcamento-search-card__arrow"></i>
                            </div>

                            <div class="orcamento-search-card__body">
                                <div class="orcamento-search-card__client">
                                    <span class="orcamento-search-card__label">Cliente</span>
                                    <strong class="orcamento-search-card__client-name"><?= View::e((string) $os['nome_cliente']) ?></strong>
                                </div>
                                <div class="orcamento-search-card__meta">
                                    <div class="orcamento-search-card__meta-item">
                                        <span class="orcamento-search-card__label">Telefone</span>
                                        <span class="orcamento-search-card__value text-mono"><?= View::e((string) $os['telefone']) ?></span>
                                    </div>
                                    <div class="orcamento-search-card__meta-item">
                                        <span class="orcamento-search-card__label">Equipamentos</span>
                                        <span class="orcamento-search-card__value"><?= (int) ($os['qtd_equipamentos'] ?? 0) ?></span>
                                    </div>
                                </div>
                                <div class="orcamento-search-card__summary">
                                    <span class="orcamento-search-card__label">Resumo</span>
                                    <span class="orcamento-search-card__summary-text"><?= View::e((string) ($os['equipamentos'] ?? '—')) ?></span>
                                </div>
                            </div>

                            <div class="orcamento-search-card__footer">
                                <span class="status-badge <?= $badgeCls ?>"><?= View::e((string) $os['status']) ?></span>
                                <span class="orcamento-search-card__action">Abrir OS</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Inbox de pendentes -->
    <section class="orcamento-inbox d-flex flex-column gap-4">
        <div class="orcamento-inbox__header">
            <div>
                <span class="orcamento-inbox__eyebrow">Fila operacional</span>
                <h2 class="orcamento-inbox__title">Equipamentos aguardando aprovação</h2>
                <p class="orcamento-inbox__subtitle">
                    Priorize a abertura por equipamento para acelerar retorno ao cliente.
                </p>
            </div>
            <div class="orcamento-inbox__badge">
                <span class="status-badge status-badge--warning"><?= count($pendentes) ?> pendente(s)</span>
            </div>
        </div>

        <div class="orcamento-inbox-overview">
            <article class="orcamento-inbox-kpi">
                <span class="orcamento-inbox-kpi__label">Pendências</span>
                <strong class="orcamento-inbox-kpi__value"><?= number_format(count($pendentes), 0, ',', '.') ?></strong>
                <span class="orcamento-inbox-kpi__hint">Equipamentos esperando aprovação do cliente.</span>
            </article>
            <article class="orcamento-inbox-kpi">
                <span class="orcamento-inbox-kpi__label">Total em aberto</span>
                <strong class="orcamento-inbox-kpi__value"><?= $fmtBrl($pendentesTotal) ?></strong>
                <span class="orcamento-inbox-kpi__hint">Soma dos orçamentos listados abaixo.</span>
            </article>
            <article class="orcamento-inbox-kpi">
                <span class="orcamento-inbox-kpi__label">OS na fila</span>
                <strong class="orcamento-inbox-kpi__value"><?= number_format(count($pendentesOs), 0, ',', '.') ?></strong>
                <span class="orcamento-inbox-kpi__hint"><?= number_format(count($pendentesClientes), 0, ',', '.') ?> cliente(s) distintos.</span>
            </article>
            <article class="orcamento-inbox-kpi">
                <span class="orcamento-inbox-kpi__label">Marcados como pagos</span>
                <strong class="orcamento-inbox-kpi__value"><?= number_format($pendentesPagos, 0, ',', '.') ?></strong>
                <span class="orcamento-inbox-kpi__hint">Ajuda a separar aprovação de baixa financeira.</span>
            </article>
        </div>

        <?php if (empty($pendentes)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-check-circle"></i></div>
                <h3 class="empty-state__title">Nenhum orcamento pendente</h3>
                <p class="empty-state__desc">Bom trabalho!</p>
            </div>
        <?php else: ?>
            <div class="orcamento-inbox-grid">
                <?php foreach ($pendentes as $orc): ?>
                    <?php
                        $diasAguardando = isset($orc['dias_aguardando']) && $orc['dias_aguardando'] !== null
                            ? (int) $orc['dias_aguardando']
                            : null;
                        $followupBadge = null;
                        if ($diasAguardando !== null && (string) $orc['status'] === 'enviado') {
                            if ($diasAguardando >= 7) {
                                $followupBadge = ['cls' => 'status-badge--danger', 'txt' => "{$diasAguardando}d — Follow-up urgente"];
                            } elseif ($diasAguardando >= 3) {
                                $followupBadge = ['cls' => 'status-badge--warning', 'txt' => "{$diasAguardando}d — Follow-up recomendado"];
                            } else {
                                $followupBadge = ['cls' => 'status-badge--info', 'txt' => "{$diasAguardando}d — Aguardando resposta"];
                            }
                        }
                    ?>
                    <a href="/orcamento/<?= rawurlencode((string) $orc['os_id']) ?>#equip-<?= (int) $orc['equip_idx'] ?>" class="orcamento-inbox-card">
                        <div class="orcamento-inbox-card__top">
                            <div>
                                <div class="orcamento-inbox-card__os">
                                    <span class="text-mono"><?= View::e((string) $orc['os_id']) ?></span>
                                    <span class="orcamento-inbox-card__equip">Equip. #<?= (int) $orc['equip_idx'] ?></span>
                                </div>
                                <div class="orcamento-inbox-card__title"><?= View::e((string) ($orc['equip_nome'] ?? '—')) ?></div>
                            </div>
                            <i class="ph ph-arrow-up-right orcamento-inbox-card__arrow"></i>
                        </div>

                        <div class="orcamento-inbox-card__body">
                            <div class="orcamento-inbox-card__client">
                                <span class="orcamento-inbox-card__label">Cliente</span>
                                <strong class="orcamento-inbox-card__client-name"><?= View::e((string) $orc['nome_cliente']) ?></strong>
                                <?php if (!empty($orc['telefone'])): ?>
                                    <span class="orcamento-inbox-card__phone text-mono"><?= View::e((string) $orc['telefone']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="orcamento-inbox-card__meta">
                                <div class="orcamento-inbox-card__meta-item">
                                    <span class="orcamento-inbox-card__label">Total</span>
                                    <span class="orcamento-inbox-card__value text-success"><?= $fmtBrl((float) $orc['total']) ?></span>
                                </div>
                                <div class="orcamento-inbox-card__meta-item">
                                    <span class="orcamento-inbox-card__label">
                                        <?= (string) $orc['status'] === 'enviado' && !empty($orc['wpp_enviado_em']) ? 'Enviado em' : 'Atualizado' ?>
                                    </span>
                                    <span class="orcamento-inbox-card__value">
                                        <?php if ((string) $orc['status'] === 'enviado' && !empty($orc['wpp_enviado_em'])): ?>
                                            <?= date('d/m/Y H:i', strtotime((string) $orc['wpp_enviado_em'])) ?>
                                        <?php else: ?>
                                            <?= View::e((string) $orc['updated_at']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="orcamento-inbox-card__footer">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="status-badge status-badge--warning"><?= View::e((string) $orc['status']) ?></span>
                                <?php if ($followupBadge !== null): ?>
                                    <span class="status-badge <?= $followupBadge['cls'] ?>"><?= View::e($followupBadge['txt']) ?></span>
                                <?php endif; ?>
                                <?php if ((int) $orc['pago'] === 1): ?>
                                    <span class="status-badge status-badge--success">pago</span>
                                <?php endif; ?>
                            </div>
                            <span class="orcamento-inbox-card__action">Abrir orçamento</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
