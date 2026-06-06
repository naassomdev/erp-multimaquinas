<?php
use App\Core\View;

/**
 * @var array<int,array<string,mixed>> $jobs
 * @var array<string,int> $resumo
 * @var string $csrf_token
 */
$dt = fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '-';
$money = fn(mixed $v): string => $v === null || $v === '' ? '-' : 'R$ ' . number_format((float)$v, 2, ',', '.');
?>
<div class="d-flex flex-column gap-4">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-list-checks"></i> Jobs fiscais NFS-e
            </h1>
            <p class="page-header__subtitle mb-0">
                Revisão administrativa dos jobs pendentes da integração fiscal antiga.
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/nfse" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left"></i> Voltar
            </a>
        </div>
    </div>

    <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
        <i class="ph ph-warning-circle"></i>
        <div>
            <strong>Área de contenção fiscal.</strong>
            Arquivar um job marca apenas o controle interno. Nenhuma NFS-e será transmitida, cancelada ou alterada.
        </div>
    </div>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
        <div class="col">
            <div class="card shadow-sm border-start border-primary border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Jobs fiscais</div>
                    <div class="fs-4 fw-bold"><?= (int)($resumo['total'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm border-start border-warning border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Inválidos</div>
                    <div class="fs-4 fw-bold text-warning"><?= (int)($resumo['invalidos'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm border-start border-success border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Preservados</div>
                    <div class="fs-4 fw-bold text-success"><?= (int)($resumo['preservados'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm border-start border-secondary border-3">
                <div class="card-body py-2 px-3">
                    <div class="small text-body-secondary">Arquivados</div>
                    <div class="fs-4 fw-bold text-secondary"><?= (int)($resumo['arquivados'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Tipo/status</th>
                        <th>Nota</th>
                        <th>OS</th>
                        <th>Cliente</th>
                        <th class="text-end">Valor</th>
                        <th>Tentativas</th>
                        <th>Datas</th>
                        <th>Diagnóstico</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td class="text-mono">#<?= (int)$job['job_id'] ?></td>
                        <td>
                            <div><?= View::e($job['tipo'] ?? '') ?></div>
                            <span class="badge bg-light text-dark border"><?= View::e($job['job_status'] ?? '') ?></span>
                            <?php if (!empty($job['arquivado'])): ?>
                                <span class="badge bg-secondary">Arquivado</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($job['nota_fiscal_id'])): ?>
                                <?php if (!empty($job['nota_existe_bool'])): ?>
                                    <a href="/nfse/<?= (int)$job['nota_fiscal_id'] ?>">#<?= (int)$job['nota_fiscal_id'] ?></a>
                                    <div class="small text-success">nota existente</div>
                                <?php else: ?>
                                    <span class="text-mono">#<?= (int)$job['nota_fiscal_id'] ?></span>
                                    <div class="small text-danger">nota não encontrada</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-body-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-mono"><?= View::e($job['os_id'] ?? '-') ?></td>
                        <td>
                            <?php if (!empty($job['cliente_id'])): ?>
                                <div><?= View::e($job['cliente_nome'] ?? '') ?></div>
                                <div class="small text-body-secondary">ID <?= (int)$job['cliente_id'] ?></div>
                            <?php else: ?>
                                <span class="text-body-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $money($job['valor'] ?? null) ?></td>
                        <td>
                            <?= (int)($job['tentativas'] ?? 0) ?> / <?= (int)($job['max_tentativas'] ?? 0) ?>
                            <?php if (!empty($job['erro'])): ?>
                                <div class="small text-danger"><?= View::e($job['erro']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <div>Criado: <?= $dt($job['criado_em'] ?? null) ?></div>
                            <div>Disp.: <?= $dt($job['disponivel_em'] ?? null) ?></div>
                            <div>Proc.: <?= $dt($job['processado_em'] ?? null) ?></div>
                            <?php if (!empty($job['arquivado_em'])): ?>
                                <div>Arq.: <?= $dt($job['arquivado_em']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <?php foreach (($job['diagnosticos'] ?? []) as $diag): ?>
                                    <?php
                                    $badge = match ($diag) {
                                        'nota não encontrada', 'recomendado arquivar' => 'bg-warning text-dark',
                                        'nota existente', 'preservar', 'job 25 preservado' => 'bg-success',
                                        'arquivado internamente' => 'bg-secondary',
                                        default => 'bg-light text-dark border',
                                    };
                                    ?>
                                    <span class="badge <?= $badge ?>"><?= View::e($diag) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <details>
                                <summary class="small text-body-secondary">Payload</summary>
                                <code class="small"><?= View::e($job['payload_resumo'] ?? '{}') ?></code>
                            </details>
                            <?php if (!empty($job['arquivamento_motivo'])): ?>
                                <div class="small text-body-secondary mt-1">
                                    Motivo: <?= View::e($job['arquivamento_motivo']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="min-width: 260px">
                            <?php if (!empty($job['pode_arquivar'])): ?>
                                <form method="POST"
                                      action="/nfse/jobs-fiscais/<?= (int)$job['job_id'] ?>/arquivar"
                                      onsubmit="return confirm('Este job fiscal será arquivado apenas no sistema interno. Nenhuma NFS-e será transmitida, cancelada ou alterada. Deseja continuar?');">
                                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                                    <label class="form-label small" for="motivo-<?= (int)$job['job_id'] ?>">Motivo</label>
                                    <textarea class="form-control form-control-sm mb-2"
                                              id="motivo-<?= (int)$job['job_id'] ?>"
                                              name="motivo"
                                              rows="2"
                                              required
                                              placeholder="Ex.: nota fiscal vinculada não existe"></textarea>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="ph ph-archive"></i> Arquivar
                                    </button>
                                </form>
                            <?php elseif (!empty($job['arquivado'])): ?>
                                <span class="text-body-secondary small">Arquivado por controle interno.</span>
                            <?php else: ?>
                                <span class="text-body-secondary small">
                                    Preservado, pendente e bloqueado por configuração.
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
