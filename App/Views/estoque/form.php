<?php
use App\Core\View;
use App\Core\Flash;
/**
 * @var ?array  $produto
 * @var string  $csrf_token
 * @var string  $modo
 * @var string  $return_url
 */
$p    = $produto;
$edit = $modo === 'editar';
$action = $edit ? '/estoque/' . (int)$p['id'] : '/estoque';
$returnUrl = (string) ($return_url ?? '/estoque');
$returnParam = rawurlencode($returnUrl);

$v = function(string $campo, $default = '') use ($p, $edit): string {
    $old = \App\Core\Flash::old($campo);
    if ($old !== '') return (string)$old;
    return $edit ? (string)($p[$campo] ?? $default) : (string)$default;
};

$unidades = ['un' => 'Unidade (un)', 'pc' => 'Peca (pc)', 'cx' => 'Caixa (cx)', 'kg' => 'Quilograma (kg)', 'mt' => 'Metro (mt)', 'lt' => 'Litro (lt)', 'rl' => 'Rolo (rl)', 'jg' => 'Jogo (jg)'];
$unAtual = $v('unidade', 'un');
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title"><?= $edit ? 'Editar Produto' : 'Novo Produto' ?></h1>
            <p class="page-header__subtitle"><?= $edit ? 'Atualize os dados do produto' : 'Cadastre um novo produto ou peca' ?></p>
        </div>
        <div class="page-header__actions">
            <a href="<?= View::e($returnUrl) ?>" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <?php if ($edit): ?>
                <a href="/estoque/<?= (int)$p['id'] ?>?return_url=<?= $returnParam ?>" class="btn btn-outline-secondary">
                    <i class="ph ph-eye me-1"></i> Visualizar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="<?= $action ?>" class="d-flex flex-column gap-4" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="return_url" value="<?= View::e($returnUrl) ?>">

        <!-- Identificacao -->
        <div class="card shadow-sm">
            <div class="card-header"><i class="ph ph-tag"></i> Identificacao</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Codigo Interno</label>
                        <input type="text" name="codigo" value="<?= View::e($v('codigo')) ?>"
                               placeholder="Ex: PCA-001" class="form-control text-mono">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Codigo de Barras (EAN)</label>
                        <input type="text" name="ean" value="<?= View::e($v('ean')) ?>"
                               placeholder="EAN / GTIN" class="form-control text-mono">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descricao <span class="required">*</span></label>
                        <input type="text" name="descricao" value="<?= View::e($v('descricao')) ?>"
                               placeholder="Nome completo do produto" required autofocus class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Categoria</label>
                        <input type="text" name="categoria" value="<?= View::e($v('categoria')) ?>"
                               placeholder="Ex: Pecas, Acessorios, Consumiveis..." class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Marca</label>
                        <input type="text" name="marca" value="<?= View::e($v('marca')) ?>"
                               placeholder="Fabricante" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">NCM</label>
                        <input type="text" name="ncm" value="<?= View::e($v('ncm')) ?>"
                               placeholder="00000000" maxlength="10" class="form-control text-mono">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unidade</label>
                        <select name="unidade" class="form-select">
                            <?php foreach ($unidades as $sigla => $nome): ?>
                                <option value="<?= $sigla ?>" <?= $unAtual === $sigla ? 'selected' : '' ?>><?= $nome ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Precos -->
        <div class="card shadow-sm">
            <div class="card-header"><i class="ph ph-currency-dollar"></i> Precos</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Preco de Custo (R$)</label>
                        <input type="number" name="preco_custo" id="preco_custo"
                               value="<?= View::e($v('preco_custo', '0.00')) ?>"
                               step="0.01" min="0" placeholder="0,00" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Margem de Lucro (%)</label>
                        <input type="number" name="margem_lucro" id="margem_lucro"
                               value="<?= View::e($v('margem_lucro', '0.00')) ?>"
                               step="0.01" min="0" placeholder="0" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Preco de Venda Calculado (R$)</label>
                        <input type="number" name="valor_venda_calculado" id="valor_venda_calculado"
                               value="<?= View::e($v('valor_venda_calculado', '0.00')) ?>"
                               step="0.01" min="0" placeholder="0,00"
                               class="form-control fw-bold fs-5 text-primary">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Valor Tabela / Lista (R$)</label>
                        <input type="number" name="valor" value="<?= View::e($v('valor', '0.00')) ?>"
                               step="0.01" min="0" placeholder="0,00" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Valor Oferta (R$)</label>
                        <input type="number" name="valor_oferta" value="<?= View::e($v('valor_oferta', '0.00')) ?>"
                               step="0.01" min="0" placeholder="0,00" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <!-- Estoque -->
        <div class="card shadow-sm">
            <div class="card-header"><i class="ph ph-package"></i> Estoque</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Quantidade em Estoque</label>
                        <input type="number" name="estoque_qty" value="<?= View::e($v('estoque_qty', '0')) ?>"
                               step="1" min="0" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Estoque Minimo (alerta)</label>
                        <input type="number" name="estoque_min" value="<?= View::e($v('estoque_min', '0')) ?>"
                               step="1" min="0" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Controla estoque fisico?</label>
                        <select name="controla_estoque" class="form-select">
                            <option value="1" <?= $v('controla_estoque', '1') !== '0' ? 'selected' : '' ?>>Sim — produto fisico, baixa estoque</option>
                            <option value="0" <?= $v('controla_estoque', '1') === '0' ? 'selected' : '' ?>>Nao — servico/M.O./taxa, nao baixa estoque</option>
                        </select>
                        <div class="form-text">Desmarque para servicos, mao de obra ou taxas que nao devem gerar movimentacao de estoque.</div>
                    </div>
                    <?php if ($edit): ?>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="ativo" class="form-select">
                            <option value="1" <?= $v('ativo', '1') === '1' ? 'selected' : '' ?>>Ativo</option>
                            <option value="0" <?= $v('ativo', '1') === '0' ? 'selected' : '' ?>>Inativo</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Acoes -->
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
            <a href="<?= View::e($returnUrl) ?>" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ph ph-<?= $edit ? 'floppy-disk' : 'check-circle' ?> me-1"></i>
                <?= $edit ? 'Salvar Alteracoes' : 'Cadastrar Produto' ?>
            </button>
        </div>
    </form>
</div>

<script>
const custoEl  = document.getElementById('preco_custo');
const margemEl = document.getElementById('margem_lucro');
const vendaEl  = document.getElementById('valor_venda_calculado');

function calcularVenda() {
    const custo  = parseFloat(custoEl?.value || 0);
    const margem = parseFloat(margemEl?.value || 0);
    if (custo > 0 && margem > 0 && vendaEl) {
        vendaEl.value = (custo * (1 + margem / 100)).toFixed(2);
    }
}

function calcularMargem() {
    const custo = parseFloat(custoEl?.value || 0);
    const venda = parseFloat(vendaEl?.value || 0);
    if (custo > 0 && venda > 0 && margemEl) {
        margemEl.value = (((venda - custo) / custo) * 100).toFixed(2);
    }
}

custoEl?.addEventListener('input', calcularVenda);
margemEl?.addEventListener('input', calcularVenda);
vendaEl?.addEventListener('input', calcularMargem);
</script>
