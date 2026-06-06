<?php
use App\Core\View;
/**
 * @var array $nota
 * @var array $settings
 * @var string $csrf_token
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$writeEnabled = ($settings['write_enabled'] ?? '0') === '1';
$shadow = ($settings['danfse_shadow_mode'] ?? '1') === '1';
?>
<div class="d-flex flex-column gap-4">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-header__title"><i class="ph ph-clipboard-text"></i> Conferência NFS-e #<?= (int)$nota['id'] ?></h1>
            <p class="page-header__subtitle mb-0">Revise os dados fiscais antes de qualquer transmissão.</p>
        </div>
        <div class="page-header__actions d-flex gap-2">
            <a href="/nfse/<?= (int)$nota['id'] ?>" class="btn btn-outline-secondary"><i class="ph ph-eye"></i> Detalhe</a>
            <a href="/nfse" class="btn btn-secondary"><i class="ph ph-arrow-left"></i> Voltar</a>
        </div>
    </div>

    <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
        <i class="ph ph-warning-circle"></i>
        <div>
            <strong><?= $writeEnabled ? 'Escrita habilitada por configuração.' : 'Emissão real desabilitada.' ?></strong>
            Ambiente: <?= View::e((string)($nota['ambiente'] ?? $settings['ambiente'] ?? 'homologacao')) ?>.
            <?= $shadow ? 'DANFSe em shadow mode.' : 'DANFSe fora de shadow mode.' ?>
        </div>
    </div>

    <form method="POST" action="/nfse/<?= (int)$nota['id'] ?>/conferencia" class="card shadow-sm">
        <div class="card-header"><i class="ph ph-list-checks"></i> Dados conferidos</div>
        <div class="card-body d-flex flex-column gap-3">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Cliente/tomador</label>
                    <input class="form-control" value="<?= View::e((string)($nota['cliente_nome'] ?? '')) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">CPF/CNPJ</label>
                    <input class="form-control text-mono" value="<?= View::e((string)($nota['cpf_cnpj'] ?? '')) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Valor total</label>
                    <input class="form-control text-mono" value="<?= isset($nota['valor']) ? $money((float)$nota['valor']) : '' ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">OS</label>
                    <input class="form-control text-mono" value="<?= View::e((string)($nota['os_id'] ?? '')) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Orçamento</label>
                    <input class="form-control text-mono" value="<?= View::e((string)($nota['orcamento_id'] ?? '')) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Código de serviço</label>
                    <input class="form-control text-mono" value="<?= View::e((string)($settings['codigo_trib_nacional'] ?? '')) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Município incidência</label>
                    <input class="form-control text-mono" value="<?= View::e((string)($settings['prestador_codigo_municipio'] ?? '')) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Endereço</label>
                    <input class="form-control" value="<?= View::e(trim(implode(', ', array_filter([(string)($nota['cliente_endereco'] ?? ''), (string)($nota['cliente_numero'] ?? ''), (string)($nota['cliente_bairro'] ?? ''), (string)($nota['cliente_cidade'] ?? ''), (string)($nota['cliente_uf'] ?? ''), (string)($nota['cliente_cep'] ?? '')])))) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Retenção ISS</label>
                    <input class="form-control" value="Conforme parametrização fiscal futura" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <input class="form-control" value="<?= View::e((string)($nota['status'] ?? '')) ?>" readonly>
                </div>
            </div>

            <div>
                <label class="form-label">Descrição do serviço</label>
                <textarea name="descricao_servico" class="form-control" rows="10"><?= View::e((string)($nota['descricao'] ?? '')) ?></textarea>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="/nfse" class="btn btn-secondary"><i class="ph ph-arrow-left"></i> Voltar</a>
            <button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Salvar rascunho</button>
            <button type="button" class="btn btn-outline-primary"><i class="ph ph-check-circle"></i> Validar dados</button>
            <button type="button" class="btn btn-danger" disabled><i class="ph ph-paper-plane-tilt"></i> Transmitir NFS-e</button>
        </div>
    </form>
</div>
