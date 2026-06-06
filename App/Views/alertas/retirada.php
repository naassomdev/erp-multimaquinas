<?php
use App\Core\Csrf;
use App\Core\View;

/**
 * @var array  $pendentes    Lista de equipamentos pendentes (um registro por equipamento)
 * @var array  $contadores   {total, normal, armazenamento, abandono, renotificar}
 * @var string $filtroNivel  Filtro atual (normal|armazenamento|abandono|'')
 * @var array  $filtros      {q:string, nivel:string, status:string, renotificar:bool}
 */
$csrfToken = Csrf::token();
$filtros = array_merge([
    'q'           => '',
    'nivel'       => '',
    'status'      => '',
    'renotificar' => false,
], is_array($filtros ?? null) ? $filtros : []);

$buildFilterUrl = static function (array $overrides = []) use ($filtros): string {
    $query = array_merge([
        'q'           => $filtros['q'] ?? '',
        'nivel'       => $filtros['nivel'] ?? '',
        'status'      => $filtros['status'] ?? '',
        'renotificar' => !empty($filtros['renotificar']) ? '1' : '',
    ], $overrides);

    $query = array_filter($query, static fn ($value): bool => $value !== '' && $value !== null && $value !== false);
    return '/alertas/retirada' . (!empty($query) ? '?' . http_build_query($query) : '');
};

$filtrosAtivos = [];
if (($filtros['q'] ?? '') !== '') {
    $filtrosAtivos[] = 'Busca: ' . (string) $filtros['q'];
}
if (($filtros['nivel'] ?? '') !== '') {
    $map = [
        'normal'        => 'Dentro do prazo',
        'armazenamento' => 'Taxa de armazenamento',
        'abandono'      => 'Abandono legal',
    ];
    $filtrosAtivos[] = 'Nivel: ' . ($map[(string) $filtros['nivel']] ?? (string) $filtros['nivel']);
}
if (($filtros['status'] ?? '') !== '') {
    $map = [
        'pronto'    => 'Pronto',
        'cancelado' => 'Cancelado',
    ];
    $filtrosAtivos[] = 'Status: ' . ($map[(string) $filtros['status']] ?? (string) $filtros['status']);
}
if (!empty($filtros['renotificar'])) {
    $filtrosAtivos[] = 'Somente renotificar';
}

$grupos = [];
$equipCriticos = 0;
$equipRenotificar = 0;

foreach ($pendentes as $item) {
    $osId = (string) ($item['os_id'] ?? '');
    if ($osId === '') {
        continue;
    }

    if (!isset($grupos[$osId])) {
        $grupos[$osId] = [
            'os_id'               => $osId,
            'nome_cliente'        => (string) ($item['nome_cliente'] ?? ''),
            'telefone'            => (string) ($item['telefone'] ?? ''),
            'total_notificacoes'  => (int) ($item['total_notificacoes'] ?? 0),
            'ultima_notificacao'  => $item['ultima_notificacao'] ?? null,
            'equipamentos'        => [],
            'total'               => 0,
            'normal'              => 0,
            'armazenamento'       => 0,
            'abandono'            => 0,
            'renotificar'         => 0,
            'max_dias'            => 0,
            'taxa_total'          => 0.0,
        ];
    }

    $grupos[$osId]['equipamentos'][] = $item;
    $grupos[$osId]['total']++;
    $grupos[$osId]['max_dias'] = max($grupos[$osId]['max_dias'], (int) ($item['dias_aguardando'] ?? 0));
    $grupos[$osId]['taxa_total'] += (float) ($item['taxa_armazenamento'] ?? 0);

    $nivel = (string) ($item['nivel'] ?? 'normal');
    if (isset($grupos[$osId][$nivel])) {
        $grupos[$osId][$nivel]++;
    }

    if ($nivel !== 'normal') {
        $equipCriticos++;
    }
    if (!empty($item['renotificar'])) {
        $grupos[$osId]['renotificar']++;
        $equipRenotificar++;
    }
}

$grupos = array_values($grupos);
usort($grupos, static function (array $a, array $b): int {
    $prioridade = static function (array $grupo): int {
        if (($grupo['abandono'] ?? 0) > 0) return 3;
        if (($grupo['armazenamento'] ?? 0) > 0) return 2;
        return 1;
    };

    $cmp = $prioridade($b) <=> $prioridade($a);
    if ($cmp !== 0) {
        return $cmp;
    }

    $cmp = ((int) ($b['max_dias'] ?? 0)) <=> ((int) ($a['max_dias'] ?? 0));
    if ($cmp !== 0) {
        return $cmp;
    }

    return strcmp((string) ($a['os_id'] ?? ''), (string) ($b['os_id'] ?? ''));
});

$osVisiveis = count($grupos);
$equipVisiveis = count($pendentes);
$temFiltrosAtivos = !empty($filtrosAtivos);
?>

<div class="alerta-retirada-page d-flex flex-column gap-4">

    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-warning"></i> Alertas de Retirada
            </h1>
            <p class="page-header__subtitle">Fila operacional para contato, taxa de armazenamento e abandono legal.</p>
        </div>
    </div>

    <div class="row row-cols-2 row-cols-md-4 row-cols-xl-5 g-3">
        <div class="col">
            <a href="<?= $buildFilterUrl(['nivel' => '']) ?>" class="text-decoration-none">
                <div class="kpi-card <?= ($filtros['nivel'] ?? '') === '' ? 'kpi-card--active' : '' ?>">
                    <span class="kpi-card__value"><?= $contadores['total'] ?></span>
                    <span class="kpi-card__label">Total Pendentes</span>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="<?= $buildFilterUrl(['nivel' => 'normal']) ?>" class="text-decoration-none">
                <div class="kpi-card kpi-card--success <?= ($filtros['nivel'] ?? '') === 'normal' ? 'kpi-card--active' : '' ?>">
                    <span class="kpi-card__value"><?= $contadores['normal'] ?></span>
                    <span class="kpi-card__label"><i class="ph ph-check-circle text-success"></i> Dentro do Prazo</span>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="<?= $buildFilterUrl(['nivel' => 'armazenamento']) ?>" class="text-decoration-none">
                <div class="kpi-card kpi-card--warning <?= ($filtros['nivel'] ?? '') === 'armazenamento' ? 'kpi-card--active' : '' ?>">
                    <span class="kpi-card__value"><?= $contadores['armazenamento'] ?></span>
                    <span class="kpi-card__label"><i class="ph ph-warning text-warning"></i> Tx. Armazenamento</span>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="<?= $buildFilterUrl(['nivel' => 'abandono']) ?>" class="text-decoration-none">
                <div class="kpi-card kpi-card--danger <?= ($filtros['nivel'] ?? '') === 'abandono' ? 'kpi-card--active' : '' ?>">
                    <span class="kpi-card__value"><?= $contadores['abandono'] ?></span>
                    <span class="kpi-card__label"><i class="ph ph-x-circle text-danger"></i> Abandono Legal</span>
                </div>
            </a>
        </div>
        <?php if ($contadores['renotificar'] > 0): ?>
        <div class="col">
            <a href="<?= $buildFilterUrl(['renotificar' => '1']) ?>" class="text-decoration-none">
                <div class="kpi-card kpi-card--info <?= !empty($filtros['renotificar']) ? 'kpi-card--active' : '' ?>">
                    <span class="kpi-card__value"><?= $contadores['renotificar'] ?></span>
                    <span class="kpi-card__label"><i class="ph ph-megaphone"></i> Precisam Renotificacao</span>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <section class="card shadow-sm alerta-retirada-filters">
        <div class="card-body">
            <div class="alerta-retirada-filters__header">
                <div>
                    <span class="alerta-retirada-filters__eyebrow">Triagem</span>
                    <h2 class="alerta-retirada-filters__title">Filtrar a fila de retirada</h2>
                    <p class="alerta-retirada-filters__subtitle">Busque por OS, cliente, telefone, documento ou nome do equipamento.</p>
                </div>
                <?php if ($temFiltrosAtivos): ?>
                    <a href="/alertas/retirada" class="btn btn-outline-secondary">Limpar filtros</a>
                <?php endif; ?>
            </div>

            <form method="GET" action="/alertas/retirada" class="alerta-retirada-filter-form">
                <div class="alerta-retirada-filter-form__search">
                    <label for="retiradaBusca" class="form-label">Buscar</label>
                    <input
                        id="retiradaBusca"
                        type="text"
                        name="q"
                        class="form-control"
                        value="<?= View::e((string) ($filtros['q'] ?? '')) ?>"
                        placeholder="OS, cliente, telefone, CPF/CNPJ ou equipamento">
                </div>

                <div>
                    <label for="retiradaNivel" class="form-label">Nivel</label>
                    <select id="retiradaNivel" name="nivel" class="form-select">
                        <option value="">Todos</option>
                        <option value="normal" <?= ($filtros['nivel'] ?? '') === 'normal' ? 'selected' : '' ?>>Dentro do prazo</option>
                        <option value="armazenamento" <?= ($filtros['nivel'] ?? '') === 'armazenamento' ? 'selected' : '' ?>>Taxa de armazenamento</option>
                        <option value="abandono" <?= ($filtros['nivel'] ?? '') === 'abandono' ? 'selected' : '' ?>>Abandono legal</option>
                    </select>
                </div>

                <div>
                    <label for="retiradaStatus" class="form-label">Status do equipamento</label>
                    <select id="retiradaStatus" name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="pronto" <?= ($filtros['status'] ?? '') === 'pronto' ? 'selected' : '' ?>>Pronto</option>
                        <option value="cancelado" <?= ($filtros['status'] ?? '') === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>

                <label class="alerta-retirada-filter-form__toggle">
                    <input type="checkbox" name="renotificar" value="1" <?= !empty($filtros['renotificar']) ? 'checked' : '' ?>>
                    <span>Somente os que precisam renotificação</span>
                </label>

                <div class="alerta-retirada-filter-form__actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-funnel me-1"></i> Aplicar
                    </button>
                    <a href="/alertas/retirada" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>

            <?php if ($temFiltrosAtivos): ?>
                <div class="alerta-retirada-filter-tags">
                    <?php foreach ($filtrosAtivos as $tag): ?>
                        <span class="alerta-retirada-filter-tag"><?= View::e($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="alerta-retirada-overview">
        <article class="alerta-retirada-overview__card">
            <span class="alerta-retirada-overview__label">OS visiveis</span>
            <strong class="alerta-retirada-overview__value"><?= number_format($osVisiveis, 0, ',', '.') ?></strong>
            <span class="alerta-retirada-overview__hint">Agrupadas para evitar repeticao quando a mesma OS tem muitos equipamentos.</span>
        </article>
        <article class="alerta-retirada-overview__card">
            <span class="alerta-retirada-overview__label">Equipamentos</span>
            <strong class="alerta-retirada-overview__value"><?= number_format($equipVisiveis, 0, ',', '.') ?></strong>
            <span class="alerta-retirada-overview__hint">Itens filtrados na fila atual.</span>
        </article>
        <article class="alerta-retirada-overview__card">
            <span class="alerta-retirada-overview__label">Criticos</span>
            <strong class="alerta-retirada-overview__value"><?= number_format($equipCriticos, 0, ',', '.') ?></strong>
            <span class="alerta-retirada-overview__hint">Equipamentos com taxa ativa ou abandono legal.</span>
        </article>
        <article class="alerta-retirada-overview__card">
            <span class="alerta-retirada-overview__label">Renotificar</span>
            <strong class="alerta-retirada-overview__value"><?= number_format($equipRenotificar, 0, ',', '.') ?></strong>
            <span class="alerta-retirada-overview__hint">Ajuda a puxar follow-up sem abrir OS por OS.</span>
        </article>
    </div>

    <?php if (empty($pendentes)): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="ph ph-check-circle"></i></div>
            <?php if ($temFiltrosAtivos): ?>
                <h4 class="empty-state__title">Nenhum equipamento encontrado</h4>
                <p class="empty-state__desc">Os filtros atuais nao retornaram itens. <a href="/alertas/retirada">Ver a fila completa</a>.</p>
            <?php else: ?>
                <h4 class="empty-state__title">Tudo em dia!</h4>
                <p class="empty-state__desc">Nenhum equipamento pendente de retirada ou destino físico.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <section class="alerta-retirada-groups">
            <?php foreach ($grupos as $grupo): ?>
                <?php
                $grupoId = 'grupo-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $grupo['os_id']);
                $topNivel = ($grupo['abandono'] ?? 0) > 0
                    ? 'abandono'
                    : ((($grupo['armazenamento'] ?? 0) > 0) ? 'armazenamento' : 'normal');

                $waTel = preg_replace('/\D/', '', $grupo['telefone'] ?? '');
                if (strlen($waTel) === 10 || strlen($waTel) === 11) {
                    $waTel = '55' . $waTel;
                }

                $primeiroNome = trim(strtok((string) ($grupo['nome_cliente'] ?? ''), ' ') ?: '');
                $equipamentosLista = [];
                foreach ($grupo['equipamentos'] as $equipamentoOs) {
                    $equipamentosLista[] = '*#' . (int) $equipamentoOs['equip_num'] . ': ' . ((string) ($equipamentoOs['equip_nome'] ?? 'Equipamento')) . '*';
                }

                $waReforco  = "Ola, *{$primeiroNome}*!\n\n";
                $waReforco .= "Lembramos que a OS *#{$grupo['os_id']}* possui *" . count($grupo['equipamentos']) . " equipamento(s)* aguardando retirada em nossa loja.\n\n";
                $waReforco .= "Equipamentos:\n" . implode("\n", $equipamentosLista) . "\n\n";
                $waReforco .= "Tempo maximo aguardando: *" . (int) $grupo['max_dias'] . " dias*.\n";
                if (($grupo['abandono'] ?? 0) > 0) {
                    $waReforco .= "ATENCAO: Ha equipamento(s) com prazo legal ultrapassado, sujeito(s) a abandono legal.\n";
                } elseif (($grupo['armazenamento'] ?? 0) > 0) {
                    $waReforco .= "Informamos que a taxa de armazenamento esta em vigor para parte desta OS.\n";
                }
                $waReforco .= "\nPor favor, entre em contato para agendar a retirada.\n— Multimaquinas";
                $waLink = $waTel !== '' ? "https://wa.me/{$waTel}?text=" . rawurlencode($waReforco) : '';
                ?>
                <article class="card shadow-sm alerta-retirada-group alerta-retirada-group--<?= View::e($topNivel) ?>">
                    <div class="card-body">
                        <div class="alerta-retirada-group__header">
                            <div class="alerta-retirada-group__identity">
                                <div class="alerta-retirada-group__eyebrow">
                                    <a href="/os/<?= rawurlencode((string) $grupo['os_id']) ?>" class="text-decoration-none">
                                        OS #<?= View::e((string) $grupo['os_id']) ?>
                                    </a>
                                    <span>&middot;</span>
                                    <span><?= (int) $grupo['total'] ?> equipamento(s)</span>
                                </div>
                                <h2 class="alerta-retirada-group__title"><?= View::e((string) $grupo['nome_cliente']) ?></h2>
                                <div class="alerta-retirada-group__meta">
                                    <?php if ((string) ($grupo['telefone'] ?? '') !== ''): ?>
                                        <span><i class="ph ph-phone"></i> <?= View::e((string) $grupo['telefone']) ?></span>
                                    <?php endif; ?>
                                    <span><i class="ph ph-megaphone"></i> <?= (int) $grupo['total_notificacoes'] ?> notificacao(oes)</span>
                                    <?php if (!empty($grupo['ultima_notificacao'])): ?>
                                        <span><i class="ph ph-clock-counter-clockwise"></i> Ultima em <?= date('d/m/Y', strtotime((string) $grupo['ultima_notificacao'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="alerta-retirada-group__stats">
                                <div class="alerta-retirada-group__stat">
                                    <span class="alerta-retirada-group__stat-label">Maior espera</span>
                                    <strong class="alerta-retirada-group__stat-value"><?= (int) $grupo['max_dias'] ?> dias</strong>
                                </div>
                                <?php if ((float) $grupo['taxa_total'] > 0): ?>
                                    <div class="alerta-retirada-group__stat">
                                        <span class="alerta-retirada-group__stat-label">Taxa acumulada</span>
                                        <strong class="alerta-retirada-group__stat-value">R$ <?= number_format((float) $grupo['taxa_total'], 2, ',', '.') ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="alerta-retirada-group__chips">
                            <span class="alerta-retirada-chip alerta-retirada-chip--<?= View::e($topNivel) ?>">
                                <?= $topNivel === 'abandono' ? 'Abandono legal na OS' : ($topNivel === 'armazenamento' ? 'Taxa ativa na OS' : 'Dentro do prazo') ?>
                            </span>
                            <?php if (($grupo['abandono'] ?? 0) > 0): ?>
                                <span class="alerta-retirada-chip alerta-retirada-chip--danger"><?= (int) $grupo['abandono'] ?> em abandono</span>
                            <?php endif; ?>
                            <?php if (($grupo['armazenamento'] ?? 0) > 0): ?>
                                <span class="alerta-retirada-chip alerta-retirada-chip--warning"><?= (int) $grupo['armazenamento'] ?> com taxa</span>
                            <?php endif; ?>
                            <?php if (($grupo['normal'] ?? 0) > 0): ?>
                                <span class="alerta-retirada-chip alerta-retirada-chip--success"><?= (int) $grupo['normal'] ?> no prazo</span>
                            <?php endif; ?>
                            <?php if (($grupo['renotificar'] ?? 0) > 0): ?>
                                <span class="alerta-retirada-chip alerta-retirada-chip--info"><?= (int) $grupo['renotificar'] ?> pedem follow-up</span>
                            <?php endif; ?>
                        </div>

                        <div class="alerta-retirada-group__toolbar">
                            <div class="alerta-retirada-group__actions">
                                <?php if ($waLink !== ''): ?>
                                    <a href="<?= $waLink ?>" target="_blank" class="btn btn-sm btn-wpp"
                                       onclick="registrarNotif('<?= View::e((string) $grupo['os_id']) ?>', 'whatsapp')">
                                        <i class="ph ph-whatsapp-logo"></i> WhatsApp
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-registrar-ligacao"
                                        data-os="<?= View::e((string) $grupo['os_id']) ?>"
                                        data-cliente="<?= View::e((string) $grupo['nome_cliente']) ?>">
                                    <i class="ph ph-phone"></i> Registrar Ligacao
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-anexar-print"
                                        data-os="<?= View::e((string) $grupo['os_id']) ?>">
                                    <i class="ph ph-paperclip"></i> Anexar Comprovante
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-ver-historico"
                                        data-os="<?= View::e((string) $grupo['os_id']) ?>">
                                    <i class="ph ph-clipboard-text"></i> Historico
                                </button>
                                <a href="/os/<?= rawurlencode((string) $grupo['os_id']) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="ph ph-arrow-square-out"></i> Abrir OS
                                </a>
                            </div>

                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary alerta-retirada-group__toggle"
                                    data-role="toggle-group"
                                    data-target="<?= View::e($grupoId) ?>">
                                <i class="ph ph-caret-up"></i> Ocultar equipamentos
                            </button>
                        </div>

                        <div class="alerta-retirada-group__equipments" id="<?= View::e($grupoId) ?>">
                            <?php foreach ($grupo['equipamentos'] as $item): ?>
                                <?php
                                $nivelClass = match ((string) ($item['nivel'] ?? 'normal')) {
                                    'abandono'      => 'alerta-retirada-equip-card--abandono',
                                    'armazenamento' => 'alerta-retirada-equip-card--armazenamento',
                                    default         => 'alerta-retirada-equip-card--normal',
                                };
                                $statusEqLabel = match ((string) ($item['status_equip'] ?? '')) {
                                    'pronto'    => 'Pronto',
                                    'cancelado' => 'Cancelado',
                                    default     => ucfirst((string) ($item['status_equip'] ?? '')),
                                };
                                $statusEqVariant = ((string) ($item['status_equip'] ?? '')) === 'cancelado'
                                    ? '--danger'
                                    : '--success';
                                $equipNumVisual = (int) ($item['equip_num'] ?? 0);
                                ?>
                                <article class="alerta-retirada-equip-card <?= $nivelClass ?>">
                                    <div class="alerta-retirada-equip-card__top">
                                        <div>
                                            <div class="alerta-retirada-equip-card__index">Equipamento <?= $equipNumVisual ?></div>
                                            <h3 class="alerta-retirada-equip-card__title"><?= View::e((string) ($item['equip_nome'] ?: '—')) ?></h3>
                                        </div>
                                        <div class="alerta-retirada-equip-card__badges">
                                            <span class="status-badge status-badge<?= $statusEqVariant ?>"><?= View::e($statusEqLabel) ?></span>
                                            <span class="alerta-retirada-chip alerta-retirada-chip--<?= View::e((string) ($item['nivel'] ?? 'normal')) ?>">
                                                <?= View::e((string) ($item['nivel_label'] ?? '')) ?>
                                            </span>
                                            <?php if (!empty($item['renotificar'])): ?>
                                                <span class="alerta-retirada-chip alerta-retirada-chip--info alerta-retirada-chip--pulse">
                                                    <i class="ph ph-megaphone"></i> Renotificar
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="alerta-retirada-equip-card__details">
                                        <div class="alerta-retirada-equip-card__detail">
                                            <span class="alerta-retirada-equip-card__detail-label">Aguardando</span>
                                            <strong class="alerta-retirada-equip-card__detail-value"><?= (int) ($item['dias_aguardando'] ?? 0) ?> dias</strong>
                                        </div>
                                        <div class="alerta-retirada-equip-card__detail">
                                            <span class="alerta-retirada-equip-card__detail-label">Prazo base</span>
                                            <strong class="alerta-retirada-equip-card__detail-value"><?= (int) ($item['prazo_retirada'] ?? 0) ?> dias</strong>
                                        </div>
                                        <?php if (!empty($item['data_base'])): ?>
                                        <?php $dataBaseFontaEquip = !empty($item['status_equip_em']); ?>
                                        <div class="alerta-retirada-equip-card__detail">
                                            <span class="alerta-retirada-equip-card__detail-label"
                                                  title="<?= $dataBaseFontaEquip ? 'Desde status do equipamento' : 'Data estimada da OS (sem registro individual)' ?>">
                                                <?= $dataBaseFontaEquip ? 'Desde status' : 'Data base (OS)' ?>
                                            </span>
                                            <strong class="alerta-retirada-equip-card__detail-value">
                                                <?= date('d/m/Y', strtotime((string) $item['data_base'])) ?>
                                            </strong>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ((float) ($item['taxa_armazenamento'] ?? 0) > 0): ?>
                                            <div class="alerta-retirada-equip-card__detail">
                                                <span class="alerta-retirada-equip-card__detail-label">Taxa</span>
                                                <strong class="alerta-retirada-equip-card__detail-value">R$ <?= number_format((float) $item['taxa_armazenamento'], 2, ',', '.') ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['ultima_notificacao'])): ?>
                                            <div class="alerta-retirada-equip-card__detail">
                                                <span class="alerta-retirada-equip-card__detail-label">Ultimo contato</span>
                                                <strong class="alerta-retirada-equip-card__detail-value"><?= date('d/m/Y', strtotime((string) $item['ultima_notificacao'])) ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ((string) ($item['nivel'] ?? '') === 'abandono'): ?>
                                        <div class="alerta-retirada-equip-card__footer">
                                            <button type="button" class="btn btn-sm btn-danger btn-descarte-equip"
                                                    data-os="<?= View::e((string) $item['os_id']) ?>"
                                                    data-equip-idx="<?= (int) $item['equip_idx'] ?>"
                                                    data-equip-num="<?= $equipNumVisual ?>"
                                                    data-equip-nome="<?= View::e((string) $item['equip_nome']) ?>"
                                                    data-cliente="<?= View::e((string) $item['nome_cliente']) ?>">
                                                <i class="ph ph-trash"></i> Descartar Equipamento
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div id="historico-<?= View::e((string) $grupo['os_id']) ?>" hidden class="alerta-retirada-history">
                            <div class="small text-body-secondary">Carregando historico...</div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

</div>

<!-- Modal: Registrar Ligacao -->
<div class="modal fade" id="modalLigacao" tabindex="-1" aria-labelledby="modalLigacaoLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalLigacaoLabel"><i class="ph ph-phone"></i> Registrar Ligacao</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="text-body-secondary" id="ligacaoCliente"></p>
                <input type="hidden" id="ligacaoOsId">
                <div class="mb-3">
                    <label class="form-label">Observacao</label>
                    <textarea id="ligacaoObs" class="form-control" rows="3" placeholder="Ex: Falou que busca semana que vem, nao atendeu..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnSalvarLigacao">Salvar Registro</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Anexar Comprovante -->
<div class="modal fade" id="modalComprovante" tabindex="-1" aria-labelledby="modalComprovanteLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalComprovanteLabel"><i class="ph ph-paperclip"></i> Anexar Comprovante</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary">Anexe um print da tela do WhatsApp (mostrando tiques azuis, telefone e data) como prova de notificacao.</p>
                <input type="hidden" id="comprovanteOsId">
                <div class="mb-3">
                    <label class="form-label">Arquivo (imagem)</label>
                    <input type="file" id="comprovanteFile" class="form-control" accept="image/*" capture="environment">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnSalvarComprovante">Enviar Comprovante</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= View::e($csrfToken) ?>';
const modalLigacao = new bootstrap.Modal(document.getElementById('modalLigacao'));
const modalComprovante = new bootstrap.Modal(document.getElementById('modalComprovante'));

// Registrar notificacao (apos clique no WhatsApp — por OS)
function registrarNotif(osId, tipo, obs = '') {
    fetch(`/api/alertas/retirada/${osId}/notificar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ _csrf: CSRF, tipo, mensagem: 'Notificacao de retirada enviada via ' + tipo, obs })
    }).catch(err => console.error('Erro ao registrar notificacao:', err));
}

// Registrar Ligacao
document.querySelectorAll('.btn-registrar-ligacao').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('ligacaoOsId').value = btn.dataset.os;
        document.getElementById('ligacaoCliente').textContent = 'Cliente: ' + btn.dataset.cliente;
        document.getElementById('ligacaoObs').value = '';
        modalLigacao.show();
    });
});

document.getElementById('btnSalvarLigacao')?.addEventListener('click', async () => {
    const osId = document.getElementById('ligacaoOsId').value;
    const obs  = document.getElementById('ligacaoObs').value;
    const btn  = document.getElementById('btnSalvarLigacao');
    btn.disabled    = true;
    btn.textContent = 'Salvando...';

    try {
        const res  = await fetch(`/api/alertas/retirada/${osId}/notificar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ _csrf: CSRF, tipo: 'ligacao', mensagem: 'Contato telefonico com o cliente.', obs })
        });
        const json = await res.json();
        if (json.ok) {
            modalLigacao.hide();
            window.location.reload();
        } else {
            alert('Erro: ' + json.error);
        }
    } catch (e) {
        alert('Erro de conexao.');
    }
    btn.disabled    = false;
    btn.textContent = 'Salvar Registro';
});

// Anexar Comprovante
document.querySelectorAll('.btn-anexar-print').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('comprovanteOsId').value = btn.dataset.os;
        document.getElementById('comprovanteFile').value  = '';
        modalComprovante.show();
    });
});

document.getElementById('btnSalvarComprovante')?.addEventListener('click', async () => {
    const osId      = document.getElementById('comprovanteOsId').value;
    const fileInput = document.getElementById('comprovanteFile');
    const file      = fileInput.files[0];
    if (!file) { alert('Selecione um arquivo.'); return; }

    const btn       = document.getElementById('btnSalvarComprovante');
    btn.disabled    = true;
    btn.textContent = 'Enviando...';

    try {
        const notifRes  = await fetch(`/api/alertas/retirada/${osId}/notificar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ _csrf: CSRF, tipo: 'sistema', mensagem: 'Comprovante de notificacao anexado.', obs: '' })
        });
        const notifJson = await notifRes.json();
        if (!notifJson.ok) { alert('Erro ao criar registro.'); return; }

        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('notificacao_id', notifJson.id);
        fd.append('comprovante', file);

        const res  = await fetch(`/api/alertas/retirada/${osId}/comprovante`, { method: 'POST', body: fd });
        const json = await res.json();
        if (json.ok) {
            modalComprovante.hide();
            window.location.reload();
        } else {
            alert('Erro: ' + json.error);
        }
    } catch (e) {
        alert('Erro de conexao.');
    }
    btn.disabled    = false;
    btn.textContent = 'Enviar Comprovante';
});

// Descarte por equipamento (por abandono legal)
document.querySelectorAll('.btn-descarte-equip').forEach(btn => {
    btn.addEventListener('click', async () => {
        const osId      = btn.dataset.os;
        const equipIdx  = btn.dataset.equipIdx;
        const equipNum  = btn.dataset.equipNum;
        const equipNome = btn.dataset.equipNome;
        const cliente   = btn.dataset.cliente;

        const confirm1 = confirm(
            `ATENCAO: Deseja registrar o DESCARTE do equipamento #${equipNum} (${equipNome}) ` +
            `da OS #${osId} — ${cliente}?\n\n` +
            `Esta acao descartara APENAS este equipamento, nao a OS inteira.\n` +
            `Baseado no Art. 1.275, III do Codigo Civil — abandono legal apos 90 dias.`
        );
        if (!confirm1) return;

        const confirm2 = confirm(
            `CONFIRMACAO FINAL: Tem certeza absoluta que deseja marcar como DESCARTADO?\n\n` +
            `Equipamento: #${equipNum} — ${equipNome}\n` +
            `OS: #${osId}\n\n` +
            `Esta acao e irreversivel.`
        );
        if (!confirm2) return;

        btn.disabled    = true;
        btn.textContent = 'Processando...';

        try {
            const res  = await fetch(`/api/alertas/retirada/${osId}/equip/${equipIdx}/descarte`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ _csrf: CSRF })
            });
            const json = await res.json();
            if (json.ok) {
                window.location.reload();
            } else {
                alert('Erro: ' + json.error);
                btn.disabled = false;
                btn.innerHTML = '<i class="ph ph-trash"></i> Descartar Equipamento';
            }
        } catch (e) {
            alert('Erro de conexao.');
            btn.disabled = false;
            btn.innerHTML = '<i class="ph ph-trash"></i> Descartar Equipamento';
        }
    });
});

// Ver Historico (toggle + link para pagina da OS)
document.querySelectorAll('.btn-ver-historico').forEach(btn => {
    btn.addEventListener('click', () => {
        const osId = btn.dataset.os;
        const histDiv = document.getElementById('historico-' + osId);
        if (!histDiv) return;

        const oculto = histDiv.hasAttribute('hidden');
        if (!oculto) {
            histDiv.setAttribute('hidden', '');
            btn.innerHTML = '<i class="ph ph-clipboard-text"></i> Historico';
            return;
        }

        histDiv.removeAttribute('hidden');
        btn.innerHTML = '<i class="ph ph-clipboard-text"></i> Ocultar';
        histDiv.innerHTML = `
            <div class="small text-body-secondary p-2">
                <p>Para ver o historico completo de notificacoes, acesse a <a href="/os/${osId}" class="text-primary">pagina da OS #${osId}</a>.</p>
            </div>
        `;
    });
});

// Colapsar/expandir equipamentos por OS
document.querySelectorAll('[data-role="toggle-group"]').forEach(btn => {
    btn.addEventListener('click', () => {
        const targetId = btn.dataset.target;
        const target = document.getElementById(targetId);
        if (!target) return;

        const oculto = target.hasAttribute('hidden');
        if (oculto) {
            target.removeAttribute('hidden');
            btn.innerHTML = '<i class="ph ph-caret-up"></i> Ocultar equipamentos';
            return;
        }

        target.setAttribute('hidden', '');
        btn.innerHTML = '<i class="ph ph-caret-down"></i> Mostrar equipamentos';
    });
});
</script>
