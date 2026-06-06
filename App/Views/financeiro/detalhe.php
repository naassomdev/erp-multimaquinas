<?php
use App\Core\View;
/**
 * @var string $tipo
 * @var array  $lancamento
 * @var string $csrf_token
 */
$ehReceber = $tipo === 'receber';
$basePath  = '/financeiro/' . $tipo;
$l         = $lancamento;
$money     = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt        = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$dtH       = fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';

$atrasado = ($l['status'] === 'aberto' && $l['vencimento'] < date('Y-m-d'));

if ($l['status'] === 'pago') {
    $badgeCls = 'status-badge--success';
    $badgeTxt = $ehReceber ? 'Recebido' : 'Pago';
} elseif ($l['status'] === 'aguardando_fatura') {
    $badgeCls = 'status-badge--info';
    $badgeTxt = 'Aguardando NFS-e';
} elseif ($l['status'] === 'cancelado') {
    $badgeCls = 'status-badge--neutral';
    $badgeTxt = 'Cancelado';
} elseif ($atrasado) {
    $badgeCls = 'status-badge--danger';
    $badgeTxt = 'Vencido';
} else {
    $badgeCls = 'status-badge--warning';
    $badgeTxt = 'Em aberto';
}
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <h1 class="page-header__title">
                    <i class="ph ph-<?= $ehReceber ? 'arrow-circle-down text-success' : 'arrow-circle-up text-danger' ?> me-2"></i>
                    <?= $ehReceber ? 'Receber' : 'Pagar' ?>
                    <span class="text-mono">#<?= (int)$l['id'] ?></span>
                </h1>
                <span class="status-badge <?= $badgeCls ?>"><?= $badgeTxt ?></span>
            </div>
            <p class="page-header__subtitle"><?= View::e($l['descricao']) ?></p>
        </div>
        <div class="page-header__actions">
            <a href="<?= $basePath ?>" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <?php if ($l['status'] === 'aberto'): ?>
                <a href="<?= $basePath ?>/<?= (int)$l['id'] ?>/editar" class="btn btn-primary">
                    <i class="ph ph-pencil-simple me-1"></i> Editar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">

        <!-- Dados do lancamento -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-file-text"></i> Dados</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Descricao</dt>
                        <dd class="col-12 mb-3"><?= View::e($l['descricao']) ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Valor</dt>
                        <dd class="col-12 text-mono fs-5 fw-bold mb-3"><?= $money((float)$l['valor']) ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Vencimento</dt>
                        <dd class="col-12 text-mono mb-3 <?= $atrasado ? 'text-danger fw-semibold' : '' ?>">
                            <?= $dt($l['vencimento']) ?>
                            <?php if ($atrasado): ?>
                                <span class="ms-1 small">vencido</span>
                            <?php endif; ?>
                        </dd>

                        <?php if ($ehReceber && (float)($l['desconto_valor'] ?? 0) > 0): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold border-top pt-3">Desconto</dt>
                        <dd class="col-12 text-mono fs-5 fw-bold text-danger mb-3">— <?= $money((float)$l['desconto_valor']) ?></dd>
                        <?php endif; ?>

                        <?php if ($l['valor_pago'] !== null): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold <?= (float)($l['desconto_valor'] ?? 0) > 0 ? '' : 'border-top pt-3' ?>">Valor pago</dt>
                        <dd class="col-12 text-mono fs-5 fw-bold text-success mb-3"><?= $money((float)$l['valor_pago']) ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Data do pagamento</dt>
                        <dd class="col-12 text-mono mb-3"><?= $dt($l['data_pagamento']) ?></dd>
                        <?php endif; ?>

                        <?php if ($ehReceber && (float)($l['desconto_valor'] ?? 0) > 0 && $l['valor_pago'] === null): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Valor a faturar</dt>
                        <dd class="col-12 text-mono fs-5 fw-bold text-info mb-3"><?= $money(max(0.0, (float)$l['valor'] - (float)$l['desconto_valor'])) ?></dd>
                        <?php endif; ?>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold border-top pt-3">Criado em</dt>
                        <dd class="col-12 text-mono small text-body-secondary mb-0">
                            <?= !empty($l['criado_em']) ? $dtH($l['criado_em']) : '—' ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Contraparte -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-<?= $ehReceber ? 'user' : 'buildings' ?>"></i>
                    <?= $ehReceber ? 'Cliente' : 'Fornecedor' ?>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Nome</dt>
                        <dd class="col-12 fw-medium mb-3"><?= View::e($l['contraparte_nome'] ?? '—') ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold"><?= $ehReceber ? 'CPF/CNPJ' : 'CNPJ' ?></dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($l['contraparte_doc'] ?? '—') ?></dd>

                        <?php if (!empty($l['contraparte_telefone'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Telefone</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($l['contraparte_telefone']) ?></dd>
                        <?php endif; ?>

                        <?php if ($ehReceber && !empty($l['os_id'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold border-top pt-3">OS vinculada</dt>
                        <dd class="col-12 mb-2">
                            <a href="/os/<?= rawurlencode((string)$l['os_id']) ?>" class="text-mono fw-medium text-decoration-none">
                                #<?= View::e((string)$l['os_id']) ?>
                                <i class="ph ph-arrow-square-out ms-1"></i>
                            </a>
                        </dd>
                        <?php if (!empty($l['equip_idx'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Equipamento</dt>
                        <dd class="col-12 mb-2">
                            <span class="text-mono">Equip. <?= (int)$l['equip_idx'] ?></span>
                            <?php if (!empty($l['equip_nome'])): ?>
                                — <?= View::e($l['equip_nome']) ?>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>
                        <?php if (!empty($l['orcamento_id'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Orcamento</dt>
                        <dd class="col-12 mb-2">
                            <a href="/orcamento/<?= rawurlencode((string)$l['os_id']) ?>" class="text-mono text-decoration-none">
                                #<?= (int)$l['orcamento_id'] ?>
                                <i class="ph ph-arrow-square-out ms-1"></i>
                            </a>
                        </dd>
                        <?php endif; ?>
                        <?php if ($ehReceber && !empty($l['forma_pagamento'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Forma de pagamento</dt>
                        <dd class="col-12 mb-0"><?= View::e(ucfirst(str_replace('_', ' ', (string)$l['forma_pagamento']))) ?></dd>
                        <?php endif; ?>
                        <?php elseif (!$ehReceber && !empty($l['chave_nfe'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold border-top pt-3">Chave NF-e</dt>
                        <dd class="col-12 text-mono small break-all mb-0"><?= View::e($l['chave_nfe']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Acoes -->
        <?php if ($l['status'] === 'aberto'): ?>
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-currency-dollar"></i>
                    Registrar <?= $ehReceber ? 'recebimento' : 'pagamento' ?>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= $basePath ?>/<?= (int)$l['id'] ?>/pagar" class="d-flex flex-column gap-3">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

                        <div>
                            <label class="form-label small">Valor pago (R$)</label>
                            <input type="number" name="valor_pago"
                                   value="<?= number_format((float)$l['valor'], 2, '.', '') ?>"
                                   step="0.01" min="0.01" required class="form-control text-mono">
                        </div>
                        <div>
                            <label class="form-label small">Data do pagamento</label>
                            <input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>" required class="form-control">
                        </div>

                        <button type="submit" class="btn btn-success w-100">
                            <i class="ph ph-check-circle me-1"></i>
                            Confirmar <?= $ehReceber ? 'recebimento' : 'pagamento' ?>
                        </button>
                    </form>

                    <hr>

                    <form method="POST" action="<?= $basePath ?>/<?= (int)$l['id'] ?>/cancelar"
                          onsubmit="return confirm('Cancelar este lancamento? Esta acao nao pode ser desfeita.');">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="ph ph-trash me-1"></i> Cancelar lancamento
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
