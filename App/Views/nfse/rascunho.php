<?php
use App\Core\View;
use App\Services\NfseDraftService;
/**
 * @var array|null $preview
 * @var array $settings
 * @var string $os_id
 * @var string|int $orcamento_id
 * @var string $csrf_token
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$contadorOk = ($settings['contador_aprova_total_os'] ?? '0') === '1';
?>
<div class="d-flex flex-column gap-4">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-header__title"><i class="ph ph-file-plus"></i> Novo rascunho NFS-e</h1>
            <p class="page-header__subtitle mb-0">Rascunho interno, sem transmissão real.</p>
        </div>
        <div class="page-header__actions">
            <a href="/nfse" class="btn btn-secondary"><i class="ph ph-arrow-left"></i> Voltar</a>
        </div>
    </div>

    <?php if (!$contadorOk): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-0">
            <i class="ph ph-x-circle"></i>
            <div><?= View::e(NfseDraftService::MSG_TOTAL_BLOQUEADO) ?></div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header"><i class="ph ph-magnifying-glass"></i> Origem do rascunho</div>
        <div class="card-body">
            <form method="GET" action="/nfse/rascunho" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">OS</label>
                    <input name="os_id" value="<?= View::e((string)$os_id) ?>" class="form-control text-mono" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Orçamento</label>
                    <input name="orcamento_id" value="<?= View::e((string)$orcamento_id) ?>" class="form-control text-mono" placeholder="Opcional">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="ph ph-eye"></i> Pré-visualizar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($preview): ?>
        <div class="card shadow-sm">
            <div class="card-header"><i class="ph ph-clipboard-text"></i> Conferência inicial</div>
            <div class="card-body">
                <?php if (!empty($preview['validacoes'])): ?>
                    <div class="alert alert-warning">
                        <strong>Pendências:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                            <?php foreach ($preview['validacoes'] as $erro): ?>
                                <li><?= View::e((string)$erro) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/nfse/rascunho" class="d-flex flex-column gap-3">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="os_id" value="<?= View::e((string)$preview['os_id']) ?>">
                    <input type="hidden" name="orcamento_id" value="<?= (int)$preview['orcamento_id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tomador</label>
                            <input class="form-control" value="<?= View::e((string)($preview['cliente_nome'] ?: $preview['os_nome_cliente'])) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">CPF/CNPJ</label>
                            <input class="form-control text-mono" value="<?= View::e((string)($preview['cpf_cnpj'] ?: $preview['doc_cliente'])) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Valor total</label>
                            <input class="form-control text-mono" value="<?= $money((float)$preview['valor_total']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Equipamento</label>
                            <input class="form-control" value="<?= View::e((string)($preview['equip_nome'] ?? '')) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Marca/Modelo</label>
                            <input class="form-control" value="<?= View::e(trim((string)($preview['fabricante'] ?? '') . ' ' . (string)($preview['modelo'] ?? ''))) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Série</label>
                            <input class="form-control text-mono" value="<?= View::e((string)($preview['serie'] ?? '')) ?>" readonly>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Descrição consolidada editável</label>
                        <textarea name="descricao_servico" class="form-control" rows="9"><?= View::e((string)$preview['descricao_servico']) ?></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="/nfse" class="btn btn-secondary"><i class="ph ph-arrow-left"></i> Voltar</a>
                        <button type="submit" class="btn btn-primary" <?= (!$contadorOk || !empty($preview['validacoes'])) ? 'disabled' : '' ?>>
                            <i class="ph ph-floppy-disk"></i> Salvar rascunho
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
