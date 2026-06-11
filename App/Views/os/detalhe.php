<?php
use App\Core\Auth;
use App\Core\View;
use App\Helpers\ClienteHelper;
/**
 * @var array  $os
 * @var array  $equipamentos
 * @var array  $orcamentosPorEquip   Orçamento indexado por equip_idx (int)
 * @var array<int, array{pendentes:int, compradas_sem_entrada:int, manuais_sem_entrada:int, entradas_feitas:int, bloqueantes_total:int}> $resumoNecessidades
 * @var ?array $aceite
 * @var array  $notifRetirada
 * @var array<int,string> $mapaUsuarios  Mapa id → nome para usuários do destino físico
 */
$nivelUser   = Auth::user()['nivel_acesso'] ?? '';
$podeOrcamento = in_array($nivelUser, ['admin', 'recepcao'], true);
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
// 'retirado' é o status interno legado para OS encerrada — exibe label amigável.
// Internamente continua sendo 'retirado'; não criar status novo por ora.
$stLabel = match ($st) {
    'retirado'   => 'Encerrada',
    'descartado' => 'Descartada',
    default      => ucfirst($st),
};

$waTel = ClienteHelper::telefoneParaWhatsapp([
    'telefone' => (string) ($os['telefone'] ?? ''),
]) ?? '';
$contatoTel = trim((string) ($os['contato_telefone'] ?? ''));
$waDestino = $contatoTel !== ''
    ? ClienteHelper::telefoneParaWhatsapp([], $contatoTel)
    : $waTel;
$nomeClienteWhatsapp = ClienteHelper::nomeParaMensagem(['nome_cliente' => (string) ($os['nome_cliente'] ?? '')]);

// True quando todos os equipamentos têm status_equip='pronto'.
// Usado para exibir botão de retirada quando o macro da OS ficou desatualizado
// (TecnicoService não avança OS para 'pronto' diretamente — aguarda concluir()).
$todosEquipProntos = !empty($equipamentos)
    && count(array_filter($equipamentos, fn($e) => (string)$e['status_equip'] !== 'pronto')) === 0;

$listaEqs = [];
$tipoGarantiaWhatsapp = '';
foreach ($equipamentos as $e) {
    if ((int) ($e['em_garantia'] ?? 0) === 1) {
        $tipo = (string) ($e['tipo_garantia'] ?? '');
        if ($tipo === 'fabricante') {
            $tipoGarantiaWhatsapp = 'fabricante';
        } elseif ($tipo === 'loja' && $tipoGarantiaWhatsapp !== 'fabricante') {
            $tipoGarantiaWhatsapp = 'loja';
        } elseif ($tipoGarantiaWhatsapp === '') {
            $tipoGarantiaWhatsapp = 'generica';
        }
    }

    $nomeEq = trim((string) ($e['nome'] ?? ''));
    if ($nomeEq === '') {
        continue;
    }

    $fabricante = trim((string) ($e['fabricante'] ?? ''));
    $modelo = trim((string) ($e['modelo'] ?? ''));
    $tensao = trim((string) ($e['voltagem'] ?? ''));
    $linhaEq = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
        $nomeEq,
        $fabricante,
        $modelo,
    ], static fn($valor) => trim((string) $valor) !== ''))) ?? '');

    if ($tensao !== '') {
        $linhaEq .= ' - ' . $tensao;
    }

    $listaEqs[] = '• ' . $linhaEq;
}
$emGarantiaWhatsapp = $tipoGarantiaWhatsapp !== '';
$textoGarantiaWhatsapp = match ($tipoGarantiaWhatsapp) {
    'fabricante' => ' em garantia do fabricante',
    'loja'       => ' em garantia da loja',
    'generica'   => ' em garantia',
    default      => '',
};
$saudacao = ClienteHelper::saudacaoPorHorario();
$waText  = $nomeClienteWhatsapp !== ''
    ? "{$saudacao}, *{$nomeClienteWhatsapp}*, tudo bem?\n\n"
    : "{$saudacao}, tudo bem?\n\n";
$waText .= "Sua Ordem de Serviço{$textoGarantiaWhatsapp} *#{$os['id']}* foi registrada na Multimáquinas.\n\n";
$waText .= "Equipamento(s):\n" . implode("\n", $listaEqs) . "\n\n";
if (!$emGarantiaWhatsapp && !empty($aceite) && empty($aceite['aceito_em'])) {
    $termoUrl = (new \App\Services\TermoService())->gerarUrl($aceite['slug']);
    $waText .= "Leia e aceite nosso Termo de Responsabilidade:\n{$termoUrl}\n\n";
}
$waText .= $emGarantiaWhatsapp
    ? "Assim que a análise da garantia estiver pronta, avisaremos.\n\n"
    : "Assim que o orçamento estiver pronto, avisaremos.\n\n";
$waText .= "Obrigado pela confiança! — Multimáquinas";

$fotoModalUrl = '';
foreach ($equipamentos as $equipamentoFoto) {
    if ((int) ($equipamentoFoto['ordem_idx'] ?? -1) !== 0) {
        continue;
    }
    $fotosArr = json_decode((string) ($equipamentoFoto['fotos_os_json'] ?? '[]'), true);
    if (is_array($fotosArr) && !empty($fotosArr[0])) {
        $fotoModalUrl = trim((string) $fotosArr[0]);
    }
    break;
}
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="page-header__title">
                    OS <span class="text-mono">#<?= View::e($os['id']) ?></span>
                </h1>
                <span class="status-badge <?= $badgeCls ?>"><?= View::e($stLabel) ?></span>
            </div>
            <p class="page-header__subtitle">
                Criada em <span class="text-mono"><?= date('d/m/Y H:i', strtotime($os['created_at'])) ?></span>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/os" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <?php if (!empty($waDestino)): ?>
                <button type="button"
                        class="btn btn-outline-success"
                        id="btn-wa-os"
                        data-bs-toggle="modal"
                        data-bs-target="#modalWaOs"
                        data-tel="<?= View::e($waDestino) ?>"
                        data-msg="<?= View::e($waText) ?>"
                        data-foto="<?= View::e($fotoModalUrl) ?>"
                        data-os="<?= View::e((string) ($os['id'] ?? '')) ?>">
                    <i class="ph ph-whatsapp-logo me-1"></i> WhatsApp
                </button>
            <?php endif; ?>
            <a href="/os/<?= View::e($os['id']) ?>/imprimir" target="_blank" class="btn btn-outline-secondary">
                <i class="ph ph-printer me-1"></i> Imprimir
            </a>
            <?php if ($st === 'aberta' || $st === 'andamento'): ?>
                <a href="/os/<?= View::e($os['id']) ?>/editar" class="btn btn-primary">
                    <i class="ph ph-pencil-simple me-1"></i> Editar OS
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Grid: coluna esquerda (info) + direita (equipamentos) -->
    <div class="row g-4">

        <!-- Coluna ESQUERDA -->
        <div class="col-lg-4 d-flex flex-column gap-3">

            <!-- Status atual + acoes -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h2 class="fs-6 fw-semibold mb-0">Status atual</h2>
                        <span class="status-badge <?= $badgeCls ?>"><?= View::e($stLabel) ?></span>
                    </div>

                    <?php if (in_array($st, ['aberta', 'andamento', 'pronto'], true)): ?>
                        <small class="text-body-secondary text-uppercase fw-semibold d-block mb-2">Mudar status</small>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($st === 'aberta'): ?>
                                <?php if ($podeOrcamento && count($equipamentos) === 1 && $todosEquipProntos): ?>
                                    <!-- Equipamento já está 'pronto'; macro OS não foi atualizado — conclusão administrativa primeiro -->
                                    <button class="btn btn-sm btn-outline-success btn-status" data-status="pronto">
                                        <i class="ph ph-check-circle me-1"></i> Concluir OS
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary btn-status" data-status="andamento">
                                        <i class="ph ph-wrench me-1"></i> Em andamento
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger btn-status" data-status="cancelado">
                                        <i class="ph ph-x-circle me-1"></i> Cancelar
                                    </button>
                                <?php endif; ?>
                            <?php elseif ($st === 'andamento'): ?>
                                <?php if ($podeOrcamento): ?>
                                <button class="btn btn-sm btn-outline-success btn-status" data-status="pronto">
                                    <i class="ph ph-check-circle me-1"></i> Concluir OS
                                </button>
                                <?php endif; ?>
                            <?php elseif ($st === 'pronto'): ?>
                                <?php if (count($equipamentos) === 1): ?>
                                    <button class="btn btn-sm btn-outline-secondary btn-status" data-status="retirado">
                                        <i class="ph ph-package me-1"></i> Retirado (entregue)
                                    </button>
                                <?php else: ?>
                                    <p class="small text-body-secondary mb-0">
                                        <i class="ph ph-info me-1"></i>
                                        Use o botão <strong>Retirar</strong> em cada equipamento individualmente.
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cliente -->
            <div class="card shadow-sm">
                <div class="card-header"><i class="ph ph-user"></i> Cliente</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Nome</dt>
                        <dd class="col-12 fw-medium mb-2"><?= View::e($os['nome_cliente']) ?></dd>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Telefone</dt>
                        <dd class="col-12 text-mono mb-2"><?= View::e($os['telefone'] ?: '—') ?></dd>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">CPF / CNPJ</dt>
                        <dd class="col-12 text-mono <?= (!empty($os['contato_nome']) || !empty($os['contato_telefone'])) ? 'mb-2' : 'mb-0' ?>"><?= View::e($os['doc_cliente'] ?: '—') ?></dd>
                        <?php if (!empty($os['contato_nome']) || !empty($os['contato_telefone'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Contato responsável</dt>
                        <dd class="col-12 mb-0">
                            <?php if (!empty($os['contato_nome'])): ?>
                                <span class="fw-medium"><?= View::e($os['contato_nome']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($os['contato_telefone'])): ?>
                                <?php $contatoTelefone = (string) $os['contato_telefone']; ?>
                                <span class="text-mono text-body-secondary ms-2"><?= View::e($contatoTelefone) ?></span>
                                <?php if (str_contains($contatoTelefone, '@g.us')): ?>
                                    <span class="badge text-bg-info ms-1"><i class="ph ph-whatsapp-logo me-1"></i>Grupo</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success ms-1"><i class="ph ph-whatsapp-logo me-1"></i>WA</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($os['cliente_id']): ?>
                        <div class="mt-3 pt-3 border-top text-end">
                            <a href="/clientes/<?= (int)$os['cliente_id'] ?>" class="small fw-medium text-decoration-none">
                                Ver ficha completa <i class="ph ph-arrow-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info da OS -->
            <div class="card shadow-sm">
                <div class="card-header"><i class="ph ph-info"></i> Informacoes da OS</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Recebido por</dt>
                        <dd class="col-12 mb-2"><?= View::e($os['usuario_recebeu'] ?: '—') ?></dd>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Data de entrada</dt>
                        <dd class="col-12 text-mono mb-2"><?= date('d/m/Y H:i', strtotime($os['data_entrada'])) ?></dd>
                        <?php if (!empty($os['data_conclusao'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Conclusao</dt>
                        <dd class="col-12 text-mono mb-0"><?= date('d/m/Y H:i', strtotime($os['data_conclusao'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <?php
            $fotosRecepcao = [];
            if (!empty($equipamentos[0]['fotos_os_json'])) {
                $decoded = json_decode((string)$equipamentos[0]['fotos_os_json'], true);
                if (is_array($decoded)) $fotosRecepcao = array_values(array_filter($decoded, 'is_string'));
            }
            if (!empty($fotosRecepcao)):
            ?>
            <!-- Fotos da recepcao -->
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="ph ph-camera"></i>
                    <span class="flex-grow-1">Fotos da recepcao</span>
                    <span class="badge bg-secondary"><?= count($fotosRecepcao) ?></span>
                </div>
                <div class="card-body">
                    <div class="row row-cols-3 row-cols-sm-4 g-2">
                        <?php foreach ($fotosRecepcao as $url): ?>
                            <div class="col">
                                <a href="<?= View::e($url) ?>" target="_blank" class="d-block aspect-square rounded-3 overflow-hidden border">
                                    <img src="<?= View::e($url) ?>" alt="Foto recepcao" class="w-100 h-100 object-cover" loading="lazy">
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Termo -->
            <div class="card shadow-sm">
                <div class="card-header"><i class="ph ph-file-text"></i> Termo de Responsabilidade</div>
                <div class="card-body">
                    <?php if ($aceite === null): ?>
                        <div class="alert alert-warning small d-flex align-items-start gap-2 mb-0">
                            <i class="ph ph-warning flex-shrink-0 mt-1"></i>
                            <span>Aceite digital nao gerado para esta OS.</span>
                        </div>
                    <?php elseif ($aceite['aceito_em'] !== null): ?>
                        <div class="alert alert-success small mb-0">
                            <div class="d-flex align-items-center gap-2 fw-semibold">
                                <i class="ph ph-check-circle"></i> Aceite registrado
                            </div>
                            <div class="small text-body-secondary mt-1">
                                Em <span class="text-mono"><?= date('d/m/Y \a\s H:i', strtotime($aceite['aceito_em'])) ?></span><br>
                                IP: <span class="text-mono"><?= View::e($aceite['ip_cliente'] ?? '—') ?></span>
                            </div>
                        </div>
                    <?php else:
                        $termoUrl = (new \App\Services\TermoService())->gerarUrl($aceite['slug']);
                    ?>
                        <div class="alert alert-warning small mb-2">
                            <div class="d-flex align-items-center gap-2 fw-semibold">
                                <i class="ph ph-clock"></i> Aguardando aceite
                            </div>
                            <div class="small text-body-secondary mt-1">
                                Link gerado em <span class="text-mono"><?= date('d/m/Y H:i', strtotime($aceite['created_at'])) ?></span>
                            </div>
                        </div>
                        <a href="<?= View::e($termoUrl) ?>" target="_blank" class="small fw-medium text-decoration-none">
                            <i class="ph ph-arrow-square-out me-1"></i> Abrir pagina do aceite
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notificacoes de retirada -->
            <?php if (in_array($st, ['pronto', 'cancelado'], true) && !empty($notifRetirada)): ?>
            <div class="card shadow-sm">
                <div class="card-header"><i class="ph ph-megaphone"></i> Notificacoes de retirada</div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush small">
                        <?php foreach (array_slice($notifRetirada, 0, 5) as $notif): ?>
                            <?php $icon = match($notif['tipo']) { 'whatsapp' => 'ph-whatsapp-logo', 'email' => 'ph-envelope', 'ligacao' => 'ph-phone', default => 'ph-bell' }; ?>
                            <div class="list-group-item">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <span class="fw-medium">
                                        <i class="ph <?= $icon ?> me-1"></i>
                                        <?= ucfirst(View::e($notif['tipo'])) ?>
                                        <?php if (!empty($notif['usuario_nome'])): ?>
                                            <span class="text-body-secondary fw-normal">por <?= View::e($notif['usuario_nome']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-mono text-body-secondary"><?= date('d/m/Y H:i', strtotime($notif['enviado_em'])) ?></span>
                                </div>
                                <?php if (!empty($notif['obs'])): ?>
                                    <div class="text-body-secondary fst-italic mt-1"><?= View::e($notif['obs']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($notif['print_path'])): ?>
                                    <a href="<?= View::e($notif['print_path']) ?>" target="_blank" class="small text-decoration-none mt-1 d-inline-block">
                                        <i class="ph ph-paperclip me-1"></i> Ver comprovante
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($notifRetirada) > 5): ?>
                            <div class="list-group-item text-center">
                                <a href="/alertas/retirada" class="small fw-medium text-decoration-none">
                                    Ver todas (<?= count($notifRetirada) ?>) <i class="ph ph-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Coluna DIREITA: Equipamentos com status por equipamento -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="ph ph-wrench"></i>
                    <span class="flex-grow-1">Equipamentos na OS</span>
                    <span class="badge bg-secondary"><?= count($equipamentos) ?> equipamento(s)</span>
                </div>

                <div class="card-body d-flex flex-column gap-3">
                    <?php foreach ($equipamentos as $eq):
                        $eqIdx = (int) $eq['ordem_idx'];
                        $statusEq = (string) $eq['status_equip'];
                        $orc = $orcamentosPorEquip[$eqIdx] ?? null;
                        $statusOrc = $orc ? (string) $orc['status'] : null;
                        $necResumo = $resumoNecessidades[$eqIdx] ?? null;
                        $necBloqueantes = $necResumo !== null ? (int) $necResumo['bloqueantes_total'] : 0;

                        // Badge cor: status técnico do equipamento
                        $eqBadgeCls = match ($statusEq) {
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
                        $eqBadgeTxt = match ($statusEq) {
                            'aberta'     => 'Aguard. diagnóstico',
                            'andamento'  => 'Em diagnóstico',
                            'montagem'   => 'Em montagem',
                            'pronto'     => 'Pronto',
                            'retirado'   => 'Retirado',
                            'cancelado'  => 'Cancelado',
                            'devolvido'  => 'Devolvido',
                            'descartado' => 'Descartado',
                            default      => ucfirst($statusEq),
                        };

                        // Badge cor: status do orçamento
                        $orcBadgeCls = match ($statusOrc) {
                            'rascunho'  => 'status-badge--neutral',
                            'enviado'   => 'status-badge--info',
                            'aprovado'  => 'status-badge--success',
                            'cancelado' => 'status-badge--danger',
                            'pronto'    => 'status-badge--success',
                            'retirado'  => 'status-badge--neutral',
                            default     => 'status-badge--neutral',
                        };
                        $orcBadgeTxt = match ($statusOrc) {
                            'rascunho'  => 'Rascunho',
                            'enviado'   => 'Enviado',
                            'aprovado'  => 'Aprovado',
                            'cancelado' => 'Cancelado',
                            'pronto'    => 'Pronto',
                            'retirado'  => 'Retirado',
                            null        => 'Sem orçamento',
                            default     => ucfirst((string) $statusOrc),
                        };

                        // Etapa operacional: calculada na view, sem gravar no banco
                        $etapa = match (true) {
                            $statusEq === 'devolvido'
                                => ['txt' => 'Devolvido ao cliente', 'cls' => 'text-body-secondary'],
                            $statusEq === 'descartado'
                                => ['txt' => 'Descartado', 'cls' => 'text-body-secondary'],
                            $statusEq === 'cancelado'
                                => ['txt' => 'Cancelado — aguardando destino', 'cls' => 'text-danger'],
                            $statusEq === 'pronto' && $statusOrc === 'cancelado'
                                => ['txt' => 'Pronto para devolução', 'cls' => 'text-warning-emphasis fw-semibold'],
                            $statusEq === 'pronto'
                                => ['txt' => 'Pronto para retirada', 'cls' => 'text-success fw-semibold'],
                            $statusEq === 'montagem'
                                => ['txt' => 'Em montagem / conserto', 'cls' => 'text-primary fw-semibold'],
                            $statusOrc === 'aprovado' && $statusEq === 'andamento'
                                => ['txt' => 'Aprovado — aguard. montagem', 'cls' => 'text-primary'],
                            $statusOrc === 'enviado'
                                => ['txt' => 'Aguardando aprovação do cliente', 'cls' => 'text-warning-emphasis'],
                            $statusOrc === 'rascunho' && $orc !== null
                                => ['txt' => 'Orçamento em rascunho', 'cls' => 'text-body-secondary'],
                            $orc === null && $statusEq === 'andamento'
                                => ['txt' => 'Em diagnóstico', 'cls' => 'text-warning-emphasis'],
                            $orc === null
                                => ['txt' => 'Aguardando diagnóstico', 'cls' => 'text-body-secondary'],
                            default
                                => ['txt' => 'Em andamento', 'cls' => 'text-body-secondary'],
                        };
                    ?>
                        <div class="border rounded-3 p-3 bg-body-tertiary">

                            <!-- Cabeçalho do equipamento: nome + status técnico -->
                            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap mb-2">
                                <div>
                                    <h3 class="fs-6 fw-semibold text-primary mb-0">
                                        <span class="text-body-secondary fw-normal me-1">#<?= $eqIdx + 1 ?></span>
                                        <?= View::e($eq['nome']) ?>
                                    </h3>
                                    <span class="small <?= $etapa['cls'] ?>">
                                        <i class="ph ph-arrow-right me-1"></i><?= $etapa['txt'] ?>
                                    </span>
                                </div>
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <?php if (!empty($eq['em_garantia'])): ?>
                                        <span class="status-badge status-badge--info">
                                            <i class="ph ph-shield-check"></i>
                                            Garantia (<?= View::e($eq['tipo_garantia'] ?? 'loja') ?>)
                                        </span>
                                        <?php if (!empty($eq['garantia_autorizacao'])): ?>
                                            <span class="status-badge status-badge--secondary" title="Autorização / RMA do fabricante">
                                                <i class="ph ph-hash me-1"></i><?= View::e($eq['garantia_autorizacao']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($necBloqueantes > 0): ?>
                                        <span class="status-badge status-badge--warning"
                                              title="Aguardando peças — Pendentes: <?= $necResumo['pendentes'] ?> · Compradas s/ entrada: <?= $necResumo['compradas_sem_entrada'] ?> · Itens manuais sem produto: <?= $necResumo['manuais_sem_entrada'] ?>">
                                            <i class="ph ph-clock me-1"></i>Aguardando peças (<?= $necBloqueantes ?>)
                                        </span>
                                    <?php endif; ?>
                                    <span class="status-badge <?= $eqBadgeCls ?>"><?= $eqBadgeTxt ?></span>
                                </div>
                            </div>

                            <!-- Defeito -->
                            <div class="small text-danger fst-italic mb-2">
                                <i class="ph ph-warning me-1"></i><?= View::e($eq['defeito'] ?: 'Defeito não informado') ?>
                            </div>

                            <?php
                            $obsIntEq = (string) ($eq['obs_int'] ?? '');
                            $semConsertoPos = $statusEq === 'cancelado' ? strpos($obsIntEq, 'Sem conserto viável:') : false;
                            if ($semConsertoPos !== false):
                                $motivoRaw = trim(substr($obsIntEq, $semConsertoPos + strlen('Sem conserto viável:')));
                                $motivoPrimLinha = explode("\n", $motivoRaw)[0];
                            ?>
                            <div class="alert alert-danger py-2 px-3 small mb-2 d-flex align-items-start gap-2">
                                <i class="ph ph-x-circle flex-shrink-0 mt-1"></i>
                                <span>
                                    <strong>Sem conserto viável:</strong>
                                    <?= View::e($motivoPrimLinha) ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <?php if ($necBloqueantes > 0): ?>
                            <div class="alert alert-warning py-1 px-2 small mb-2">
                                <i class="ph ph-clock me-1"></i>
                                <strong>Aguardando peças</strong> —
                                <?php
                                    $partes = [];
                                    if ($necResumo['pendentes'] > 0)
                                        $partes[] = $necResumo['pendentes'] . ' pendente(s)';
                                    if ($necResumo['compradas_sem_entrada'] > 0)
                                        $partes[] = $necResumo['compradas_sem_entrada'] . ' comprada(s) s/ entrada';
                                    if ($necResumo['manuais_sem_entrada'] > 0)
                                        $partes[] = $necResumo['manuais_sem_entrada'] . ' item(ns) manual(is) sem produto vinculado';
                                    echo implode(' · ', $partes);
                                ?>
                                <a href="/compras/necessidades?os_id=<?= rawurlencode((string) $eq['os_id']) ?>"
                                   class="ms-1 link-warning fw-semibold" target="_blank">
                                    Ver necessidades <i class="ph ph-arrow-square-out"></i>
                                </a>
                            </div>
                            <?php endif; ?>

                            <!-- Dados técnicos resumidos -->
                            <div class="d-flex flex-wrap gap-3 small text-body-secondary mb-3">
                                <?php $fabr = (string) ($eq['fabricante'] ?? ''); ?>
                                <?php $mdl  = (string) ($eq['modelo']     ?? ''); ?>
                                <?php if ($fabr !== '' || $mdl !== ''): ?>
                                    <span>
                                        <span class="fw-semibold">Fabricante:</span>
                                        <?= $fabr !== '' ? View::e($fabr) : '<span class="fst-italic text-body-tertiary">—</span>' ?>
                                        <?php if ($mdl !== ''): ?>
                                            &middot; <span class="fw-semibold">Modelo:</span> <span class="text-mono"><?= View::e($mdl) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($eq['serie']): ?>
                                    <span><span class="fw-semibold">Série:</span> <span class="text-mono"><?= View::e($eq['serie']) ?></span></span>
                                <?php endif; ?>
                                <?php if ($eq['voltagem']): ?>
                                    <span><span class="fw-semibold">Volt.:</span> <?= View::e($eq['voltagem']) ?></span>
                                <?php endif; ?>
                                <?php if ($eq['cx']): ?>
                                    <span><span class="fw-semibold">Caixa:</span> <?= View::e($eq['cx']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($eq['status_equip_em'])): ?>
                                    <span title="Data em que o status atual foi registrado">
                                        <span class="fw-semibold">Status desde:</span>
                                        <?= date('d/m/Y H:i', strtotime((string) $eq['status_equip_em'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Status do orçamento + WhatsApp -->
                            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap pt-2 border-top">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="small text-body-secondary">Orçamento:</span>
                                    <span class="status-badge <?= $orcBadgeCls ?>"><?= $orcBadgeTxt ?></span>
                                    <?php if ($orc && !empty($orc['total']) && (float)$orc['total'] > 0): ?>
                                        <span class="small fw-semibold">
                                            R$ <?= number_format((float)$orc['total'], 2, ',', '.') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($orc && !empty($orc['wpp_enviado_em'])): ?>
                                        <span class="small text-body-secondary" title="WhatsApp enviado">
                                            <i class="ph ph-whatsapp-logo text-success"></i>
                                            <?= date('d/m H:i', strtotime($orc['wpp_enviado_em'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php // 9J-1: badge de gratuidade quando total=0 e motivo definido
                                    if ($orc && (float) $orc['total'] == 0.0 && !empty($orc['motivo_gratuidade'])):
                                        $mgLabel = (string) $orc['motivo_gratuidade'] === 'garantia_fabricante'
                                            ? 'Garantia fabricante' : 'Cortesia';
                                    ?>
                                        <span class="status-badge status-badge--info" title="Motivo da gratuidade">
                                            <i class="ph ph-tag me-1"></i><?= View::e($mgLabel) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Links de ação por perfil -->
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($podeOrcamento): ?>
                                        <a href="/orcamento/<?= View::e($os['id']) ?>#equip-<?= $eqIdx ?>"
                                           class="btn btn-outline-primary btn-sm"
                                           title="Abrir orçamento deste equipamento">
                                            <i class="ph ph-currency-circle-dollar me-1"></i>Orçamento
                                        </a>
                                    <?php endif; ?>
                                    <a href="/tecnico/os/<?= View::e($os['id']) ?>/equipamento/<?= $eqIdx ?>"
                                       class="btn btn-outline-secondary btn-sm"
                                       title="Painel técnico">
                                        <i class="ph ph-wrench me-1"></i>Técnico
                                    </a>
                                    <?php
                                        $descarteAutorizado = !empty($eq['descarte_autorizado_em']);
                                    ?>
                                    <?php
                                        $podeDevolver = $podeOrcamento
                                            && !$descarteAutorizado
                                            && !in_array($st, ['retirado', 'descartado'])
                                            && (
                                                $statusEq === 'cancelado'
                                                || ($statusEq === 'pronto' && $statusOrc === 'cancelado')
                                            );
                                    ?>
                                    <?php if ($podeDevolver): ?>
                                        <!-- Devolução: cancelado aguardando destino, ou pronto após remontagem com orçamento cancelado -->
                                        <?php if ($statusEq === 'pronto' && $statusOrc === 'cancelado'): ?>
                                            <button class="btn btn-warning btn-sm btn-devolver-equip"
                                                    data-equip-idx="<?= $eqIdx ?>"
                                                    data-equip-nome="<?= View::e($eq['nome']) ?>"
                                                    title="Equipamento remontado/pronto para devolução ao cliente.">
                                                <i class="ph ph-arrow-u-up-left me-1"></i>Registrar devolução
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-warning btn-sm btn-devolver-equip"
                                                    data-equip-idx="<?= $eqIdx ?>"
                                                    data-equip-nome="<?= View::e($eq['nome']) ?>">
                                                <i class="ph ph-arrow-u-up-left me-1"></i>Devolver
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($podeOrcamento && !$descarteAutorizado
                                        && !in_array($statusEq, ['retirado', 'devolvido', 'descartado'])
                                        && ($statusEq === 'cancelado' || $statusOrc === 'cancelado')
                                        && $statusOrc !== 'aprovado'
                                        && !in_array($st, ['retirado', 'descartado'])): ?>
                                        <!-- Autorizar descarte: orçamento/equipamento cancelado, sem descarte já autorizado -->
                                        <button class="btn btn-outline-danger btn-sm btn-autorizar-descarte"
                                                data-equip-idx="<?= $eqIdx ?>"
                                                data-equip-nome="<?= View::e($eq['nome']) ?>">
                                            <i class="ph ph-trash me-1"></i>Autorizar descarte
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($descarteAutorizado && $statusEq !== 'descartado' && !in_array($st, ['retirado', 'descartado'])): ?>
                                        <!-- Descarte autorizado e OS aberta: botão para confirmar execução física -->
                                        <button class="btn btn-danger btn-sm btn-confirmar-descarte"
                                                data-equip-idx="<?= $eqIdx ?>"
                                                data-equip-nome="<?= View::e($eq['nome']) ?>"
                                                title="Autorizado por <?= View::e($eq['descarte_autorizado_por'] ?? '') ?> via <?= View::e($eq['descarte_meio'] ?? '') ?>">
                                            <i class="ph ph-trash me-1"></i>Confirmar descarte
                                        </button>
                                    <?php elseif ($descarteAutorizado && $statusEq !== 'descartado'): ?>
                                        <!-- OS encerrada mas descarte ainda não confirmado: só badge informativo -->
                                        <span class="badge bg-warning text-dark"
                                              title="Descarte autorizado por <?= View::e($eq['descarte_autorizado_por'] ?? '') ?>">
                                            <i class="ph ph-trash me-1"></i>Descarte autorizado
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($podeOrcamento && $statusEq === 'pronto' && !in_array($st, ['cancelado', 'retirado', 'descartado']) && count($equipamentos) > 1):
                                        // 9H-4: apenas 'aprovado' — 'pronto' removido do modelo comercial.
                                        // Bloqueia: null, rascunho, enviado, cancelado.
                                        $orcAprovado   = $orc !== null && (string) $orc['status'] === 'aprovado';
                                        // Indica se este equip tem cobrança (orç. aprovado com total > 0).
                                        // Usado pelo JS para exibir/ocultar a seção de pagamento no modal.
                                        $equipTemValor = $orcAprovado && (float) $orc['total'] > 0.0;
                                    ?>
                                        <!-- Botão de retirada individual — apenas para OS com múltiplos equipamentos.
                                             OS com 1 equipamento usa o botão "Retirado (entregue)" no painel de status. -->
                                        <?php if ($orcAprovado): ?>
                                        <button class="btn btn-success btn-sm btn-retirar-equip"
                                                data-equip-idx="<?= $eqIdx ?>"
                                                data-equip-nome="<?= View::e($eq['nome']) ?>"
                                                data-tem-valor="<?= $equipTemValor ? '1' : '0' ?>"
                                                data-valor-total="<?= number_format((float)($orc['total'] ?? 0), 2, '.', '') ?>">
                                            <i class="ph ph-package me-1"></i>Retirar
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-outline-secondary btn-sm" disabled
                                                title="Orçamento precisa estar aprovado para retirada.">
                                            <i class="ph ph-lock me-1"></i>Retirar
                                        </button>
                                        <?php endif; ?>
                                    <?php elseif ($podeOrcamento && $statusEq === 'retirado' && count($equipamentos) > 1): ?>
                                        <button class="btn btn-danger btn-sm btn-desfazer-retirada-equip"
                                                data-equip-idx="<?= $eqIdx ?>"
                                                data-equip-nome="<?= View::e($eq['nome']) ?>">
                                            <i class="ph ph-arrow-u-up-left me-1"></i>Desfazer Retirada
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php
                                // ── Seção "Destino físico" ────────────────────────────────────────────
                                // Exibida quando o equipamento tem informação relevante de devolução ou descarte.
                                // Não aparece para status em processo técnico (aberta, andamento, montagem, pronto).
                                $dfDevolucaoEm      = (string) ($eq['devolucao_em']            ?? '');
                                $dfDevolucaoUid     = (int)    ($eq['devolucao_uid']            ?? 0);
                                $dfAutoEm           = (string) ($eq['descarte_autorizado_em']   ?? '');
                                $dfAutoPor          = (string) ($eq['descarte_autorizado_por']  ?? '');
                                $dfAutoUid          = (int)    ($eq['descarte_autorizado_uid']  ?? 0);
                                $dfAutoMeio         = (string) ($eq['descarte_meio']            ?? '');
                                $dfExecEm           = (string) ($eq['descarte_executado_em']    ?? '');
                                $dfExecUid          = (int)    ($eq['descarte_executado_uid']   ?? 0);

                                // Nomes dos operadores a partir do mapa (query única feita no controller)
                                $dfNomeDevolucao    = $dfDevolucaoUid > 0 ? ($mapaUsuarios[$dfDevolucaoUid] ?? null) : null;
                                $dfNomeAutoReg      = $dfAutoUid      > 0 ? ($mapaUsuarios[$dfAutoUid]      ?? null) : null;
                                $dfNomeExec         = $dfExecUid      > 0 ? ($mapaUsuarios[$dfExecUid]      ?? null) : null;

                                $dfIsDevolvido      = $statusEq === 'devolvido';
                                $dfIsDescartado     = $statusEq === 'descartado';
                                $dfIsCancelado      = $statusEq === 'cancelado';
                                $dfTemAuto          = $dfAutoEm !== '';
                                $dfPorAbandono      = str_contains($dfAutoPor, 'Abandono legal');

                                // Mostra seção se: devolvido, descartado, cancelado aguardando destino,
                                // ou autorização de descarte registrada (mesmo antes de executado).
                                $dfMostrar = $dfIsDevolvido || $dfIsDescartado
                                          || $dfIsCancelado
                                          || ($dfTemAuto && !$dfIsDescartado);
                            ?>
                            <?php if ($dfMostrar): ?>
                            <div class="mt-3 pt-3 border-top" style="font-size:.82rem;">
                                <div class="text-body-secondary fw-semibold text-uppercase mb-2"
                                     style="font-size:.68rem;letter-spacing:.06em;">
                                    Destino físico
                                </div>

                                <?php if ($dfIsDevolvido): ?>
                                    <div class="d-flex align-items-start gap-2">
                                        <span class="status-badge status-badge--neutral flex-shrink-0">
                                            <i class="ph ph-arrow-u-up-left me-1"></i>Devolvido ao cliente
                                        </span>
                                        <div class="text-body-secondary">
                                            <?php if ($dfDevolucaoEm): ?>
                                                <div>em <?= date('d/m/Y H:i', strtotime($dfDevolucaoEm)) ?><?= $dfNomeDevolucao ? ' por <strong>' . View::e($dfNomeDevolucao) . '</strong>' : '' ?></div>
                                            <?php endif; ?>
                                            <div class="fst-italic">Equipamento devolvido ao cliente sem conserto.</div>
                                        </div>
                                    </div>

                                <?php elseif ($dfIsDescartado): ?>
                                    <div class="d-flex align-items-start gap-2">
                                        <span class="status-badge status-badge--neutral flex-shrink-0">
                                            <i class="ph ph-trash me-1"></i>Descartado
                                        </span>
                                        <div class="text-body-secondary">
                                            <?php if ($dfExecEm): ?>
                                                <div>em <?= date('d/m/Y H:i', strtotime($dfExecEm)) ?><?= $dfNomeExec ? ' por <strong>' . View::e($dfNomeExec) . '</strong>' : '' ?></div>
                                            <?php endif; ?>
                                            <?php if ($dfPorAbandono): ?>
                                                <div class="fst-italic">
                                                    <i class="ph ph-warning me-1"></i>Descarte por abandono legal/prazo (90 dias).
                                                </div>
                                            <?php elseif ($dfAutoPor): ?>
                                                <div>
                                                    Autorizado por <strong><?= View::e($dfAutoPor) ?></strong><?= $dfAutoMeio ? ' via ' . View::e($dfAutoMeio) : '' ?>.
                                                </div>
                                                <div class="fst-italic">Descarte autorizado pelo cliente.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                <?php elseif ($dfTemAuto): ?>
                                    <!-- Autorização registrada, descarte físico ainda não confirmado -->
                                    <div class="d-flex align-items-start gap-2">
                                        <span class="status-badge status-badge--warning flex-shrink-0">
                                            <i class="ph ph-clock me-1"></i>Descarte autorizado
                                        </span>
                                        <div class="text-body-secondary">
                                            <div>
                                                Autorizado por <strong><?= View::e($dfAutoPor) ?></strong><?= $dfAutoMeio ? ' via ' . View::e($dfAutoMeio) : '' ?>
                                            </div>
                                            <?php if ($dfAutoEm): ?>
                                                <div>em <?= date('d/m/Y H:i', strtotime($dfAutoEm)) ?><?= $dfNomeAutoReg ? ' — registrado por <strong>' . View::e($dfNomeAutoReg) . '</strong>' : '' ?></div>
                                            <?php endif; ?>
                                            <div class="fst-italic text-warning-emphasis">Aguardando confirmação do descarte físico.</div>
                                        </div>
                                    </div>

                                <?php elseif ($dfIsCancelado): ?>
                                    <!-- Cancelado sem destino físico definido -->
                                    <div class="d-flex align-items-center gap-2 text-danger-emphasis">
                                        <i class="ph ph-question flex-shrink-0"></i>
                                        <span>Cancelado — aguardando devolução ou descarte.</span>
                                    </div>
                                <?php endif; ?>

                            </div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Desfazer Retirada por Equipamento -->
<div class="modal fade" id="modalDesfazerRetiradaEquip" tabindex="-1">
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
                <input type="hidden" id="inpDesfazerEquipIdx">
                
                <div class="alert alert-danger bg-white border-danger mb-4">
                    <div class="d-flex">
                        <i class="ph-fill ph-warning-circle fs-4 text-danger me-3 mt-1"></i>
                        <div>
                            <h6 class="fw-bold mb-1 text-danger">Atenção!</h6>
                            <p class="mb-0 text-body-secondary small">
                                Você está prestes a desfazer a retirada do equipamento 
                                <strong id="txtDesfazerEquipNome" class="text-dark"></strong>.<br>
                                O equipamento voltará para o status "Pronto", o financeiro será revertido e as peças retornarão ao estoque.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Motivo da reversão <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="inpJustificativaDesfazer" rows="3" placeholder="Ex: Equipamento retirado por engano..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmarDesfazerRetirada">
                    <i class="ph ph-check-circle me-1"></i>Confirmar Reversão
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Retirada por Equipamento (Etapa 7B) -->
<div class="modal fade" id="modalRetiradaEquip" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-package me-2"></i>Retirar Equipamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small">
                    Equipamento: <strong id="retiradaEquipNome"></strong>
                </p>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ph ph-info me-1"></i>
                    Apenas este equipamento será marcado como retirado.
                    Os demais equipamentos da OS não serão alterados.
                </div>
                <div class="mb-3">
                    <label class="form-label">Nome de quem está retirando <span class="text-danger">*</span></label>
                    <input type="text" id="inpRetiradoPorEquip" class="form-control"
                           placeholder="Nome completo do cliente ou responsável">
                </div>

                <!-- Aviso para retirada intermediária (não é o último equipamento) -->
                <div id="avisoRetiradaIntermediaria" class="alert alert-secondary small py-2 mb-3 d-none">
                    <i class="ph ph-info me-1"></i>
                    Retirada parcial: somente este equipamento será marcado como retirado.
                    Os demais equipamentos da OS não serão alterados.
                </div>

                <!-- Seção de pagamento: exibida quando o equipamento tem cobrança (ou quando for o último) -->
                <div id="secPagamentoEquip">
                    <div class="mb-3">
                        <label class="form-label">Forma de Pagamento <span class="text-danger">*</span></label>
                        <select id="selFormaPagEquip" class="form-select">
                            <option value="">Selecione...</option>
                            <option value="dinheiro">Dinheiro</option>
                            <option value="pix">PIX</option>
                            <option value="cartao">Cartão</option>
                            <option value="faturado">Faturado (B2B)</option>
                        </select>
                    </div>
                    <div id="divNumPedidoEquip" class="mb-3 d-none">
                        <label class="form-label">Nº do Pedido (Opcional)</label>
                        <input type="text" id="inpNumPedidoEquip" class="form-control" placeholder="Ex: PO-12345">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Desconto (R$)</label>
                        <input type="number" id="inpDescontoEquip" class="form-control text-mono"
                               min="0" step="0.01" value="0" placeholder="0,00">
                    </div>
                    <div id="divResumoValorEquip" class="mb-3 p-2 bg-light rounded small d-none">
                        <div class="d-flex justify-content-between">
                            <span class="text-body-secondary">Valor original:</span>
                            <span id="spanValorOriginalEquip" class="text-mono fw-medium">R$ 0,00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-body-secondary">Desconto:</span>
                            <span id="spanDescontoEquip" class="text-mono text-danger">R$ 0,00</span>
                        </div>
                        <div class="d-flex justify-content-between border-top mt-1 pt-1">
                            <span class="fw-semibold">A receber:</span>
                            <span id="spanValorLiquidoEquip" class="text-mono fw-bold text-success">R$ 0,00</span>
                        </div>
                    </div>
                    <p id="retiradaEquipAvisoUltimo" class="small text-warning-emphasis d-none">
                        <i class="ph ph-warning me-1"></i>
                        Este é o <strong>último equipamento</strong> apto. A OS será encerrada e o financeiro será quitado após a confirmação.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmarRetiradaEquip" class="btn btn-success">Confirmar Retirada</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Autorização de Descarte (Etapa 9C-3) -->
<div class="modal fade" id="modalAutorizarDescarte" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph ph-trash me-2"></i>Autorizar Descarte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3 small">
                    Equipamento: <strong id="descarteEquipNome"></strong>
                </p>
                <div class="alert alert-warning small py-2 mb-3">
                    <i class="ph ph-warning me-1"></i>
                    Esta ação <strong>não</strong> baixa estoque, <strong>não</strong> gera financeiro
                    e <strong>não</strong> marca o equipamento como descartado.
                    Apenas registra a autorização e notifica a oficina para realizar o descarte físico.
                </div>
                <div class="mb-3">
                    <label for="inpDescarteAutorizadoPor" class="form-label small fw-semibold">
                        Quem autorizou <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="inpDescarteAutorizadoPor" class="form-control"
                           placeholder="Nome do cliente ou responsável" maxlength="120">
                </div>
                <div class="mb-3">
                    <label for="selDescarteMeio" class="form-label small fw-semibold">
                        Meio da autorização <span class="text-danger">*</span>
                    </label>
                    <select id="selDescarteMeio" class="form-select">
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
                <button type="button" class="btn btn-danger" id="btnConfirmarAutorizarDescarte">
                    <i class="ph ph-trash me-1"></i>Registrar Autorização
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Retirada -->
<div class="modal fade" id="modalRetirada" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar Entrega / Retirada</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Forma de Pagamento <span class="required">*</span></label>
                <select id="selFormaPagamento" class="form-select">
                    <option value="">Selecione...</option>
                    <option value="dinheiro">Dinheiro</option>
                    <option value="pix">PIX</option>
                    <option value="cartao">Cartao</option>
                    <option value="faturado">Faturado (B2B)</option>
                </select>

                <div id="divNumPedido" class="mt-3 d-none">
                    <label class="form-label">No do Pedido (Opcional)</label>
                    <input type="text" id="inpNumPedido" class="form-control" placeholder="Ex: PO-12345">
                </div>
                <div class="mt-3">
                    <label class="form-label">Desconto (R$)</label>
                    <input type="number" id="inpDescontoRetirada" class="form-control text-mono"
                           min="0" step="0.01" value="0" placeholder="0,00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmarRetirada" class="btn btn-primary">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal WhatsApp OS -->
<div class="modal fade" id="modalWaOs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ph ph-whatsapp-logo me-2 text-success"></i>Enviar WhatsApp
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-1">Destinatario</p>
                <p class="mb-3 fw-semibold text-mono" id="wa-modal-tel">—</p>

                <p class="text-muted small mb-1">Mensagem</p>
                <pre class="bg-light rounded p-2 small mb-0" style="white-space:pre-wrap;max-height:180px;overflow-y:auto" id="wa-modal-msg"></pre>

                <div id="wa-modal-foto-wrap" class="mt-3 d-none">
                    <p class="text-muted small mb-1">Foto da recepcao</p>
                    <img id="wa-modal-foto" src="" alt="Foto da recepcao" class="rounded" style="max-height:120px;object-fit:cover;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="wa-modal-enviar">
                    <i class="ph ph-paper-plane-tilt me-1"></i> Enviar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const btnWa     = document.getElementById('btn-wa-os');
    const btnEnviar = document.getElementById('wa-modal-enviar');
    const modalEl   = document.getElementById('modalWaOs');
    if (!btnWa || !btnEnviar || !modalEl) return;

    const elTel      = document.getElementById('wa-modal-tel');
    const elMsg      = document.getElementById('wa-modal-msg');
    const elFotoWrap = document.getElementById('wa-modal-foto-wrap');
    const elFoto     = document.getElementById('wa-modal-foto');

    let dadosEnvio = {};

    modalEl.addEventListener('show.bs.modal', function () {
        dadosEnvio = {
            tel: btnWa.dataset.tel || '',
            msg: btnWa.dataset.msg || '',
            foto: btnWa.dataset.foto || '',
            osId: btnWa.dataset.os || '',
        };

        elTel.textContent = dadosEnvio.tel || '-';
        elMsg.textContent = dadosEnvio.msg || '';

        if (dadosEnvio.foto) {
            elFoto.src = dadosEnvio.foto;
            elFotoWrap.classList.remove('d-none');
        } else {
            elFoto.removeAttribute('src');
            elFotoWrap.classList.add('d-none');
        }

        btnEnviar.disabled = false;
        btnEnviar.innerHTML = '<i class="ph ph-paper-plane-tilt me-1"></i> Enviar';
    });

    btnEnviar.addEventListener('click', function () {
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enviando...';

        fetch('/os/' + encodeURIComponent(dadosEnvio.osId) + '/whatsapp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ mensagem: dadosEnvio.msg, telefone: dadosEnvio.tel }),
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            const bsModal = window.bootstrap ? bootstrap.Modal.getInstance(modalEl) : null;
            if (bsModal) bsModal.hide();
            if (data.success) {
                btnWa.classList.remove('btn-outline-success');
                btnWa.classList.add('btn-success');
                btnWa.removeAttribute('data-bs-toggle');
                btnWa.innerHTML = '<i class="ph ph-check me-1"></i> WhatsApp Enviado';
                btnWa.disabled = true;
                mostrarToast('✓ Mensagem enviada via WhatsApp!', 'success');
            } else {
                mostrarToast('✗ ' + (data.error || 'Erro ao enviar.'), 'danger');
                btnEnviar.disabled = false;
                btnEnviar.innerHTML = '<i class="ph ph-paper-plane-tilt me-1"></i> Enviar';
            }
        })
        .catch(function () {
            const bsModal = window.bootstrap ? bootstrap.Modal.getInstance(modalEl) : null;
            if (bsModal) bsModal.hide();
            mostrarToast('✗ Erro de conexao. Verifique os logs.', 'danger');
            btnEnviar.disabled = false;
            btnEnviar.innerHTML = '<i class="ph ph-paper-plane-tilt me-1"></i> Enviar';
        });
    });

    function mostrarToast(msg, tipo) {
        const id = 'toast-wa-' + Date.now();
        const el = document.createElement('div');
        el.id = id;
        el.className = 'alert alert-' + tipo + ' position-fixed bottom-0 end-0 m-3 shadow-lg';
        el.style.cssText = 'z-index:9999;min-width:260px;font-weight:500';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function () {
            const toast = document.getElementById(id);
            if (toast) toast.remove();
        }, 5000);
    }
}());

document.querySelectorAll('.btn-status').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        const novoStatus = e.currentTarget.getAttribute('data-status');

        if (novoStatus === 'retirado') {
            const modalEl = document.getElementById('modalRetirada');
            const modal = new bootstrap.Modal(modalEl);
            const selForma = document.getElementById('selFormaPagamento');
            const divNum = document.getElementById('divNumPedido');
            const inpNum = document.getElementById('inpNumPedido');
            const btnConfirm = document.getElementById('btnConfirmarRetirada');

            selForma.value = '';
            inpNum.value = '';
            divNum.classList.add('d-none');
            document.getElementById('inpDescontoRetirada').value = '0';

            selForma.onchange = () => {
                divNum.classList.toggle('d-none', selForma.value !== 'faturado');
            };

            modal.show();

            btnConfirm.onclick = async () => {
                const forma = selForma.value;
                if (!forma) {
                    alert('Selecione uma forma de pagamento.');
                    return;
                }

                const descontoRet = Math.max(0, parseFloat(document.getElementById('inpDescontoRetirada').value) || 0);
                const bodyData = { status: 'retirado', forma_pagamento: forma };
                if (forma === 'faturado' && inpNum.value.trim()) {
                    bodyData.numero_pedido = inpNum.value.trim();
                }
                if (descontoRet > 0) bodyData.desconto_valor = descontoRet;

                btnConfirm.disabled = true;
                btnConfirm.innerText = 'Processando...';

                try {
                    const res = await fetch(`/api/os/<?= View::e($os['id']) ?>/status`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '<?= View::e(\App\Core\Csrf::token()) ?>'
                        },
                        body: JSON.stringify(bodyData)
                    });
                    const json = await res.json();
                    if (json.ok) window.location.reload();
                    else {
                        alert('Erro: ' + json.error);
                        btnConfirm.disabled = false;
                        btnConfirm.innerText = 'Confirmar';
                    }
                } catch (err) {
                    console.error(err);
                    alert('Erro ao atualizar status.');
                    btnConfirm.disabled = false;
                    btnConfirm.innerText = 'Confirmar';
                }
            };
            return;
        }

        if (!confirm(`Deseja alterar o status para "${novoStatus}"?`)) return;
        try {
            const res = await fetch(`/api/os/<?= View::e($os['id']) ?>/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= View::e(\App\Core\Csrf::token()) ?>'
                },
                body: JSON.stringify({ status: novoStatus })
            });
            const json = await res.json();
            if (json.ok) window.location.reload();
            else alert('Erro: ' + json.error);
        } catch (err) {
            console.error(err);
            alert('Erro ao atualizar status.');
        }
    });
});

// ── Retirada parcial por equipamento (Etapa 7B / 7D.1) ───────────────────────
(function () {
    const osId      = '<?= View::e($os['id']) ?>';
    const csrfToken = '<?= View::e(\App\Core\Csrf::token()) ?>';

    // Conta equipamentos ainda não em status final (informa o usuário sobre último).
    const statusEquips = <?= json_encode(
        array_column($equipamentos, 'status_equip'),
        JSON_UNESCAPED_UNICODE
    ) ?>;
    // Terminais físicos: destino físico definido e irreversível.
    // 'cancelado' NÃO está aqui — equipamento cancelado ainda aguarda destino físico.
    const TERMINAIS = ['retirado', 'devolvido', 'descartado'];
    const aptos = statusEquips.filter(s => !TERMINAIS.includes(s)).length;

    // Helpers
    const fmtMoney = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    // Estado do modal para o equipamento corrente.
    let equipIdxAtual = null;
    let equipTemValorAtual = false;
    let equipValorTotalAtual = 0;

    function atualizarResumoEquip() {
        const desconto = Math.max(0, parseFloat(document.getElementById('inpDescontoEquip').value) || 0);
        const liquido  = Math.max(0, equipValorTotalAtual - desconto);
        document.getElementById('spanValorOriginalEquip').textContent = fmtMoney(equipValorTotalAtual);
        document.getElementById('spanDescontoEquip').textContent      = fmtMoney(desconto);
        document.getElementById('spanValorLiquidoEquip').textContent  = fmtMoney(liquido);
    }

    document.getElementById('inpDescontoEquip').addEventListener('input', atualizarResumoEquip);

    // ── Devolução por equipamento (Etapa 9C-2) ────────────────────────────────
    document.querySelectorAll('.btn-devolver-equip').forEach(btn => {
        btn.addEventListener('click', async () => {
            const equipIdx  = parseInt(btn.dataset.equipIdx, 10);
            const equipNome = btn.dataset.equipNome;

            if (!confirm(
                `Confirmar devolução de "${equipNome}" ao cliente?\n\n` +
                `Esta ação registra que o equipamento foi fisicamente devolvido e não pode ser desfeita.`
            )) return;

            btn.disabled = true;
            try {
                const res = await fetch(`/api/os/${osId}/equip/${equipIdx}/devolver`, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({}),
                });
                const json = await res.json();
                if (json.ok) {
                    window.location.reload();
                } else {
                    alert('Erro ao registrar devolução: ' + json.error);
                    btn.disabled = false;
                }
            } catch (err) {
                console.error(err);
                alert('Erro ao registrar devolução.');
                btn.disabled = false;
            }
        });
    });

    // ── Autorização de descarte por equipamento (Etapa 9C-3) ─────────────────
    let descarteEquipIdxAtual = null;
    let modalAutorizarDescarte = null;

    document.querySelectorAll('.btn-autorizar-descarte').forEach(btn => {
        btn.addEventListener('click', () => {
            descarteEquipIdxAtual = parseInt(btn.dataset.equipIdx, 10);
            document.getElementById('descarteEquipNome').textContent = btn.dataset.equipNome;
            document.getElementById('inpDescarteAutorizadoPor').value = '';
            document.getElementById('selDescarteMeio').value = '';
            document.getElementById('btnConfirmarAutorizarDescarte').disabled = false;
            document.getElementById('btnConfirmarAutorizarDescarte').textContent = 'Registrar Autorização';
            
            if (!modalAutorizarDescarte) {
                modalAutorizarDescarte = new bootstrap.Modal(document.getElementById('modalAutorizarDescarte'));
            }
            modalAutorizarDescarte.show();
        });
    });

    document.getElementById('btnConfirmarAutorizarDescarte').addEventListener('click', async () => {
        const autorizadoPor = document.getElementById('inpDescarteAutorizadoPor').value.trim();
        const meio          = document.getElementById('selDescarteMeio').value;
        const btn           = document.getElementById('btnConfirmarAutorizarDescarte');

        if (!autorizadoPor) {
            alert('Informe o nome de quem autorizou o descarte.');
            return;
        }
        if (!meio) {
            alert('Selecione o meio pelo qual o descarte foi autorizado.');
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'Registrando...';

        try {
            const res = await fetch(`/api/os/${osId}/equip/${descarteEquipIdxAtual}/autorizar-descarte`, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ autorizado_por: autorizadoPor, descarte_meio: meio }),
            });
            const json = await res.json();
            if (json.ok) {
                if (modalAutorizarDescarte) modalAutorizarDescarte.hide();
                window.location.reload();
            } else {
                alert('Erro ao registrar autorização: ' + json.error);
                btn.disabled    = false;
                btn.textContent = 'Registrar Autorização';
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao registrar autorização de descarte.');
            btn.disabled    = false;
            btn.textContent = 'Registrar Autorização';
        }
    });

    // ── Confirmação de descarte físico (Etapa 9C-4) ──────────────────────────
    document.querySelectorAll('.btn-confirmar-descarte').forEach(btn => {
        btn.addEventListener('click', async () => {
            const equipIdx  = parseInt(btn.dataset.equipIdx, 10);
            const equipNome = btn.dataset.equipNome;

            if (!confirm(
                `Confirmar que o descarte físico de "${equipNome}" foi executado?\n\n` +
                `Esta ação marcará o equipamento como descartado e não pode ser desfeita.`
            )) return;

            btn.disabled = true;
            try {
                const res = await fetch(`/api/os/${osId}/equip/${equipIdx}/confirmar-descarte`, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({}),
                });
                const json = await res.json();
                if (json.ok) {
                    window.location.reload();
                } else {
                    alert('Erro ao confirmar descarte: ' + json.error);
                    btn.disabled = false;
                }
            } catch (err) {
                console.error(err);
                alert('Erro ao confirmar descarte.');
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.btn-desfazer-retirada-equip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('inpDesfazerEquipIdx').value = btn.dataset.equipIdx;
            document.getElementById('txtDesfazerEquipNome').textContent = btn.dataset.equipNome;
            document.getElementById('inpJustificativaDesfazer').value = '';
            new bootstrap.Modal(document.getElementById('modalDesfazerRetiradaEquip')).show();
        });
    });

    document.getElementById('btnConfirmarDesfazerRetirada').addEventListener('click', async () => {
        const justificativa = document.getElementById('inpJustificativaDesfazer').value.trim();
        if (!justificativa) {
            alert('A justificativa é obrigatória.');
            return;
        }

        const equipIdx = document.getElementById('inpDesfazerEquipIdx').value;
        const btnConfirm = document.getElementById('btnConfirmarDesfazerRetirada');
        
        btnConfirm.disabled = true;
        btnConfirm.innerHTML = '<i class="ph ph-spinner ph-spin me-1"></i>Revertendo...';

        try {
            const res = await fetch(`/api/os/${osId}/equip/${equipIdx}/desfazer-retirada`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ justificativa }),
            });
            const json = await res.json();

            if (json.ok) {
                window.location.reload();
            } else {
                alert('Erro ao desfazer retirada: ' + json.error);
                btnConfirm.disabled = false;
                btnConfirm.innerHTML = '<i class="ph ph-check-circle me-1"></i>Confirmar Reversão';
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao desfazer retirada.');
            btnConfirm.disabled = false;
            btnConfirm.innerHTML = '<i class="ph ph-check-circle me-1"></i>Confirmar Reversão';
        }
    });

    document.querySelectorAll('.btn-retirar-equip').forEach(btn => {
        btn.addEventListener('click', () => {
            equipIdxAtual        = parseInt(btn.dataset.equipIdx, 10);
            equipTemValorAtual   = btn.dataset.temValor === '1';
            equipValorTotalAtual = parseFloat(btn.dataset.valorTotal || '0');
            const eEhUltimo      = aptos === 1; // este é o único ainda apto

            document.getElementById('retiradaEquipNome').textContent = btn.dataset.equipNome;
            document.getElementById('inpRetiradoPorEquip').value = '';
            document.getElementById('selFormaPagEquip').value = '';
            document.getElementById('inpNumPedidoEquip').value = '';
            document.getElementById('inpDescontoEquip').value = '0';
            document.getElementById('divNumPedidoEquip').classList.add('d-none');

            // Resumo de valor: exibir apenas quando há cobrança neste equip.
            const divResumo = document.getElementById('divResumoValorEquip');
            divResumo.classList.toggle('d-none', !equipTemValorAtual);
            if (equipTemValorAtual) atualizarResumoEquip();

            // Seção de pagamento: exibir quando este equip tem cobrança OU é o último.
            // (Último sem cobrança: seção aparece mas usuário pode deixar em branco.)
            const secPagamento = document.getElementById('secPagamentoEquip');
            secPagamento.classList.toggle('d-none', !equipTemValorAtual && !eEhUltimo);

            // Aviso intermediário: mostrar quando NÃO for o último.
            document.getElementById('avisoRetiradaIntermediaria').classList.toggle('d-none', eEhUltimo);

            // Aviso de último equipamento.
            document.getElementById('retiradaEquipAvisoUltimo').classList.toggle('d-none', !eEhUltimo);

            new bootstrap.Modal(document.getElementById('modalRetiradaEquip')).show();
        });
    });

    document.getElementById('selFormaPagEquip').addEventListener('change', function () {
        document.getElementById('divNumPedidoEquip').classList.toggle('d-none', this.value !== 'faturado');
    });

    document.getElementById('btnConfirmarRetiradaEquip').addEventListener('click', async () => {
        const eEhUltimo  = aptos === 1;
        const forma      = document.getElementById('selFormaPagEquip').value;
        const retPor     = document.getElementById('inpRetiradoPorEquip').value.trim();
        const numPed     = document.getElementById('inpNumPedidoEquip').value.trim();
        const desconto   = Math.max(0, parseFloat(document.getElementById('inpDescontoEquip').value) || 0);
        const btnConfirm = document.getElementById('btnConfirmarRetiradaEquip');

        // Forma de pagamento obrigatória quando equip tem cobrança.
        if (equipTemValorAtual && !forma) {
            alert('Selecione uma forma de pagamento (este equipamento possui valor a receber).');
            return;
        }
        if (!retPor) {
            alert('Informe o nome de quem está retirando.');
            return;
        }
        if (desconto < 0 || (equipValorTotalAtual > 0 && desconto > equipValorTotalAtual)) {
            alert('Desconto inválido. Deve ser entre 0 e ' + fmtMoney(equipValorTotalAtual) + '.');
            return;
        }
        if (desconto > 0) {
            const liquido = equipValorTotalAtual - desconto;
            if (!confirm(`Aplicar desconto de ${fmtMoney(desconto)}?\nValor a receber: ${fmtMoney(liquido)}`)) return;
        }

        const body = { retirado_por: retPor };
        if (forma) {
            body.forma_pagamento = forma;
            if (forma === 'faturado' && numPed) body.numero_pedido = numPed;
        }
        if (desconto > 0) body.desconto_valor = desconto;

        btnConfirm.disabled    = true;
        btnConfirm.textContent = 'Processando...';

        try {
            const res = await fetch(`/api/os/${osId}/equip/${equipIdxAtual}/retirar`, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify(body),
            });
            const json = await res.json();
            if (json.ok) {
                window.location.reload();
            } else {
                alert('Erro: ' + json.error);
                btnConfirm.disabled    = false;
                btnConfirm.textContent = 'Confirmar Retirada';
            }
        } catch (err) {
            console.error(err);
            alert('Erro ao processar retirada.');
            btnConfirm.disabled    = false;
            btnConfirm.textContent = 'Confirmar Retirada';
        }
    });
}());
</script>
