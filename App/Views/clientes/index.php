<?php
use App\Core\View;

/**
 * @var array $clientes
 * @var array $filtros
 * @var array $paginacao
 */
$q  = $filtros['busca'] ?? '';
$uf = $filtros['uf']    ?? '';
$pg = $paginacao ?? [];
$temFiltro = ($q !== '' || $uf !== '');

$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$clientesPagina = count($clientes);
$clientesComEmail = 0;
$clientesComCelular = 0;
$clientesComWhatsappContato = 0;
$ufsNaBusca = [];

$getDigits = static fn(?string $value): string => preg_replace('/\D+/', '', (string) $value) ?? '';
$getDocTipo = static function (?string $doc) use ($getDigits): string {
    $digits = $getDigits($doc);
    return match (strlen($digits)) {
        11 => 'PF',
        14 => 'PJ',
        default => 'Sem doc',
    };
};
$getInitials = static function (?string $nome): string {
    $parts = preg_split('/\s+/', trim((string) $nome)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'CL';
};

foreach ($clientes as $cliente) {
    if (!empty($cliente['email'])) {
        $clientesComEmail++;
    }
    if (!empty($cliente['celular'])) {
        $clientesComCelular++;
    }
    if (!empty($cliente['whatsapp']) || !empty($cliente['celular'])) {
        $clientesComWhatsappContato++;
    }
    if (!empty($cliente['uf'])) {
        $ufsNaBusca[mb_strtoupper((string) $cliente['uf'])] = true;
    }
}

$waBadge = static function (?string $whatsapp): string {
    $valor = trim((string) $whatsapp);
    if ($valor === '') {
        return '';
    }
    $label = str_contains($valor, '@g.us') ? 'Grupo' : 'WA';
    $class = str_contains($valor, '@g.us') ? 'text-bg-info' : 'text-bg-success';
    return '<span class="badge ' . $class . ' ms-1"><i class="ph ph-whatsapp-logo me-1"></i>' . $label . '</span>';
};
?>

<div class="clientes-page d-flex flex-column gap-4">

    <div class="page-header">
        <div>
            <h1 class="page-header__title">Clientes</h1>
            <p class="page-header__subtitle">
                <?= number_format($pg['total'] ?? 0, 0, ',', '.') ?> cliente(s) cadastrado(s)
                <?php if (($pg['total_pages'] ?? 1) > 1): ?>
                    &middot; pagina <?= (int) ($pg['page'] ?? 1) ?> de <?= (int) ($pg['total_pages'] ?? 1) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/clientes/configuracao-documentos" class="btn btn-outline-secondary">
                <i class="ph ph-gear me-1"></i> Configurar CPF/CNPJ
            </a>
            <a href="/clientes/novo" class="btn btn-primary">
                <i class="ph ph-user-plus me-1"></i> Novo Cliente
            </a>
        </div>
    </div>

    <section class="clientes-overview">
        <article class="clientes-kpi">
            <span class="clientes-kpi__label">Base total</span>
            <strong class="clientes-kpi__value"><?= number_format($pg['total'] ?? 0, 0, ',', '.') ?></strong>
            <span class="clientes-kpi__hint">Cadastros disponiveis no modulo.</span>
        </article>
        <article class="clientes-kpi">
            <span class="clientes-kpi__label">Nesta pagina</span>
            <strong class="clientes-kpi__value"><?= number_format($clientesPagina, 0, ',', '.') ?></strong>
            <span class="clientes-kpi__hint">Registros carregados com os filtros atuais.</span>
        </article>
        <article class="clientes-kpi">
            <span class="clientes-kpi__label">Com WhatsApp</span>
            <strong class="clientes-kpi__value"><?= number_format($clientesComWhatsappContato, 0, ',', '.') ?></strong>
            <span class="clientes-kpi__hint">Campo dedicado ou celular nesta pagina.</span>
        </article>
        <article class="clientes-kpi">
            <span class="clientes-kpi__label">UFs visiveis</span>
            <strong class="clientes-kpi__value"><?= number_format(count($ufsNaBusca), 0, ',', '.') ?></strong>
            <span class="clientes-kpi__hint"><?= $clientesComEmail ?> registro(s) desta pagina com e-mail.</span>
        </article>
    </section>

    <section class="card shadow-sm clientes-filter-card">
        <div class="card-body">
            <div class="clientes-filter-card__top">
                <div>
                    <span class="clientes-filter-card__eyebrow">Consulta rapida</span>
                    <h2 class="clientes-filter-card__title">Buscar e refinar clientes</h2>
                </div>
                <?php if ($temFiltro): ?>
                    <div class="clientes-filter-tags">
                        <?php if ($q !== ''): ?>
                            <span class="clientes-filter-tag">
                                <i class="ph ph-magnifying-glass"></i><?= View::e($q) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($uf !== ''): ?>
                            <span class="clientes-filter-tag">
                                <i class="ph ph-map-pin"></i><?= View::e($uf) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="GET" action="/clientes" class="row g-3 align-items-end">
                <div class="col-lg">
                    <label class="form-label">Buscar</label>
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" name="q" value="<?= View::e($q) ?>"
                               placeholder="Nome, CPF/CNPJ, telefone, e-mail ou cidade..." autofocus
                               class="form-control">
                    </div>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">UF</label>
                    <select name="uf" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($ufs as $u): ?>
                            <option value="<?= $u ?>" <?= $uf === $u ? 'selected' : '' ?>><?= $u ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2 page-filters__actions">
                    <button type="submit" class="btn btn-primary flex-grow-1 flex-lg-grow-0">
                        <i class="ph ph-magnifying-glass me-1"></i> Buscar
                    </button>
                    <?php if ($temFiltro): ?>
                        <a href="/clientes" class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0">
                            <i class="ph ph-x me-1"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <?php if (empty($clientes)): ?>
        <div class="card shadow-sm">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-users"></i></div>
                <h3 class="empty-state__title">Nenhum cliente encontrado</h3>
                <p class="empty-state__desc">
                    <?= $temFiltro ? 'Ajuste os filtros ou ' : 'Comece ' ?>
                    <a href="/clientes/novo">cadastrando um novo cliente</a>.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="d-md-none mobile-records clientes-mobile-list">
            <?php foreach ($clientes as $c): ?>
                <?php
                $docTipo = $getDocTipo($c['cpf_cnpj'] ?? null);
                $iniciais = $getInitials($c['nome'] ?? '');
                $cidadeUf = !empty($c['cidade'])
                    ? View::e($c['cidade']) . (!empty($c['uf']) ? ' / ' . View::e($c['uf']) : '')
                    : '—';
                ?>
                <div class="mobile-record-card clientes-mobile-card cursor-pointer" onclick="window.location='/clientes/<?= (int) $c['id'] ?>'">
                    <div class="mobile-record-card__top">
                        <div class="clientes-mobile-card__identity">
                            <span class="avatar avatar--sm clientes-avatar"><?= View::e($iniciais) ?></span>
                            <div>
                                <div class="mobile-record-card__title"><?= View::e($c['nome']) ?></div>
                                <div class="clientes-mobile-card__chips">
                                    <span class="clientes-chip"><?= View::e($docTipo) ?></span>
                                    <?php if (!empty($c['nome_fantasia'])): ?>
                                        <span class="mobile-record-card__subtitle"><?= View::e($c['nome_fantasia']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <i class="ph ph-caret-right mobile-record-card__arrow"></i>
                    </div>

                    <div class="mobile-record-card__body">
                        <div class="mobile-record-card__grid">
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">CPF / CNPJ</span>
                                <span class="mobile-record-card__value text-mono small"><?= View::e($c['cpf_cnpj'] ?: '—') ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Cidade / UF</span>
                                <span class="mobile-record-card__value small"><?= $cidadeUf ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">Telefone</span>
                                <span class="mobile-record-card__value text-mono small"><?= View::e($c['telefone'] ?: ($c['telefone2'] ?? '—')) ?></span>
                            </div>
                            <div class="mobile-record-card__row">
                                <span class="mobile-record-card__label">WhatsApp</span>
                                <span class="mobile-record-card__value text-mono small">
                                    <?= View::e(($c['whatsapp'] ?? '') ?: ($c['celular'] ?: '—')) ?>
                                    <?= $waBadge($c['whatsapp'] ?? '') ?>
                                </span>
                            </div>
                        </div>

                        <div class="mobile-record-card__section">
                            <span class="mobile-record-card__label">E-mail</span>
                            <span class="mobile-record-card__value small"><?= View::e($c['email'] ?: '—') ?></span>
                        </div>
                    </div>

                    <div class="mobile-record-card__footer">
                        <span class="mobile-record-card__hint">Toque para abrir o cadastro</span>
                        <div class="mobile-record-card__actions" onclick="event.stopPropagation()">
                            <a href="/clientes/<?= (int) $c['id'] ?>" class="btn-icon" title="Ver detalhes">
                                <i class="ph ph-eye"></i>
                            </a>
                            <a href="/clientes/<?= (int) $c['id'] ?>/editar" class="btn-icon" title="Editar">
                                <i class="ph ph-pencil-simple"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="card shadow-sm clientes-table-card d-none d-md-block">
            <div class="card-header clientes-table-card__header">
                <div>
                    <span class="clientes-table-card__eyebrow">Lista operacional</span>
                    <h2 class="clientes-table-card__title">Cadastros encontrados</h2>
                </div>
                <div class="clientes-table-card__summary">
                    <span><?= number_format($clientesPagina, 0, ',', '.') ?> registro(s) nesta pagina</span>
                    <span><?= number_format($clientesComEmail, 0, ',', '.') ?> com e-mail</span>
                </div>
            </div>

            <div class="data-table clientes-table-wrap border-0 rounded-0">
                <table class="table table-hover clientes-table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>CPF / CNPJ</th>
                            <th>Telefone</th>
                            <th>WhatsApp</th>
                            <th>E-mail</th>
                            <th>Cidade / UF</th>
                            <th class="text-end">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clientes as $c): ?>
                        <?php
                        $docTipo = $getDocTipo($c['cpf_cnpj'] ?? null);
                        $iniciais = $getInitials($c['nome'] ?? '');
                        ?>
                        <tr class="cursor-pointer" onclick="window.location='/clientes/<?= (int) $c['id'] ?>'">
                            <td>
                                <div class="clientes-row-main">
                                    <span class="avatar avatar--sm clientes-avatar"><?= View::e($iniciais) ?></span>
                                    <div class="clientes-row-main__content">
                                        <a href="/clientes/<?= (int) $c['id'] ?>" class="clientes-row-main__name text-decoration-none">
                                            <?= View::e($c['nome']) ?>
                                        </a>
                                        <div class="clientes-row-main__meta">
                                            <?php if (!empty($c['nome_fantasia'])): ?>
                                                <span><?= View::e($c['nome_fantasia']) ?></span>
                                            <?php else: ?>
                                                <span>Cadastro principal</span>
                                            <?php endif; ?>
                                            <span class="clientes-chip"><?= View::e($docTipo) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                <div class="clientes-contact">
                                    <span class="clientes-contact__label">Documento</span>
                                    <span class="text-mono small"><?= View::e($c['cpf_cnpj'] ?: '—') ?></span>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                <div class="clientes-contact">
                                    <span class="clientes-contact__label">Principal</span>
                                    <span class="text-mono small"><?= View::e($c['telefone'] ?: ($c['telefone2'] ?? '—')) ?></span>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                <div class="clientes-contact">
                                    <span class="clientes-contact__label">WhatsApp</span>
                                    <span class="text-mono small">
                                        <?= View::e(($c['whatsapp'] ?? '') ?: ($c['celular'] ?: '—')) ?>
                                        <?= $waBadge($c['whatsapp'] ?? '') ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="clientes-contact clientes-contact--wide">
                                    <span class="clientes-contact__label">Contato</span>
                                    <span class="small text-truncate"><?= View::e($c['email'] ?: '—') ?></span>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                <div class="clientes-contact">
                                    <span class="clientes-contact__label">Local</span>
                                    <span class="small">
                                        <?php if (!empty($c['cidade'])): ?>
                                            <?= View::e($c['cidade']) ?><?= !empty($c['uf']) ? ' / ' . View::e($c['uf']) : '' ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <div class="clientes-actions">
                                    <a href="/clientes/<?= (int) $c['id'] ?>" class="btn-icon" title="Ver detalhes">
                                        <i class="ph ph-eye"></i>
                                    </a>
                                    <a href="/clientes/<?= (int) $c['id'] ?>/editar" class="btn-icon" title="Editar">
                                        <i class="ph ph-pencil-simple"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (($pg['total_pages'] ?? 1) > 1): ?>
                    <?php
                    $p    = $pg['page'];
                    $tp   = $pg['total_pages'];
                    $qs   = http_build_query(array_filter(['q' => $q, 'uf' => $uf]));
                    $base = '/clientes' . ($qs ? "?{$qs}&" : '?');
                    ?>
                    <div class="data-table__footer">
                        <div>
                            Pagina <strong><?= $p ?></strong> de <strong><?= $tp ?></strong>
                            <span class="d-none d-sm-inline">&middot; <?= number_format($pg['total'] ?? 0, 0, ',', '.') ?> resultados</span>
                        </div>
                        <nav aria-label="Paginacao">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $base ?>p=<?= max(1, $p - 1) ?>" aria-label="Anterior">
                                        <i class="ph ph-caret-left"></i>
                                    </a>
                                </li>
                                <?php for ($i = max(1, $p - 2); $i <= min($tp, $p + 2); $i++): ?>
                                    <li class="page-item <?= $i === $p ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $base ?>p=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $p >= $tp ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $base ?>p=<?= min($tp, $p + 1) ?>" aria-label="Proxima">
                                        <i class="ph ph-caret-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
