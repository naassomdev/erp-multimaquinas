<?php
use App\Core\Auth;
use App\Core\View;

/** @var array $os */
/** @var array<int, array<string, mixed>> $equipamentos */
/** @var array<int, array<string, mixed>> $orcamento_por_equip */
/** @var array<int, int> $necessidades_por_equip */
/** @var array<int, array<int, array<string, mixed>>> $servicos_terceiros_por_equip */
/** @var string $csrf_token */

$podePreAprovar = Auth::temNivel('admin', 'recepcao');
$podeReverterCancelamento = Auth::temNivel('admin');

$fmtBrl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

$equipInfoLinha = static function (array $equip): string {
    $partes = [];
    foreach (['fabricante', 'modelo'] as $campo) {
        $valor = trim((string) ($equip[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }
    return implode(' · ', $partes);
};

$fotosDiagnostico = static function (array $equip): array {
    $raw = (string) ($equip['fotos_json'] ?? '');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $urls = [];
    foreach ($decoded as $url) {
        if (!is_string($url)) {
            continue;
        }
        $url = trim($url);
        if ($url === '') {
            continue;
        }
        if (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $urls[$url] = $url;
    }

    return array_values($urls);
};

$servicosTerceirosPorEquip = is_array($servicos_terceiros_por_equip ?? null) ? $servicos_terceiros_por_equip : [];
$fmtDataTerceiro = static function (?string $valor, bool $comHora = false): string {
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') {
        return '-';
    }
    $ts = strtotime($valor);
    if ($ts === false) {
        return $valor;
    }
    return date($comHora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
};
$tipoServicoTerceiroLabel = static fn(string $tipo): string => match ($tipo) {
    'rebobinamento' => 'Recondicionamento',
    'outro' => 'Outro serviço terceirizado',
    default => ucfirst($tipo),
};

$statusOrcOptions = ['rascunho', 'enviado', 'aprovado', 'cancelado']; // 9H-4: pronto removido
$statusLabelMap = [
    'rascunho'  => 'Rascunho',
    'enviado'   => 'Enviado',
    'aprovado'  => 'Aprovado',
    'cancelado' => 'Cancelado',
    'pronto'    => 'Pronto',
    'retirado'  => 'Retirado',
];
$equipStatusLabelMap = [
    'aberta'    => 'Aguard. diagnostico',
    'andamento' => 'Em diagnostico',
    'montagem'  => 'Em montagem',
    'pronto'    => 'Pronto',
    'retirado'  => 'Retirado',
    'cancelado' => 'Cancelado',
    'devolvido' => 'Devolvido',
    'descartado'=> 'Descartado',
];

$osBadgeCls = match ((string) $os['status']) {
    'aberta'     => 'status-badge--info',
    'andamento'  => 'status-badge--warning',
    'pronto'     => 'status-badge--success',
    'retirado'   => 'status-badge--neutral',
    'cancelado'  => 'status-badge--danger',
    'descartado' => 'status-badge--warning',
    default      => 'status-badge--neutral',
};

$equipCards = [];
$totalEquipamentos = count($equipamentos);
$totalOrcamentos = 0;
$totalAguardandoCliente = 0;
$totalAprovados = 0;
$totalPagos = 0;
$valorTotalOrcado = 0.0;

foreach ($equipamentos as $equip) {
    $idx = (int) $equip['ordem_idx'];
    $orc = $orcamento_por_equip[$idx] ?? null;
    $statusOrc = $orc !== null ? (string) $orc['status'] : 'rascunho';
    $temOrc    = $orc !== null;
    $pago      = $temOrc && (int) $orc['pago'] === 1;
    // Quando orçamento aprovado tem total > 0, o campo `pago` é gerenciado pelo
    // sistema financeiro (lancamentos_receber). O botão manual não deve sobrescrever.
    $pagoGerenciadoFinanceiro = $temOrc
        && $statusOrc === 'aprovado'
        && (float) ($orc['total'] ?? 0) > 0.0;
    $itens     = $temOrc && is_array($orc['itens']) ? $orc['itens'] : [];
    $infoEquip = $equipInfoLinha($equip);
    $fotosEquip = $fotosDiagnostico($equip);
    $qtdItensTecnico = (int) ($equip['qtd_itens_tecnico'] ?? 0);
    $servicosTerceirosEquip = $servicosTerceirosPorEquip[$idx] ?? [];
    $servicoTerceiroEnviado = null;
    foreach ($servicosTerceirosEquip as $servicoTerceiro) {
        if ((string) ($servicoTerceiro['status'] ?? '') === 'enviado') {
            $servicoTerceiroEnviado = $servicoTerceiro;
            break;
        }
    }
    $diagnosticoConcluido = !empty($equip['diagnostico_concluido_em']);
    $subtotalPecas = 0.0;
    $itensFornecidosCliente = [];
    $itensElegiveisClienteForneceu = [];

    foreach ($itens as $item) {
        $fornecidoCliente = (int) ($item['fornecido_cliente'] ?? 0) === 1;
        if ($fornecidoCliente) {
            $itensFornecidosCliente[] = $item;
            continue;
        }
        $subtotalPecas += (float) ($item['valor_total'] ?? 0);
        if ((int) ($item['id'] ?? 0) > 0) {
            $itensElegiveisClienteForneceu[] = $item;
        }
    }

    $moValor = (float) ($orc['mo_valor'] ?? 0);
    $totalEquipamento = $subtotalPecas + $moValor;

    $orcBadgeCls = match ($statusOrc) {
        'rascunho'  => 'status-badge--neutral',
        'enviado'   => 'status-badge--info',
        'aprovado'  => 'status-badge--success',
        'cancelado' => 'status-badge--danger',
        'pronto'    => 'status-badge--success',
        'retirado'  => 'status-badge--neutral',
        default     => 'status-badge--neutral',
    };
    $equipBadgeCls = $diagnosticoConcluido && (string) $equip['status_equip'] === 'andamento'
        ? 'status-badge--info'
        : match ((string) $equip['status_equip']) {
        'aberta'     => 'status-badge--info',
        'andamento'  => 'status-badge--warning',
        'montagem'   => 'status-badge--brand',
        'pronto'     => 'status-badge--success',
        'retirado'   => 'status-badge--neutral',
        'cancelado'  => 'status-badge--danger',
        'devolvido'  => 'status-badge--neutral',
        'descartado' => 'status-badge--warning',
        default      => 'status-badge--neutral',
    };
    $statusEquipLabel = $diagnosticoConcluido && (string) $equip['status_equip'] === 'andamento'
        ? 'Recepção / diagnóstico concluído'
        : ($equipStatusLabelMap[(string) $equip['status_equip']] ?? ucfirst((string) $equip['status_equip']));

    if ($temOrc) {
        $totalOrcamentos++;
        $valorTotalOrcado += $totalEquipamento;
    }
    if ($statusOrc === 'enviado') {
        $totalAguardandoCliente++;
    }
    if ($statusOrc === 'aprovado') {
        $totalAprovados++;
    }
    if ($pago) {
        $totalPagos++;
    }

    // Follow-up: calculado apenas para status=enviado com wpp_enviado_em preenchido
    $wppEnviadoEm  = ($temOrc && !empty($orc['wpp_enviado_em'])) ? (string) $orc['wpp_enviado_em'] : null;
    $diasAguardando = ($statusOrc === 'enviado' && $wppEnviadoEm !== null)
        ? (int) floor((time() - strtotime($wppEnviadoEm)) / 86400)
        : null;
    $followupNivel = null;
    if ($diasAguardando !== null) {
        $followupNivel = match (true) {
            $diasAguardando >= 7 => 'urgente',
            $diasAguardando >= 3 => 'recomendado',
            default              => 'aguardando',
        };
    }

    $equipCards[] = [
        'idx' => $idx,
        'equip' => $equip,
        'info_equip' => $infoEquip,
        'fotos_diagnostico' => $fotosEquip,
        'orc' => $orc,
        'status_orc' => $statusOrc,
        'status_orc_label' => $statusLabelMap[$statusOrc] ?? ucfirst($statusOrc),
        'status_orc_badge' => $orcBadgeCls,
        'status_equip_badge' => $equipBadgeCls,
        'status_equip_label' => $statusEquipLabel,
        'diagnostico_concluido' => $diagnosticoConcluido,
        'tem_orc' => $temOrc,
        'pago' => $pago,
        'itens' => $itens,
        'itens_fornecidos_cliente' => $itensFornecidosCliente,
        'itens_fornecimento_cliente' => $itensElegiveisClienteForneceu,
        'subtotal_pecas' => $subtotalPecas,
        'mo_valor' => $moValor,
        'total' => $totalEquipamento,
        'qtd_itens_tecnico' => $qtdItensTecnico,
        'servico_terceiro_enviado' => $servicoTerceiroEnviado,
        'wpp_enviado_em' => $wppEnviadoEm,
        'dias_aguardando' => $diasAguardando,
        'followup_nivel' => $followupNivel,
        'necessidades_pendentes' => (int) (($necessidades_por_equip[$idx] ?? [])['bloqueantes_total'] ?? 0),
        'necessidades_resumo'   => $necessidades_por_equip[$idx] ?? null,
        'motivo_gratuidade' => $temOrc ? ($orc['motivo_gratuidade'] ?? null) : null, // 9J-1
    ];
}

$activePanelId = !empty($equipCards) ? 'equip-' . $equipCards[0]['idx'] : '';
?>

<div class="orcamento-page d-flex flex-column gap-4" data-os-id="<?= View::e((string) $os['id']) ?>">

    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                Orcamento - OS <span class="text-mono"><?= View::e((string) $os['id']) ?></span>
            </h1>
            <p class="page-header__subtitle">
                <strong><?= View::e((string) $os['nome_cliente']) ?></strong>
                <?php if (!empty($os['telefone'])): ?> &middot; <?= View::e((string) $os['telefone']) ?><?php endif; ?>
                <?php if (!empty($os['contato_nome']) || !empty($os['contato_telefone'])): ?>
                    &middot; <span class="text-body-secondary">Contato:
                    <?= View::e((string)($os['contato_nome'] ?? '')) ?>
                    <?php if (!empty($os['contato_telefone'])): ?>
                        &mdash; <?= View::e((string) $os['contato_telefone']) ?>
                    <?php endif; ?>
                    </span>
                <?php endif; ?>
                &middot; entrada: <?= View::e((string) $os['data_entrada']) ?>
                &middot; OS: <span class="status-badge <?= $osBadgeCls ?>"><?= View::e((string) $os['status']) ?></span>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/orcamento" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="/os/<?= View::e((string) $os['id']) ?>" class="btn btn-outline-secondary">
                <i class="ph ph-clipboard-text me-1"></i> Ver OS
            </a>
            <a href="/dashboard" class="btn btn-outline-secondary">
                <i class="ph ph-squares-four me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <div id="toast" class="alert alert-success alert-dismissible position-fixed top-0 end-0 m-3" style="z-index:1070" hidden>
        <span id="toast-msg"></span>
        <button type="button" class="btn-close" onclick="this.parentElement.hidden=true"></button>
    </div>

    <section class="orcamento-overview">
        <article class="orcamento-kpi">
            <span class="orcamento-kpi__label">Equipamentos</span>
            <strong class="orcamento-kpi__value"><?= $totalEquipamentos ?></strong>
            <span class="orcamento-kpi__hint">Navegue por equipamento sem abrir tudo de uma vez.</span>
        </article>
        <article class="orcamento-kpi">
            <span class="orcamento-kpi__label">Orcamentos criados</span>
            <strong class="orcamento-kpi__value"><?= $totalOrcamentos ?></strong>
            <span class="orcamento-kpi__hint"><?= $totalPagos ?> pago(s) nesta OS.</span>
        </article>
        <article class="orcamento-kpi">
            <span class="orcamento-kpi__label">Aguardando cliente</span>
            <strong class="orcamento-kpi__value"><?= $totalAguardandoCliente ?></strong>
            <span class="orcamento-kpi__hint"><?= $totalAprovados ?> aprovado(s) para seguir.</span>
        </article>
        <article class="orcamento-kpi">
            <span class="orcamento-kpi__label">Total orcado</span>
            <strong class="orcamento-kpi__value"><?= $fmtBrl($valorTotalOrcado) ?></strong>
            <span class="orcamento-kpi__hint">Soma dos equipamentos com orcamento salvo.</span>
        </article>
    </section>

    <div class="orcamento-shell">
        <aside class="card shadow-sm orcamento-sidebar">
            <div class="card-header orcamento-sidebar__header">
                <div>
                    <span class="orcamento-sidebar__eyebrow">Navegacao da OS</span>
                    <h2 class="orcamento-sidebar__title">Equipamentos</h2>
                </div>
                <span class="badge text-bg-secondary"><?= $totalEquipamentos ?></span>
            </div>
            <div class="orcamento-sidebar__body">
                <?php foreach ($equipCards as $card): ?>
                    <?php
                    $idx = (int) $card['idx'];
                    $displayIdx = $idx + 1;
                    $equip = $card['equip'];
                    $isActive = $activePanelId === 'equip-' . $idx;
                    ?>
                    <button type="button"
                            class="orcamento-nav__item<?= $isActive ? ' is-active' : '' ?>"
                            data-role="equip-nav"
                            data-target="equip-<?= $idx ?>"
                            data-equip-idx="<?= $idx ?>"
                            aria-controls="equip-<?= $idx ?>"
                            aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                        <div class="orcamento-nav__top">
                            <div>
                                <div class="orcamento-nav__index">Equipamento #<?= $displayIdx ?></div>
                                <div class="orcamento-nav__name"><?= View::e((string) $equip['nome']) ?></div>
                                <?php if ($card['info_equip'] !== ''): ?>
                                    <div class="small text-body-secondary text-truncate" title="<?= View::e($card['info_equip']) ?>">
                                        <?= View::e($card['info_equip']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge <?= $card['status_orc_badge'] ?>" data-role="nav-status-badge">
                                <?= View::e((string) $card['status_orc']) ?>
                            </span>
                        </div>
                        <p class="orcamento-nav__description">
                            <?= View::e((string) ($equip['defeito'] ?: 'Defeito nao informado')) ?>
                        </p>
                        <div class="orcamento-nav__meta">
                            <span class="status-badge <?= $card['status_equip_badge'] ?>">
                                <?= View::e((string) $card['status_equip_label']) ?>
                            </span>
                            <?php if ($card['status_orc'] === 'cancelado' && (string) $equip['status_equip'] === 'devolvido'): ?>
                                <span class="status-badge status-badge--neutral">
                                    <i class="ph ph-package me-1"></i> Retirada desmontada sem custo
                                </span>
                            <?php endif; ?>
                            <?php if ($card['followup_nivel'] !== null): ?>
                                <?php
                                    $fpNavCls = match ($card['followup_nivel']) {
                                        'urgente'     => 'status-badge--danger',
                                        'recomendado' => 'status-badge--warning',
                                        default       => 'status-badge--info',
                                    };
                                ?>
                                <span class="status-badge <?= $fpNavCls ?>"
                                      title="<?= $card['dias_aguardando'] ?>d sem resposta do cliente">
                                    <?= $card['dias_aguardando'] ?>d
                                </span>
                            <?php endif; ?>
                            <?php if ($card['necessidades_pendentes'] > 0): ?>
                                <?php $res = $card['necessidades_resumo']; ?>
                                <span class="status-badge status-badge--warning"
                                      title="Aguardando peças — Pendentes: <?= $res['pendentes'] ?> · S/ entrada: <?= $res['compradas_sem_entrada'] ?> · Itens manuais sem produto: <?= $res['manuais_sem_entrada'] ?>">
                                    <i class="ph ph-clock me-1"></i><?= $card['necessidades_pendentes'] ?>p
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($card['servico_terceiro_enviado'])): ?>
                                <span class="status-badge status-badge--warning"
                                      title="Serviço terceirizado em andamento">
                                    <i class="ph ph-truck me-1"></i> Terceiro
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($equip['serie']) && $equip['serie'] !== 'N/I'): ?>
                                <span class="orcamento-nav__chip">Serie <?= View::e((string) $equip['serie']) ?></span>
                            <?php endif; ?>
                            <?php if ($card['qtd_itens_tecnico'] > 0): ?>
                                <span class="orcamento-nav__chip orcamento-nav__chip--accent">
                                    <?= $card['qtd_itens_tecnico'] ?> do tecnico
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="orcamento-nav__footer">
                            <strong class="orcamento-nav__total" data-role="nav-total"><?= $fmtBrl((float) $card['total']) ?></strong>
                            <span class="orcamento-nav__count" data-role="nav-item-count">
                                <?= count($card['itens']) ?> item(ns)
                            </span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="orcamento-panels">
            <?php foreach ($equipCards as $card): ?>
                <?php
                $idx = (int) $card['idx'];
                $displayIdx = $idx + 1;
                $equip = $card['equip'];
                $orc = $card['orc'];
                $temOrc = (bool) $card['tem_orc'];
                $pago = (bool) $card['pago'];
                $itens = $card['itens'];
                $isActive = $activePanelId === 'equip-' . $idx;
                $autoimport = $temOrc
                    && $card['status_orc'] === 'rascunho'
                    && count($itens) === 0
                    && $card['qtd_itens_tecnico'] > 0
                    && !in_array((string) $equip['status_equip'], ['retirado', 'devolvido', 'descartado'], true);

                // ── Bloqueio de edição (9G-3) ────────────────────────────────────────────
                // Edição de itens/M.O. bloqueada quando o orçamento ou o equipamento
                // já estão em estado comercial/físico avançado.
                $eqStatus = (string) $equip['status_equip'];
                $orcBloqueiaEdicao = in_array($card['status_orc'], ['aprovado', 'cancelado'], true) // 9H-4
                    || in_array($eqStatus, ['retirado', 'devolvido', 'descartado'], true);
                $dis = $orcBloqueiaEdicao ? 'disabled' : '';
                $ro  = $orcBloqueiaEdicao ? 'readonly'  : '';

                // ── Banner contextual por estado (9G-1) ───────────────────────────────────
                $equipFinalizado = in_array($eqStatus, ['retirado', 'devolvido', 'descartado'], true);

                if ($eqStatus === 'retirado') {
                    [$bannerTitulo, $bannerSubtexto, $bannerCls, $bannerIco] = [
                        'Equipamento retirado.', 'Fluxo finalizado.', 'alert-success', 'ph-check-square',
                    ];
                } elseif ($eqStatus === 'devolvido') {
                    [$bannerTitulo, $bannerSubtexto, $bannerCls, $bannerIco] = [
                        'Equipamento devolvido ao cliente.', 'Fluxo finalizado.', 'alert-secondary', 'ph-arrow-u-up-left',
                    ];
                } elseif ($eqStatus === 'descartado') {
                    [$bannerTitulo, $bannerSubtexto, $bannerCls, $bannerIco] = [
                        'Equipamento descartado.', 'Fluxo finalizado.', 'alert-secondary', 'ph-trash',
                    ];
                } elseif (!$temOrc) {
                    $_subSemOrc = $card['qtd_itens_tecnico'] > 0
                        ? 'Há ' . $card['qtd_itens_tecnico'] . ' item(s) do técnico disponíveis — serão importados automaticamente ao salvar o orçamento.'
                        : 'Revise os itens do diagnóstico, adicione M.O. e salve o orçamento.';
                    [$bannerTitulo, $bannerSubtexto, $bannerCls, $bannerIco] = [
                        'Crie o orçamento deste equipamento.',
                        $_subSemOrc,
                        'alert-info', 'ph-file-plus',
                    ];
                    unset($_subSemOrc);
                } else {
                    [$bannerTitulo, $bannerSubtexto, $bannerCls, $bannerIco] = match ($card['status_orc']) {
                        'rascunho' => [
                            'Orçamento em rascunho.',
                            'Revise os itens e envie ao cliente pelo WhatsApp.',
                            'alert-warning', 'ph-pencil',
                        ],
                        'enviado' => [
                            'Orçamento enviado — aguardando resposta do cliente.',
                            $card['dias_aguardando'] !== null
                                ? 'Enviado há ' . $card['dias_aguardando'] . ' dia(s).'
                                : 'Aguardando resposta.',
                            'alert-info', 'ph-clock',
                        ],
                        'aprovado' => [
                            'Orçamento aprovado pelo cliente.',
                            match (true) {
                                $card['necessidades_pendentes'] > 0 => 'Aguardando peças para montagem.',
                                $eqStatus === 'montagem'            => 'Equipamento em montagem/conserto.',
                                $eqStatus === 'pronto'              => 'Equipamento pronto para retirada.',
                                default                             => 'Técnico dará seguimento ao serviço.',
                            },
                            'alert-success', 'ph-check-circle',
                        ],
                        'cancelado' => [
                            'Cliente recusou o orçamento.',
                            'Defina o destino físico do equipamento: devolução ou descarte.',
                            'alert-danger', 'ph-x-circle',
                        ],
                        'pronto' => [
                            'Serviço pronto.',
                            'Registre a retirada quando o cliente vier buscar.',
                            'alert-success', 'ph-package',
                        ],
                        'retirado' => [
                            'Equipamento retirado.', 'Fluxo finalizado.', 'alert-success', 'ph-check-square',
                        ],
                        default => [
                            ucfirst((string) $card['status_orc']), '', 'alert-secondary', 'ph-minus',
                        ],
                    };
                }

                $mostrarBotoesAprovarCancelar = $temOrc
                    && $card['status_orc'] === 'enviado'
                    && $podePreAprovar
                    && !$equipFinalizado;

                $mostrarPecasFornecidasCliente = $podePreAprovar
                    && $temOrc
                    && !empty($card['itens_fornecimento_cliente'])
                    && $card['status_orc'] !== 'cancelado'
                    && !in_array($eqStatus, ['montagem', 'pronto', 'retirado', 'devolvido', 'descartado', 'cancelado'], true);

                // ── Flags de destino físico (9G-4) ────────────────────────────────────────
                $descarteAutorizadoEm  = (string) ($equip['descarte_autorizado_em']  ?? '');
                $descarteAutorizadoPor = (string) ($equip['descarte_autorizado_por'] ?? '');
                $descarteMeio         = (string) ($equip['descarte_meio']            ?? '');
                $devolucaoEm          = (string) ($equip['devolucao_em']             ?? '');

                $mostrarDestinoFisico = $podePreAprovar
                    && $card['status_orc'] === 'cancelado'
                    && !$equipFinalizado;

                $mostrarBtnReverterCancelamento = $podeReverterCancelamento
                    && $temOrc
                    && $card['status_orc'] === 'cancelado'
                    && !$equipFinalizado;

                $mostrarBtnDevolver = $mostrarDestinoFisico
                    && $descarteAutorizadoEm === ''
                    && $eqStatus === 'pronto';

                $mostrarBtnRetiradaSemCusto = $mostrarDestinoFisico
                    && $descarteAutorizadoEm === ''
                    && $eqStatus !== 'pronto';

                $mostrarBtnDescarte = $mostrarDestinoFisico
                    && $descarteAutorizadoEm === ''
                    && $card['status_orc'] === 'cancelado';

                $mostrarDescarteAutorizadoBadge = $mostrarDestinoFisico
                    && $descarteAutorizadoEm !== ''
                    && !in_array($eqStatus, ['descartado'], true);
                ?>
                <article class="card shadow-sm orcamento-panel<?= $isActive ? ' is-active' : '' ?>"
                         id="equip-<?= $idx ?>"
                         data-role="equip-panel"
                         data-os-id="<?= View::e((string) $os['id']) ?>"
                         data-equip-idx="<?= $idx ?>"
                         data-orc-id="<?= $temOrc ? (int) $orc['id'] : '' ?>"
                         data-status="<?= View::e((string) $card['status_orc']) ?>"
                         data-orc-tipo="<?= View::e((string) (($orc['tipo'] ?? '') !== '' ? $orc['tipo'] : 'maquina')) ?>"
                         data-autoimport="<?= $autoimport ? '1' : '0' ?>"
                         data-dirty="0"
                         <?= $isActive ? '' : 'hidden' ?>>

                    <div class="card-header orcamento-panel__header">
                        <div class="orcamento-panel__headline">
                            <div>
                                <div class="orcamento-panel__eyebrow">
                                    Equipamento <?= $displayIdx ?> de <?= $totalEquipamentos ?>
                                </div>
                                <h5 class="mb-1" data-role="equip-title">
                                    <span class="text-body-secondary">#<?= $displayIdx ?></span>
                                    <?= View::e((string) $equip['nome']) ?>
                                </h5>
                                <?php if ($card['info_equip'] !== ''): ?>
                                    <div class="small fw-semibold text-body-secondary text-truncate mb-1"
                                         title="<?= View::e($card['info_equip']) ?>">
                                        <?= View::e($card['info_equip']) ?>
                                    </div>
                                <?php endif; ?>
                                <p class="small text-body-secondary mb-0">
                                    Defeito: <em><?= View::e((string) ($equip['defeito'] ?: 'Nao informado')) ?></em>
                                </p>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <?php if ($card['qtd_itens_tecnico'] > 0): ?>
                                    <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle"
                                          title="<?= $card['qtd_itens_tecnico'] ?> item(s) registrado(s) pelo tecnico">
                                        <i class="ph ph-wrench me-1"></i><?= $card['qtd_itens_tecnico'] ?> do tecnico
                                    </span>
                                <?php endif; ?>
                                <span class="status-badge <?= $card['status_equip_badge'] ?>">
                                    <?= View::e((string) $card['status_equip_label']) ?>
                                </span>
                                <span class="status-badge <?= $card['status_orc_badge'] ?>" data-role="status-badge">
                                    <?= View::e((string) $card['status_orc']) ?>
                                </span>
                                <?php if ($card['followup_nivel'] !== null): ?>
                                    <?php
                                        $fpCls = match ($card['followup_nivel']) {
                                            'urgente'     => 'status-badge--danger',
                                            'recomendado' => 'status-badge--warning',
                                            default       => 'status-badge--info',
                                        };
                                        $fpTxt = match ($card['followup_nivel']) {
                                            'urgente'     => 'Follow-up urgente',
                                            'recomendado' => 'Follow-up recomendado',
                                            default       => 'Aguardando resposta',
                                        };
                                        $fpDias = $card['dias_aguardando'];
                                    ?>
                                    <span class="status-badge <?= $fpCls ?>"
                                          title="Enviado em <?= date('d/m/Y H:i', strtotime((string) $card['wpp_enviado_em'])) ?> · <?= $fpDias ?>d sem resposta">
                                        <?= $fpDias ?>d — <?= $fpTxt ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($card['necessidades_pendentes'] > 0): ?>
                                    <?php $res = $card['necessidades_resumo']; ?>
                                    <span class="status-badge status-badge--warning"
                                          title="Pendentes: <?= $res['pendentes'] ?> · Compradas s/ entrada: <?= $res['compradas_sem_entrada'] ?> · Itens manuais sem produto: <?= $res['manuais_sem_entrada'] ?>">
                                        <i class="ph ph-clock me-1"></i>Aguardando peças (<?= $card['necessidades_pendentes'] ?>)
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($card['servico_terceiro_enviado'])): ?>
                                    <span class="status-badge status-badge--warning">
                                        <i class="ph ph-truck me-1"></i> Serviço terceirizado em andamento
                                    </span>
                                <?php endif; ?>
                                <?php if ($pago): ?>
                                    <span class="status-badge status-badge--success" data-role="pago-badge">pago</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="orcamento-panel__quickmeta">
                            <?php if (!empty($equip['serie']) && $equip['serie'] !== 'N/I'): ?>
                                <span class="orcamento-meta-pill">
                                    <span class="orcamento-meta-pill__label">Serie</span>
                                    <span class="text-mono"><?= View::e((string) $equip['serie']) ?></span>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($equip['voltagem'])): ?>
                                <span class="orcamento-meta-pill">
                                    <span class="orcamento-meta-pill__label">Voltagem</span>
                                    <?= View::e((string) $equip['voltagem']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($equip['cx'])): ?>
                                <span class="orcamento-meta-pill">
                                    <span class="orcamento-meta-pill__label">Caixa</span>
                                    <?= View::e((string) $equip['cx']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="orcamento-meta-pill orcamento-meta-pill--total">
                                <span class="orcamento-meta-pill__label">Total atual</span>
                                <strong data-role="panel-total"><?= $fmtBrl((float) $card['total']) ?></strong>
                            </span>
                        </div>

                        <div class="orcamento-panel__nav">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-role="equip-prev">
                                <i class="ph ph-caret-left me-1"></i> Anterior
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-role="equip-next">
                                Proximo <i class="ph ph-caret-right ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <?php // ── Banner contextual (9G-1) ─────────────────────────────────────── ?>
                    <div class="alert <?= $bannerCls ?> mx-3 mt-3 mb-0 d-flex align-items-center gap-3 flex-wrap">
                        <i class="ph <?= $bannerIco ?> fs-5 flex-shrink-0"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= View::e($bannerTitulo) ?></div>
                            <?php if ($bannerSubtexto !== ''): ?>
                                <div class="small mt-1"><?= View::e($bannerSubtexto) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($mostrarBotoesAprovarCancelar): ?>
                            <div class="d-flex gap-2 flex-shrink-0">
                                <button class="btn btn-success btn-sm"
                                        data-role="aprovar-orcamento">
                                    <i class="ph ph-check me-1"></i> Aprovar
                                </button>
                                <button class="btn btn-outline-danger btn-sm"
                                        data-role="cancelar-orcamento">
                                    <i class="ph ph-x me-1"></i> Cliente recusou
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if ($mostrarBtnReverterCancelamento): ?>
                            <div class="d-flex gap-2 flex-shrink-0">
                                <button class="btn btn-outline-warning btn-sm"
                                        data-role="reverter-cancelamento"
                                        data-orc-id="<?= (int) $orc['id'] ?>"
                                        data-equip-idx="<?= $idx ?>"
                                        data-equip-nome="<?= View::e((string) $equip['nome']) ?>">
                                    <i class="ph ph-arrow-counter-clockwise me-1"></i> Reverter cancelamento
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if ($mostrarDestinoFisico): ?>
                            <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
                                <?php if ($mostrarBtnDevolver): ?>
                                    <button class="btn btn-warning btn-sm"
                                            data-role="devolver-equip-orc"
                                            data-equip-idx="<?= $idx ?>"
                                            data-equip-nome="<?= View::e((string) $equip['nome']) ?>">
                                        <i class="ph ph-arrow-u-up-left me-1"></i> Registrar devolução
                                    </button>
                                <?php endif; ?>
                                <?php if ($mostrarBtnRetiradaSemCusto): ?>
                                    <button class="btn btn-warning btn-sm"
                                            data-role="retirada-sem-custo"
                                            data-orc-id="<?= (int) $orc['id'] ?>"
                                            data-equip-idx="<?= $idx ?>"
                                            data-equip-nome="<?= View::e((string) $equip['nome']) ?>">
                                        <i class="ph ph-package me-1"></i> Retirada do equipamento
                                    </button>
                                <?php endif; ?>
                                <?php if ($mostrarBtnDescarte): ?>
                                    <button class="btn btn-outline-danger btn-sm"
                                            data-role="autorizar-descarte-orc"
                                            data-equip-idx="<?= $idx ?>"
                                            data-equip-nome="<?= View::e((string) $equip['nome']) ?>">
                                        <i class="ph ph-trash me-1"></i> Autorizar descarte
                                    </button>
                                <?php endif; ?>
                                <?php if ($mostrarDescarteAutorizadoBadge): ?>
                                    <span class="status-badge status-badge--warning"
                                          title="Autorizado por <?= View::e($descarteAutorizadoPor) ?> via <?= View::e($descarteMeio) ?>">
                                        <i class="ph ph-trash me-1"></i> Descarte autorizado — aguardando confirmação física.
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($card['servico_terceiro_enviado'])): ?>
                        <?php $servTerc = $card['servico_terceiro_enviado']; ?>
                        <div class="alert alert-warning mx-3 mt-3 mb-0 d-flex align-items-start gap-2">
                            <i class="ph ph-truck fs-5 flex-shrink-0 mt-1"></i>
                            <div>
                                <strong>Serviço terceirizado em andamento</strong>
                                <div class="small mt-1">
                                    <?= View::e($tipoServicoTerceiroLabel((string) ($servTerc['tipo'] ?? ''))) ?>
                                    <?php if (!empty($servTerc['fornecedor_nome'])): ?>
                                        · Fornecedor: <?= View::e((string) $servTerc['fornecedor_nome']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($servTerc['previsao_retorno'])): ?>
                                        · Previsão de retorno: <?= View::e($fmtDataTerceiro((string) $servTerc['previsao_retorno'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($equip['obs_int']) || !empty($equip['obs_cli'])): ?>
                        <div class="orcamento-notes">
                            <div class="row g-3">
                                <?php if (!empty($equip['obs_int'])): ?>
                                    <?php
                                    $obsIntOrc = (string) $equip['obs_int'];
                                    $semConsertoOrcPos = (string) $equip['status_equip'] === 'cancelado'
                                        ? strpos($obsIntOrc, 'Sem conserto viável:')
                                        : false;
                                    if ($semConsertoOrcPos !== false) {
                                        $motivoOrcRaw = trim(substr($obsIntOrc, $semConsertoOrcPos + strlen('Sem conserto viável:')));
                                        $motivoOrcLinha = explode("\n", $motivoOrcRaw)[0];
                                    }
                                    ?>
                                    <div class="col-lg-6">
                                        <?php if ($semConsertoOrcPos !== false): ?>
                                            <div class="orcamento-note-card" style="border-color: var(--bs-danger-border-subtle); background: var(--bs-danger-bg-subtle);">
                                                <strong class="text-danger"><i class="ph ph-x-circle me-1"></i> Sem conserto viável</strong>
                                                <div class="text-danger fw-semibold mt-1"><?= View::e($motivoOrcLinha) ?></div>
                                                <?php
                                                $restoObsInt = trim(substr($obsIntOrc, 0, $semConsertoOrcPos) . substr($obsIntOrc, $semConsertoOrcPos + strlen('Sem conserto viável:') + strlen($motivoOrcLinha)));
                                                if ($restoObsInt !== ''):
                                                ?>
                                                <div class="text-body-secondary mt-2 small"><?= nl2br(View::e($restoObsInt)) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="orcamento-note-card">
                                                <strong class="text-info"><i class="ph ph-wrench me-1"></i> Laudo tecnico</strong>
                                                <div class="text-body-secondary mt-2"><?= nl2br(View::e($obsIntOrc)) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($equip['obs_cli'])): ?>
                                    <div class="col-lg-6">
                                        <div class="orcamento-note-card">
                                            <strong class="text-primary"><i class="ph ph-chat-centered-text me-1"></i> Obs. para cliente</strong>
                                            <div class="text-body-secondary mt-2" data-role="obs-cli-preview"><?= nl2br(View::e((string) $equip['obs_cli'])) ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card-body">
                        <input type="hidden" name="tipo" value="<?= View::e((string) (($orc['tipo'] ?? '') !== '' ? $orc['tipo'] : 'maquina')) ?>">
                        <input type="hidden" name="tecnico" value="<?= View::e((string) ($orc['tecnico'] ?? '')) ?>">
                        <input type="hidden" name="gerado_por" value="<?= View::e((string) ($orc['gerado_por'] ?? '')) ?>">
                        <input type="hidden" name="data_orcamento" value="<?= View::e((string) ($orc['data_orcamento'] ?? '')) ?>">
                        <input type="hidden" name="obs_int" value="<?= View::e((string) ($equip['obs_int'] ?? '')) ?>">
                        <textarea name="obs_admin" hidden aria-hidden="true"><?= View::e((string) ($orc['obs_admin'] ?? '')) ?></textarea>

                        <?php if (!empty($card['fotos_diagnostico'])): ?>
                        <section class="orcamento-panel__section">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                <h6 class="fw-semibold mb-0">
                                    <i class="ph ph-images me-1"></i> Fotos do diagnóstico
                                </h6>
                                <span class="small text-body-secondary">
                                    <?= count($card['fotos_diagnostico']) ?> arquivo(s)
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($card['fotos_diagnostico'] as $fotoIdx => $fotoUrl): ?>
                                    <a href="<?= View::e($fotoUrl) ?>"
                                       target="_blank"
                                       rel="noopener"
                                       class="d-inline-block rounded border overflow-hidden bg-body-tertiary"
                                       title="Abrir foto <?= $fotoIdx + 1 ?> do diagnóstico">
                                        <img src="<?= View::e($fotoUrl) ?>"
                                             alt="Foto <?= $fotoIdx + 1 ?> do diagnóstico do equipamento <?= $displayIdx ?>"
                                             loading="lazy"
                                             style="width:72px;height:72px;object-fit:cover;display:block">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>

                        <section class="orcamento-panel__section">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                <h6 class="fw-semibold mb-0">
                                    <i class="ph ph-package me-1"></i> Pecas e servicos
                                    <small class="text-body-secondary" data-role="qtd-itens">(<?= count($itens) ?>)</small>
                                </h6>
                                <div class="orcamento-inline-stats">
                                    <span>Subtotal pecas <strong data-role="subtotal-pecas"><?= $fmtBrl((float) $card['subtotal_pecas']) ?></strong></span>
                                    <span>Total geral <strong data-role="total-geral"><?= $fmtBrl((float) $card['total']) ?></strong></span>
                                </div>
                            </div>

                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-hover mb-0 orc-itens-table">
                                    <thead>
                                        <tr>
                                            <th style="width:90px">Codigo</th>
                                            <th>Descricao</th>
                                            <th style="width:70px">Qtd</th>
                                            <th style="width:60px">Un</th>
                                            <th style="width:110px">Unitario</th>
                                            <th style="width:110px">Total</th>
                                            <th style="width:50px"></th>
                                        </tr>
                                    </thead>
                                    <tbody data-role="itens-body">
                                        <?php if (empty($itens)): ?>
                                            <tr class="empty-row" data-role="empty">
                                                <td colspan="7" class="text-body-secondary text-center py-3">
                                                    <?= $orcBloqueiaEdicao ? 'Nenhum item registrado.' : 'Nenhum item. Clique em "+ Adicionar item".' ?>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($itens as $it): ?>
                                                <?php
                                                $fornecidoCliente = (int) ($it['fornecido_cliente'] ?? 0) === 1;
                                                $linhaRo = ($orcBloqueiaEdicao || $fornecidoCliente) ? 'readonly' : '';
                                                $valorOriginalInfo = $fornecidoCliente && isset($it['subtotal_original']) && $it['subtotal_original'] !== null
                                                    ? $fmtBrl((float) $it['subtotal_original'])
                                                    : '';
                                                ?>
                                                <tr class="orc-item-row<?= $fornecidoCliente ? ' table-info' : '' ?>"
                                                    data-item-id="<?= (int) ($it['id'] ?? 0) ?>"
                                                    data-produto-id="<?= (int) ($it['produto_id'] ?? 0) ?>"
                                                    data-tecnico-item-id="<?= (int) ($it['tecnico_item_id'] ?? 0) ?>"
                                                    data-fornecido-cliente="<?= $fornecidoCliente ? '1' : '0' ?>">
                                                    <td><input type="text" name="codigo" value="<?= View::e((string) $it['codigo']) ?>" class="form-control form-control-sm text-mono" <?= $linhaRo ?>></td>
                                                    <td>
                                                        <input type="text" name="descricao" value="<?= View::e((string) $it['descricao']) ?>" class="form-control form-control-sm" <?= $linhaRo ?>>
                                                        <?php if ($fornecidoCliente): ?>
                                                            <div class="small mt-1">
                                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                                    Cliente trouxe a peça
                                                                </span>
                                                                <?php if ($valorOriginalInfo !== ''): ?>
                                                                    <span class="text-body-secondary ms-1">Original: <?= View::e($valorOriginalInfo) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><input type="number" name="qtd" step="0.001" min="0" value="<?= View::e((string) $it['qtd']) ?>" class="form-control form-control-sm" data-role="qtd" <?= $linhaRo ?>></td>
                                                    <td><input type="text" name="unidade" value="<?= View::e((string) ($it['unidade'] ?: 'un')) ?>" class="form-control form-control-sm" <?= $linhaRo ?>></td>
                                                    <td><input type="number" name="valor_unit" step="0.01" min="0" value="<?= View::e((string) $it['valor_unit']) ?>" class="form-control form-control-sm" data-role="vu" <?= $linhaRo ?>></td>
                                                    <td class="text-mono align-middle" data-role="vt"><?= $fmtBrl((float) $it['valor_total']) ?></td>
                                                    <td>
                                                        <?php if (!$orcBloqueiaEdicao && !$fornecidoCliente): ?>
                                                        <button class="btn-icon text-danger" data-role="remover-item" title="Remover">
                                                            <i class="ph ph-x"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($mostrarPecasFornecidasCliente): ?>
                            <div class="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <span>
                                    <i class="ph ph-package me-1"></i>
                                    Use quando o cliente trouxe uma ou mais peças deste orçamento.
                                </span>
                                <button type="button" class="btn btn-info btn-sm" data-role="pecas-fornecidas-cliente">
                                    <i class="ph ph-hand-coins me-1"></i> Cliente trouxe as peças
                                </button>
                            </div>
                            <?php endif; ?>

                            <?php if (!$orcBloqueiaEdicao): ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-outline-secondary btn-sm" data-role="adicionar-item">
                                    <i class="ph ph-plus me-1"></i> Adicionar item
                                </button>
                                <?php if ($podePreAprovar): ?>
                                <button class="btn btn-outline-secondary btn-sm" style="border-style:dashed" data-role="abrir-catalogo-mo">
                                    <i class="ph ph-list-plus me-1"></i> Da Tabela Base
                                </button>
                                <?php endif; ?>
                                <?php if ($card['qtd_itens_tecnico'] > 0): ?>
                                <button class="btn btn-outline-secondary btn-sm"
                                        data-role="importar-tecnico"
                                        data-os-id="<?= View::e((string) $os['id']) ?>"
                                        data-equip-idx="<?= $idx ?>"
                                        title="Reimporta peças registradas pelo técnico (use se a auto-importação não ocorreu)">
                                    <i class="ph ph-download-simple me-1"></i>
                                    Reimportar do técnico
                                    <span class="badge bg-secondary ms-1"><?= $card['qtd_itens_tecnico'] ?></span>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="small text-body-secondary">
                                <i class="ph ph-lock me-1"></i>
                                Edição de itens bloqueada — orçamento em estado <strong><?= View::e($card['status_orc']) ?></strong>.
                            </div>
                            <?php endif; ?>
                        </section>

                        <?php if ($podePreAprovar): ?>
                        <section class="orcamento-panel__section orcamento-panel__section--admin">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <h6 class="fw-semibold mb-1">Fechamento do orcamento</h6>
                                    <p class="small text-body-secondary mb-0">Ajuste a mao de obra e registre a mensagem que o cliente deve receber neste equipamento.</p>
                                </div>
                                <span class="small text-body-secondary">Esses dados valem apenas para o equipamento ativo.</span>
                            </div>

                            <div class="orcamento-admin-grid">
                                <div class="orcamento-admin-card orcamento-admin-card--highlight">
                                    <div class="orcamento-admin-card__header">
                                        <div>
                                            <span class="orcamento-admin-card__eyebrow">Mao de obra</span>
                                            <h6 class="orcamento-admin-card__title mb-0">Valor da mao de obra</h6>
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                data-role="sugerir-mo"
                                                title="Sugerir com base no equipamento"
                                                <?= $dis ?>>
                                            <i class="ph ph-lightbulb me-1"></i>Sugerir valor
                                        </button>
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label small mb-1">Selecionar da tabela M.O. (tipo: <?= View::e((string) ($orc['tipo'] ?? 'maquina')) ?>)</label>
                                        <select data-role="mo-select" class="form-select form-select-sm" <?= $dis ?>>
                                            <option value="">-- selecione para preencher automaticamente --</option>
                                        </select>
                                    </div>

                                    <label class="form-label small mb-2" for="mo-valor-<?= $idx ?>">Valor em reais</label>
                                    <div class="orcamento-admin-input">
                                        <span class="orcamento-admin-input__prefix">R$</span>
                                        <input id="mo-valor-<?= $idx ?>"
                                               type="number"
                                               name="mo_valor"
                                               step="0.01"
                                               min="0"
                                               value="<?= View::e((string) ($orc['mo_valor'] ?? '0.00')) ?>"
                                               class="form-control form-control-lg"
                                               <?= $ro ?>>
                                    </div>

                                    <div class="orcamento-admin-summary">
                                        <span>Total do equipamento</span>
                                        <strong data-role="admin-total"><?= $fmtBrl((float) $card['total']) ?></strong>
                                    </div>

                                    <p class="small text-body-secondary mt-2 mb-0">
                                        <i class="ph ph-info me-1"></i> Valor sugerido pela tabela de M.O. quando disponivel; ajuste manualmente se necessario. Nao baixa estoque.
                                    </p>
                                </div>

                                <div class="orcamento-admin-card">
                                    <div class="orcamento-admin-card__header">
                                        <div>
                                            <span class="orcamento-admin-card__eyebrow">Mensagem ao cliente</span>
                                            <h6 class="orcamento-admin-card__title mb-0">Observacao para o cliente</h6>
                                        </div>
                                    </div>

                                    <label class="form-label small fw-semibold mb-2" for="obs-cli-<?= $idx ?>">Observação para o cliente (Impressa no orçamento)</label>
                                    <textarea id="obs-cli-<?= $idx ?>"
                                              name="obs_cli"
                                              rows="5"
                                              class="form-control orcamento-admin-textarea"
                                              placeholder="Mensagem clara para o cliente..."
                                              <?= $ro ?>><?= View::e((string) ($equip['obs_cli'] ?? '')) ?></textarea>
                                </div>
                            </div>

                            <!-- Mensagem interna recepção <-> técnico (uso interno; NAO sai no orçamento) -->
                            <div class="orcamento-admin-card mt-3" data-role="obs-recepcao-card">
                                <div class="orcamento-admin-card__header">
                                    <div>
                                        <span class="orcamento-admin-card__eyebrow">Uso interno — não sai no orçamento</span>
                                        <h6 class="orcamento-admin-card__title mb-0">Mensagem para o técnico</h6>
                                    </div>
                                </div>

                                <label class="form-label small fw-semibold mb-2" for="obs-recepcao-<?= $idx ?>">Recado interno (visível ao técnico no painel — canal de mão dupla)</label>
                                <textarea id="obs-recepcao-<?= $idx ?>"
                                          name="obs_recepcao"
                                          rows="4"
                                          class="form-control orcamento-admin-textarea"
                                          placeholder="Ex.: cliente pediu para revisar o cabo; usar peça X; etc."><?= View::e((string) ($equip['obs_recepcao'] ?? '')) ?></textarea>
                                <div class="d-flex align-items-center gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-role="salvar-obs-recepcao">
                                        <i class="ph ph-paper-plane-tilt me-1"></i> Salvar recado
                                    </button>
                                    <span class="small text-body-secondary" data-role="obs-recepcao-status"></span>
                                </div>
                            </div>

                            <?php
                            // ── 9J-1: Motivo da gratuidade ──────────────────────────────────────
                            $motivoGratuidade    = $card['motivo_gratuidade'];
                            $totalZeroCard       = (float) $card['total'] == 0.0;
                            $emGarantiaFab       = (int) ($equip['em_garantia'] ?? 0) === 1
                                && (string) ($equip['tipo_garantia'] ?? '') === 'fabricante';
                            $mostrarMotivoEdit   = $temOrc && $totalZeroCard && !$orcBloqueiaEdicao;
                            $mostrarMotivoBadge  = $temOrc && $motivoGratuidade !== null && $orcBloqueiaEdicao;
                            // ── 9J-2: Autorização / RMA do fabricante ────────────────────────────
                            $garantiaAuth        = (string) ($equip['garantia_autorizacao'] ?? '');
                            ?>
                            <?php if ($emGarantiaFab && $garantiaAuth !== ''): ?>
                            <div class="mt-2">
                                <span class="status-badge status-badge--secondary" title="Autorização / RMA do fabricante">
                                    <i class="ph ph-hash me-1"></i><?= View::e($garantiaAuth) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if ($mostrarMotivoEdit): ?>
                            <div class="mt-3 pt-2 border-top">
                                <label class="form-label small fw-semibold mb-1">
                                    <i class="ph ph-tag me-1"></i>Motivo da gratuidade
                                </label>
                                <?php if ($motivoGratuidade === null && $emGarantiaFab): ?>
                                <div class="alert alert-info small py-1 px-2 mb-2">
                                    <i class="ph ph-shield-check me-1"></i>
                                    Este equipamento está em garantia de fabricante. Informe o motivo da gratuidade.
                                </div>
                                <?php elseif ($motivoGratuidade === null): ?>
                                <div class="alert alert-warning small py-1 px-2 mb-2">
                                    <i class="ph ph-warning me-1"></i>
                                    Orçamento sem cobrança. Informe se é garantia de fabricante ou cortesia.
                                </div>
                                <?php endif; ?>
                                <?php
                                // Auto-sugerir garantia_fabricante quando equipamento tem garantia de fabricante
                                // e o motivo ainda não foi definido.
                                $motivoSugerido = ($motivoGratuidade === null && $emGarantiaFab)
                                    ? 'garantia_fabricante' : null;
                                ?>
                                <select name="motivo_gratuidade" class="form-select form-select-sm" style="max-width:260px">
                                    <option value="">Selecionar motivo...</option>
                                    <option value="garantia_fabricante"
                                        <?= ($motivoGratuidade ?? $motivoSugerido) === 'garantia_fabricante' ? 'selected' : '' ?>>
                                        Garantia de fabricante
                                    </option>
                                    <option value="cortesia"
                                        <?= $motivoGratuidade === 'cortesia' ? 'selected' : '' ?>>
                                        Cortesia
                                    </option>
                                </select>
                                <p class="form-text mb-0">Salvo junto com o orçamento.</p>
                            </div>
                            <?php elseif ($mostrarMotivoBadge): ?>
                            <div class="mt-3 pt-2 border-top">
                                <?php
                                $motLabel = $motivoGratuidade === 'garantia_fabricante'
                                    ? 'Garantia de fabricante' : 'Cortesia';
                                $motCls   = $motivoGratuidade === 'garantia_fabricante'
                                    ? 'status-badge--info' : 'status-badge--neutral';
                                ?>
                                <span class="status-badge <?= $motCls ?>">
                                    <i class="ph ph-tag me-1"></i>
                                    Gratuidade: <?= View::e($motLabel) ?>
                                </span>
                            </div>
                            <?php endif; ?>

                        </section>
                        <?php endif; ?>
                    </div>

                    <div class="card-footer orcamento-panel__footer d-flex flex-column gap-2">
                        <div class="orcamento-panel__footer-actions">
                            <?php if ($pago && $eqStatus === 'pronto'): ?>
                                <!-- Pago antecipado — equipamento ainda aguarda retirada física. -->
                                <span class="badge text-bg-success fs-6 px-3 py-2"
                                      title="Pagamento registrado. Equipamento aguarda retirada.">
                                    <i class="ph ph-check-circle me-1"></i>Pago — aguardando retirada
                                </span>
                            <?php elseif ($pago): ?>
                                <!-- Indicador informativo — pago gerenciado pelo financeiro. -->
                                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true"
                                      title="Pago registrado pelo sistema financeiro">
                                    Pago ✓
                                </span>
                            <?php endif; ?>
                            <?php
                            // 10D-3: botão "Registrar Pagamento" — visível quando orçamento aprovado,
                            // total > 0, ainda não pago, equipamento pronto e não finalizado.
                            $podeRegistrarPagamento = $podePreAprovar
                                && $temOrc
                                && $card['status_orc'] === 'aprovado'
                                && (float) $card['total'] > 0
                                && !(bool) $card['pago']
                                && $eqStatus === 'pronto'
                                && !$equipFinalizado;
                            ?>
                            <?php if ($podeRegistrarPagamento): ?>
                                <button class="btn btn-sm btn-primary btn-pagar-antecipado-orc"
                                        data-equip-idx="<?= $idx ?>"
                                        data-equip-nome="<?= View::e((string) $equip['nome']) ?>"
                                        data-valor-total="<?= number_format((float) $card['total'], 2, '.', '') ?>">
                                    <i class="ph ph-currency-circle-dollar me-1"></i> Registrar Pagamento
                                </button>
                            <?php endif; ?>
                            <!-- Botão manual de pago removido (Etapa 7D.2):
                                 orcamentos.pago é reflexo do financeiro e atualizado
                                 apenas por retirarEquipamento() / retirar() / reabrir(). -->
                            <?php
                            // WPP só faz sentido em rascunho (envio inicial) e enviado (reenvio/lembrete).
                            // Em aprovado/cancelado e equip finalizado não aparece (9G-5).
                            $mostrarWpp = $podePreAprovar
                                && $temOrc
                                && in_array($card['status_orc'], ['rascunho', 'enviado'], true)
                                && !$equipFinalizado;
                            ?>
                            <?php if ($mostrarWpp): ?>
                                <button class="btn btn-sm btn-wpp" data-role="wpp-orcamento">
                                    <i class="ph ph-whatsapp-logo me-1"></i>
                                    <?= $card['status_orc'] === 'enviado' ? 'Reenviar WhatsApp' : 'Enviar por WhatsApp' ?>
                                </button>
                                <button class="btn btn-sm btn-outline-primary" data-role="enviar-email-orcamento">
                                    <i class="ph ph-envelope me-1"></i>
                                    <?= $card['status_orc'] === 'enviado' ? 'Reenviar por E-mail' : 'Enviar por E-mail' ?>
                                </button>
                            <?php endif; ?>
                            <?php
                                // Retirada disponível quando: admin/recepcao + equip pronto + orçamento aprovado + OS não encerrada.
                                // Regra principal: os_equipamento.status_equip = 'pronto'.
                                // Regra comercial: orcamentos.status = 'aprovado'.
                                // 9H-4: 'pronto' removido do modelo comercial — estado físico em status_equip.
                                //   Bloqueado: rascunho, enviado, cancelado.
                                $podeRetirar = $podePreAprovar
                                    && (string) $equip['status_equip'] === 'pronto'
                                    && $card['status_orc'] === 'aprovado'
                                    && !in_array((string) $os['status'], ['retirado', 'descartado', 'cancelado'], true);
                            ?>
                            <?php if ($podeRetirar): ?>
                                <button class="btn btn-sm btn-success btn-retirar-equip-orc"
                                        data-equip-idx="<?= $idx ?>"
                                        data-equip-nome="<?= View::e((string) $equip['nome']) ?>"
                                        data-tem-valor="<?= $card['total'] > 0 ? '1' : '0' ?>"
                                        data-valor-total="<?= number_format((float) $card['total'], 2, '.', '') ?>"
                                        data-ja-pago="<?= (int) $card['pago'] ?>">
                                    <i class="ph ph-package me-1"></i> Retirar Equipamento
                                </button>
                            <?php elseif ($podePreAprovar && (string) $equip['status_equip'] === 'retirado'): ?>
                                <button class="btn btn-sm btn-danger btn-desfazer-retirada-equip-orc"
                                        data-equip-idx="<?= $idx ?>"
                                        data-equip-nome="<?= View::e((string) $equip['nome']) ?>">
                                    <i class="ph ph-arrow-u-up-left me-1"></i> Desfazer Retirada
                                </button>
                            <?php endif; ?>
                            <?php if ($podePreAprovar && $temOrc): ?>
                            <button class="btn btn-sm btn-outline-secondary"
                                    data-role="orcamento-pdf"
                                    title="Visualizar orçamento formal para impressão ou PDF">
                                <i class="ph ph-file-pdf me-1"></i> Imprimir/PDF
                            </button>
                            <?php endif; ?>
                            <?php if (!$orcBloqueiaEdicao): ?>
                            <button class="btn btn-primary" data-role="salvar">
                                <i class="ph ph-floppy-disk me-1"></i> Salvar orcamento
                            </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($podePreAprovar): ?>
                        <!-- Ajuste administrativo (9G-2) — rebaixado do footer principal -->
                        <details class="w-100">
                            <summary class="small text-body-secondary" style="cursor:pointer;user-select:none">
                                <i class="ph ph-gear me-1"></i> Ajuste administrativo
                            </summary>
                            <div class="small text-body-secondary mt-1 mb-2">
                                Use apenas para correções ou situações excepcionais.
                                O fluxo recomendado está nos botões principais do card.
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <label class="form-label small mb-0 text-body-secondary">Status:</label>
                                <select name="novo_status"
                                        data-role="status-select"
                                        class="form-select form-select-sm"
                                        style="width:auto"
                                        <?= !$temOrc ? 'disabled' : '' ?>>
                                    <?php foreach ($statusOrcOptions as $s): ?>
                                        <option value="<?= View::e($s) ?>" <?= $card['status_orc'] === $s ? 'selected' : '' ?>>
                                            <?= View::e($statusLabelMap[$s] ?? $s) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary" data-role="aplicar-status" <?= !$temOrc ? 'disabled' : '' ?>>
                                    Aplicar
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" data-role="pre-aprovar">
                                    Pre-aprovar
                                </button>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</div>

<!-- Modal: Retirada desmontada sem custo -->
<div class="modal fade" id="modalRetiradaSemCusto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ph ph-package me-2"></i>Confirmar retirada do equipamento?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small">Equipamento: <strong id="retiradaSemCustoEquipNome"></strong></p>
                <div class="alert alert-warning small py-2 mb-3">
                    <i class="ph ph-warning-circle me-1"></i>
                    Este equipamento teve o orçamento cancelado e será retirado sem conserto, desmontado ou sem remontagem.
                </div>
                <div class="alert alert-info small py-2 mb-0">
                    <i class="ph ph-info me-1"></i>
                    Esta ação não irá gerar cobrança, financeiro, NF, baixa de estoque ou envio de WhatsApp.
                </div>
                <p class="small text-body-secondary mt-3 mb-0">Deseja continuar?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btnConfirmarRetiradaSemCusto">
                    <i class="ph ph-check me-1"></i> Confirmar retirada sem custo
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Reversão de Cancelamento — apenas admin -->
<div class="modal fade" id="modalReverterCancelamento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ph ph-arrow-counter-clockwise me-2"></i>Reverter cancelamento?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small">Equipamento: <strong id="reverterCancelamentoEquipNome"></strong></p>
                <div class="alert alert-warning small py-2 mb-3">
                    <i class="ph ph-warning-circle me-1"></i>
                    Essa ação deve ser usada apenas quando o equipamento/orçamento foi cancelado por engano.
                    O histórico do cancelamento será mantido e a reversão será registrada com seu usuário, data e motivo.
                </div>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ph ph-info me-1"></i>
                    Não foi encontrado status anterior confiável. O equipamento será reaberto em modo seguro para nova análise/orçamento.
                    Isso não aprova orçamento, não inicia montagem e não aciona cliente automaticamente.
                </div>
                <div class="mb-2">
                    <label for="reverterCancelamentoMotivo" class="form-label small fw-semibold">
                        Motivo da reversão <span class="text-danger">*</span>
                    </label>
                    <textarea id="reverterCancelamentoMotivo"
                              class="form-control"
                              rows="3"
                              maxlength="500"
                              placeholder="Ex: Cancelamento realizado por engano"></textarea>
                    <div id="reverterCancelamentoErro" class="invalid-feedback">
                        Informe o motivo da reversão.
                    </div>
                </div>
                <p class="small text-body-secondary mb-0">Deseja continuar?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btnConfirmarReverterCancelamento">
                    <i class="ph ph-check me-1"></i> Confirmar reversão
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Autorização de Descarte — página Orçamento (9G-4) -->
<div class="modal fade" id="modalAutorizarDescarteOrc" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-trash me-2"></i>Autorizar Descarte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3 small">Equipamento: <strong id="descarteOrcEquipNome"></strong></p>
                <div class="alert alert-warning small py-2 mb-3">
                    <i class="ph ph-warning me-1"></i>
                    Esta ação <strong>não</strong> baixa estoque, <strong>não</strong> gera financeiro
                    e <strong>não</strong> marca o equipamento como descartado.
                    Apenas registra a autorização e notifica a oficina para realizar o descarte físico.
                </div>
                <div class="mb-3">
                    <label for="inpDescarteOrcAutorizadoPor" class="form-label small fw-semibold">
                        Quem autorizou <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="inpDescarteOrcAutorizadoPor" class="form-control"
                           placeholder="Nome do cliente ou responsável" maxlength="120">
                </div>
                <div class="mb-3">
                    <label for="selDescarteOrcMeio" class="form-label small fw-semibold">
                        Meio da autorização <span class="text-danger">*</span>
                    </label>
                    <select id="selDescarteOrcMeio" class="form-select">
                        <option value="">Selecionar...</option>
                        <option value="presencial">Presencial</option>
                        <option value="telefone">Telefone</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="email">E-mail</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmarAutorizarDescarteOrc">
                    <i class="ph ph-trash me-1"></i> Registrar Autorização
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDesfazerRetiradaEquipOrc" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-bottom border-light shadow-sm">
                <h5 class="modal-title d-flex align-items-center fw-bold">
                    <i class="ph ph-arrow-u-up-left fs-3 text-danger me-2"></i>
                    Desfazer Retirada
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light bg-opacity-50">
                <input type="hidden" id="inpDesfazerEquipIdxOrc">
                
                <div class="alert alert-danger bg-white border-danger mb-4">
                    <div class="d-flex">
                        <i class="ph-fill ph-warning-circle fs-4 text-danger me-3 mt-1"></i>
                        <div>
                            <h6 class="fw-bold mb-1 text-danger">Atenção!</h6>
                            <p class="mb-0 text-body-secondary small">
                                Você está prestes a desfazer a retirada do equipamento 
                                <strong id="txtDesfazerEquipNomeOrc" class="text-dark"></strong>.<br>
                                O equipamento voltará para o status "Pronto", o financeiro será revertido e as peças retornarão ao estoque.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Motivo da reversão <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="inpJustificativaDesfazerOrc" rows="3" placeholder="Ex: Equipamento retirado por engano..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmarDesfazerRetiradaOrc">
                    <i class="ph ph-check-circle me-1"></i>Confirmar Reversão
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCatalogoMo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-list-checks me-2"></i>Tabela Base de Mao de Obra</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="catalogoMoContainer" style="max-height:60vh;overflow-y:auto">
                <div class="text-center text-body-secondary py-4">
                    <span class="spinner-border spinner-border-sm me-2"></span>Carregando tabela...
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPecasFornecedorCliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-hand-coins me-2"></i>Cliente trouxe as peças</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2">
                    <i class="ph ph-info me-1"></i>
                    Os itens selecionados deixam de ser cobrados, as necessidades de compra relacionadas são canceladas e o equipamento será liberado para montagem quando possível.
                </div>
                <div id="pecasClienteLista" class="d-grid gap-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-info" id="btnConfirmarPecasCliente">
                    <i class="ph ph-check me-1"></i> Confirmar peças
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalWhatsappPreview" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-whatsapp-logo me-2"></i>Prévia da mensagem</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold mb-2" for="whatsappPreviewMensagem">Mensagem que será enviada</label>
                <textarea id="whatsappPreviewMensagem"
                          class="form-control text-mono"
                          rows="12"
                          readonly></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btnConfirmarWhatsappPreview">
                    <i class="ph ph-whatsapp-logo me-1"></i>Enviar por WhatsApp
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Retirada por Equipamento (página Orçamento - 9C-12) -->
<div class="modal fade" id="modalRetiradaEquipOrc" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-package me-2"></i>Retirar Equipamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small">Equipamento: <strong id="retiradaOrcEquipNome"></strong></p>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ph ph-info me-1"></i>
                    Esta retirada afeta apenas este equipamento. A OS continuará aberta se houver outros equipamentos pendentes.
                </div>
                <div class="mb-3">
                    <label class="form-label">Nome de quem está retirando <span class="text-danger">*</span></label>
                    <input type="text" id="inpRetiradoPorOrc" class="form-control"
                           placeholder="Nome completo do cliente ou responsável">
                </div>
                <div id="secPagamentoOrc">
                    <div class="mb-3">
                        <label class="form-label">Forma de Pagamento <span class="text-danger">*</span></label>
                        <select id="selFormaPagOrc" class="form-select">
                            <option value="">Selecione...</option>
                            <option value="dinheiro">Dinheiro</option>
                            <option value="pix">PIX</option>
                            <option value="cartao">Cartão</option>
                            <option value="faturado">Faturado (B2B)</option>
                        </select>
                    </div>
                    <div id="divNumPedidoOrc" class="mb-3 d-none">
                        <label class="form-label">Nº do Pedido (Opcional)</label>
                        <input type="text" id="inpNumPedidoOrc" class="form-control" placeholder="Ex: PO-12345">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Desconto (R$)</label>
                        <input type="number" id="inpDescontoOrc" class="form-control text-mono"
                               min="0" step="0.01" value="0" placeholder="0,00">
                    </div>
                    <div id="divResumoOrc" class="mb-3 p-2 bg-light rounded small d-none">
                        <div class="d-flex justify-content-between">
                            <span class="text-body-secondary">Valor original:</span>
                            <span id="spanValorOriginalOrc" class="text-mono fw-medium">R$ 0,00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-body-secondary">Desconto:</span>
                            <span id="spanDescontoOrc" class="text-mono text-danger">R$ 0,00</span>
                        </div>
                        <div class="d-flex justify-content-between border-top mt-1 pt-1">
                            <span class="fw-semibold">A receber:</span>
                            <span id="spanValorLiquidoOrc" class="text-mono fw-bold text-success">R$ 0,00</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmarRetiradaOrc" class="btn btn-success">Confirmar Retirada</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Registrar Pagamento Antecipado (10D-3) -->
<div class="modal fade" id="modalPagarAntecipadoOrc" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-currency-circle-dollar me-2"></i>Registrar Pagamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small">Equipamento: <strong id="pagarOrcEquipNome"></strong></p>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ph ph-info me-1"></i>
                    O equipamento permanecerá na assistência até a retirada física.
                    Estoque e OS <strong>não</strong> serão alterados agora.
                </div>
                <div class="mb-3">
                    <label class="form-label">Forma de Pagamento <span class="text-danger">*</span></label>
                    <select id="selFormaPagAntecipadoOrc" class="form-select">
                        <option value="">Selecione...</option>
                        <option value="dinheiro">Dinheiro</option>
                        <option value="pix">PIX</option>
                        <option value="cartao">Cartão</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmarPagarAntecipadoOrc" class="btn btn-primary">
                    <i class="ph ph-check me-1"></i>Registrar Pagamento
                </button>
            </div>
        </div>
    </div>
</div>

<?php $jsVer = substr(md5_file(BASE_PATH . '/public/assets/js/orcamento-detalhe.js'), 0, 8); ?>
<script src="/assets/js/orcamento-detalhe.js?v=<?= $jsVer ?>" defer></script>
