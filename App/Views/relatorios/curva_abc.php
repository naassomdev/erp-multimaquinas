<?php
use App\Core\View;

/** @var string $aba */
/** @var list<array<string,mixed>> $dados */
/** @var float $somaTotal */
/** @var string $de */
/** @var string $ate */

$classeColor = ['A' => 'success', 'B' => 'warning', 'C' => 'danger'];
$isEstoque = $aba === 'estoque';
?>

<div class="container-fluid py-3">
    <h4 class="mb-3">Curva ABC</h4>

    <ul class="nav nav-tabs mb-3">
        <?php foreach ([
            'estoque' => 'Estoque (valor)',
            'tipo'    => 'Tipo de equipamento',
            'marca'   => 'Marca (fabricante)',
        ] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $aba === $key ? 'active' : '' ?>"
                   href="?aba=<?= View::e($key) ?>&de=<?= urlencode($de) ?>&ate=<?= urlencode($ate) ?>">
                    <?= View::e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (!$isEstoque): ?>
        <form method="GET" class="d-flex gap-2 mb-3 align-items-end flex-wrap">
            <input type="hidden" name="aba" value="<?= View::e($aba) ?>">
            <div>
                <label class="form-label mb-1 small">De</label>
                <input type="date" name="de" class="form-control form-control-sm" value="<?= View::e($de) ?>">
            </div>
            <div>
                <label class="form-label mb-1 small">Até</label>
                <input type="date" name="ate" class="form-control form-control-sm" value="<?= View::e($ate) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        </form>
    <?php endif; ?>

    <div class="d-flex gap-3 mb-3 small flex-wrap">
        <span><span class="badge bg-success">A</span> 0–80% acumulado — itens críticos</span>
        <span><span class="badge bg-warning text-dark">B</span> 80–95% — itens intermediários</span>
        <span><span class="badge bg-danger">C</span> 95–100% — itens de baixo impacto</span>
    </div>

    <?php if (empty($dados)): ?>
        <div class="alert alert-info">Nenhum dado encontrado.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?= $isEstoque ? 'Produto' : 'Item' ?></th>
                        <?php if ($isEstoque): ?>
                            <th class="text-end">Qtd estoque</th>
                            <th class="text-end">Custo unit.</th>
                            <th class="text-end">Valor total</th>
                        <?php else: ?>
                            <th class="text-end">Quantidade</th>
                        <?php endif; ?>
                        <th class="text-end">% item</th>
                        <th class="text-end">% acumulado</th>
                        <th class="text-center">Classe</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dados as $rank => $d): ?>
                    <?php $classe = (string) ($d['classe'] ?? 'C'); ?>
                    <tr>
                        <td class="text-muted small"><?= $rank + 1 ?></td>
                        <td>
                            <?php if ($isEstoque): ?>
                                <div class="fw-semibold small"><?= View::e($d['descricao']) ?></div>
                                <div class="text-muted" style="font-size:.8em"><?= View::e($d['codigo']) ?></div>
                            <?php else: ?>
                                <?= View::e($d['label']) ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($isEstoque): ?>
                            <td class="text-end small"><?= number_format((float) $d['qty'], 0, ',', '.') ?></td>
                            <td class="text-end small">R$ <?= number_format((float) $d['custo_unit'], 2, ',', '.') ?></td>
                            <td class="text-end fw-semibold">R$ <?= number_format((float) $d['valor_total'], 2, ',', '.') ?></td>
                        <?php else: ?>
                            <td class="text-end fw-semibold"><?= number_format((int) $d['quantidade'], 0, ',', '.') ?></td>
                        <?php endif; ?>
                        <td class="text-end small"><?= number_format((float) $d['pct_item'], 1, ',', '.') ?>%</td>
                        <td class="text-end">
                            <div class="d-flex align-items-center gap-1 justify-content-end">
                                <div class="progress flex-grow-1" style="height:6px;min-width:60px;max-width:80px">
                                    <div class="progress-bar bg-<?= View::e($classeColor[$classe] ?? 'danger') ?>"
                                         style="width:<?= min(100, (float) $d['pct_acum']) ?>%"></div>
                                </div>
                                <span class="small"><?= number_format((float) $d['pct_acum'], 1, ',', '.') ?>%</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= View::e($classeColor[$classe] ?? 'danger') ?>"><?= View::e($classe) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="<?= $isEstoque ? 4 : 2 ?>">Total</td>
                        <td class="text-end">
                            <?= $isEstoque
                                ? 'R$ ' . number_format($somaTotal, 2, ',', '.')
                                : number_format((int) $somaTotal, 0, ',', '.') ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
