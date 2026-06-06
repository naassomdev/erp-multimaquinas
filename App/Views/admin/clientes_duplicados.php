<?php
use App\Core\View;

/**
 * @var array  $candidatos  Todos os pares suspeitos
 * @var array  $porMotivo   Pares indexados por motivo
 * @var array  $totais      Contagens por critério
 */

$fmtData = fn(string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

$badgeMotivo = [
    'CPF/CNPJ igual'        => 'text-bg-danger',
    'E-mail igual'          => 'text-bg-warning',
    'Telefone/celular igual' => 'text-bg-info',
];
?>

<div class="d-flex flex-column gap-4">

    <div class="page-header">
        <div>
            <h1 class="page-header__title">Clientes Duplicados</h1>
            <p class="page-header__subtitle">
                Detecção automática de possíveis duplicidades — revisão manual obrigatória antes de qualquer mesclagem.
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/clientes" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Clientes
            </a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold <?= $totais['total'] > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $totais['total'] ?>
                    </div>
                    <div class="small text-body-secondary">Pares suspeitos</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold <?= $totais['cpf'] > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?= $totais['cpf'] ?>
                    </div>
                    <div class="small text-body-secondary">CPF/CNPJ igual</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold <?= $totais['email'] > 0 ? 'text-warning' : 'text-muted' ?>">
                        <?= $totais['email'] ?>
                    </div>
                    <div class="small text-body-secondary">E-mail igual</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold <?= $totais['telefone'] > 0 ? 'text-info' : 'text-muted' ?>">
                        <?= $totais['telefone'] ?>
                    </div>
                    <div class="small text-body-secondary">Telefone igual</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($candidatos)): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="ph ph-check-circle fs-4"></i>
        <span>Nenhum par duplicado detectado com os critérios atuais. Base de clientes limpa.</span>
    </div>
    <?php else: ?>

    <div class="alert alert-warning small d-flex gap-2 align-items-start">
        <i class="ph ph-warning flex-shrink-0 mt-1 fs-5"></i>
        <div>
            <strong>Atenção:</strong> Esta tela lista <em>suspeitas</em> de duplicidade. Nem todo par listado é necessariamente um duplicado real.
            Telefones repetidos podem pertencer a empresas do mesmo grupo. E-mails iguais podem ser de uma contabilidade compartilhada.
            <strong>Revise cada caso antes de mesclar.</strong>
            Use o botão <kbd>Mesclar</kbd> para comparar os dois clientes lado a lado antes de confirmar.
        </div>
    </div>

    <!-- Abas por critério -->
    <ul class="nav nav-tabs" id="tabDuplicados" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-todos">
                Todos <span class="badge text-bg-secondary ms-1"><?= $totais['total'] ?></span>
            </button>
        </li>
        <?php foreach ($porMotivo as $motivo => $pares): ?>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab"
                    data-bs-target="#tab-<?= htmlspecialchars(preg_replace('/\W+/', '-', strtolower($motivo)) ?? '') ?>">
                <?= View::e($motivo) ?>
                <span class="badge <?= $badgeMotivo[$motivo] ?? 'text-bg-secondary' ?> ms-1"><?= count($pares) ?></span>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content mt-3">

        <!-- Aba: Todos -->
        <div class="tab-pane fade show active" id="tab-todos">
            <?php renderTabela($candidatos, $badgeMotivo, $fmtData) ?>
        </div>

        <!-- Abas por critério -->
        <?php foreach ($porMotivo as $motivo => $pares): ?>
        <div class="tab-pane fade" id="tab-<?= htmlspecialchars(preg_replace('/\W+/', '-', strtolower($motivo)) ?? '') ?>">
            <?php $this->renderTabela($pares, $badgeMotivo, $fmtData) ?>
        </div>
        <?php endforeach; ?>

    </div>
    <?php endif; ?>
</div>

<?php
// Helper inline para não duplicar HTML da tabela
function renderTabela(array $candidatos, array $badgeMotivo, callable $fmtData): void
{
?>
<div class="table-responsive">
    <table class="table table-hover table-bordered table-sm align-middle small">
        <thead class="table-dark">
            <tr>
                <th>Motivo</th>
                <th>Cliente A</th>
                <th>OS</th>
                <th>CPF/CNPJ</th>
                <th>E-mail</th>
                <th>Telefone</th>
                <th>Criado em</th>
                <th></th>
                <th>Cliente B</th>
                <th>OS</th>
                <th>CPF/CNPJ</th>
                <th>E-mail</th>
                <th>Telefone</th>
                <th>Criado em</th>
                <th></th>
                <th class="text-center">Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($candidatos as $par): ?>
            <tr>
                <td>
                    <span class="badge <?= $badgeMotivo[$par['motivo']] ?? 'text-bg-secondary' ?>">
                        <?= View::e($par['motivo']) ?>
                    </span>
                </td>
                <!-- Cliente A -->
                <td>
                    <div class="fw-medium"><?= View::e($par['nome_a']) ?></div>
                    <?php if (!empty($par['fantasia_a'])): ?>
                        <div class="text-body-secondary"><?= View::e($par['fantasia_a']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <span class="badge text-bg-<?= ($par['os_a'] ?? 0) > 0 ? 'primary' : 'light text-dark' ?>">
                        <?= (int)($par['os_a'] ?? 0) ?>
                    </span>
                </td>
                <td class="text-mono"><?= View::e($par['cpf_a']) ?: '—' ?></td>
                <td><?= View::e($par['email_a']) ?: '—' ?></td>
                <td class="text-mono">
                    <?= View::e($par['celular_a'] ?: $par['telefone_a']) ?: '—' ?>
                </td>
                <td class="text-mono"><?= $fmtData((string)($par['created_a'] ?? '')) ?></td>
                <td>
                    <a href="/clientes/<?= (int)$par['id_a'] ?>" target="_blank"
                       class="btn btn-xs btn-outline-secondary" title="Ver ficha #<?= (int)$par['id_a'] ?>">
                        #<?= (int)$par['id_a'] ?> <i class="ph ph-arrow-square-out"></i>
                    </a>
                </td>
                <!-- VS -->
                <!-- Cliente B -->
                <td>
                    <div class="fw-medium"><?= View::e($par['nome_b']) ?></div>
                    <?php if (!empty($par['fantasia_b'])): ?>
                        <div class="text-body-secondary"><?= View::e($par['fantasia_b']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <span class="badge text-bg-<?= ($par['os_b'] ?? 0) > 0 ? 'primary' : 'light text-dark' ?>">
                        <?= (int)($par['os_b'] ?? 0) ?>
                    </span>
                </td>
                <td class="text-mono"><?= View::e($par['cpf_b']) ?: '—' ?></td>
                <td><?= View::e($par['email_b']) ?: '—' ?></td>
                <td class="text-mono">
                    <?= View::e($par['celular_b'] ?: $par['telefone_b']) ?: '—' ?>
                </td>
                <td class="text-mono"><?= $fmtData((string)($par['created_b'] ?? '')) ?></td>
                <td>
                    <a href="/clientes/<?= (int)$par['id_b'] ?>" target="_blank"
                       class="btn btn-xs btn-outline-secondary" title="Ver ficha #<?= (int)$par['id_b'] ?>">
                        #<?= (int)$par['id_b'] ?> <i class="ph ph-arrow-square-out"></i>
                    </a>
                </td>
                <td class="text-center text-nowrap">
                    <?php
                    // Sugestão de destino: prioridade CPF → mais OS → email → menor ID
                    $idA = (int)$par['id_a']; $idB = (int)$par['id_b'];
                    $scoreA = 0; $scoreB = 0;
                    if (trim((string)($par['cpf_a'] ?? '')) !== '') $scoreA += 100;
                    if (trim((string)($par['cpf_b'] ?? '')) !== '') $scoreB += 100;
                    $scoreA += (int)($par['os_a'] ?? 0) * 10;
                    $scoreB += (int)($par['os_b'] ?? 0) * 10;
                    if (trim((string)($par['email_a'] ?? '')) !== '') $scoreA += 5;
                    if (trim((string)($par['email_b'] ?? '')) !== '') $scoreB += 5;
                    // Destino = mais completo; Origem = menos completo (será inativado)
                    if ($scoreA > $scoreB || ($scoreA === $scoreB && $idA < $idB)) {
                        $sugDestino = $idA; $sugOrigem = $idB;
                    } else {
                        $sugDestino = $idB; $sugOrigem = $idA;
                    }
                    ?>
                    <a href="/admin/clientes/<?= $sugOrigem ?>/mesclar-em/<?= $sugDestino ?>"
                       class="btn btn-xs btn-outline-danger"
                       title="Comparar e mesclar #<?= $sugOrigem ?> → #<?= $sugDestino ?>">
                        <i class="ph ph-git-merge me-1"></i>Mesclar
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}
?>
