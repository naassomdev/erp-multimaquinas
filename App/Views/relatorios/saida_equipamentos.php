<?php
use App\Core\View;

/** @var list<array<string,mixed>> $registros */
/** @var int $total */
/** @var array<string,mixed> $kpis */
/** @var list<array<string,mixed>> $tecnicos */
/** @var array<string,string> $filtros */
/** @var int $pagina */
/** @var int $totalPaginas */

$statusLabels = [
    'aberta'     => 'Aberta',
    'andamento'  => 'Em andamento',
    'montagem'   => 'Montagem',
    'pronto'     => 'Pronto',
    'retirado'   => 'Retirado',
    'devolvido'  => 'Devolvido',
    'cancelado'  => 'Cancelado',
    'descartado' => 'Descartado',
];
$tipoOpcoes = ['Motobomba', 'Bomba', 'Motor Elétrico', 'Outros'];
?>

<div class="container-fluid py-3">
    <h4 class="mb-3">Saída de Equipamentos</h4>

    <form method="GET" action="/relatorios/saida-equipamentos" class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
            <div>
                <label class="form-label mb-1 small">De</label>
                <input type="date" name="de" class="form-control form-control-sm" value="<?= View::e($filtros['de']) ?>">
            </div>
            <div>
                <label class="form-label mb-1 small">Até</label>
                <input type="date" name="ate" class="form-control form-control-sm" value="<?= View::e($filtros['ate']) ?>">
            </div>
            <div>
                <label class="form-label mb-1 small">Técnico</label>
                <select name="tecnico_id" class="form-select form-select-sm" style="min-width:160px">
                    <option value="">Todos</option>
                    <?php foreach ($tecnicos as $tecnico): ?>
                        <option value="<?= (int) $tecnico['id'] ?>" <?= (string) $filtros['tecnico_id'] === (string) $tecnico['id'] ? 'selected' : '' ?>>
                            <?= View::e($tecnico['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1 small">Tipo</label>
                <select name="tipo" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($tipoOpcoes as $tipo): ?>
                        <option value="<?= View::e($tipo) ?>" <?= $filtros['tipo'] === $tipo ? 'selected' : '' ?>>
                            <?= View::e($tipo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <a href="/relatorios/saida-equipamentos" class="btn btn-outline-secondary btn-sm">Limpar</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['label' => 'Total',        'val' => $kpis['total']      ?? 0, 'color' => 'primary'],
            ['label' => 'Motobombas',   'val' => $kpis['motobombas'] ?? 0, 'color' => 'info'],
            ['label' => 'Bombas',       'val' => $kpis['bombas']     ?? 0, 'color' => 'info'],
            ['label' => 'Motores',      'val' => $kpis['motores']    ?? 0, 'color' => 'warning'],
            ['label' => 'Outros',       'val' => $kpis['outros']     ?? 0, 'color' => 'secondary'],
            ['label' => 'OS distintas', 'val' => $kpis['total_os']   ?? 0, 'color' => 'dark'],
        ];
        foreach ($cards as $card):
        ?>
            <div class="col-6 col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body py-2">
                        <div class="fs-4 fw-bold text-<?= View::e($card['color']) ?>"><?= (int) $card['val'] ?></div>
                        <div class="small text-muted"><?= View::e($card['label']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($registros)): ?>
        <div class="alert alert-info">Nenhum equipamento encontrado para os filtros selecionados.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>OS</th>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Tipo</th>
                        <th>Fabricante</th>
                        <th>Técnico</th>
                        <th>Concluído em</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $registro): ?>
                    <tr>
                        <td>
                            <a href="/tecnico/os/<?= View::e($registro['os_id']) ?>/equipamento/<?= (int) $registro['ordem_idx'] ?>"
                               class="text-decoration-none font-monospace small">
                                <?= View::e($registro['os_id']) ?>
                            </a>
                        </td>
                        <td class="small"><?= View::e($registro['nome_cliente']) ?></td>
                        <td><?= View::e($registro['equip_nome']) ?></td>
                        <td><span class="badge bg-secondary"><?= View::e($registro['tipo_equip']) ?></span></td>
                        <td class="small text-muted"><?= View::e($registro['fabricante'] ?? '—') ?></td>
                        <td class="small"><?= View::e($registro['tecnico_nome'] ?? '—') ?></td>
                        <td class="small text-muted">
                            <?php
                            $concluidoEm = (string) ($registro['diagnostico_concluido_em'] ?? '');
                            $timestamp = $concluidoEm !== '' ? strtotime($concluidoEm) : false;
                            ?>
                            <?= $timestamp !== false ? date('d/m/Y H:i', $timestamp) : '—' ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= View::e($statusLabels[(string) $registro['status_equip']] ?? $registro['status_equip']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPaginas > 1): ?>
            <nav>
                <ul class="pagination pagination-sm">
                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                        <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($filtros, ['p' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
