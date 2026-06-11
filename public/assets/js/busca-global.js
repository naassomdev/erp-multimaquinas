/* ──────────────────────────────────────────────────────────────────────────
 * Busca global do topo (header)
 * Localiza uma OS por: ID da OS, nome/telefone do cliente ou dados do
 * equipamento (série, nome, fabricante, modelo).
 * Endpoint: GET /api/os/busca?q=...  → { ok, ordens: [...] }
 * Clicar (ou Enter sobre um item) abre /os/{id}.
 * Mesmo padrão de UX do autocomplete de cliente (os-form.js):
 * debounce 350ms + AbortController + cache + navegação por teclado.
 * ────────────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('busca-global');
    if (!input) return;

    const wrap = input.closest('.app-topbar__search');
    if (!wrap) return;

    // Dropdown de resultados (injetado uma única vez)
    const dd = document.createElement('div');
    dd.className = 'busca-global__dropdown';
    dd.style.display = 'none';
    wrap.appendChild(dd);

    let debounceTimer = null;
    let abortCtrl     = null;
    let activeIndex   = -1;
    let results       = [];
    const cache       = new Map();

    const STATUS_LABEL = {
        aberta:     'Aberta',
        andamento:  'Em andamento',
        montagem:   'Montagem',
        pronto:     'Pronto',
        retirado:   'Retirado',
        devolvido:  'Devolvido',
        descartado: 'Descartado',
        cancelado:  'Cancelado',
    };

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, m => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
        ));
    }

    function close() {
        activeIndex = -1;
        dd.style.display = 'none';
        dd.innerHTML = '';
    }

    function open() {
        if (dd.innerHTML !== '') dd.style.display = 'block';
    }

    function render(list) {
        results = list;
        activeIndex = -1;

        if (!list.length) {
            dd.innerHTML = '<div class="busca-global__empty">Nenhuma OS encontrada.</div>';
            dd.style.display = 'block';
            return;
        }

        dd.innerHTML = '';
        list.forEach((o, i) => {
            const item = document.createElement('a');
            item.className = 'busca-global__item';
            item.href = '/os/' + encodeURIComponent(o.id);
            item.setAttribute('data-index', i);

            const status = (o.status || '').toLowerCase();
            const statusLabel = STATUS_LABEL[status] || (o.status || '');
            const equip = o.equipamentos ? escapeHtml(o.equipamentos) : 'sem equipamento';
            const serie = o.series ? ` · sér. ${escapeHtml(o.series)}` : '';

            item.innerHTML =
                `<div class="busca-global__row1">` +
                    `<strong>OS ${escapeHtml(o.id)}</strong>` +
                    `<span class="busca-global__status busca-global__status--${escapeHtml(status)}">${escapeHtml(statusLabel)}</span>` +
                `</div>` +
                `<div class="busca-global__cliente">${escapeHtml(o.nome_cliente || 'Sem cliente')}</div>` +
                `<div class="busca-global__equip">${equip}${serie}</div>`;

            dd.appendChild(item);
        });
        dd.style.display = 'block';
    }

    function highlight(items) {
        items.forEach((el, i) => {
            el.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) el.scrollIntoView({ block: 'nearest' });
        });
    }

    function go(o) {
        if (o && o.id) window.location.href = '/os/' + encodeURIComponent(o.id);
    }

    async function buscar(termo) {
        if (cache.has(termo)) { render(cache.get(termo)); return; }

        if (abortCtrl) abortCtrl.abort();
        abortCtrl = new AbortController();

        try {
            const res = await fetch('/api/os/busca?q=' + encodeURIComponent(termo), {
                signal: abortCtrl.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            const list = (json.ok && Array.isArray(json.ordens)) ? json.ordens : [];
            if (cache.size > 50) cache.clear();
            cache.set(termo, list);
            render(list);
        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error('[busca-global] falha:', err);
        }
    }

    // ── Eventos ──────────────────────────────────────────────────────────
    input.addEventListener('input', () => {
        const termo = input.value.trim();
        clearTimeout(debounceTimer);
        if (termo.length < 2) { close(); return; }
        debounceTimer = setTimeout(() => buscar(termo), 350);
    });

    input.addEventListener('keydown', (e) => {
        const items = dd.querySelectorAll('.busca-global__item');
        if (e.key === 'ArrowDown' && items.length) {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            highlight(items);
        } else if (e.key === 'ArrowUp' && items.length) {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlight(items);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && results[activeIndex]) {
                e.preventDefault();
                go(results[activeIndex]);
            } else if (results.length === 1) {
                e.preventDefault();
                go(results[0]);
            }
        } else if (e.key === 'Escape') {
            close();
            input.blur();
        }
    });

    input.addEventListener('focus', open);

    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) close();
    });
});
