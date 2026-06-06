<?php
use App\Core\View;
use App\Core\Csrf;
/**
 * @var array $produto
 * @var string $return_url
 */
$p = $produto;
$returnUrl = (string) ($return_url ?? '/estoque');
$returnParam = rawurlencode($returnUrl);
$custoFloat  = (float)($p['preco_custo'] ?? 0);
$margemFloat = (float)($p['margem_lucro'] ?? 0);
$vendaFloat  = (float)($p['valor_venda_calculado'] ?? $p['valor'] ?? 0);
$estoqueQty  = (float)($p['estoque_qty'] ?? 0);
$estoqueMin  = (float)($p['estoque_min'] ?? 0);
$negativo    = $estoqueQty < 0;
$baixo       = ($estoqueMin > 0 && $estoqueQty <= $estoqueMin);
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title"><?= View::e($p['descricao']) ?></h1>
            <?php if (!empty($p['codigo'])): ?>
                <p class="page-header__subtitle">Codigo: <span class="text-mono"><?= View::e($p['codigo']) ?></span></p>
            <?php endif; ?>
        </div>
        <div class="page-header__actions">
            <a href="<?= View::e($returnUrl) ?>" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="/estoque/<?= (int)$p['id'] ?>/editar?return_url=<?= $returnParam ?>" class="btn btn-primary">
                <i class="ph ph-pencil-simple me-1"></i> Editar
            </a>
            <form method="POST" action="/estoque/<?= (int)$p['id'] ?>/desativar"
                  onsubmit="return confirm('Deseja desativar este produto?');"
                  class="d-inline-block">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="ph ph-trash me-1"></i> Desativar
                </button>
            </form>
        </div>
    </div>

    <div class="row g-3">

        <!-- Identificacao -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-tag"></i> Identificacao</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Codigo</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($p['codigo'] ?: '—') ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">EAN</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($p['ean'] ?: '—') ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Descricao</dt>
                        <dd class="col-12 fw-semibold mb-3"><?= View::e($p['descricao']) ?></dd>

                        <?php if (!empty($p['categoria'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Categoria</dt>
                        <dd class="col-12 mb-3"><?= View::e($p['categoria']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($p['marca'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Marca</dt>
                        <dd class="col-12 mb-3"><?= View::e($p['marca']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($p['ncm'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">NCM</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($p['ncm']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Unidade</dt>
                        <dd class="col-12 mb-3"><?= View::e(strtoupper($p['unidade'] ?? 'UN')) ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Status</dt>
                        <dd class="col-12 mb-0">
                            <span class="status-badge <?= $p['ativo'] ? 'status-badge--success' : 'status-badge--danger' ?>">
                                <?= $p['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Precos -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-currency-dollar"></i> Precos</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Preco de Custo</dt>
                        <dd class="col-12 text-mono mb-3">R$ <?= number_format($custoFloat, 2, ',', '.') ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Margem de Lucro</dt>
                        <dd class="col-12 text-mono mb-3"><?= number_format($margemFloat, 2, ',', '.') ?>%</dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Preco de Venda</dt>
                        <dd class="col-12 text-mono fw-bold fs-5 text-primary mb-3">
                            R$ <?= number_format($vendaFloat, 2, ',', '.') ?>
                        </dd>

                        <?php if ((float)($p['valor'] ?? 0) > 0): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Valor Tabela</dt>
                        <dd class="col-12 text-mono mb-3">R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?></dd>
                        <?php endif; ?>

                        <?php if ((float)($p['valor_oferta'] ?? 0) > 0): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Valor Oferta</dt>
                        <dd class="col-12 text-mono text-success mb-0">R$ <?= number_format((float)$p['valor_oferta'], 2, ',', '.') ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Estoque -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-package"></i> Estoque</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Quantidade Atual</dt>
                        <dd class="col-12 mb-3">
                            <span class="fs-3 fw-bold <?= $negativo ? 'text-danger' : ($baixo ? 'text-warning' : '') ?>">
                                <?= number_format($estoqueQty, 0, ',', '.') ?>
                            </span>
                            <span class="text-body-secondary small ms-1"><?= View::e(strtoupper($p['unidade'] ?? 'UN')) ?></span>
                            <?php if ($negativo): ?>
                                <span class="status-badge status-badge--danger ms-2">NEGATIVO — repor estoque</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Estoque Minimo</dt>
                        <dd class="col-12 mb-3">
                            <?= number_format($estoqueMin, 0, ',', '.') ?>
                            <?php if ($baixo): ?>
                                <span class="status-badge status-badge--warning ms-1">Abaixo do minimo</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold border-top pt-3">Controla estoque fisico</dt>
                        <dd class="col-12 mb-3">
                            <?php if ((int)($p['controla_estoque'] ?? 1)): ?>
                                <span class="status-badge status-badge--success">Sim — produto fisico, baixa estoque</span>
                            <?php else: ?>
                                <span class="status-badge status-badge--info">Nao — servico/M.O./taxa, nao baixa estoque</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Cadastrado em</dt>
                        <dd class="col-12 text-mono small text-body-secondary mb-0"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></dd>

                        <?php if (!empty($p['updated_at']) && $p['updated_at'] !== $p['created_at']): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold mt-2">Atualizado em</dt>
                        <dd class="col-12 text-mono small text-body-secondary mb-0"><?= date('d/m/Y H:i', strtotime($p['updated_at'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
