<?php
use App\Core\View;
use App\Core\Flash;
/**
 * @var string  $tipo
 * @var ?array  $lancamento
 * @var string  $modo
 * @var string  $csrf_token
 */
$ehReceber = $tipo === 'receber';
$edit      = $modo === 'editar';
$basePath  = '/financeiro/' . $tipo;
$action    = $edit ? "{$basePath}/" . (int) $lancamento['id'] : $basePath;
$titulo    = $edit
    ? 'Editar lancamento'
    : ($ehReceber ? 'Nova conta a receber' : 'Nova conta a pagar');

$v = function (string $campo, $default = '') use ($lancamento, $edit): string {
    $old = Flash::old($campo);
    if ($old !== '') return (string) $old;
    return $edit ? (string) ($lancamento[$campo] ?? $default) : (string) $default;
};
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-<?= $ehReceber ? 'arrow-circle-down text-success' : 'arrow-circle-up text-danger' ?> me-2"></i>
                <?= $titulo ?>
            </h1>
            <p class="page-header__subtitle">
                <?= $ehReceber ? 'Conta a receber de cliente.' : 'Conta a pagar para fornecedor.' ?>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="<?= $basePath ?>" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <form method="POST" action="<?= $action ?>" class="d-flex flex-column gap-4" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <!-- Dados do lancamento -->
        <div class="card shadow-sm">
            <div class="card-header"><i class="ph ph-file-text"></i> Dados do lancamento</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Descricao <span class="required">*</span></label>
                        <input type="text" name="descricao" value="<?= View::e($v('descricao')) ?>"
                               placeholder="Ex.: Conserto de maquina XYZ" required autofocus class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Valor (R$) <span class="required">*</span></label>
                        <input type="number" name="valor" value="<?= View::e($v('valor', '0.00')) ?>"
                               step="0.01" min="0.01" required class="form-control text-mono">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Vencimento <span class="required">*</span></label>
                        <input type="date" name="vencimento" value="<?= View::e($v('vencimento', date('Y-m-d'))) ?>" required class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <!-- Vinculacao -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-<?= $ehReceber ? 'link' : 'paperclip' ?>"></i>
                <span class="flex-grow-1"><?= $ehReceber ? 'Vinculacao com cliente / OS' : 'Vinculacao com fornecedor / NF-e' ?></span>
                <small class="text-body-secondary fst-italic">opcionais</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php if ($ehReceber): ?>
                        <div class="col-md-6">
                            <label class="form-label">Cliente (ID)</label>
                            <input type="number" name="cliente_id" value="<?= View::e($v('cliente_id')) ?>"
                                   min="0" placeholder="ID do cliente" class="form-control text-mono">
                            <div class="form-text">Deixe em branco para lancamentos avulsos.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">OS vinculada (ID)</label>
                            <input type="text" name="os_id" value="<?= View::e($v('os_id')) ?>"
                                   placeholder="ID da Ordem de Servico" class="form-control text-mono">
                            <div class="form-text">Use para vincular a um conserto especifico.</div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-6">
                            <label class="form-label">Fornecedor (ID)</label>
                            <input type="number" name="fornecedor_id" value="<?= View::e($v('fornecedor_id')) ?>"
                                   min="0" placeholder="ID do fornecedor" class="form-control text-mono">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Chave NF-e</label>
                            <input type="text" name="chave_nfe" value="<?= View::e($v('chave_nfe')) ?>"
                                   maxlength="44" placeholder="44 digitos da chave de acesso" class="form-control text-mono">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Acoes -->
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
            <a href="<?= $basePath ?>" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ph ph-<?= $edit ? 'floppy-disk' : 'check-circle' ?> me-1"></i>
                <?= $edit ? 'Salvar alteracoes' : 'Criar lancamento' ?>
            </button>
        </div>
    </form>
</div>
