<?php
use App\Core\View;

/** @var array $os */
/** @var array $equip */
/** @var array<int, array<string, mixed>> $equipamentos_os */
/** @var array<int, array<string, mixed>> $itens */
/** @var array<int, string> $fotos */
/** @var array<int, string> $fotos_recepcao */
/** @var string $vista */
/** @var string $csrf_token */
/** @var array<int, array<string, mixed>> $catalogo_fontes */
/** @var array|null $orcamento */
/** @var array<int, array<string, mixed>> $orcamento_itens */
/** @var int $necessidades_pendentes */
/** @var array{pendentes:int, compradas_sem_entrada:int, manuais_sem_entrada:int, entradas_feitas:int, bloqueantes_total:int} $necessidades_resumo */
/** @var array<int, array<string, mixed>> $servicos_terceiros */

$statusEq    = (string) $equip['status_equip'];
$emGarantia  = (int) $equip['em_garantia'] === 1;
$tipoGar     = (string) ($equip['tipo_garantia'] ?? '');
$garantiaAutorizacao = (string) ($equip['garantia_autorizacao'] ?? '');
$obsInt      = (string) ($equip['obs_int'] ?? '');
$obsCli      = (string) ($equip['obs_cli'] ?? '');
$nomeEquip   = (string) ($equip['nome'] ?? '');
$fabricanteEquip = trim((string) ($equip['fabricante'] ?? ''));
$modeloEquip = trim((string) ($equip['modelo'] ?? ''));
$serieEquip  = (string) ($equip['serie'] ?? '');
$defeito     = (string) ($equip['defeito'] ?? '');
$voltagemEquip = trim((string) ($equip['voltagem'] ?? ''));
$caixaEquip    = trim((string) ($equip['cx'] ?? ''));
$totalFotos  = count($fotos);
$totalFotosRecepcao = count($fotos_recepcao);
$totalItens = count($itens);
$equipamentosOs = is_array($equipamentos_os ?? null) ? $equipamentos_os : [];
$totalEquipamentosOs = count($equipamentosOs);
$equipamentoAtualNumero = (int) $equip['ordem_idx'] + 1;
$nivelAcesso = (string) ($usuario['nivel_acesso'] ?? '');
$podeVerFontesPdf = $nivelAcesso === 'admin';
$servicosTerceiros = is_array($servicos_terceiros ?? null) ? $servicos_terceiros : [];
$servicoTerceiroEnviado = null;
foreach ($servicosTerceiros as $servicoTerceiro) {
    if ((string) ($servicoTerceiro['status'] ?? '') === 'enviado') {
        $servicoTerceiroEnviado = $servicoTerceiro;
        break;
    }
}
$podeCancelarServicoTerceiro = $nivelAcesso === 'admin';

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

$statusServicoTerceiroLabel = static fn(string $status): string => match ($status) {
    'aguardando_envio' => 'Aguardando envio',
    'enviado' => 'Enviado',
    'retornado' => 'Retornado',
    'cancelado' => 'Cancelado',
    default => ucfirst($status),
};

$badgeCls = match ($statusEq) {
    'aberta'     => 'status-badge--info',
    'andamento'  => 'status-badge--warning',
    'montagem'   => 'status-badge--brand',
    'pronto'     => 'status-badge--success',
    'retirado'   => 'status-badge--neutral',
    'cancelado'  => 'status-badge--danger',
    'devolvido'  => 'status-badge--neutral',
    'descartado' => 'status-badge--neutral',
    default      => 'status-badge--neutral',
};

$descarteAutorizadoEm  = (string) ($equip['descarte_autorizado_em']  ?? '');
$descarteAutorizadoPor = (string) ($equip['descarte_autorizado_por'] ?? '');
$descarteMeio          = (string) ($equip['descarte_meio']           ?? '');
$descarteExecutadoEm   = (string) ($equip['descarte_executado_em']   ?? '');
$temDescarteAutorizado = $descarteAutorizadoEm !== '' && $statusEq !== 'descartado';
$temDescarteExecutado  = $descarteExecutadoEm  !== '' || $statusEq === 'descartado';
$equipFinalizado       = in_array($statusEq, ['retirado', 'devolvido', 'descartado'], true);
$podeGerenciarServicoTerceiro = !$equipFinalizado && in_array($nivelAcesso, ['admin', 'oficina'], true);
$orcStatus = $orcamento !== null ? (string) ($orcamento['status'] ?? '') : '';

// Heurística de origem dos itens do orçamento (sem migration).
// Compara código e, se vazio, descrição normalizada com os itens do técnico.
$_tecCods  = [];
$_tecDescs = [];
foreach ($itens as $_ti) {
    $_c = strtolower(trim((string) ($_ti['codigo'] ?? '')));
    $_d = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($_ti['descricao'] ?? ''))));
    if ($_c !== '') $_tecCods[$_c]   = true;
    if ($_d !== '') $_tecDescs[$_d]  = true;
}
$orcItensComOrigem = [];
foreach ($orcamento_itens as $_oi) {
    $_c = strtolower(trim((string) ($_oi['codigo'] ?? '')));
    $_d = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($_oi['descricao'] ?? ''))));
    if ($_c !== '') {
        $_origem = isset($_tecCods[$_c]) ? 'diagnostico' : 'recepcao';
    } elseif ($_d !== '' && isset($_tecDescs[$_d])) {
        $_origem = 'diagnostico';
    } else {
        $_origem = 'orcamento';
    }
    $orcItensComOrigem[] = array_merge($_oi, ['_origem' => $_origem]);
}
unset($_ti, $_oi, $_c, $_d, $_origem, $_tecCods, $_tecDescs);
$mostrarItensOrc = false;
$orcItensFornecidosCliente = [];

$mostrarBtnRemontar          = $orcStatus === 'cancelado'
    && in_array($statusEq, ['aberta', 'andamento'], true)
    && $descarteAutorizadoEm === '';
$mostrarBannerDevolucao      = $orcStatus === 'cancelado' && $statusEq === 'montagem';
$mostrarBannerAguardDev      = $orcStatus === 'cancelado'
    && $statusEq === 'pronto'
    && $descarteAutorizadoEm === '';
$mostrarBtnSemConserto         = in_array($statusEq, ['aberta', 'andamento'], true)
    && !in_array($orcStatus, ['aprovado', 'cancelado'], true) // 9H-4: pronto/retirado removidos
    && $descarteAutorizadoEm === '';
$mostrarBannerIniciarMontagem  = $orcStatus === 'aprovado'
    && $statusEq === 'andamento'
    && $descarteAutorizadoEm === ''
    && (int) $necessidades_pendentes === 0;
$mostrarBannerAprovBloqueado   = $orcStatus === 'aprovado'
    && $statusEq === 'andamento'
    && $descarteAutorizadoEm === ''
    && (int) $necessidades_pendentes > 0;
$mostrarBannerMontagemAndamento = $orcStatus === 'aprovado' && $statusEq === 'montagem';
$mostrarBannerProntoAprovado    = $orcStatus === 'aprovado' && $statusEq === 'pronto';
$mostrarBannerRetirado          = $statusEq === 'retirado';
$mostrarBannerDevolvido         = $statusEq === 'devolvido';
$diagnosticoConcluido           = !empty($equip['diagnostico_concluido_em']);
$podeConcluirDiagnostico        = !$equipFinalizado
    && $statusEq === 'andamento'
    && !$diagnosticoConcluido
    && !in_array($orcStatus, ['aprovado', 'cancelado'], true);
// Badge comercial: calculado a partir do status do orçamento + status do equipamento.
// Diferencia estados intermediários para que o texto reflita a etapa atual do fluxo.
// Apenas status textual — nenhum valor financeiro é exibido.
if ($orcStatus === 'aprovado') {
    if ($statusEq === 'pronto') {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--success', 'ph-check-circle', 'Serviço pronto — aguardando retirada pela recepção.',
        ];
    } elseif ($statusEq === 'montagem') {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--success', 'ph-check-circle', 'Orçamento aprovado — montagem/conserto em andamento.',
        ];
    } else {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--warning', 'ph-clock', 'Orçamento aprovado — verificar estoque/peças antes de montar.',
        ];
    }
} elseif ($orcStatus === 'cancelado') {
    if ($statusEq === 'pronto') {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--info', 'ph-check-circle', 'Remontagem concluída — aguardando devolução pela recepção.',
        ];
    } elseif ($statusEq === 'montagem') {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--warning', 'ph-arrow-counter-clockwise', 'Remontagem para devolução em andamento.',
        ];
    } elseif ($descarteAutorizadoEm !== '') {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--warning', 'ph-trash', 'Cliente autorizou descarte — aguardando confirmação física.',
        ];
    } else {
        [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = [
            'status-badge--danger', 'ph-x-circle', 'Cliente recusou o orçamento — remontar para devolução.',
        ];
    }
} else {
    [$orcAvisoCls, $orcAvisoIco, $orcAvisoTxt] = match ($orcStatus) {
        'rascunho' => ['status-badge--neutral',  'ph-pencil',       'Orçamento em elaboração pela recepção.'],
        'enviado'  => ['status-badge--warning',  'ph-clock',        'Orçamento enviado ao cliente — aguardando resposta.'],
        'pronto'   => ['status-badge--brand',    'ph-package',      'Serviço pronto — aguardando retirada.'],
        'retirado' => ['status-badge--neutral',  'ph-check-square', 'Equipamento retirado pelo cliente.'],
        default    => ['status-badge--neutral',  'ph-minus',        'Sem orçamento administrativo.'],
    };
}

$osBadgeCls = match ((string) $os['status']) {
    'aberta'     => 'status-badge--info',
    'andamento'  => 'status-badge--warning',
    'pronto'     => 'status-badge--success',
    'retirado'   => 'status-badge--neutral',
    'cancelado'  => 'status-badge--danger',
    'descartado' => 'status-badge--warning',
    default      => 'status-badge--neutral',
};

$catalogSourcesJson = json_encode(
    $catalogo_fontes,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';

$garantiaResumo = $emGarantia
    ? ($tipoGar !== '' ? $tipoGar : 'Sim')
    : 'Nao';
$statusDesdeEm = (string) ($equip['status_equip_em'] ?? '');
$statusDesdeFmt = $statusDesdeEm !== '' ? date('d/m/Y H:i', strtotime($statusDesdeEm)) : '';
$observacaoRecepcao = trim((string) ($equip['obs_recepcao'] ?? $os['obs_recepcao'] ?? $os['observacao'] ?? $os['obs'] ?? ''));
$prazoResumo = trim((string) ($os['prazo'] ?? $os['prazo_entrega'] ?? $os['data_conclusao'] ?? ''));
$situacaoComercialLabel = $orcStatus !== '' ? $orcStatus : 'sem orçamento';
$metadadosEquipamento = [];
if ($fabricanteEquip !== '') {
    $metadadosEquipamento[] = ['label' => '', 'value' => $fabricanteEquip];
}
if ($modeloEquip !== '') {
    $metadadosEquipamento[] = ['label' => '', 'value' => $modeloEquip, 'mono' => true];
}
if ($serieEquip !== '') {
    $metadadosEquipamento[] = ['label' => 'Série', 'value' => $serieEquip, 'mono' => true];
}
if ($voltagemEquip !== '') {
    $metadadosEquipamento[] = ['label' => '', 'value' => $voltagemEquip];
}
if ($caixaEquip !== '') {
    $metadadosEquipamento[] = ['label' => 'Caixa', 'value' => $caixaEquip, 'id' => 'focus-cx-main'];
}
$metadadosEquipamento[] = ['label' => 'Garantia', 'value' => $garantiaResumo];

$resumoClienteSolicitacao = [
    'cliente' => trim((string) ($os['nome_cliente'] ?? '')),
    'telefone' => trim((string) ($os['telefone'] ?? '')),
    'defeito' => $defeito !== '' ? $defeito : 'Sem defeito informado na recepcao.',
    'observacao_recepcao' => $observacaoRecepcao,
    'data_entrada' => trim((string) ($os['data_entrada'] ?? '')),
    'prazo' => $prazoResumo,
];

$acaoPrincipal = null;
$acoesAndamento = [];
if ($podeConcluirDiagnostico) {
    $acoesAndamento[] = [
        'tag' => 'button',
        'id' => 'btn-concluir-diagnostico',
        'label' => 'Concluir diagnóstico e enviar para recepção',
        'class' => 'btn-success',
        'icon' => 'ph-check-circle',
    ];
}
if ($mostrarBtnSemConserto) {
    $acoesAndamento[] = [
        'tag' => 'button',
        'id' => 'btn-sem-conserto',
        'label' => 'Indicar sem conserto',
        'class' => 'btn-outline-danger',
        'icon' => 'ph-x-circle',
        'title' => 'Use quando o equipamento não tiver conserto viável ou peças disponíveis.',
    ];
}
if ($mostrarBannerIniciarMontagem) {
    $acaoPrincipal = ['href' => '#btn-iniciar-montagem', 'label' => 'Iniciar montagem agora', 'class' => 'btn-success'];
} elseif ($mostrarBtnRemontar) {
    $acaoPrincipal = ['href' => '#btn-remontar-equipamento', 'label' => 'Remontar equipamento', 'class' => 'btn-warning'];
} elseif ($mostrarBannerMontagemAndamento) {
    $acaoPrincipal = ['href' => '#btn-marcar-pronto', 'label' => 'Marcar como pronto', 'class' => 'btn-primary'];
} elseif ($mostrarBannerDevolucao) {
    $acaoPrincipal = ['href' => '#btn-marcar-pronto-devolucao', 'label' => 'Marcar pronto para devolucao', 'class' => 'btn-warning'];
}

$resumoAndamento = [
    'status_atual' => $statusEq,
    'status_badge' => $badgeCls,
    'status_desde' => $statusDesdeFmt,
    'situacao_comercial' => $situacaoComercialLabel,
    'situacao_comercial_badge' => $orcAvisoCls,
    'observacao_comercial' => $orcAvisoTxt,
    'acoes' => $acoesAndamento,
    'acao_principal' => $acaoPrincipal,
];
?>

<div class="tecnico-page d-flex flex-column gap-4"
     data-os-id="<?= View::e((string) $os['id']) ?>"
     data-equip-idx="<?= (int) $equip['ordem_idx'] ?>"
     data-equip-nome="<?= View::e($nomeEquip) ?>"
     data-equip-serie="<?= View::e($serieEquip) ?>"
     data-equip-defeito="<?= View::e($defeito) ?>"
     data-status-equip="<?= View::e($statusEq) ?>"
     data-diagnostico-concluido="<?= $diagnosticoConcluido ? '1' : '0' ?>">

    <?php include __DIR__ . '/partials/_os_top_summary.php'; ?>

    <div id="toast" class="alert alert-success alert-dismissible position-fixed top-0 end-0 m-3" style="z-index:1070" hidden>
        <span id="toast-msg"></span>
        <button type="button" class="btn-close" onclick="this.parentElement.hidden=true"></button>
    </div>

    <section class="tecnico-overview tecnico-overview--summary">
        <?php include __DIR__ . '/partials/_cliente_solicitacao_card.php'; ?>

        <?php include __DIR__ . '/partials/_andamento_os_card.php'; ?>
    </section>

    <div class="tecnico-shell">
        <aside class="card shadow-sm tecnico-sidebar d-none d-lg-flex">
            <div class="card-header border-bottom">
                <i class="ph ph-user"></i>
                <span class="flex-grow-1">Dados do Cliente</span>
            </div>
            <div class="card-body d-flex flex-column gap-3 border-bottom">
                <div class="tecnico-sidebar__summary">
                    <span class="tecnico-sidebar__eyebrow">Cliente da OS</span>
                    <h2 class="tecnico-sidebar__title fw-bold text-dark"><?= View::e((string) $os['nome_cliente']) ?></h2>
                    <div class="tecnico-sidebar__meta mt-2 small text-muted d-flex flex-column gap-1">
                        <?php if (!empty($os['telefone'])): ?>
                            <div><i class="ph ph-phone me-1"></i><?= View::e((string) $os['telefone']) ?></div>
                        <?php endif; ?>
                        <div><i class="ph ph-calendar me-1"></i>Entrada: <?= View::e((string) $os['data_entrada']) ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($equipamentosOs)): ?>
            <div class="card-header border-bottom">
                <i class="ph ph-stack"></i>
                <span class="flex-grow-1">Equipamentos da OS</span>
                <span class="badge bg-secondary"><?= $totalEquipamentosOs ?></span>
            </div>
            <div class="card-body p-2 bg-light border-bottom" style="max-height: 280px; overflow-y: auto;">
                <div class="tecnico-sidebar-equip-list d-flex flex-column gap-2">
                    <?php foreach ($equipamentosOs as $equipNav): ?>
                        <?php
                        $navIdx = (int) ($equipNav['ordem_idx'] ?? 0);
                        $navStatus = (string) ($equipNav['status_equip'] ?? '');
                        $navBadgeCls = match ($navStatus) {
                            'aberta'     => 'status-badge--info',
                            'andamento'  => 'status-badge--warning',
                            'montagem'   => 'status-badge--brand',
                            'pronto'     => 'status-badge--success',
                            'retirado'   => 'status-badge--neutral',
                            'cancelado'  => 'status-badge--danger',
                            'devolvido'  => 'status-badge--neutral',
                            'descartado' => 'status-badge--neutral',
                            default      => 'status-badge--neutral',
                        };
                        $isAtual = $navIdx === (int) $equip['ordem_idx'];
                        ?>
                        <a href="/tecnico/os/<?= rawurlencode((string) $os['id']) ?>/equipamento/<?= $navIdx ?>"
                           class="tecnico-sidebar-equip-item p-2 rounded border bg-white d-flex align-items-center justify-content-between text-decoration-none color-inherit <?= $isAtual ? 'border-primary border-2 bg-primary-subtle shadow-sm' : '' ?>">
                            <div class="d-flex flex-column min-w-0">
                                <span class="fw-semibold text-truncate text-dark small" style="font-size: 0.85rem;">
                                    <?= $navIdx + 1 ?>. <?= View::e((string) (($equipNav['nome'] ?? '') !== '' ? $equipNav['nome'] : 'Sem nome')) ?>
                                </span>
                                <?php if (!empty($equipNav['cx'])): ?>
                                    <span class="text-muted small" style="font-size: 0.75rem;"><i class="ph ph-archive me-1"></i>Caixa <?= View::e((string) $equipNav['cx']) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge <?= $navBadgeCls ?> ms-2 py-0 px-2" style="font-size: 0.7rem;"><?= View::e($navStatus) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($podeVerFontesPdf): ?>
            <div class="card-footer bg-white p-3">
                <a href="/tecnico/catalogo-fontes" class="btn btn-outline-secondary w-100 btn-sm">
                    <i class="ph ph-sliders-horizontal me-1"></i> Configurar fontes
                </a>
            </div>
            <?php endif; ?>
        </aside>

        <div class="tecnico-main">
            <!-- Seletor Dropdown Móvel -->
            <?php if (!empty($equipamentosOs)): ?>
            <div class="tecnico-mobile-equip-selector d-lg-none mb-3 p-3 bg-white border rounded shadow-sm">
                <label for="mobile-equip-select" class="form-label small fw-bold text-muted mb-1"><i class="ph ph-stack me-1"></i>Equipamento Ativo (<?= $totalEquipamentosOs ?> no total)</label>
                <select id="mobile-equip-select" class="form-select border-2 border-primary fw-semibold">
                    <?php foreach ($equipamentosOs as $equipNav): ?>
                        <?php
                        $navIdx = (int) ($equipNav['ordem_idx'] ?? 0);
                        $isAtual = $navIdx === (int) $equip['ordem_idx'];
                        $statusName = View::e($equipNav['status_equip']);
                        $equipName = View::e(($equipNav['nome'] ?? '') !== '' ? $equipNav['nome'] : 'Sem nome');
                        ?>
                        <option value="/tecnico/os/<?= rawurlencode((string) $os['id']) ?>/equipamento/<?= $navIdx ?>" <?= $isAtual ? 'selected' : '' ?>>
                            <?= $navIdx + 1 ?> de <?= $totalEquipamentosOs ?>: <?= $equipName ?> (<?= $statusName ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php include __DIR__ . '/partials/_tech_section_nav.php'; ?>

            <?php include __DIR__ . '/partials/_dados_status_panel.php'; ?>

            <?php if ($diagnosticoConcluido): ?>
                <div class="alert alert-info d-flex align-items-start gap-2 shadow-sm" id="diagnostico-concluido-alert">
                    <i class="ph ph-clipboard-text fs-5 mt-1"></i>
                    <div>
                        <strong>Diagnóstico concluído e enviado para a recepção.</strong>
                        <div class="small text-body-secondary">Aguardando orçamento, aprovação ou cancelamento administrativo.</div>
                    </div>
                </div>
            <?php endif; ?>

            <section class="tecnico-panel" id="tech-panel-pecas" data-tech-panel="pecas" data-tech-label="Peças e serviços" hidden>
                <div class="tecnico-parts-layout">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <i class="ph ph-package"></i>
                            <span class="flex-grow-1">Itens já lançados</span>
                            <span class="tecnico-chip"><span id="tabela-itens-count"><?= number_format($totalItens, 0, ',', '.') ?></span> item(ns)</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="tabela-itens">
                                <thead>
                                    <tr>
                                        <th style="width:150px">Código</th>
                                        <th>Descrição</th>
                                        <th style="width:100px">Qtd</th>
                                        <th style="width:180px">Compra</th>
                                        <th style="width:60px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($itens)): ?>
                                        <tr class="empty-row">
                                            <td colspan="5" class="text-body-secondary text-center py-3">Nenhum item adicionado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($itens as $it): ?>
                                            <?php
                                                $necessidade = isset($it['necessidade_compra']) && is_array($it['necessidade_compra'])
                                                    ? $it['necessidade_compra']
                                                    : null;
                                                $statusCompra = (string) ($necessidade['status'] ?? '');
                                                $produtoId = isset($it['produto_id']) ? (int) $it['produto_id'] : 0;
                                                $controlaEstoque = array_key_exists('controla_estoque', $it)
                                                    ? (int) $it['controla_estoque']
                                                    : 1;
                                                $itemCompravel = $produtoId <= 0 || $controlaEstoque !== 0;
                                                $statusCompraLabel = [
                                                    'pendente' => ['Compra solicitada', 'bg-warning-subtle text-warning-emphasis border-warning-subtle'],
                                                    'comprado' => ['Comprado', 'bg-success-subtle text-success-emphasis border-success-subtle'],
                                                    'cancelado' => ['Compra cancelada', 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'],
                                                ][$statusCompra] ?? null;
                                                $podeSolicitarCompra = !$equipFinalizado
                                                    && $itemCompravel
                                                    && !in_array($statusCompra, ['pendente', 'comprado'], true);
                                            ?>
                                            <tr data-item-id="<?= (int) $it['id'] ?>">
                                                <td class="text-mono"><?= View::e((string) $it['codigo']) ?></td>
                                                <td><?= View::e((string) $it['descricao']) ?></td>
                                                <td><?= View::e((string) $it['qtd']) ?></td>
                                                <td>
                                                    <div class="d-flex flex-wrap align-items-center gap-1">
                                                        <?php if ($statusCompraLabel !== null): ?>
                                                            <span class="badge border <?= $statusCompraLabel[1] ?>">
                                                                <?= View::e($statusCompraLabel[0]) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($podeSolicitarCompra): ?>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-warning js-solicitar-compra"
                                                                    data-id="<?= (int) $it['id'] ?>">
                                                                <i class="ph ph-shopping-cart-simple me-1"></i> Solicitar compra
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!$equipFinalizado): ?>
                                                    <button class="btn-icon text-danger js-remover-item" data-id="<?= (int) $it['id'] ?>" title="Remover">
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
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header">
                            <i class="ph ph-truck"></i>
                            <span class="flex-grow-1">Serviço terceirizado</span>
                            <?php if ($podeGerenciarServicoTerceiro): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btn-servico-terceiro">
                                <i class="ph ph-plus me-1"></i> Recondicionamento / terceirizado
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($servicosTerceiros)): ?>
                                <p class="text-body-secondary small mb-0">Nenhum serviço terceirizado registrado para este equipamento.</p>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($servicosTerceiros as $servico): ?>
                                        <?php
                                            $servStatus = (string) ($servico['status'] ?? '');
                                            $servBadge = match ($servStatus) {
                                                'enviado' => 'status-badge--warning',
                                                'retornado' => 'status-badge--success',
                                                'cancelado' => 'status-badge--neutral',
                                                default => 'status-badge--info',
                                            };
                                        ?>
                                        <div class="border rounded p-3 bg-light" data-servico-terceiro-id="<?= (int) $servico['id'] ?>">
                                            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?= View::e($tipoServicoTerceiroLabel((string) ($servico['tipo'] ?? ''))) ?>
                                                        <span class="status-badge <?= $servBadge ?> ms-1">
                                                            <?= View::e($statusServicoTerceiroLabel($servStatus)) ?>
                                                        </span>
                                                    </div>
                                                    <div class="small text-body-secondary mt-1">
                                                        Fornecedor: <?= View::e((string) ($servico['fornecedor_nome'] ?: '-')) ?>
                                                        · Saída: <?= View::e($fmtDataTerceiro((string) ($servico['saida_em'] ?? ''))) ?>
                                                        · Previsão: <?= View::e($fmtDataTerceiro((string) ($servico['previsao_retorno'] ?? ''))) ?>
                                                    </div>
                                                    <?php if (!empty($servico['retorno_em'])): ?>
                                                        <div class="small text-body-secondary mt-1">
                                                            Retorno: <?= View::e($fmtDataTerceiro((string) $servico['retorno_em'], true)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($servico['observacao'])): ?>
                                                        <div class="small mt-2"><?= nl2br(View::e((string) $servico['observacao'])) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($servico['observacao_retorno'])): ?>
                                                        <div class="small mt-2 text-success"><?= nl2br(View::e((string) $servico['observacao_retorno'])) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (in_array($servStatus, ['aguardando_envio', 'enviado'], true)): ?>
                                                <div class="d-flex gap-2 flex-wrap">
                                                    <?php if ($podeGerenciarServicoTerceiro && $servStatus === 'enviado'): ?>
                                                    <button type="button"
                                                            class="btn btn-success btn-sm js-servico-terceiro-retorno"
                                                            data-id="<?= (int) $servico['id'] ?>">
                                                        <i class="ph ph-check-circle me-1"></i> Registrar retorno
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($podeCancelarServicoTerceiro): ?>
                                                    <button type="button"
                                                            class="btn btn-outline-danger btn-sm js-servico-terceiro-cancelar"
                                                            data-id="<?= (int) $servico['id'] ?>">
                                                        <i class="ph ph-x-circle me-1"></i> Cancelar
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$equipFinalizado): ?>
                    <div class="card shadow-sm tecnico-side-card">
                        <div class="card-header">
                            <i class="ph ph-plus-circle"></i>
                            <span class="flex-grow-1">Lançamento rápido</span>
                        </div>
                        <div class="card-body d-flex flex-column gap-3">
                            <div class="tecnico-tip-box">
                                <i class="ph ph-info" style="font-size:1rem;flex-shrink:0;margin-top:.05rem"></i>
                                <span>Lance primeiro as peças e serviços para orçamento. A descrição aceita busca multi-termos e o código acelera quando você já conhece a peça.</span>
                            </div>

                            <form id="form-novo-item" class="tecnico-quick-form" autocomplete="off">
                                <div class="position-relative dropdown" id="wrap-codigo">
                                    <label class="form-label small">Código <span class="text-body-secondary">(2+ digitos)</span></label>
                                    <input type="text" name="codigo" id="input-codigo" placeholder="Buscar codigo..." class="form-control text-mono" autocomplete="off" data-ac-trigger="codigo">
                                </div>

                                <div class="position-relative dropdown" id="wrap-descricao">
                                    <label class="form-label small">Descrição <span class="text-body-secondary">(2+ chars · multi-termos)</span></label>
                                    <input type="text" name="descricao" id="input-descricao" placeholder="Ex.: induzido 220v bosch..." required class="form-control" autocomplete="off" data-ac-trigger="descricao">
                                </div>

                                <div>
                                    <label class="form-label small">Qtd</label>
                                    <input type="number" name="qtd" placeholder="Qtd" step="0.001" value="1" required class="form-control">
                                </div>

                                <input type="hidden" name="produto_id" value="">
                                <input type="hidden" name="valor_unit" value="0">

                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ph ph-plus me-1"></i> Adicionar item
                                </button>
                            </form>

                            <div class="tecnico-side-card__note">
                                <i class="ph ph-lightbulb me-1"></i>Use o campo de M.O. no orçamento para mão de obra principal. O autocomplete bloqueia os códigos reservados para evitar lançamento errado.
                            </div>
                            <ul id="autocomplete-results" class="dropdown-menu shadow autocomplete-list"></ul>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($mostrarItensOrc): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="ph ph-list-checks"></i>
                        <span class="flex-grow-1 fw-semibold">Itens aprovados no orçamento</span>
                        <?php if ($orcStatus !== ''): ?>
                            <span class="status-badge <?= $orcAvisoCls ?>"><?= View::e($orcStatus) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($orcStatus === 'aprovado'): // 9H-4: pronto removido do modelo ?>
                    <div class="alert alert-warning mb-0 py-2 px-3 small border-0 border-bottom rounded-0">
                        <i class="ph ph-warning me-1"></i>
                        Confira esta lista antes de iniciar ou finalizar a montagem.
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($orcItensFornecidosCliente)): ?>
                    <div class="alert alert-info mb-0 py-2 px-3 small border-0 border-bottom rounded-0">
                        <i class="ph ph-hand-coins me-1"></i>
                        Peças fornecidas pelo cliente:
                        <?= View::e(implode(', ', array_map(
                            static fn(array $item): string => (string) ($item['descricao'] ?? ''),
                            $orcItensFornecidosCliente
                        ))) ?>
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:120px">Código</th>
                                    <th>Descrição</th>
                                    <th style="width:70px">Qtd</th>
                                    <th style="width:55px">Un</th>
                                    <th style="width:110px">Origem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orcItensComOrigem as $orcItem): ?>
                                    <tr>
                                        <td class="text-mono"><?= View::e((string) ($orcItem['codigo'] ?? '')) ?></td>
                                        <td>
                                            <?= View::e((string) ($orcItem['descricao'] ?? '')) ?>
                                            <?php if ((int) ($orcItem['fornecido_cliente'] ?? 0) === 1): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-1">
                                                    Cliente trouxe
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= View::e((string) ($orcItem['qtd'] ?? '')) ?></td>
                                        <td class="text-body-secondary"><?= View::e((string) ($orcItem['unidade'] ?? 'un')) ?></td>
                                        <td>
                                            <?php if ($orcItem['_origem'] === 'diagnostico'): ?>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Diagnóstico</span>
                                            <?php elseif ($orcItem['_origem'] === 'recepcao'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Recepção</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Orçamento</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer text-body-secondary small">
                        <i class="ph ph-info me-1"></i>
                        Lista final usada pela recepção. Valores não exibidos neste painel.
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <section class="tecnico-panel" id="tech-panel-laudo" data-tech-panel="laudo" data-tech-label="Laudo técnico" hidden>
                <div class="card shadow-sm">
                    <div class="card-header"><i class="ph ph-clipboard-text"></i> Laudo técnico</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <label class="form-label small fw-semibold">Observação interna</label>
                                <textarea id="obs-int" rows="10" class="form-control" placeholder="Descreva os detalhes técnicos, defeitos encontrados e ações realizadas..."<?= $equipFinalizado ? ' readonly' : '' ?>><?= View::e($obsInt) ?></textarea>
                                <div class="form-text">Visível para orçamento e demais módulos internos.</div>
                            </div>
                            <div class="col-lg-5">
                                <label class="form-label small fw-semibold">Observação para o cliente</label>
                                <textarea id="obs-cli" rows="10" class="form-control" placeholder="Mensagem clara para o cliente..."<?= $equipFinalizado ? ' readonly' : '' ?>><?= View::e($obsCli) ?></textarea>
                                <div class="form-text">Será impressa no orçamento enviado ao cliente.</div>
                            </div>
                        </div>
                        <?php if (!$equipFinalizado): ?>
                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                            <button id="btn-salvar-laudo" class="btn btn-primary px-4">
                                <i class="ph ph-floppy-disk me-1"></i> Salvar laudo
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="tecnico-panel" id="tech-panel-midias" data-tech-panel="midias" data-tech-label="Fotos e evidências" hidden>
                <div class="row g-4">
                    <div class="col-12 col-xxl-7">
                        <div class="card shadow-sm h-100">
                            <div class="card-header">
                                <i class="ph ph-image-square"></i>
                                <span class="flex-grow-1">Fotos da análise técnica</span>
                                <span id="fotos-count" class="badge bg-secondary"><?= $totalFotos ?> arquivo(s)</span>
                            </div>
                            <div class="card-body">
                                <div id="fotos-grid" class="row g-2 mb-3">
                                    <?php if (empty($fotos)): ?>
                                        <p class="text-body-secondary small mb-0" id="fotos-empty">Nenhuma foto enviada.</p>
                                    <?php else: ?>
                                        <?php foreach ($fotos as $url): ?>
                                            <div class="col-6 col-sm-4 col-md-3 foto-item" data-url="<?= View::e($url) ?>">
                                                <div class="position-relative">
                                                    <a href="<?= View::e($url) ?>" target="_blank" rel="noopener">
                                                        <img src="<?= View::e($url) ?>" alt="Foto da análise" loading="lazy"
                                                             class="img-fluid rounded border tecnico-media-thumb">
                                                    </a>
                                                    <?php if (!$equipFinalizado): ?>
                                                    <button class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 js-remover-foto"
                                                            data-url="<?= View::e($url) ?>" title="Remover" style="line-height:1;padding:2px 6px">
                                                        <i class="ph ph-x"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$equipFinalizado): ?>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <label class="btn btn-outline-secondary btn-sm mb-0">
                                        <i class="ph ph-camera me-1"></i> Adicionar fotos
                                        <input type="file" id="input-fotos" accept="image/jpeg,image/png,image/webp" capture="environment" multiple hidden>
                                    </label>
                                    <small class="text-body-secondary">Use a câmera ou selecione arquivos · até 10 MB cada</small>
                                    <progress id="prog-fotos" max="100" value="0" hidden style="flex:1;max-width:220px"></progress>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xxl-5">
                        <div class="card shadow-sm h-100">
                            <div class="card-header">
                                <i class="ph ph-camera"></i>
                                <span class="flex-grow-1">Fotos da recepção</span>
                                <span class="badge bg-secondary"><?= $totalFotosRecepcao ?> foto(s)</span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($fotos_recepcao)): ?>
                                    <div class="row g-2">
                                        <?php foreach ($fotos_recepcao as $url): ?>
                                            <div class="col-6 col-md-4">
                                                <a href="<?= View::e($url) ?>" target="_blank" rel="noopener" class="d-block position-relative">
                                                    <img src="<?= View::e($url) ?>" alt="Foto da recepção" loading="lazy"
                                                         class="img-fluid rounded border tecnico-media-thumb">
                                                    <span class="tecnico-media-tag">recepção</span>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-body-secondary small mb-0">Nenhuma foto de recepção vinculada a esta OS.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tecnico-panel" id="tech-panel-vista" data-tech-panel="vista" data-tech-label="Vista explodida" hidden>
                <div class="row g-4">
                    <div class="col-12 col-xxl-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header">
                                <i class="ph ph-blueprint"></i>
                                <span class="flex-grow-1">Arquivo atual</span>
                            </div>
                            <div class="card-body d-flex flex-column gap-3">
                                <div id="vista-current" class="tecnico-vista-current">
                                    <?php if ($vista !== ''): ?>
                                        <div class="tecnico-vista-current__filled">
                                            <span class="tecnico-chip tecnico-chip--success">Vinculada</span>
                                            <a href="<?= View::e($vista) ?>" target="_blank" rel="noopener" class="fw-medium">
                                                <i class="ph ph-arrow-square-out me-1"></i> Abrir vista atual
                                            </a>
                                            <?php if (!$equipFinalizado): ?>
                                            <button id="btn-remover-vista" class="btn btn-sm btn-outline-danger">Remover</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="tecnico-vista-current__empty">
                                            <span class="tecnico-chip">Sem arquivo</span>
                                            <p class="text-body-secondary small mb-0">Busque em uma fonte do catálogo ou envie manualmente o PDF correto.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="tecnico-tip-box">
                                    <i class="ph ph-info" style="font-size:1rem;flex-shrink:0;margin-top:.05rem"></i>
                                    <span>Priorize sempre a vista vinculada ao modelo exato. Se a fonte externa abrir apenas uma página de busca, valide o arquivo antes de vincular.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xxl-8">
                        <div class="card shadow-sm">
                            <div class="card-header">
                                <i class="ph ph-magnifying-glass"></i>
                                <span class="flex-grow-1">Buscar no catálogo</span>
                                <?php if ($podeVerFontesPdf): ?>
                                <a href="/tecnico/catalogo-fontes" class="btn btn-sm btn-outline-secondary">
                                    <i class="ph ph-sliders-horizontal me-1"></i> Fontes
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body d-flex flex-column gap-3">
                                <div class="tecnico-source-pills" id="cat-source-pills"></div>

                                <div class="row g-3 align-items-end">
                                    <div class="col-lg-4">
                                        <label class="form-label small">Fonte ativa</label>
                                        <select id="cat-fonte" class="form-select"></select>
                                    </div>
                                    <div class="col-lg-8">
                                        <div class="tecnico-catalog-hint" id="cat-source-summary">Selecione a fonte e busque pelo modelo correto.</div>
                                    </div>
                                </div>

                                <div id="cat-controls" class="row g-3 align-items-end"></div>

                                <div class="row g-3 align-items-end">
                                    <div class="col-lg">
                                        <label class="form-label small" id="cat-busca-label">Modelo / busca</label>
                                        <input type="text" id="cat-busca" class="form-control" placeholder="Digite o modelo..." autocomplete="off">
                                        <div class="form-text" id="cat-source-help">Use o modelo e a marca do equipamento para refinar a busca.</div>
                                    </div>
                                    <div class="col-lg-auto d-flex gap-2">
                                        <button type="button" class="btn btn-outline-secondary" id="btn-cat-prefill">
                                            <i class="ph ph-sparkle me-1"></i> Usar dados do equipamento
                                        </button>
                                        <button type="button" class="btn btn-primary" id="btn-cat-buscar">
                                            <i class="ph ph-magnifying-glass me-1"></i> Buscar
                                        </button>
                                    </div>
                                </div>

                                <div id="cat-results"></div>
                            </div>
                        </div>

                        <?php if (!$equipFinalizado): ?>
                        <div class="card shadow-sm mt-4">
                            <div class="card-header">
                                <i class="ph ph-upload-simple"></i>
                                <span class="flex-grow-1">Upload manual</span>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <label class="btn btn-outline-secondary btn-sm mb-0">
                                        <i class="ph ph-upload-simple me-1"></i> Selecionar arquivo
                                        <input type="file" id="input-vista" accept="application/pdf,image/jpeg,image/png,image/webp" hidden>
                                    </label>
                                    <small class="text-body-secondary">PDF, JPEG, PNG ou WebP · até 20 MB</small>
                                    <progress id="prog-vista" max="100" value="0" hidden style="flex:1;max-width:220px"></progress>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Modal: Concluir diagnostico -->
    <div class="modal fade" id="modal-concluir-diagnostico" tabindex="-1" aria-labelledby="modalConcluirDiagnosticoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalConcluirDiagnosticoLabel">
                        <i class="ph ph-clipboard-text me-1 text-success"></i>Concluir diagnóstico?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">
                        Você lançou peças, serviços ou observações para este equipamento. Deseja concluir o diagnóstico e enviar para a recepção?
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="btn-continuar-editando">
                        Não, continuar editando
                    </button>
                    <button type="button" class="btn btn-success" id="btn-confirmar-concluir-diagnostico">
                        <i class="ph ph-check-circle me-1"></i> Sim, concluir e avisar recepção
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Indicar sem conserto (9F-9) -->
    <div class="modal fade" id="modal-sem-conserto" tabindex="-1" aria-labelledby="modalSemConsertoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalSemConsertoLabel">
                        <i class="ph ph-x-circle me-1 text-danger"></i>Indicar equipamento sem conserto
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-body-secondary small mb-3">
                        O motivo será registrado no laudo interno e ficará visível para a recepção.
                        O status do equipamento será marcado como <strong>cancelado</strong>.
                    </p>
                    <label class="form-label fw-semibold" for="input-motivo-sem-conserto">
                        Motivo técnico <span class="text-danger">*</span>
                    </label>
                    <textarea id="input-motivo-sem-conserto" class="form-control" rows="4" maxlength="500"
                              placeholder="Ex: peça fora de linha, fabricante sem reposição, equipamento inviável, custo não compensa..."></textarea>
                    <div class="form-text text-danger d-none" id="erro-motivo-sem-conserto">
                        Motivo obrigatório (mínimo 10 caracteres).
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btn-confirmar-sem-conserto">
                        <i class="ph ph-x-circle me-1"></i> Confirmar sem conserto
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Servico terceirizado -->
    <div class="modal fade" id="modal-servico-terceiro" tabindex="-1" aria-labelledby="modalServicoTerceiroLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalServicoTerceiroLabel">
                        <i class="ph ph-truck me-1 text-primary"></i>Serviço terceirizado
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="servico-terceiro-tipo">Tipo</label>
                            <select id="servico-terceiro-tipo" class="form-select">
                                <option value="rebobinamento">Recondicionamento</option>
                                <option value="outro">Outro serviço terceirizado</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="servico-terceiro-item">Item técnico</label>
                            <select id="servico-terceiro-item" class="form-select">
                                <option value="">Equipamento inteiro</option>
                                <?php foreach ($itens as $it): ?>
                                    <option value="<?= (int) $it['id'] ?>">
                                        <?= View::e((string) ($it['descricao'] ?? 'Item técnico')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="servico-terceiro-fornecedor">Fornecedor / terceiro</label>
                            <input type="text" id="servico-terceiro-fornecedor" class="form-control" maxlength="150" placeholder="Nome do terceiro responsável">
                            <div class="form-text">Obrigatório quando a data de saída for preenchida.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="servico-terceiro-saida">Data de saída</label>
                            <input type="date" id="servico-terceiro-saida" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="servico-terceiro-previsao">Previsão de retorno</label>
                            <input type="date" id="servico-terceiro-previsao" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="servico-terceiro-observacao">Observação</label>
                            <textarea id="servico-terceiro-observacao" class="form-control" rows="3" maxlength="1000"></textarea>
                        </div>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0">
                        Esta ação não baixa estoque, não cria necessidade de compra, não altera orçamento, financeiro, NF ou WhatsApp.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btn-salvar-servico-terceiro">
                        <i class="ph ph-floppy-disk me-1"></i> Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Retorno de servico terceirizado -->
    <div class="modal fade" id="modal-servico-terceiro-retorno" tabindex="-1" aria-labelledby="modalServicoTerceiroRetornoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalServicoTerceiroRetornoLabel">
                        <i class="ph ph-check-circle me-1 text-success"></i>Registrar retorno
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="servico-terceiro-retorno-id" value="">
                    <label class="form-label small fw-semibold" for="servico-terceiro-retorno-observacao">Observação de retorno</label>
                    <textarea id="servico-terceiro-retorno-observacao" class="form-control" rows="4" maxlength="1000"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btn-confirmar-servico-terceiro-retorno">
                        <i class="ph ph-check-circle me-1"></i> Registrar retorno
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="tecnico-sticky-bar d-lg-none position-sticky bottom-0 start-0 w-100 border-top p-3 z-3 d-flex align-items-center justify-content-between">
        <a href="/tecnico" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="ph ph-arrow-left"></i> Painel
        </a>
        <span class="status-badge <?= $badgeCls ?>" id="status-sticky-mobile"><?= View::e($statusEq) ?></span>
    </div>
</div>

<script id="catalog-fontes-data" type="application/json"><?= $catalogSourcesJson ?></script>
<?php $jsVer = substr(md5_file(BASE_PATH . '/public/assets/js/tecnico-detalhe.js'), 0, 8); ?>
<script src="/assets/js/tecnico-detalhe.js?v=<?= $jsVer ?>" defer></script>
