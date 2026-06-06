document.addEventListener('DOMContentLoaded', () => {

    // ══════════════════════════════════════════════════════════════════════
    // AUTOCOMPLETE DE CLIENTE — Debounce + AbortController
    //
    // Arquitetura:
    //  1. Debounce (350ms): o timer é reiniciado a cada tecla. O fetch só
    //     dispara quando o usuário PARA de digitar por 350ms.
    //  2. AbortController: cada novo fetch cancela o anterior em voo,
    //     eliminando race conditions de respostas fora de ordem.
    //  3. Cache local (Map): evita repetir requests para o mesmo termo.
    //  4. Navegação por teclado: ↑↓ no dropdown + Enter para selecionar.
    // ══════════════════════════════════════════════════════════════════════

    const DEBOUNCE_NOME = 350;     // ms — espera o usuário parar de digitar
    const DEBOUNCE_TEL_DOC = 400;  // ms — tel/cpf são mais curtos, delay ok
    const MIN_CHARS_NOME = 2;      // mínimo de chars para disparar busca por nome
    const MIN_DIGITS_TEL = 4;      // mínimo de dígitos para buscar por telefone/CPF

    const clienteIdInput   = document.getElementById('cliente_id');
    const nomeInput        = document.getElementById('nome_cliente');
    const telInput         = document.getElementById('telefone');
    const docInput         = document.getElementById('doc_cliente');
    const statusBox        = document.getElementById('cliente_status');
    // 10F-2: campos de contato responsável
    const contatoNomeInput = document.getElementById('contato_nome');
    const contatoTelInput  = document.getElementById('contato_telefone');

    const dropdowns = {
        nome:     document.getElementById('ac_nome'),
        telefone: document.getElementById('ac_telefone'),
        doc:      document.getElementById('ac_doc'),
    };

    // ── Estado interno ──────────────────────────────────────────────────
    let debounceTimer = null;       // Timer do debounce
    let abortCtrl     = null;       // AbortController do fetch em andamento
    let activeIndex   = -1;         // Índice do item selecionado no dropdown
    const cache       = new Map();  // cache: termo → resultados

    // ── Helpers ─────────────────────────────────────────────────────────
    function digitsOnly(v) { return (v || '').replace(/\D/g, ''); }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function showStatus(html) {
        if (!statusBox) return;
        statusBox.style.display = 'block';
        statusBox.innerHTML = html;
    }
    function hideStatus() {
        if (!statusBox) return;
        statusBox.style.display = 'none';
        statusBox.innerHTML = '';
    }

    // ── Preencher campos quando um cliente é selecionado ─────────────────
    function fillFromCliente(c, source) {
        clienteIdInput.value = c.id;

        // Nome: sempre preenche (o usuário digitou parcial para buscar)
        if (nomeInput) nomeInput.value = c.nome || '';

        // Telefone e CPF: preenche sempre se a busca foi pelo nome,
        // senão só preenche campos vazios (não sobrescreve o que o
        // usuário já digitou intencionalmente em outro campo).
        if (source === 'nome') {
            if (telInput) telInput.value = c.telefone || c.celular || '';
            if (docInput) docInput.value = c.cpf_cnpj || '';
        } else {
            const fillIfEmpty = (input, val) => {
                if (input && (input.value || '').trim() === '' && val) input.value = val;
            };
            fillIfEmpty(nomeInput, c.nome);
            fillIfEmpty(telInput,  c.telefone || c.celular || '');
            fillIfEmpty(docInput,  c.cpf_cnpj || '');
        }

        // 10F-2: sugerir celular no campo contato_telefone quando
        // o cliente é empresa (tem nome_fantasia) e o campo está vazio.
        // O celular costuma ser o WhatsApp do funcionário de contato.
        if (c.nome_fantasia && c.celular && contatoTelInput && (contatoTelInput.value || '').trim() === '') {
            contatoTelInput.value = c.celular;
        }

        const displayNome = c.nome_fantasia
            ? `${escapeHtml(c.nome_fantasia)} <small class="text-body-secondary">(${escapeHtml(c.nome)})</small>`
            : `<strong>${escapeHtml(c.nome)}</strong>`;
        showStatus(
            `<div class="alert alert-success">
                ✅ Cliente vinculado: ${displayNome}
                ${c.cidade ? `· ${escapeHtml(c.cidade)}/${escapeHtml(c.uf || '')}` : ''}
                <button type="button" id="btnDesvincular">desvincular</button>
            </div>`
        );
        document.getElementById('btnDesvincular')?.addEventListener('click', () => {
            clienteIdInput.value = '';
            hideStatus();
        });
        closeAllDropdowns();
    }

    // ── Dropdowns ───────────────────────────────────────────────────────
    function closeAllDropdowns() {
        activeIndex = -1;
        Object.values(dropdowns).forEach(d => {
            if (d) { d.style.display = 'none'; d.innerHTML = ''; }
        });
    }

    function renderDropdown(targetField, results) {
        const dd = dropdowns[targetField];
        if (!dd) return;
        activeIndex = -1;

        if (results.length === 0) {
            dd.innerHTML = '<div class="ac-empty">Nenhum cliente encontrado — será cadastrado automaticamente.</div>';
            dd.style.display = 'block';
            return;
        }

        dd.innerHTML = '';
        results.forEach((c, i) => {
            const item = document.createElement('div');
            item.className = 'ac-item';
            item.setAttribute('data-index', i);

            const tel  = c.telefone || c.celular || '';
            const meta = [tel, c.cpf_cnpj, c.cidade].filter(Boolean).join(' · ');
            // 10F-2: exibe nome_fantasia quando disponível
            const nomeLabel = c.nome_fantasia
                ? `<strong>${escapeHtml(c.nome_fantasia)}</strong> <span class="text-body-secondary small">${escapeHtml(c.nome)}</span>`
                : `<strong>${escapeHtml(c.nome)}</strong>`;
            item.innerHTML = `${nomeLabel}<span class="ac-meta">${escapeHtml(meta || 'sem dados adicionais')}</span>`;

            // mousedown (não click) para evitar que o blur feche o dropdown antes
            item.addEventListener('mousedown', (ev) => {
                ev.preventDefault();
                fillFromCliente(c, targetField);
            });
            dd.appendChild(item);
        });
        dd.style.display = 'block';

        // Guarda referência dos resultados para navegação por teclado
        dd._results = results;
    }

    // ── Navegação por teclado (↑ ↓ Enter Esc) ──────────────────────────
    function handleKeyNav(e, targetField) {
        const dd = dropdowns[targetField];
        if (!dd || dd.style.display === 'none') return;

        const items = dd.querySelectorAll('.ac-item');
        if (items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            highlightItem(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlightItem(items);
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            const results = dd._results || [];
            if (results[activeIndex]) {
                fillFromCliente(results[activeIndex], targetField);
            }
        } else if (e.key === 'Escape') {
            closeAllDropdowns();
        }
    }

    function highlightItem(items) {
        items.forEach((el, i) => {
            el.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // DEBOUNCE + FETCH COM ABORT CONTROLLER
    //
    // Fluxo: Tecla → clearTimeout → setTimeout(350ms) → abortCtrl.abort()
    //        → novo AbortController → fetch(signal) → renderDropdown
    //
    // Se o usuário digita "João":
    //   J → timer reset → (350ms sem tecla? NÃO, digitou 'o')
    //   Jo → timer reset → (350ms sem tecla? NÃO, digitou 'ã')
    //   Joã → timer reset → (350ms sem tecla? NÃO, digitou 'o')
    //   João → timer reset → (350ms sem tecla? SIM!) → fetch("João")
    //
    // Resultado: 1 único request ao invés de 4.
    // ══════════════════════════════════════════════════════════════════════

    function debounceSearch(termo, targetField, delay) {
        // 1. Cancela o timer anterior (debounce)
        clearTimeout(debounceTimer);

        // 2. Agenda nova execução após `delay` ms de silêncio
        debounceTimer = setTimeout(() => {
            buscarClientes(termo, targetField);
        }, delay);
    }

    async function buscarClientes(termo, targetField) {
        // Cache hit? Usa resultado anterior sem ir ao servidor
        const cacheKey = `${targetField}:${termo}`;
        if (cache.has(cacheKey)) {
            processarResultados(cache.get(cacheKey), termo, targetField);
            return;
        }

        // Cancela qualquer fetch anterior em voo (evita race condition)
        if (abortCtrl) abortCtrl.abort();
        abortCtrl = new AbortController();

        try {
            const res = await fetch(
                `/api/clientes/busca?q=${encodeURIComponent(termo)}`,
                { signal: abortCtrl.signal }
            );
            const json = await res.json();
            const list = (json.ok && Array.isArray(json.clientes)) ? json.clientes : [];

            // Salva no cache (máx 50 entradas para não vazar memória)
            if (cache.size > 50) cache.clear();
            cache.set(cacheKey, list);

            processarResultados(list, termo, targetField);

        } catch (err) {
            // AbortError é esperado (cancelamos o fetch anterior) — ignora
            if (err.name === 'AbortError') return;
            console.error('[Autocomplete] falha na busca:', err);
        }
    }

    function processarResultados(list, termo, targetField) {
        // Preenchimento automático silencioso para telefone/CPF:
        // Se há 1 resultado e o termo numérico bate, preenche direto.
        if (list.length === 1 && (targetField === 'telefone' || targetField === 'doc')) {
            const c = list[0];
            const left  = digitsOnly(termo);
            const right = digitsOnly(
                targetField === 'telefone'
                    ? (c.telefone || c.celular || '')
                    : c.cpf_cnpj
            );
            if (left && right && right.includes(left)) {
                fillFromCliente(c, targetField);
                return;
            }
        }
        renderDropdown(targetField, list);
    }


    // ══════════════════════════════════════════════════════════════════════
    // EVENT LISTENERS
    // ══════════════════════════════════════════════════════════════════════

    // ── Telefone & CPF/CNPJ: dispara após 4+ dígitos ────────────────────
    [['telefone', telInput], ['doc', docInput]].forEach(([field, input]) => {
        if (!input) return;
        input.addEventListener('input', () => {
            if (clienteIdInput.value) { clienteIdInput.value = ''; hideStatus(); }
            const dig = digitsOnly(input.value);
            if (dig.length >= MIN_DIGITS_TEL) {
                debounceSearch(dig, field, DEBOUNCE_TEL_DOC);
            } else {
                closeAllDropdowns();
            }
        });
        input.addEventListener('focus', () => {
            const dig = digitsOnly(input.value);
            if (dig.length >= MIN_DIGITS_TEL && !clienteIdInput.value) {
                debounceSearch(dig, field, 100);
            }
        });
        input.addEventListener('blur', () => {
            setTimeout(() => { dropdowns[field] && (dropdowns[field].style.display = 'none'); }, 200);
        });
        input.addEventListener('keydown', (e) => handleKeyNav(e, field));
    });

    // ── Nome do Cliente: dispara a partir de 2+ chars ────────────────────
    if (nomeInput) {
        nomeInput.addEventListener('input', () => {
            if (clienteIdInput.value) { clienteIdInput.value = ''; hideStatus(); }
            const v = (nomeInput.value || '').trim();
            if (v.length >= MIN_CHARS_NOME) {
                debounceSearch(v, 'nome', DEBOUNCE_NOME);
            } else {
                closeAllDropdowns();
            }
        });
        nomeInput.addEventListener('blur', () => {
            const v = (nomeInput.value || '').trim();
            if (v.length >= MIN_CHARS_NOME && !clienteIdInput.value) {
                debounceSearch(v, 'nome', 50);
            }
            setTimeout(() => { dropdowns.nome && (dropdowns.nome.style.display = 'none'); }, 200);
        });
        nomeInput.addEventListener('focus', () => {
            const v = (nomeInput.value || '').trim();
            if (v.length >= MIN_CHARS_NOME && !clienteIdInput.value) {
                debounceSearch(v, 'nome', 100);
            }
        });
        nomeInput.addEventListener('keydown', (e) => handleKeyNav(e, 'nome'));
    }

    // ── Fechar dropdowns ao clicar fora ──────────────────────────────────
    document.addEventListener('click', (e) => {
        Object.entries(dropdowns).forEach(([field, dd]) => {
            if (!dd) return;
            const input = ({ nome: nomeInput, telefone: telInput, doc: docInput })[field];
            if (e.target !== input && !dd.contains(e.target)) dd.style.display = 'none';
        });
    });

    // ── Status inicial (modo editar — já vem com cliente_id) ─────────────
    if (clienteIdInput && clienteIdInput.value && (nomeInput?.value || '').trim() !== '') {
        showStatus(
            `<div class="alert alert-info">
                ℹ️ Cliente vinculado ao ID #${escapeHtml(clienteIdInput.value)}: <strong>${escapeHtml(nomeInput.value)}</strong>
                <button type="button" id="btnDesvincular">desvincular</button>
            </div>`
        );
        document.getElementById('btnDesvincular')?.addEventListener('click', () => {
            clienteIdInput.value = '';
            hideStatus();
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    // EQUIPAMENTOS (template dinâmico — adicionar/remover)
    // ══════════════════════════════════════════════════════════════════════
    const container = document.getElementById('equipamentos-container');
    const btnAdd    = document.getElementById('btnAdicionarEquip');
    const template  = document.getElementById('equip-template');

    function getNextIndex() {
        const cards = container.querySelectorAll('.equip-card');
        let max = -1;
        cards.forEach(c => {
            const idx = parseInt(c.getAttribute('data-index'), 10);
            if (idx > max) max = idx;
        });
        return max + 1;
    }

    function initGarantiaToggles(rootElement) {
        rootElement.querySelectorAll('.chk-garantia').forEach(chk => {
            chk.addEventListener('change', (e) => {
                const wrap = e.target.closest('.equip-card').querySelector('.tipo-garantia-wrap');
                if (wrap) wrap.style.display = e.target.checked ? 'flex' : 'none';
            });
        });
    }

    if (btnAdd && container && template) {
        btnAdd.addEventListener('click', () => {
            const idx = getNextIndex();
            const html = template.innerHTML.replace(/{IDX}/g, idx);
            container.insertAdjacentHTML('beforeend', html);
            initGarantiaToggles(container.lastElementChild);
        });

        container.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-remover-equip');
            if (btn) {
                const cards = container.querySelectorAll('.equip-card');
                if (cards.length > 1) {
                    btn.closest('.equip-card').remove();
                } else {
                    alert('A OS deve ter pelo menos um equipamento.');
                }
            }
        });

        initGarantiaToggles(container);
    }


    // ══════════════════════════════════════════════════════════════════════
    // FOTOS DA RECEPÇÃO (preview + remoção via DataTransfer)
    // ══════════════════════════════════════════════════════════════════════
    const fotoInput   = document.getElementById('fotos_recepcao');
    const fotoPreview = document.getElementById('fotos_preview');
    const fotoHint    = document.getElementById('fotos_hint');

    if (fotoInput && fotoPreview) {
        let fotosBuffer = []; // File[]

        function renderPreview() {
            // Limpa apenas os previews novos (mantém .existing do modo editar)
            fotoPreview.querySelectorAll('.foto-thumb:not(.existing)').forEach(el => el.remove());

            fotosBuffer.forEach((file, idx) => {
                const url = URL.createObjectURL(file);
                const wrap = document.createElement('div');
                wrap.className = 'foto-thumb';
                wrap.innerHTML = `
                    <img src="${url}" alt="Foto ${idx + 1}">
                    <button type="button" class="foto-remove" data-idx="${idx}" title="Remover">✕</button>
                    <small>${(file.size / 1024).toFixed(0)} KB</small>
                `;
                fotoPreview.appendChild(wrap);
            });

            const total = fotoPreview.querySelectorAll('.foto-thumb').length;
            fotoPreview.style.display = total > 0 ? 'grid' : 'none';
            if (fotoHint) fotoHint.style.display = total > 0 ? 'none' : 'block';

            syncInputFiles();
        }

        function syncInputFiles() {
            // Re-popula o FileList do input com o buffer atual (DataTransfer trick).
            const dt = new DataTransfer();
            fotosBuffer.forEach(f => dt.items.add(f));
            fotoInput.files = dt.files;
        }

        fotoInput.addEventListener('change', () => {
            const novos = Array.from(fotoInput.files || []);
            // Filtra duplicatas (nome+size+lastModified como chave fraca)
            const chave = f => `${f.name}|${f.size}|${f.lastModified}`;
            const existentes = new Set(fotosBuffer.map(chave));
            novos.forEach(f => {
                if (!existentes.has(chave(f))) fotosBuffer.push(f);
            });
            renderPreview();
        });

        fotoPreview.addEventListener('click', (e) => {
            const btn = e.target.closest('.foto-remove');
            if (!btn) return;
            const idx = parseInt(btn.dataset.idx, 10);
            if (Number.isInteger(idx)) {
                fotosBuffer.splice(idx, 1);
                renderPreview();
            }
        });
    }


    // ══════════════════════════════════════════════════════════════════════
    // CAIXA ALTA — todos os inputs de texto e textareas do formulário
    // (exclui type=tel/email/number automaticamente pela seleção por tipo)
    // ══════════════════════════════════════════════════════════════════════
    const osForm = document.getElementById('osForm');
    if (osForm) {
        osForm.addEventListener('input', (e) => {
            const el = e.target;
            if (el.matches('input[type=text], textarea')) {
                const pos = el.selectionStart;
                el.value = el.value.toUpperCase();
                el.setSelectionRange(pos, pos);
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // UX: feedback visual no submit (upload pode demorar no mobile)
    // ══════════════════════════════════════════════════════════════════════
    const form = document.getElementById('osForm');
    const btnSubmit = document.getElementById('btnSubmitOs');
    if (form && btnSubmit) {
        form.addEventListener('submit', () => {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '⏳ Enviando…';
        });
    }
});
