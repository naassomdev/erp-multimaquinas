<?php
declare(strict_types=1);

use App\Core\View;

/**
 * @var array  $usuarios  Lista de usuários (sem senha)
 * @var array  $filtros   Filtros ativos: q, nivel, status
 * @var array  $niveis    Níveis válidos do sistema
 */

$nivelLabel = [
    'admin'    => 'Admin',
    'recepcao' => 'Recepção',
    'oficina'  => 'Oficina',
];

$nivelBadge = [
    'admin'    => 'danger',
    'recepcao' => 'primary',
    'oficina'  => 'secondary',
];
?>

<div class="container-fluid px-4 py-4">

    <div class="d-flex align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h1 class="fs-4 fw-semibold mb-0">
                <i class="ph ph-users me-2 text-primary"></i> Usuários
            </h1>
            <p class="text-body-secondary small mt-1 mb-0">
                Gerencie os acessos ao sistema.
            </p>
        </div>
        <a href="/admin/usuarios/novo" class="btn btn-primary flex-shrink-0">
            <i class="ph ph-plus me-1"></i> Novo usuário
        </a>
    </div>

    <!-- ── Filtros ─────────────────────────────────────────────────────────── -->
    <form method="GET" action="/admin/usuarios" class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="q">Buscar</label>
                    <input type="search" class="form-control form-control-sm" id="q" name="q"
                           value="<?= View::e($filtros['q']) ?>"
                           placeholder="Nome ou e-mail…">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="nivel">Nível</label>
                    <select class="form-select form-select-sm" id="nivel" name="nivel">
                        <option value="">Todos os níveis</option>
                        <?php foreach ($niveis as $n): ?>
                        <option value="<?= View::e($n) ?>"
                            <?= $filtros['nivel'] === $n ? 'selected' : '' ?>>
                            <?= View::e($nivelLabel[$n] ?? ucfirst($n)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="status">Status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="1" <?= $filtros['status'] === '1' ? 'selected' : '' ?>>Ativo</option>
                        <option value="0" <?= $filtros['status'] === '0' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="ph ph-magnifying-glass me-1"></i> Filtrar
                    </button>
                    <?php if ($filtros['q'] !== '' || $filtros['nivel'] !== '' || $filtros['status'] !== ''): ?>
                    <a href="/admin/usuarios" class="btn btn-sm btn-outline-secondary" title="Limpar filtros">
                        <i class="ph ph-x"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <!-- ── Tabela ─────────────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($usuarios)): ?>
            <div class="text-center text-body-secondary py-5">
                <i class="ph ph-users fs-1 d-block mb-2"></i>
                <p class="mb-0">Nenhum usuário encontrado.</p>
                <?php if ($filtros['q'] !== '' || $filtros['nivel'] !== '' || $filtros['status'] !== ''): ?>
                <a href="/admin/usuarios" class="btn btn-sm btn-outline-secondary mt-3">Limpar filtros</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Nome</th>
                            <th>E-mail</th>
                            <th>Nível</th>
                            <th>Status</th>
                            <th class="text-nowrap">Criado em</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td class="ps-3 fw-medium"><?= View::e((string) $u['nome']) ?></td>
                            <td class="text-body-secondary small"><?= View::e((string) $u['email']) ?></td>
                            <td>
                                <span class="badge bg-<?= View::e($nivelBadge[$u['nivel_acesso']] ?? 'secondary') ?>">
                                    <?= View::e($nivelLabel[$u['nivel_acesso']] ?? (string) $u['nivel_acesso']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ((int) $u['status'] === 1): ?>
                                    <span class="badge bg-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-body-secondary small text-nowrap">
                                <?php
                                try {
                                    echo (new \DateTime((string) $u['criado_em']))->format('d/m/Y');
                                } catch (\Throwable) {
                                    echo View::e((string) $u['criado_em']);
                                }
                                ?>
                            </td>
                            <td class="text-end pe-3">
                                <a href="/admin/usuarios/<?= (int) $u['id'] ?>/editar"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="Editar usuário">
                                    <i class="ph ph-pencil"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-2 text-body-secondary small border-top">
                <?= count($usuarios) ?> usuário(s) encontrado(s)
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
