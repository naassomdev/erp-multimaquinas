<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (\App\Core\Auth::check()): ?>
        <meta name="csrf-token" content="<?= \App\Core\View::e(\App\Core\Csrf::token()) ?>">
    <?php endif; ?>
    <title><?= \App\Core\View::e($titulo ?? 'ERP Multimaquinas') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <?= \App\Core\Vite::tags(['resources/js/app.js', 'resources/scss/app.scss']) ?>

    <?php $buscaGlobalCssVer = substr(md5_file(BASE_PATH . '/public/assets/css/busca-global.css'), 0, 8); ?>
    <link rel="stylesheet" href="/assets/css/busca-global.css?v=<?= $buscaGlobalCssVer ?>">

    <?php if (\App\Core\Auth::check() && in_array((string) (\App\Core\Auth::user()['nivel_acesso'] ?? ''), ['admin', 'recepcao', 'oficina'], true)): ?>
        <?php $notifCssVer = substr(md5_file(BASE_PATH . '/public/assets/css/tecnico-notif.css'), 0, 8); ?>
        <link rel="stylesheet" href="/assets/css/tecnico-notif.css?v=<?= $notifCssVer ?>">
    <?php endif; ?>

    <script>
        // Theme + sidebar — aplicados antes da pintura para evitar FOUC.
        (function() {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme:dark)').matches)) {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            }
            if (localStorage.getItem('erp:sidebar-collapsed') === '1') {
                document.body.classList.add('is-sidebar-collapsed');
            }
        })();
    </script>
</head>
<body>

<?php if (\App\Core\Auth::check()):
    $user       = \App\Core\Auth::user();
    $nivel      = $user['nivel_acesso'] ?? '';
    $activeMenu = $activeMenu ?? '';
    $tituloPage = $titulo ?? 'Dashboard';
    $pdvMenuVisible = false;

    /**
     * Helper para item de navegacao. Rotas inalteradas — apenas visual muda.
     */
    $navItem = function (string $href, string $iconClass, string $label, string $key, string $active, ?string $badge = null): string {
        $isActive  = ($active === $key);
        $classes   = 'app-sidebar__item' . ($isActive ? ' active' : '');
        $badgeHtml = ($badge !== null)
            ? '<span class="app-sidebar__badge">' . htmlspecialchars($badge) . '</span>'
            : '';
        return sprintf(
            '<a href="%s" class="%s" title="%s"><i class="%s"></i><span>%s</span>%s</a>',
            htmlspecialchars($href, ENT_QUOTES),
            $classes,
            htmlspecialchars($label, ENT_QUOTES),
            htmlspecialchars($iconClass, ENT_QUOTES),
            htmlspecialchars($label),
            $badgeHtml,
        );
    };

    // Contadores de alerta (badge do menu)
    $totalAlertas = 0;
    if (in_array($nivel, ['admin', 'recepcao'])) {
        try {
            $alertaCt     = (new \App\Services\AlertaRetiradaService())->contarAlertas();
            $totalAlertas = (int) ($alertaCt['total'] ?? 0);
        } catch (\Throwable) { /* silencioso */ }
    }

    if (in_array($nivel, ['admin', 'recepcao'], true)) {
        try {
            $pdvSettings = new \App\Services\Pdv\PdvSettingsService();
            $pdvMode = $pdvSettings->mode();
            $pdvMenuVisible = $pdvSettings->enabled() && (
                ($nivel === 'admin' && in_array($pdvMode, ['shadow', 'live'], true))
                || ($nivel === 'recepcao' && $pdvMode === 'live')
            );
        } catch (\Throwable) {
            $pdvMenuVisible = false;
        }
    }
?>

<div class="app-shell">

    <!-- ============================================================= -->
    <!-- SIDEBAR                                                       -->
    <!-- ============================================================= -->
    <aside class="app-sidebar" id="sidebar">
        <!-- Brand -->
        <div class="app-sidebar__brand">
            <div class="app-sidebar__logo">
                <i class="ph-bold ph-wrench"></i>
            </div>
            <span class="app-sidebar__brand-text">Multimaquinas</span>
        </div>

        <!-- Navegacao -->
        <nav class="app-sidebar__nav">
            <?= $navItem('/dashboard', 'ph ph-squares-four', 'Dashboard', 'dashboard', $activeMenu) ?>

            <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
                <div class="app-sidebar__section">Operacao</div>
                <?= $navItem('/clientes', 'ph ph-users',     'Clientes',          'clientes', $activeMenu) ?>
                <?= $navItem('/os',       'ph ph-file-text', 'Ordens de Servico', 'os',       $activeMenu) ?>
            <?php endif; ?>

            <div class="app-sidebar__section">Bancada</div>
            <?= $navItem('/tecnico',   'ph ph-wrench',      'Painel Tecnico', 'tecnico',   $activeMenu) ?>
            <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
            <?= $navItem('/orcamento', 'ph ph-currency-dollar', 'Orcamentos', 'orcamento', $activeMenu) ?>
            <?php endif; ?>

            <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
                <?= $navItem('/compras/necessidades', 'ph ph-shopping-cart-simple', 'Compras', 'compras', $activeMenu) ?>
            <?php endif; ?>

            <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
                <div class="app-sidebar__section">Relatorios</div>
                <?= $navItem('/relatorios/garantias-fabricante', 'ph ph-shield-check', 'Garantias Fab.',     'relatorios', $activeMenu) ?>
                <?= $navItem('/relatorios/saida-equipamentos',   'ph ph-wrench',        'Saída Equipamentos', 'relatorios', $activeMenu) ?>
                <?= $navItem('/relatorios/curva-abc',            'ph ph-chart-bar',     'Curva ABC',          'relatorios', $activeMenu) ?>
            <?php endif; ?>

            <?php if ($nivel === 'admin'): ?>
                <div class="app-sidebar__section">Administrativo</div>
                <?= $navItem('/estoque',           'ph ph-package',    'Estoque',     'estoque',    $activeMenu) ?>
                <?= $navItem('/financeiro',        'ph ph-chart-line', 'Financeiro',  'financeiro', $activeMenu) ?>
                <?= $navItem('/nfse',              'ph ph-file-text',  'NFS-e',       'nfse',       $activeMenu) ?>
                <?= $navItem('/admin/mao-de-obra', 'ph ph-table',      'Tabela M.O.', 'admin_mo',   $activeMenu) ?>
                <?= $navItem('/admin/empresa',     'ph ph-buildings',  'Dados da Empresa', 'admin_empresa',  $activeMenu) ?>
                <?= $navItem('/admin/email',       'ph ph-envelope',   'Config. E-mail',   'admin_email',    $activeMenu) ?>
                <?= $navItem('/admin/usuarios',         'ph ph-users-three', 'Usuários',          'admin_usuarios',   $activeMenu) ?>
                <?= $navItem('/admin/clientes/duplicados', 'ph ph-copy',   'Clientes Duplicados', 'admin_duplicados', $activeMenu) ?>
            <?php endif; ?>

            <?php if ($pdvMenuVisible): ?>
                <div class="app-sidebar__section">PDV</div>
                <?= $navItem('/pdv', 'ph ph-cash-register', 'PDV', 'pdv', $activeMenu) ?>
                <?php if ($nivel === 'admin'): ?>
                    <?= $navItem('/pdv/vendas', 'ph ph-list-bullets', 'Vendas PDV', 'pdv_vendas', $activeMenu) ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($nivel, ['admin', 'recepcao'])): ?>
                <div class="app-sidebar__section">Atencao</div>
                <?= $navItem(
                    '/alertas/retirada', 'ph ph-warning', 'Alertas Retirada',
                    'alertas', $activeMenu,
                    $totalAlertas > 0 ? (string) $totalAlertas : null
                ) ?>
            <?php endif; ?>
        </nav>

        <!-- Logout -->
        <div class="app-sidebar__footer">
            <a href="/logout" class="app-sidebar__item" title="Sair">
                <i class="ph ph-sign-out"></i>
                <span>Sair</span>
            </a>
        </div>
    </aside>

    <!-- Overlay (mobile) -->
    <div class="app-sidebar-overlay"></div>

    <!-- ============================================================= -->
    <!-- CONTAINER (topbar + main)                                     -->
    <!-- ============================================================= -->
    <div class="app-container">

        <!-- Topbar -->
        <header class="app-topbar">
            <div class="app-topbar__left">
                <button class="app-topbar__toggle" data-toggle-sidebar aria-label="Alternar menu lateral">
                    <i class="ph ph-list"></i>
                </button>

                <!-- Breadcrumb -->
                <nav class="app-topbar__breadcrumb" aria-label="Breadcrumb">
                    <span>Sistema</span>
                    <span class="sep"><i class="ph ph-caret-right"></i></span>
                    <span class="current"><?= \App\Core\View::e($tituloPage) ?></span>
                </nav>
            </div>

            <div class="app-topbar__right">
                <!-- Busca global (desktop) -->
                <div class="app-topbar__search">
                    <i class="ph ph-magnifying-glass"></i>
                    <input type="search" id="busca-global" autocomplete="off" placeholder="Buscar OS, cliente, equipamento, série...">
                </div>

                <?php if (in_array((string) $nivel, ['admin', 'recepcao', 'oficina'], true)): ?>
                <!-- Sino de notificações do técnico -->
                <div class="notif-wrapper">
                    <button id="notif-bell-btn" class="app-topbar__toggle" title="Notificações" aria-label="Notificações">
                        <i class="ph ph-bell"></i>
                        <span id="notif-bell-badge" class="notif-bell-badge" hidden>0</span>
                    </button>
                </div>

                <!-- Gaveta Lateral (Offcanvas) de Notificações -->
                <div id="notif-drawer" class="notif-drawer" hidden>
                    <div class="notif-drawer__backdrop" id="notif-drawer-backdrop"></div>
                    <div class="notif-drawer__content">
                        <div class="notif-drawer__header">
                            <h5 class="notif-drawer__title">Notificações</h5>
                            <button id="notif-drawer-close" class="notif-drawer__close" aria-label="Fechar gaveta">
                                <i class="ph ph-x"></i>
                            </button>
                        </div>
                        <div class="notif-drawer__actions" id="notif-drawer-actions" style="display: none;">
                            <button id="notif-mark-all-read" class="btn-mark-all">
                                <i class="ph ph-checks"></i> Marcar todas como lidas
                            </button>
                        </div>
                        <ul id="notif-list" class="notif-drawer__list">
                            <li class="notif-empty">Carregando...</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Theme toggle -->
                <button class="app-topbar__toggle" id="themeToggle" aria-label="Alternar tema">
                    <i class="ph ph-sun"  id="themeIconLight"></i>
                    <i class="ph ph-moon" id="themeIconDark"></i>
                </button>

                <!-- Perfil -->
                <div class="d-flex align-items-center gap-2 ps-2 ps-sm-3 border-start">
                    <div class="app-topbar__user-info">
                        <strong><?= \App\Core\View::e($user['nome'] ?? '') ?></strong>
                        <small><?= \App\Core\View::e(ucfirst($nivel)) ?></small>
                    </div>
                    <div class="app-topbar__avatar">
                        <?= mb_strtoupper(mb_substr($user['nome'] ?? 'U', 0, 1)) ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Conteudo principal -->
        <main class="app-main">
            <!-- Flash messages -->
            <?php
            $flashSuccess = \App\Core\Flash::get('success');
            $flashError   = \App\Core\Flash::get('error');
            $flashInfo    = \App\Core\Flash::get('info');
            ?>
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show alert-dismiss-auto d-flex align-items-center mb-3" role="alert">
                    <i class="ph ph-check-circle me-2 fs-5"></i>
                    <span><?= \App\Core\View::e($flashSuccess) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show alert-dismiss-auto d-flex align-items-center mb-3" role="alert">
                    <i class="ph ph-x-circle me-2 fs-5"></i>
                    <span><?= \App\Core\View::e($flashError) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>
            <?php if ($flashInfo): ?>
                <div class="alert alert-info alert-dismissible fade show alert-dismiss-auto d-flex align-items-center mb-3" role="alert">
                    <i class="ph ph-info me-2 fs-5"></i>
                    <span><?= \App\Core\View::e($flashInfo) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>

        <!-- Footer -->
        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> Multimaquinas &mdash; ERP</span>
            <span class="d-none d-md-inline">v2.0</span>
        </footer>
    </div>
</div>

    <?php if (in_array((string) $nivel, ['admin', 'recepcao', 'oficina'], true)): ?>
        <?php $notifJsVer = substr(md5_file(BASE_PATH . '/public/assets/js/tecnico-notif.js'), 0, 8); ?>
        <script src="/assets/js/tecnico-notif.js?v=<?= $notifJsVer ?>" defer></script>
    <?php endif; ?>

    <?php $buscaGlobalJsVer = substr(md5_file(BASE_PATH . '/public/assets/js/busca-global.js'), 0, 8); ?>
    <script src="/assets/js/busca-global.js?v=<?= $buscaGlobalJsVer ?>" defer></script>

<?php else: ?>
    <!-- Login (sem chrome) -->
    <main class="d-flex align-items-center justify-content-center min-vh-100 bg-body-tertiary p-3">
        <?= $content ?>
    </main>
<?php endif; ?>

</body>
</html>
