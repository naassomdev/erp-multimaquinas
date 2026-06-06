<?php
use App\Core\View;
/**
 * @var array  $nota
 * @var array  $settings
 * @var array  $identificadores
 * @var string $csrf_token
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt    = fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';

$badgeCls = match ($nota['status']) {
    'autorizada' => 'status-badge--success',
    'rejeitada'  => 'status-badge--danger',
    'cancelada'  => 'status-badge--neutral',
    'rascunho'   => 'status-badge--info',
    'erro'       => 'status-badge--danger',
    default      => 'status-badge--warning',
};
$badgeTxt = match ($nota['status']) {
    'autorizada' => 'Autorizada',
    'rejeitada'  => 'Rejeitada',
    'cancelada'  => 'Cancelada',
    'rascunho'   => 'Rascunho',
    'erro'       => 'Erro',
    default      => 'Pendente',
};
$chaveAcesso = (string)($identificadores['chave_acesso'] ?? '');
$idDps = (string)($identificadores['id_dps'] ?? '');
$podeReemitir = in_array($nota['status'], ['pendente', 'rejeitada'], true);
$podeCancelar = $nota['status'] === 'autorizada';
$temXml       = !empty($nota['xml_retorno']);
?>
<div class="d-flex flex-column gap-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-file-text"></i> NFS-e #<?= (int)$nota['id'] ?>
                <span class="status-badge <?= $badgeCls ?> ms-2" style="font-size:0.7em;vertical-align:middle;">
                    <?= $badgeTxt ?>
                </span>
            </h1>
            <p class="page-header__subtitle mb-0">
                <?php if (!empty($nota['numero'])): ?>
                    Nº <strong><?= View::e($nota['numero']) ?></strong> ·
                <?php endif; ?>
                OS #<?= View::e((string)$nota['os_id']) ?>
            </p>
        </div>
        <div class="page-header__actions d-flex gap-2">
            <a href="/nfse" class="btn btn-secondary">
                <i class="ph ph-arrow-left"></i> Voltar
            </a>
            <?php if ($temXml): ?>
                <a href="/nfse/<?= (int)$nota['id'] ?>/xml" class="btn btn-outline-secondary">
                    <i class="ph ph-download-simple"></i> XML
                </a>
            <?php endif; ?>
            <?php if ($chaveAcesso !== ''): ?>
                <a href="/nfse/<?= (int)$nota['id'] ?>/danfse" class="btn btn-outline-secondary">
                    <i class="ph ph-file-pdf"></i> DANFSE
                </a>
            <?php endif; ?>
            <a href="/nfse/<?= (int)$nota['id'] ?>/conferencia" class="btn btn-primary">
                <i class="ph ph-clipboard-text"></i> Conferência
            </a>
        </div>
    </div>

    <?php if (($settings['write_enabled'] ?? '0') !== '1'): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
            <i class="ph ph-lock-key"></i>
            <div>
                <strong>Transmissão bloqueada.</strong>
                Esta tela não envia DPS/XML real enquanto <code>nfse_write_enabled</code> estiver desabilitado.
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Dados da nota -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-clipboard-text"></i> Dados da nota
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">ID interno</dt>
                        <dd class="col-sm-7 text-mono">#<?= (int)$nota['id'] ?></dd>

                        <dt class="col-sm-5">Número NFS-e</dt>
                        <dd class="col-sm-7 text-mono">
                            <?php if (!empty($nota['numero'])): ?>
                                <strong><?= View::e($nota['numero']) ?></strong>
                            <?php else: ?>
                                <span class="text-body-secondary">— ainda não autorizada</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Chave de acesso</dt>
                        <dd class="col-sm-7 text-mono">
                            <?= $chaveAcesso !== '' ? View::e($chaveAcesso) : '<span class="text-body-secondary">—</span>' ?>
                        </dd>

                        <dt class="col-sm-5">ID da DPS</dt>
                        <dd class="col-sm-7 text-mono">
                            <?= $idDps !== '' ? View::e($idDps) : '<span class="text-body-secondary">—</span>' ?>
                        </dd>

                        <dt class="col-sm-5">Status</dt>
                        <dd class="col-sm-7"><span class="status-badge <?= $badgeCls ?>"><?= $badgeTxt ?></span></dd>

                        <dt class="col-sm-5">Valor do serviço</dt>
                        <dd class="col-sm-7 text-mono fw-bold fs-6">
                            <?= $nota['valor'] !== null ? $money((float)$nota['valor']) : '—' ?>
                        </dd>

                        <dt class="col-sm-5">Descrição</dt>
                        <dd class="col-sm-7"><?= View::e($nota['descricao'] ?? '—') ?></dd>

                        <dt class="col-sm-5">Ambiente</dt>
                        <dd class="col-sm-7"><?= View::e($nota['ambiente'] ?? ($settings['ambiente'] ?? 'homologacao')) ?></dd>

                        <dt class="col-sm-5">Emitida em</dt>
                        <dd class="col-sm-7 text-mono"><?= $dt($nota['criado_em']) ?></dd>

                        <dt class="col-sm-5">Última atualização</dt>
                        <dd class="col-sm-7 text-mono"><?= $dt($nota['atualizado_em']) ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Tomador / cliente -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-user"></i> Tomador (cliente)
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Nome</dt>
                        <dd class="col-sm-7"><?= View::e($nota['cliente_nome'] ?? '—') ?></dd>

                        <dt class="col-sm-5">CPF/CNPJ</dt>
                        <dd class="col-sm-7 text-mono"><?= View::e($nota['cpf_cnpj'] ?? '—') ?></dd>

                        <?php if (!empty($nota['cliente_telefone'])): ?>
                        <dt class="col-sm-5">Telefone</dt>
                        <dd class="col-sm-7 text-mono"><?= View::e($nota['cliente_telefone']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($nota['cliente_email'])): ?>
                        <dt class="col-sm-5">E-mail</dt>
                        <dd class="col-sm-7"><?= View::e($nota['cliente_email']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-sm-5">OS de origem</dt>
                        <dd class="col-sm-7 text-mono">#<?= View::e((string)$nota['os_id']) ?></dd>

                        <?php if (!empty($nota['orcamento_id'])): ?>
                        <dt class="col-sm-5">Orçamento</dt>
                        <dd class="col-sm-7 text-mono">#<?= (int)$nota['orcamento_id'] ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($nota['lancamento_id'])): ?>
                        <dt class="col-sm-5">Lançamento financeiro</dt>
                        <dd class="col-sm-7">
                            <a href="/financeiro/receber/<?= (int)$nota['lancamento_id'] ?>" class="text-mono">
                                #<?= (int)$nota['lancamento_id'] ?>
                            </a>
                            <?php if (!empty($nota['lancamento_status'])): ?>
                                <span class="small text-body-secondary">(<?= View::e($nota['lancamento_status']) ?>)</span>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Ações -->
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="ph ph-arrows-clockwise"></i> Sincronização oficial
                </div>
                <div class="card-body">
                    <form method="POST" action="/nfse/<?= (int)$nota['id'] ?>/sincronizar">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                        <p class="small text-body-secondary mb-2">
                            Consulta a SEFIN pela DPS/chave de acesso e atualiza os dados oficiais desta nota no ERP.
                        </p>
                        <button type="submit" class="btn btn-outline-primary" <?= ($settings['write_enabled'] ?? '0') !== '1' ? 'disabled' : '' ?>>
                            <i class="ph ph-arrows-clockwise"></i> Sincronizar com a SEFIN
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($podeReemitir || $podeCancelar): ?>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="ph ph-lightning"></i> Ações
                </div>
                <div class="card-body">
                    <?php if ($podeReemitir): ?>
                        <form method="POST" action="/nfse/<?= (int)$nota['id'] ?>/reemitir"
                              onsubmit="return confirm('Reenfileirar emissão desta nota?');">
                            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                            <p class="small text-body-secondary mb-2">
                                Coloca a nota de volta na fila do worker para nova tentativa de emissão.
                            </p>
                            <button type="submit" class="btn btn-primary" <?= ($settings['write_enabled'] ?? '0') !== '1' ? 'disabled' : '' ?>>
                                <i class="ph ph-arrows-clockwise"></i> Reenfileirar emissão
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($podeReemitir && $podeCancelar): ?>
                        <hr class="my-3">
                    <?php endif; ?>

                    <?php if ($podeCancelar): ?>
                        <form method="POST" action="/nfse/<?= (int)$nota['id'] ?>/cancelar"
                              onsubmit="return confirm('Cancelar esta NFS-e? Esta ação não pode ser desfeita.');">
                            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                            <div class="mb-3">
                                <label class="form-label">Motivo do cancelamento <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="motivo" rows="3" required
                                          placeholder="Ex: Erro de digitação no valor, duplicidade..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger" <?= ($settings['write_enabled'] ?? '0') !== '1' ? 'disabled' : '' ?>>
                                <i class="ph ph-trash"></i> Cancelar nota
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- XML -->
        <?php if ($temXml): ?>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="ph ph-code"></i> XML de retorno
                </div>
                <div class="card-body">
                    <pre class="bg-body-secondary border rounded p-3 mb-0 small" style="max-height:300px;overflow:auto;"><?= View::e((string)$nota['xml_retorno']) ?></pre>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
