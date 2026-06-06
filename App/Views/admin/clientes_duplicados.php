<?php
use App\Core\View;

/**
 * @var array  $candidatos  Todos os pares suspeitos
 * @var array  $porMotivo   Pares indexados por motivo
 * @var array  $totais      Contagens por critério
 */

$candidatos = is_array($candidatos ?? null) ? $candidatos : [];
$porMotivo  = is_array($porMotivo ?? null) ? $porMotivo : [];
$totais     = array_merge([
    'total' => 0,
    'cpf' => 0,
    'email' => 0,
    'telefone' => 0,
], is_array($totais ?? null) ? $totais : []);

$fmtData = static function (mixed $data): string {
    $data = trim((string) $data);
    if ($data === '') {
        return '—';
    }

    $timestamp = strtotime($data);
    return $timestamp !== false ? date('d/m/Y', $timestamp) : '—';
};

$slugMotivo = static function (mixed $motivo): string {
    $slug = preg_replace('/\W+/', '-', strtolower((string) $motivo));
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : 'sem-criterio';
};

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
                    data-bs-target="#tab-<?= View::e($slugMotivo($motivo)) ?>">
                <?= View::e($motivo) ?>
                <span class="badge <?= $badgeMotivo[$motivo] ?? 'text-bg-secondary' ?> ms-1"><?= count((array) $pares) ?></span>
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
        <div class="tab-pane fade" id="tab-<?= View::e($slugMotivo($motivo)) ?>">
            <?php renderTabela((array) $pares, $badgeMotivo, $fmtData) ?>
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
            <?php
            $par = array_merge([
                'id_a' => 0,
                'nome_a' => '',
                'fantasia_a' => '',
                'cpf_a' => '',
                'email_a' => '',
                'telefone_a' => '',
                'celular_a' => '',
                'os_a' => 0,
                'created_a' => '',
                'id_b' => 0,
                'nome_b' => '',
                'fantasia_b' => '',
                'cpf_b' => '',
                'email_b' => '',
                'telefone_b' => '',
                'celular_b' => '',
                'os_b' => 0,
                'created_b' => '',
                'motivo' => 'Sem critério',
            ], is_array($par) ? $par : []);
            $idA = (int) $par['id_a'];
            $idB = (int) $par['id_b'];
            $motivo = trim((string) $par['motivo']) ?: 'Sem critério';
            ?>
            <tr>
                <td>
                    <span class="badge <?= $badgeMotivo[$motivo] ?? 'text-bg-secondary' ?>">
                        <?= View::e($motivo) ?>
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
                    <a href="/clientes/<?= $idA ?>" target="_blank"
                       class="btn btn-xs btn-outline-secondary" title="Ver ficha #<?= $idA ?>">
                        #<?= $idA ?> <i class="ph ph-arrow-square-out"></i>
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
                    <a href="/clientes/<?= $idB ?>" target="_blank"
                       class="btn btn-xs btn-outline-secondary" title="Ver ficha #<?= $idB ?>">
                        #<?= $idB ?> <i class="ph ph-arrow-square-out"></i>
                    </a>
                </td>
                <td class="text-center text-nowrap">
                    <?php
                    // Sugestão de destino: prioridade CPF → mais OS → email → menor ID
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
                    <?php if ($idA > 0 && $idB > 0): ?>
                    <a href="/admin/clientes/<?= $sugOrigem ?>/mesclar-em/<?= $sugDestino ?>"
                       class="btn btn-xs btn-outline-danger"
                       title="Comparar e mesclar #<?= $sugOrigem ?> → #<?= $sugDestino ?>">
                        <i class="ph ph-git-merge me-1"></i>Mesclar
                    </a>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}
?>
