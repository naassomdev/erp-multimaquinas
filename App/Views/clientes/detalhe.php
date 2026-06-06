<?php
use App\Core\View;
use App\Core\Csrf;
/**
 * @var array $cliente
 * @var array $ordens
 */
$c = $cliente;

$inicial = mb_strtoupper(mb_substr($c['nome'] ?? '?', 0, 1));

$temEndereco = !empty($c['endereco']) || !empty($c['cidade']);
$enderecoLinha = trim(implode(', ', array_filter([
    trim(($c['endereco'] ?? '') . (!empty($c['numero']) ? ', ' . $c['numero'] : '')),
    $c['complemento'] ?? '',
    $c['bairro']      ?? '',
    trim(($c['cidade'] ?? '') . (!empty($c['uf']) ? ' / ' . $c['uf'] : '')),
])));

$waTel = preg_replace('/\D/', '', $c['celular'] ?: ($c['telefone'] ?? ''));
if (strlen($waTel) === 10 || strlen($waTel) === 11) $waTel = '55' . $waTel;
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div class="d-flex align-items-start gap-3">
            <div class="avatar avatar--lg"><?= View::e($inicial) ?></div>
            <div>
                <h1 class="page-header__title"><?= View::e($c['nome']) ?></h1>
                <?php if (!empty($c['nome_fantasia'])): ?>
                    <p class="text-body-secondary mb-0"><?= View::e($c['nome_fantasia']) ?></p>
                <?php endif; ?>
                <p class="small text-body-tertiary text-mono mt-1 mb-0">
                    Cliente #<?= (int) $c['id'] ?>
                    <?php if (!empty($c['cpf_cnpj'])): ?>
                        &middot; <?= View::e($c['cpf_cnpj']) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="page-header__actions">
            <a href="/clientes" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <?php if (!empty($waTel)): ?>
                <a href="https://wa.me/<?= $waTel ?>" target="_blank" class="btn btn-outline-success">
                    <i class="ph ph-whatsapp-logo me-1"></i> WhatsApp
                </a>
            <?php endif; ?>
            <a href="/clientes/<?= (int) $c['id'] ?>/editar" class="btn btn-primary">
                <i class="ph ph-pencil-simple me-1"></i> Editar
            </a>
            <form method="POST" action="/clientes/<?= (int) $c['id'] ?>/excluir"
                  onsubmit="return confirm('Tem certeza que deseja excluir este cliente? Esta acao nao pode ser desfeita.');"
                  class="d-inline-block">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-outline-danger">
                    <i class="ph ph-trash me-1"></i> Excluir
                </button>
            </form>
        </div>
    </div>

    <!-- Cards de informacao -->
    <div class="row g-3">

        <!-- Dados pessoais -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-user-circle"></i> Dados pessoais</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Nome</dt>
                        <dd class="col-12 fw-medium mb-3"><?= View::e($c['nome']) ?></dd>

                        <?php if (!empty($c['nome_fantasia'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Nome Fantasia</dt>
                        <dd class="col-12 mb-3"><?= View::e($c['nome_fantasia']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">CPF / CNPJ</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($c['cpf_cnpj'] ?: '—') ?></dd>

                        <?php if (!empty($c['rg_ie'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">RG / IE</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($c['rg_ie']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($c['data_nascimento'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Nascimento</dt>
                        <dd class="col-12 text-mono mb-3"><?= date('d/m/Y', strtotime($c['data_nascimento'])) ?></dd>
                        <?php endif; ?>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold border-top pt-3">Cadastrado em</dt>
                        <dd class="col-12 text-mono small text-body-secondary mb-0"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></dd>

                        <?php if (!empty($c['updated_at']) && $c['updated_at'] !== $c['created_at']): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold mt-2">Atualizado em</dt>
                        <dd class="col-12 text-mono small text-body-secondary mb-0"><?= date('d/m/Y H:i', strtotime($c['updated_at'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Contato -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-phone"></i> Contato</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Telefone</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($c['telefone'] ?: '—') ?></dd>

                        <?php if (!empty($c['telefone2'])): ?>
                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Telefone 2</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($c['telefone2']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Celular</dt>
                        <dd class="col-12 text-mono mb-3"><?= View::e($c['celular'] ?: '—') ?></dd>

                        <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">E-mail</dt>
                        <dd class="col-12 break-all mb-0">
                            <?php if (!empty($c['email'])): ?>
                                <a href="mailto:<?= View::e($c['email']) ?>"><?= View::e($c['email']) ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Endereco -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><i class="ph ph-map-pin"></i> Endereco</div>
                <div class="card-body">
                    <?php if ($temEndereco): ?>
                        <dl class="row mb-0 small">
                            <?php if (!empty($c['cep'])): ?>
                            <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">CEP</dt>
                            <dd class="col-12 text-mono mb-3"><?= View::e($c['cep']) ?></dd>
                            <?php endif; ?>
                            <dt class="col-12 text-body-secondary text-uppercase small fw-semibold">Endereco completo</dt>
                            <dd class="col-12 mb-0"><?= View::e($enderecoLinha) ?></dd>
                        </dl>
                    <?php else: ?>
                        <p class="text-body-secondary fst-italic text-center py-3 mb-0">Nenhum endereco cadastrado.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Observacoes -->
        <?php if (!empty($c['obs'])): ?>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header"><i class="ph ph-note"></i> Observacoes</div>
                <div class="card-body">
                    <p class="small mb-0 lh-lg" style="white-space:pre-wrap"><?= View::e($c['obs']) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Historico de OSs -->
    <div class="card shadow-sm">
        <div class="card-header">
            <i class="ph ph-wrench"></i>
            <span class="flex-grow-1">Ordens de Servico</span>
            <span class="badge bg-secondary"><?= count($ordens) ?></span>
            <a href="/os/nova" class="small fw-medium text-decoration-none ms-3">
                <i class="ph ph-plus"></i> Nova OS
            </a>
        </div>

        <?php if (empty($ordens)): ?>
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-folder-open"></i></div>
                <h3 class="empty-state__title">Nenhuma OS vinculada</h3>
                <p class="empty-state__desc">Este cliente ainda nao possui ordens de servico registradas.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>OS</th>
                            <th>Data Entrada</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Equip.</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ordens as $os):
                        $st = $os['status'] ?? 'aberta';
                        $badgeCls = match ($st) {
                            'aberta'     => 'status-badge--info',
                            'andamento'  => 'status-badge--warning',
                            'pronto'     => 'status-badge--success',
                            'retirado'   => 'status-badge--neutral',
                            'cancelado'  => 'status-badge--danger',
                            'descartado' => 'status-badge--warning',
                            default      => 'status-badge--neutral',
                        };
                    ?>
                        <tr class="cursor-pointer" onclick="window.location='/os/<?= rawurlencode($os['id']) ?>'">
                            <td class="text-mono fw-semibold text-nowrap">
                                #<?= View::e($os['id']) ?>
                            </td>
                            <td class="text-mono small text-body-secondary text-nowrap">
                                <?= View::e($os['data_entrada'] ?? '—') ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <span class="status-badge <?= $badgeCls ?>"><?= ucfirst(View::e($st)) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= (int) ($os['total_equipamentos'] ?? 0) ?></span>
                            </td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <a href="/os/<?= rawurlencode($os['id']) ?>" class="btn-icon" title="Ver OS">
                                    <i class="ph ph-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
