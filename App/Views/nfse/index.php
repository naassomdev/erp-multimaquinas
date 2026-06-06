<?php
use App\Core\Auth;
use App\Core\View;
/**
 * @var array $notas
 * @var array $filtros
 * @var array $resumo
 * @var array $statusFila
 * @var array $settings
 * @var array $paginacao
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt    = fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';

$q   = $filtros['busca']  ?? '';
$st  = $filtros['status'] ?? '';
$de  = $filtros['de']     ?? '';
$ate = $filtros['ate']    ?? '';
$pg  = $paginacao;
?>
<div class="d-flex flex-column gap-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-file-text"></i> NFS-e
            </h1>
            <p class="page-header__subtitle mb-0"><?= number_format($pg['total'], 0, ',', '.') ?> nota(s) fiscal(is)</p>
        </div>
        <div class="page-header__actions">
            <a href="/nfse/rascunho" class="btn btn-primary">
                <i class="ph ph-file-plus"></i> Novo rascunho
            </a>
            <?php if (Auth::temNivel('admin')): ?>
                <a href="/nfse/jobs-fiscais" class="btn btn-outline-secondary">
                    <i class="ph ph-list-checks"></i> Jobs fiscais
                </a>
                <a href="/nfse/configuracao" class="btn btn-outline-secondary">
                    <i class="ph ph-gear"></i> Configuração
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI cards -->
    <?php if (($settings['write_enabled'] ?? '0') !== '1' || ($settings['danfse_shadow_mode'] ?? '1') === '1'): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
            <i class="ph ph-warning-circle"></i>
            <div>
                <strong>Modo seguro ativo.</strong>
                Escrita/transmissão NFS-e está desabilitada e o DANFSe permanece em shadow mode.
            </div>
        </div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-6 g-3">
        <div class="col">
            <div class="card shadow-sm kpi-card border-start border-primary border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Rascunhos</div>
                    <div class="fs-4 fw-bold text-primary"><?= (int)($resumo['rascunho']['qtd'] ?? 0) ?></div>
                    <div class="small text-body-secondary"><?= $money((float)($resumo['rascunho']['valor_total'] ?? 0)) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm kpi-card border-start border-warning border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Pendentes</div>
                    <div class="fs-4 fw-bold text-warning"><?= (int)$resumo['pendente']['qtd'] ?></div>
                    <div class="small text-body-secondary"><?= $money($resumo['pendente']['valor_total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm kpi-card border-start border-success border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Autorizadas</div>
                    <div class="fs-4 fw-bold text-success"><?= (int)$resumo['autorizada']['qtd'] ?></div>
                    <div class="small text-body-secondary"><?= $money($resumo['autorizada']['valor_total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm kpi-card border-start border-danger border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Rejeitadas</div>
                    <div class="fs-4 fw-bold text-danger"><?= (int)$resumo['rejeitada']['qtd'] ?></div>
                    <div class="small text-body-secondary"><?= $money($resumo['rejeitada']['valor_total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm kpi-card border-start border-secondary border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Canceladas</div>
                    <div class="fs-4 fw-bold text-secondary"><?= (int)$resumo['cancelada']['qtd'] ?></div>
                    <div class="small text-body-secondary"><?= $money($resumo['cancelada']['valor_total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm kpi-card border-start border-info border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Fila do worker</div>
                    <div class="fs-4 fw-bold text-info">
                        <?= (int)$statusFila['pending'] + (int)$statusFila['processing'] ?>
                    </div>
                    <div class="small text-body-secondary">
                        <?= (int)$statusFila['failed'] ?> falho(s) · <?= (int)$statusFila['done'] ?> ok
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="/nfse">
                <div class="row g-3 align-items-end">
                    <div class="col-md">
                        <label class="form-label" for="filtro-busca">Buscar</label>
                        <div class="input-icon">
                            <input type="text" class="form-control" id="filtro-busca" name="q"
                                   value="<?= View::e($q) ?>"
                                   placeholder="Número, protocolo, cliente, CPF/CNPJ ou descrição...">
                        </div>
                    </div>
                    <div class="col-md-auto">
                        <label class="form-label" for="filtro-status">Status</label>
                        <select class="form-select" id="filtro-status" name="status">
                            <option value="">Todos</option>
                            <option value="rascunho"   <?= $st === 'rascunho'   ? 'selected' : '' ?>>Rascunho</option>
                            <option value="pendente"   <?= $st === 'pendente'   ? 'selected' : '' ?>>Pendente</option>
                            <option value="autorizada" <?= $st === 'autorizada' ? 'selected' : '' ?>>Autorizada</option>
                            <option value="rejeitada"  <?= $st === 'rejeitada'  ? 'selected' : '' ?>>Rejeitada</option>
                            <option value="cancelada"  <?= $st === 'cancelada'  ? 'selected' : '' ?>>Cancelada</option>
                            <option value="erro"       <?= $st === 'erro'       ? 'selected' : '' ?>>Erro</option>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <label class="form-label" for="filtro-de">Emitida de</label>
                        <input type="date" class="form-control" id="filtro-de" name="de" value="<?= View::e($de) ?>">
                    </div>
                    <div class="col-md-auto">
                        <label class="form-label" for="filtro-ate">Até</label>
                        <input type="date" class="form-control" id="filtro-ate" name="ate" value="<?= View::e($ate) ?>">
                    </div>
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="ph ph-magnifying-glass"></i> Filtrar
                        </button>
                        <?php if ($q !== '' || $st !== '' || $de !== '' || $ate !== ''): ?>
                            <a href="/nfse" class="btn btn-secondary">
                                <i class="ph ph-x"></i> Limpar
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($notas)): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="empty-state text-center py-5">
                    <div class="empty-state__icon mb-3">
                        <i class="ph ph-file-text fs-1 text-body-secondary"></i>
                    </div>
                    <?php if ($q !== '' || $st !== '' || $de !== '' || $ate !== ''): ?>
                        <h5 class="empty-state__title">Nenhuma nota encontrada</h5>
                        <p class="empty-state__desc text-body-secondary mb-0">Nenhuma nota encontrada com os filtros informados.</p>
                    <?php else: ?>
                        <h5 class="empty-state__title">Nenhuma nota fiscal emitida</h5>
                        <p class="empty-state__desc text-body-secondary mb-0">As notas são geradas automaticamente quando uma OS é concluída.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover data-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Número</th>
                            <th>OS</th>
                            <th>Cliente</th>
                            <th>Emitida em</th>
                            <th class="text-end">Valor</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($notas as $n): ?>
                        <?php
                        $badgeCls = match ($n['status']) {
                            'autorizada' => 'status-badge--success',
                            'rejeitada'  => 'status-badge--danger',
                            'cancelada'  => 'status-badge--neutral',
                            'rascunho'   => 'status-badge--info',
                            'erro'       => 'status-badge--danger',
                            default      => 'status-badge--warning',
                        };
                        $badgeTxt = match ($n['status']) {
                            'autorizada' => 'Autorizada',
                            'rejeitada'  => 'Rejeitada',
                            'cancelada'  => 'Cancelada',
                            'rascunho'   => 'Rascunho',
                            'erro'       => 'Erro',
                            default      => 'Pendente',
                        };
                        ?>
                        <tr<?= $n['status'] === 'cancelada' ? ' class="opacity-50"' : '' ?>>
                            <td class="text-mono">#<?= (int)$n['id'] ?></td>
                            <td class="text-mono">
                                <?php if (!empty($n['numero'])): ?>
                                    <strong><?= View::e($n['numero']) ?></strong>
                                <?php else: ?>
                                    <span class="text-body-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-mono">#<?= View::e((string)$n['os_id']) ?></td>
                            <td>
                                <?= View::e($n['cliente_nome'] ?? '—') ?>
                                <?php if (!empty($n['cpf_cnpj'])): ?>
                                    <div class="small text-body-secondary text-mono"><?= View::e($n['cpf_cnpj']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-mono"><?= $dt($n['criado_em']) ?></td>
                            <td class="text-end text-mono">
                                <?= $n['valor'] !== null ? $money((float)$n['valor']) : '—' ?>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?= $badgeCls ?>"><?= $badgeTxt ?></span>
                            </td>
                            <td class="text-center">
                                <a href="/nfse/<?= (int)$n['id'] ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="Ver">
                                    <i class="ph ph-eye"></i>
                                </a>
                                <a href="/nfse/<?= (int)$n['id'] ?>/conferencia" class="btn btn-sm btn-outline-primary btn-icon" title="Conferência">
                                    <i class="ph ph-clipboard-text"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (($pg['total_pages'] ?? 1) > 1): ?>
                <div class="card-footer data-table__footer d-flex justify-content-center">
                    <?php
                    $p  = $pg['page'];
                    $tp = $pg['total_pages'];
                    $qs = http_build_query(array_filter(['q'=>$q,'status'=>$st,'de'=>$de,'ate'=>$ate]));
                    $base = '/nfse' . ($qs ? "?{$qs}&" : '?');
                    ?>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $base ?>p=1">Primeira</a>
                        </li>
                        <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $base ?>p=<?= max(1,$p-1) ?>">Anterior</a>
                        </li>
                        <?php
                        $inicio = max(1, $p - 2);
                        $fim    = min($tp, $p + 2);
                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                            <li class="page-item <?= $i === $p ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $base ?>p=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $base ?>p=<?= min($tp,$p+1) ?>">Próxima</a>
                        </li>
                        <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $base ?>p=<?= $tp ?>">Última</a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
