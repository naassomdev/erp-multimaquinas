<?php
use App\Core\View;

/** @var array $usuario */
/** @var array<int, array<string, mixed>> $itens */
$fmtBrl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
?>
<div class="d-flex flex-column gap-4">

    <div class="page-header">
        <div>
            <a href="/dashboard" class="btn btn-sm btn-outline-secondary mb-2">
                <i class="ph ph-arrow-left"></i> Voltar para Dashboard
            </a>
            <h1 class="page-header__title">Tabela de Mao de Obra</h1>
            <p class="page-header__subtitle">Gerencie os valores base para sugestoes de orcamentos.</p>
        </div>
        <div class="page-header__actions">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalForm" onclick="resetForm();">
                <i class="ph ph-plus"></i> Novo Item
            </button>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Operacao realizada com sucesso.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover data-table mb-0">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th>Nome/Referencia</th>
                            <th>Valor Base</th>
                            <th class="text-end">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($itens)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state py-4">
                                        <div class="empty-state__icon"><i class="ph ph-file-text"></i></div>
                                        <h4 class="empty-state__title">Nenhum item cadastrado</h4>
                                        <p class="empty-state__desc">Clique em "Novo Item" para adicionar o primeiro.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($itens as $it): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis text-capitalize">
                                        <?= View::e($it['categoria']) ?>
                                    </span>
                                </td>
                                <td class="fw-medium"><?= View::e($it['nome']) ?></td>
                                <td class="text-success fw-semibold"><?= $fmtBrl((float)$it['valor_padrao']) ?></td>
                                <td class="text-end">
                                    <button type="button" onclick="editar(<?= View::e(json_encode($it)) ?>)" class="btn btn-sm btn-outline-primary me-1">
                                        <i class="ph ph-pencil-simple"></i> Editar
                                    </button>
                                    <form action="/admin/mao-de-obra/deletar/<?= $it['id'] ?>" method="POST" class="d-inline" onsubmit="return confirm('Tem certeza?');">
                                        <input type="hidden" name="_csrf" value="<?= View::e(\App\Core\Csrf::token()) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="ph ph-trash"></i> Excluir
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Novo Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form action="/admin/mao-de-obra" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= View::e(\App\Core\Csrf::token()) ?>">
                    <input type="hidden" name="id" id="formId" value="">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Categoria</label>
                            <select name="categoria" id="formCategoria" required class="form-select">
                                <option value="maquina">Maquina</option>
                                <option value="motor">Motor</option>
                                <option value="bomba">Bomba</option>
                                <option value="servico">Servico Adicional</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Nome/Referencia</label>
                            <input type="text" name="nome" id="formNome" required class="form-control" placeholder="Ex: ESMERILHADEIRA 4''">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Valor Padrao (R$)</label>
                            <input type="number" step="0.01" min="0" name="valor_padrao" id="formValor" required class="form-control" placeholder="0.00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('modalTitle').innerText = 'Novo Item';
    document.getElementById('formId').value = '';
    document.getElementById('formCategoria').value = 'maquina';
    document.getElementById('formNome').value = '';
    document.getElementById('formValor').value = '';
}

function editar(item) {
    document.getElementById('modalTitle').innerText = 'Editar Item';
    document.getElementById('formId').value = item.id;
    document.getElementById('formCategoria').value = item.categoria;
    document.getElementById('formNome').value = item.nome;
    document.getElementById('formValor').value = item.valor_padrao;
    new bootstrap.Modal(document.getElementById('modalForm')).show();
}
</script>
