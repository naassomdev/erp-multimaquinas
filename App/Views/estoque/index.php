<?php
use App\Core\View;
/**
 * @var array $produtos
 * @var array $filtros
 * @var array $paginacao
 * @var array $categorias
 * @var array $marcas
 * @var array $alertasEstoque
 * @var string $return_url
 */
$q    = $filtros['busca']        ?? '';
$cat  = $filtros['categoria']    ?? '';
$marc = $filtros['marca']        ?? '';
$eb   = $filtros['estoque_baixo']?? '';
$at   = $filtros['ativo']        ?? '1';
$pg   = $paginacao ?? [];
$returnUrl = (string) ($return_url ?? '/estoque');
$returnParam = rawurlencode($returnUrl);
$temFiltro = ($q !== '' || $cat !== '' || $marc !== '' || $eb === '1' || $at !== '1');
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Estoque</h1>
            <p class="page-header__subtitle">
                <?= number_format($pg['total'] ?? 0, 0, ',', '.') ?> produto(s) cadastrado(s)
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/estoque/exportar?<?= http_build_query(array_filter(['q' => $q, 'categoria' => $cat, 'marca' => $marc, 'estoque_baixo' => $eb, 'ativo' => $at])) ?>" class="btn btn-outline-secondary">
                <i class="ph ph-file-csv me-1"></i> Exportar CSV
            </a>
            <a href="/estoque/importar" class="btn btn-outline-secondary">
                <i class="ph ph-download-simple me-1"></i> Importar NF-e
            </a>
            <a href="/estoque/novo" class="btn btn-primary">
                <i class="ph ph-plus me-1"></i> Novo Produto
            </a>
        </div>
    </div>

    <!-- Alerta de estoque baixo -->
    <?php if (!empty($alertasEstoque)): ?>
        <div class="alert alert-warning d-flex gap-3">
            <i class="ph ph-warning fs-5 flex-shrink-0 mt-1"></i>
            <div class="flex-grow-1">
                <p class="fw-medium mb-1"><?= count($alertasEstoque) ?> produto(s) abaixo do estoque minimo</p>
                <div class="d-flex flex-wrap gap-3 small">
                    <?php foreach (array_slice($alertasEstoque, 0, 5) as $al): ?>
                        <a href="/estoque/<?= (int)$al['id'] ?>?return_url=<?= $returnParam ?>" class="text-warning-emphasis text-decoration-none">
                            <?= View::e($al['descricao']) ?>
                            <span class="opacity-75">(<?= number_format((float)$al['estoque_qty'], 0, ',', '.') ?>/<?= number_format((float)$al['estoque_min'], 0, ',', '.') ?>)</span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($alertasEstoque) > 5): ?>
                        <span class="fst-italic opacity-75">e mais <?= count($alertasEstoque) - 5 ?>...</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/estoque" class="row g-3 align-items-end">
                <div class="col-lg">
                    <label class="form-label">Buscar</label>
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" name="q" value="<?= View::e($q) ?>"
                               placeholder="Codigo, descricao, EAN ou marca..." autofocus
                               class="form-control">
                    </div>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= View::e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= View::e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Marca</label>
                    <select name="marca" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($marcas as $m): ?>
                            <option value="<?= View::e($m) ?>" <?= $marc === $m ? 'selected' : '' ?>><?= View::e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-auto">
                    <label class="form-label">Status</label>
                    <select name="ativo" class="form-select">
                        <option value="1" <?= $at === '1' ? 'selected' : '' ?>>Ativos</option>
                        <option value="0" <?= $at === '0' ? 'selected' : '' ?>>Inativos</option>
                        <option value=""  <?= $at === ''  ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-lg-auto d-flex align-items-center pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="estoque_baixo" value="1" <?= $eb === '1' ? 'checked' : '' ?>
                               class="form-check-input" id="chkEstoqueBaixo">
                        <label class="form-check-label" for="chkEstoqueBaixo">Estoque baixo</label>
                    </div>
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2 page-filters__actions">
                    <button type="submit" class="btn btn-primary flex-grow-1 flex-lg-grow-0">
                        <i class="ph ph-funnel me-1"></i> Filtrar
                    </button>
                    <?php if ($temFiltro): ?>
                        <a href="/estoque" class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela -->
    <?php if (empty($produtos)): ?>
        <div class="card shadow-sm">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-package"></i></div>
                <h3 class="empty-state__title">Nenhum produto encontrado</h3>
                <p class="empty-state__desc">
                    <?= $temFiltro ? 'Ajuste os filtros ou ' : 'Comece ' ?>
                    <a href="/estoque/novo">cadastre um novo produto</a>.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="d-md-none mobile-records">
            <?php foreach ($produtos as $p): ?>
                <?php
                    $estoqueQty = (float)($p['estoque_qty'] ?? 0);
                    $estoqueMin = (float)($p['estoque_min'] ?? 0);
                    $baixo      = ($estoqueMin > 0 && $estoqueQty <= $estoqueMin);
                    $negativo   = $estoqueQty < 0;
                    $inativo    = empty($p['ativo']);

                    if ($negativo) {
                        $stockBadge = 'bg-danger';
                    } elseif ($baixo) {
                        $stockBadge = 'bg-warning text-dark';
                    } elseif ($estoqueQty > 0) {
                        $stockBadge = 'bg-success';
                    } else {
                        $stockBadge = 'bg-secondary';
                    }
                ?>
                <?php
                    $detalheUrl = '/estoque/' . (int) $p['id'] . '?return_url=' . $returnParam;
                    $editarUrl = '/estoque/' . (int) $p['id'] . '/editar?return_url=' . $returnParam;
                ?>
                <div class="mobile-record-card <?= $inativo ? 'opacity-50' : '' ?> cursor-pointer" onclick="window.location=<?= View::e(json_encode($detalheUrl, JSON_UNESCAPED_SLASHES)) ?>">
                    <div class="mobile-record-card__top">
                        <div>
                            <div class="mobile-record-card__title"><?= View::e($p['descricao']) ?></div>
                            <div class="mobile-record-card__subtitle text-mono"><?= View::e($p['codigo'] ?: '—') ?></div>
                        </div>
                        <i class="ph ph-caret-right mobile-record-card__arrow"></i>
                    </div>

                    <div class="mobile-record-card__body">
                        <div class="mobile-record-card__grid">
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Marca</span>
                                <span class="mobile-record-card__value small"><?= View::e($p['marca'] ?: '—') ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Categoria</span>
                                <span class="mobile-record-card__value small"><?= View::e($p['categoria'] ?: '—') ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Custo</span>
                                <span class="mobile-record-card__value text-mono small"><?= number_format((float)($p['preco_custo'] ?? 0), 2, ',', '.') ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Venda</span>
                                <span class="mobile-record-card__value text-mono fw-semibold"><?= number_format((float)($p['valor_venda_calculado'] ?? $p['valor'] ?? 0), 2, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="mobile-record-card__footer">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge <?= $stockBadge ?>"><?= number_format($estoqueQty, 0, ',', '.') ?></span>
                            <?php if ($negativo): ?>
                                <span class="mobile-record-card__hint text-danger">Estoque negativo</span>
                            <?php elseif ($baixo): ?>
                                <span class="mobile-record-card__hint text-warning">Min: <?= number_format($estoqueMin, 0, ',', '.') ?></span>
                            <?php else: ?>
                                <span class="mobile-record-card__hint">Estoque atual</span>
                            <?php endif; ?>
                            <?php if (!(int)($p['controla_estoque'] ?? 1)): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:.65rem">Servico/M.O.</span>
                            <?php endif; ?>
                        </div>
                        <div class="mobile-record-card__actions" onclick="event.stopPropagation()">
                            <a href="<?= View::e($detalheUrl) ?>" class="btn-icon" title="Ver detalhes">
                                <i class="ph ph-eye"></i>
                            </a>
                            <a href="<?= View::e($editarUrl) ?>" class="btn-icon" title="Editar">
                                <i class="ph ph-pencil-simple"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="data-table d-none d-md-block">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Codigo</th>
                        <th>Produto</th>
                        <th>Marca</th>
                        <th class="text-end">Custo</th>
                        <th class="text-end">Margem</th>
                        <th class="text-end">Venda</th>
                        <th class="text-center">Estoque</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($produtos as $p): ?>
                    <?php
                        $estoqueQty = (float)($p['estoque_qty'] ?? 0);
                        $estoqueMin = (float)($p['estoque_min'] ?? 0);
                        $baixo      = ($estoqueMin > 0 && $estoqueQty <= $estoqueMin);
                        $negativo   = $estoqueQty < 0;
                        $inativo    = empty($p['ativo']);

                        if ($negativo) {
                            $stockBadge = 'bg-danger';
                        } elseif ($baixo) {
                            $stockBadge = 'bg-warning text-dark';
                        } elseif ($estoqueQty > 0) {
                            $stockBadge = 'bg-success';
                        } else {
                            $stockBadge = 'bg-secondary';
                        }
                    ?>
                    <?php
                        $detalheUrl = '/estoque/' . (int) $p['id'] . '?return_url=' . $returnParam;
                        $editarUrl = '/estoque/' . (int) $p['id'] . '/editar?return_url=' . $returnParam;
                    ?>
                    <tr class="<?= $inativo ? 'opacity-50' : '' ?> cursor-pointer" onclick="window.location=<?= View::e(json_encode($detalheUrl, JSON_UNESCAPED_SLASHES)) ?>">
                        <td class="text-mono small text-body-secondary text-nowrap"><?= View::e($p['codigo'] ?: '—') ?></td>
                        <td>
                            <div class="fw-medium"><?= View::e($p['descricao']) ?></div>
                            <?php if (!empty($p['categoria'])): ?>
                                <div class="text-body-secondary small"><?= View::e($p['categoria']) ?></div>
                            <?php endif; ?>
                            <?php if (!(int)($p['controla_estoque'] ?? 1)): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:.65rem">Servico/M.O.</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-body-secondary text-nowrap"><?= View::e($p['marca'] ?: '—') ?></td>
                        <td class="text-end text-mono text-body-secondary text-nowrap"><?= number_format((float)($p['preco_custo'] ?? 0), 2, ',', '.') ?></td>
                        <td class="text-end text-mono text-body-secondary text-nowrap"><?= number_format((float)($p['margem_lucro'] ?? 0), 1, ',', '.') ?>%</td>
                        <td class="text-end text-mono fw-medium text-nowrap"><?= number_format((float)($p['valor_venda_calculado'] ?? $p['valor'] ?? 0), 2, ',', '.') ?></td>
                        <td class="text-center text-nowrap">
                            <span class="badge <?= $stockBadge ?>"><?= number_format($estoqueQty, 0, ',', '.') ?></span>
                            <?php if ($negativo): ?>
                                <div class="text-danger small fw-bold" style="font-size:.65rem">NEGATIVO</div>
                            <?php elseif ($baixo): ?>
                                <div class="text-warning small" style="font-size:.65rem">min: <?= number_format($estoqueMin, 0, ',', '.') ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <div class="d-flex justify-content-end gap-1">
                                <a href="<?= View::e($detalheUrl) ?>" class="btn-icon" title="Ver detalhes">
                                    <i class="ph ph-eye"></i>
                                </a>
                                <a href="<?= View::e($editarUrl) ?>" class="btn-icon" title="Editar">
                                    <i class="ph ph-pencil-simple"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginacao -->
            <?php if (($pg['total_pages'] ?? 1) > 1): ?>
                <?php
                $p    = $pg['page'];
                $tp   = $pg['total_pages'];
                $qs   = http_build_query(array_filter([
                    'q' => $q, 'categoria' => $cat, 'marca' => $marc,
                    'estoque_baixo' => $eb, 'ativo' => $at,
                ]));
                $base = '/estoque' . ($qs ? "?{$qs}&" : '?');
                ?>
                <div class="data-table__footer">
                    <div>
                        Pagina <strong><?= $p ?></strong> de <strong><?= $tp ?></strong>
                        <span class="d-none d-sm-inline">&middot; <?= number_format($pg['total'] ?? 0, 0, ',', '.') ?> resultados</span>
                    </div>
                    <nav aria-label="Paginacao">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base ?>p=<?= max(1, $p - 1) ?>"><i class="ph ph-caret-left"></i></a>
                            </li>
                            <?php for ($i = max(1, $p - 2); $i <= min($tp, $p + 2); $i++): ?>
                                <li class="page-item <?= $i === $p ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $base ?>p=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base ?>p=<?= min($tp, $p + 1) ?>"><i class="ph ph-caret-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
