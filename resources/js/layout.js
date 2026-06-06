/**
 * Layout — sidebar (toggle/colapsar) + topbar + theme + atalhos globais.
 *
 * Estado persistido em localStorage:
 *   erp:sidebar-collapsed: '1' | '0'   (apenas desktop)
 *   theme:                 'dark' | 'light'
 */
const LS_COLLAPSED = 'erp:sidebar-collapsed';

export function initLayout() {
    applyInitialState();
    bindToggleSidebar();
    bindCloseSidebarOnLinkMobile();
    bindThemeToggle();
    bindAutoDismissAlerts();
}

// ── Estado inicial ──────────────────────────────────────────────────
function applyInitialState() {
    if (localStorage.getItem(LS_COLLAPSED) === '1') {
        document.body.classList.add('is-sidebar-collapsed');
    }
}

// ── Sidebar: abrir/fechar/colapsar ──────────────────────────────────
function bindToggleSidebar() {
    const btn = document.querySelector('[data-toggle-sidebar]');
    const overlay = document.querySelector('.app-sidebar-overlay');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const isMobile = window.matchMedia('(max-width: 991.98px)').matches;
        if (isMobile) {
            document.body.classList.toggle('is-sidebar-open');
        } else {
            document.body.classList.toggle('is-sidebar-collapsed');
            const collapsed = document.body.classList.contains('is-sidebar-collapsed');
            localStorage.setItem(LS_COLLAPSED, collapsed ? '1' : '0');
        }
    });

    overlay?.addEventListener('click', () => {
        document.body.classList.remove('is-sidebar-open');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.body.classList.remove('is-sidebar-open');
        }
    });
}

// ── Sidebar: fechar ao clicar em link (mobile) ──────────────────────
function bindCloseSidebarOnLinkMobile() {
    document.querySelectorAll('.app-sidebar a').forEach((a) => {
        a.addEventListener('click', () => {
            if (window.matchMedia('(max-width: 991.98px)').matches) {
                document.body.classList.remove('is-sidebar-open');
            }
        });
    });
}

// ── Theme toggle (dark/light via data-bs-theme) ─────────────────────
function bindThemeToggle() {
    const btn       = document.getElementById('themeToggle');
    const iconLight = document.getElementById('themeIconLight');
    const iconDark  = document.getElementById('themeIconDark');
    if (!btn) return;

    function sync() {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        if (iconLight) iconLight.style.display = isDark ? 'inline' : 'none';
        if (iconDark)  iconDark.style.display  = isDark ? 'none'   : 'inline';
    }
    sync();

    btn.addEventListener('click', () => {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const next   = isDark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
        sync();
    });
}

// ── Flash alerts: auto-dismiss ──────────────────────────────────────
function bindAutoDismissAlerts() {
    document.querySelectorAll('.alert-dismiss-auto').forEach((el) => {
        setTimeout(() => {
            el.style.transition = 'opacity .3s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 300);
        }, 5000);
    });
}
