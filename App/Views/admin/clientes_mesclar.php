<?php
use App\Core\View;
/**
 * @var array  $origem      Dados do cliente a ser mesclado (será inativado)
 * @var array  $destino     Dados do cliente canônico (permanece ativo)
 * @var int    $osOrigem    OS vinculadas à origem
 * @var int    $osDestino   OS vinculadas ao destino
 * @var array  $campos      Lista de campos copiáveis
 * @var array  $sugestoes   campo → bool (true = sugerir cópia)
 * @var string $csrf_token
 */

$label = [
    'nome'         => 'Nome',
    'nome_fantasia'=> 'Nome Fantasia',
    'cpf_cnpj'     => 'CPF/CNPJ',
    'telefone'     => 'Telefone',
    'telefone2'    => 'Telefone 2',
    'celular'      => 'Celular',
    'fone'         => 'Fone',
    'email'        => 'E-mail',
    'endereco'     => 'Endereço',
    'numero'       => 'Número',
    'complemento'  => 'Complemento',
    'bairro'       => 'Bairro',
    'cidade'       => 'Cidade',
    'uf'           => 'UF',
    'cep'          => 'CEP',
];
?>

<div class="d-flex flex-column gap-4">

    <div class="page-header">
        <div>
            <h1 class="page-header__title">Mesclar Clientes</h1>
            <p class="page-header__subtitle">
                <strong>#<?= (int)$origem['id'] ?></strong> será inativado e seus vínculos transferidos para
                <strong>#<?= (int)$destino['id'] ?></strong>.
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/admin/clientes/duplicados" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Duplicados
            </a>
        </div>
    </div>

    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="ph ph-warning-octagon flex-shrink-0 mt-1 fs-5"></i>
        <div>
            <strong>Atenção — ação irreversível:</strong>
            O cliente <strong>#<?= (int)$origem['id'] ?> (<?= View::e($origem['nome']) ?>)</strong>
            será marcado como <em>inativo e mesclado</em>. Todos os vínculos serão transferidos para
            <strong>#<?= (int)$destino['id'] ?> (<?= View::e($destino['nome']) ?>)</strong>.
            Os snapshots históricos das OS (nome e telefone) serão preservados.
        </div>
    </div>

    <!-- Comparativo lado a lado -->
    <div class="card shadow-sm">
        <div class="card-header"><i class="ph ph-scales me-2"></i>Comparação dos dados</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 small">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:20%">Campo</th>
                            <th class="text-danger" style="width:35%">
                                Origem (será inativado) — #<?= (int)$origem['id'] ?>
                                <span class="badge text-bg-secondary ms-1"><?= $osOrigem ?> OS</span>
                            </th>
                            <th class="text-success" style="width:35%">
                                Destino (canônico) — #<?= (int)$destino['id'] ?>
                                <span class="badge text-bg-secondary ms-1"><?= $osDestino ?> OS</span>
                            </th>
                            <th class="text-center" style="width:10%">Copiar para destino?</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($campos as $campo): ?>
                        <?php
                        $valOrig = trim((string)($origem[$campo] ?? ''));
                        $valDest = trim((string)($destino[$campo] ?? ''));
                        $igual   = $valOrig === $valDest;
                        $sugerir = $sugestoes[$campo] ?? false;
                        $rowCls  = !$igual && $valOrig !== '' && $valDest !== '' ? 'table-warning' : '';
                        ?>
                        <tr class="<?= $rowCls ?>">
                            <td class="fw-medium text-body-secondary"><?= $label[$campo] ?? $campo ?></td>
                            <td class="<?= $valOrig === '' ? 'text-muted' : '' ?>">
                                <?= $valOrig !== '' ? View::e($valOrig) : '<em>vazio</em>' ?>
                            </td>
                            <td class="<?= $valDest === '' ? 'text-muted' : '' ?>">
                                <?= $valDest !== '' ? View::e($valDest) : '<em>vazio</em>' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($valOrig !== ''): ?>
                                <input type="checkbox" name="campos_copiar[]" value="<?= View::e($campo) ?>"
                                       form="formMesclar"
                                       class="form-check-input"
                                       <?= $sugerir ? 'checked' : '' ?>
                                       title="<?= $sugerir ? 'Sugerido: destino está vazio' : '' ?>">
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="table-light">
                            <td class="fw-medium text-body-secondary">OS vinculadas</td>
                            <td><span class="badge text-bg-<?= $osOrigem > 0 ? 'primary' : 'secondary' ?>"><?= $osOrigem ?></span></td>
                            <td><span class="badge text-bg-<?= $osDestino > 0 ? 'primary' : 'secondary' ?>"><?= $osDestino ?></span></td>
                            <td class="text-muted text-center small">transferidas</td>
                        </tr>
                        <tr class="table-light">
                            <td class="fw-medium text-body-secondary">Lançamentos a receber</td>
                            <td><span class="badge text-bg-<?= ($vinculosOrigem['lancamentos'] ?? 0) > 0 ? 'primary' : 'secondary' ?>"><?= $vinculosOrigem['lancamentos'] ?? 0 ?></span></td>
                            <td><span class="badge text-bg-<?= ($vinculosDestino['lancamentos'] ?? 0) > 0 ? 'primary' : 'secondary' ?>"><?= $vinculosDestino['lancamentos'] ?? 0 ?></span></td>
                            <td class="text-muted text-center small">transferidos</td>
                        </tr>
                        <tr class="table-light">
                            <td class="fw-medium text-body-secondary">Vendas (PDV)</td>
                            <td><span class="badge text-bg-<?= ($vinculosOrigem['vendas'] ?? 0) > 0 ? 'primary' : 'secondary' ?>"><?= $vinculosOrigem['vendas'] ?? 0 ?></span></td>
                            <td><span class="badge text-bg-<?= ($vinculosDestino['vendas'] ?? 0) > 0 ? 'primary' : 'secondary' ?>"><?= $vinculosDestino['vendas'] ?? 0 ?></span></td>
                            <td class="text-muted text-center small">transferidas</td>
                        </tr>
                        <tr class="table-light">
                            <td class="fw-medium text-body-secondary">Notas Fiscais</td>
                            <td><span class="badge text-bg-<?= ($vinculosOrigem['notas'] ?? 0) > 0 ? 'primary' : 'secondary' ?>"><?= $vinculosOrigem['notas'] ?? 0 ?></span></td>
                            <td><span class="badge text-bg-<?= ($vinculosDestino['notas'] ?? 0) > 0 ? 'primary' : 'secondary' ?>"><?= $vinculosDestino['notas'] ?? 0 ?></span></td>
                            <td class="text-muted text-center small">transferidas</td>
                        </tr>
                        <tr class="table-light">
                            <td class="fw-medium text-body-secondary">Criado em</td>
                            <td class="text-mono"><?= !empty($origem['created_at']) ? date('d/m/Y', strtotime($origem['created_at'])) : '—' ?></td>
                            <td class="text-mono"><?= !empty($destino['created_at']) ? date('d/m/Y', strtotime($destino['created_at'])) : '—' ?></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Legenda -->
    <div class="d-flex gap-3 small text-body-secondary">
        <span><span class="badge text-bg-warning">&nbsp;</span> Campos divergentes (ambos preenchidos)</span>
        <span><i class="ph ph-check-square"></i> Checkbox marcado = copiar da origem para o destino (se destino vazio)</span>
        <span><i class="ph ph-info"></i> Campos com destino preenchido NÃO serão sobrescritos</span>
    </div>

    <!-- Formulário de confirmação -->
    <div class="card shadow-sm border-danger">
        <div class="card-header bg-danger text-white">
            <i class="ph ph-warning me-2"></i>Confirmação de mesclagem
        </div>
        <div class="card-body">
            <form id="formMesclar"
                  method="POST"
                  action="/admin/clientes/<?= (int)$origem['id'] ?>/mesclar-em/<?= (int)$destino['id'] ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        Para confirmar, digite <kbd>MESCLAR</kbd> no campo abaixo:
                    </label>
                    <input type="text" name="confirmacao" id="confirmacao"
                           class="form-control form-control-lg text-mono text-uppercase"
                           placeholder="MESCLAR" autocomplete="off" required>
                    <div class="form-text">
                        Esta ação marcará #<?= (int)$origem['id'] ?> como inativo e transferirá todos os vínculos para #<?= (int)$destino['id'] ?>.
                        Não é possível desfazer automaticamente.
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="/admin/clientes/duplicados" class="btn btn-outline-secondary">
                        Cancelar
                    </a>
                    <button type="submit" class="btn btn-danger" id="btnMesclar" disabled>
                        <i class="ph ph-git-merge me-1"></i>
                        Mesclar #<?= (int)$origem['id'] ?> → #<?= (int)$destino['id'] ?>
                    </button>
                    <a href="/admin/clientes/<?= (int)$destino['id'] ?>/mesclar-em/<?= (int)$origem['id'] ?>"
                       class="btn btn-outline-warning btn-sm align-self-center ms-auto">
                        <i class="ph ph-arrows-left-right me-1"></i> Inverter origem/destino
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Habilita botão só quando digita MESCLAR
document.getElementById('confirmacao').addEventListener('input', function () {
    document.getElementById('btnMesclar').disabled = this.value.toUpperCase() !== 'MESCLAR';
});
</script>
