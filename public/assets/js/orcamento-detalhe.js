(() => {
    'use strict';

    const wrap = document.querySelector('[data-os-id]');
    if (!wrap) return;

    const cards = Array.from(document.querySelectorAll('[data-role=equip-panel]'));
    if (cards.length === 0) return;

    const navButtons = Array.from(document.querySelectorAll('[data-role=equip-nav]'));
    const cardById = new Map(cards.map(card => [card.id, card]));
    const navById = new Map(navButtons.map(btn => [btn.dataset.target, btn]));
    const statusMap = {
        rascunho: 'neutral',
        enviado: 'info',
        aprovado: 'success',
        cancelado: 'danger',
        pronto: 'success',
        retirado: 'neutral',
    };

    let activeCard = null;

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const CSRF = csrfMeta ? csrfMeta.content : '';
    const whatsappPreviewModalEl = document.getElementById('modalWhatsappPreview');
    const whatsappPreviewField = document.getElementById('whatsappPreviewMensagem');
    const whatsappPreviewConfirmBtn = document.getElementById('btnConfirmarWhatsappPreview');
    const whatsappPreviewModal = whatsappPreviewModalEl ? bootstrap.Modal.getOrCreateInstance(whatsappPreviewModalEl) : null;
    let whatsappPreviewState = null;
    const pecasClienteModalEl = document.getElementById('modalPecasFornecedorCliente');
    const pecasClienteLista = document.getElementById('pecasClienteLista');
    const btnConfirmarPecasCliente = document.getElementById('btnConfirmarPecasCliente');
    const pecasClienteModal = pecasClienteModalEl ? bootstrap.Modal.getOrCreateInstance(pecasClienteModalEl) : null;
    let pecasClienteState = null;
    const reverterCancelamentoModalEl = document.getElementById('modalReverterCancelamento');
    const reverterCancelamentoEquipNome = document.getElementById('reverterCancelamentoEquipNome');
    const reverterCancelamentoMotivo = document.getElementById('reverterCancelamentoMotivo');
    const reverterCancelamentoErro = document.getElementById('reverterCancelamentoErro');
    const btnConfirmarReverterCancelamento = document.getElementById('btnConfirmarReverterCancelamento');
    const reverterCancelamentoModal = reverterCancelamentoModalEl ? bootstrap.Modal.getOrCreateInstance(reverterCancelamentoModalEl) : null;
    let reverterCancelamentoState = null;
    const retiradaSemCustoModalEl = document.getElementById('modalRetiradaSemCusto');
    const retiradaSemCustoEquipNome = document.getElementById('retiradaSemCustoEquipNome');
    const btnConfirmarRetiradaSemCusto = document.getElementById('btnConfirmarRetiradaSemCusto');
    const retiradaSemCustoModal = retiradaSemCustoModalEl ? bootstrap.Modal.getOrCreateInstance(retiradaSemCustoModalEl) : null;
    let retiradaSemCustoState = null;

    const fmtBrl = (n) => 'R$ ' + Number(n || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[c]));
    }

    // ── 9K-4: Aviso de M.O. não cadastrada ──────────────────────────────────
    // Exibido no card M.O. quando o algoritmo retorna encontrado=false e mo_valor=0.
    // Não é exibido em cards bloqueados (gate no moSelect.disabled) nem em erros de rede.
    function mostrarAvisoMoNaoCadastrada(card, equipName) {
        limparAvisoMoNaoCadastrada(card);
        const moCard = card.querySelector('.orcamento-admin-card--highlight');
        if (!moCard) return;
        const aviso = document.createElement('div');
        aviso.dataset.role = 'mo-nao-cadastrada-aviso';
        aviso.className = 'alert alert-warning small py-2 mt-2 mb-0';
        aviso.innerHTML =
            '<i class="ph ph-warning me-1"></i>' +
            '<strong>M.O. não cadastrada para este equipamento.</strong><br>' +
            'Não foi encontrada M.O. para: <em>' + escapeHtml(equipName) + '</em>. ' +
            '<a href="/admin/mao-de-obra" target="_blank" class="alert-link">Cadastrar na Tabela de M.O.</a>' +
            ' ou preencha o valor manualmente.';
        moCard.appendChild(aviso);
    }

    function limparAvisoMoNaoCadastrada(card) {
        card.querySelector('[data-role=mo-nao-cadastrada-aviso]')?.remove();
    }

    const toastEl = document.getElementById('toast');
    let toastTimer = null;
    function toast(msg, kind = 'ok') {
        if (!toastEl) return;
        const toastMsg = document.getElementById('toast-msg');
        if (toastMsg) toastMsg.textContent = msg;
        toastEl.className = 'alert alert-' + (kind === 'ok' ? 'success' : 'danger') + ' alert-dismissible position-fixed top-0 end-0 m-3';
        toastEl.style.zIndex = '1070';
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3500);
    }

    async function api(method, path, body) {
        const opts = {
            method,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        if (method !== 'GET') {
            opts.headers['X-CSRF-Token'] = CSRF;
        }
        const r = await fetch(path, opts);
        let data = null;
        try { data = await r.json(); } catch (_) {}
        if (!r.ok || !data || data.ok === false) {
            const err = (data && data.error) || ('HTTP ' + r.status);
            throw new Error(err);
        }
        return data;
    }

    function setStatusBadgeState(badge, status) {
        if (!badge) return;
        badge.textContent = status;
        badge.className = 'status-badge status-badge--' + (statusMap[status] || 'neutral');
    }

    function syncCardSummary(card) {
        const nav = navById.get(card.id);
        const totalLabel = card.querySelector('[data-role=total-geral]');
        const totalText = totalLabel ? totalLabel.textContent : fmtBrl(0);
        const itemCount = card.querySelectorAll('.orc-item-row').length;

        card.querySelectorAll('[data-role=panel-total], [data-role=admin-total]').forEach((label) => {
            label.textContent = totalText;
        });

        if (!nav) return;

        const navTotal = nav.querySelector('[data-role=nav-total]');
        if (navTotal) navTotal.textContent = totalText;

        const navCount = nav.querySelector('[data-role=nav-item-count]');
        if (navCount) navCount.textContent = itemCount + ' item(ns)';

        setStatusBadgeState(nav.querySelector('[data-role=nav-status-badge]'), card.dataset.status || 'rascunho');
    }

    function recalcCard(card) {
        const tbody = card.querySelector('[data-role=itens-body]');
        let subtotalPecas = 0;

        tbody.querySelectorAll('.orc-item-row').forEach(row => {
            const qtd = Number(row.querySelector('[data-role=qtd]').value || 0);
            const vu = Number(row.querySelector('[data-role=vu]').value || 0);
            const vt = qtd * vu;
            if (row.dataset.fornecidoCliente === '1') {
                return;
            }
            row.querySelector('[data-role=vt]').textContent = fmtBrl(vt);
            subtotalPecas += vt;
        });

        const moValor = Number(card.querySelector('input[name=mo_valor]').value || 0);
        card.querySelector('[data-role=subtotal-pecas]').textContent = fmtBrl(subtotalPecas);
        card.querySelector('[data-role=total-geral]').textContent = fmtBrl(subtotalPecas + moValor);

        const qtdItens = tbody.querySelectorAll('.orc-item-row').length;
        const qtdLabel = card.querySelector('[data-role=qtd-itens]');
        if (qtdLabel) qtdLabel.textContent = '(' + qtdItens + ')';

        syncCardSummary(card);
    }

    // ── Dirty state (10B-1) ────────────────────────────────────────────────────
    // Marca o card como modificado (não salvo) ou limpo (salvo no banco).
    // Bloqueia o envio de WhatsApp enquanto dirty=1.
    function markDirty(card) { card.dataset.dirty = '1'; }
    function markClean(card) { card.dataset.dirty = '0'; }

    function buildPayload(card) {
        const cabecalho = {
            tipo: card.querySelector('[name=tipo]').value,
            tecnico: card.querySelector('input[name=tecnico]').value,
            gerado_por: card.querySelector('input[name=gerado_por]').value,
            obs_admin: card.querySelector('textarea[name=obs_admin]').value,
            mo_valor: Number(card.querySelector('input[name=mo_valor]').value || 0),
            data_orcamento: card.querySelector('input[name=data_orcamento]').value || null,
        };

        const rows = Array.from(card.querySelectorAll('.orc-item-row'));
        const itens = rows.map((row, idx) => {
            const qtd = Number(row.querySelector('[data-role=qtd]').value || 0);
            const vu = Number(row.querySelector('[data-role=vu]').value || 0);
            const produtoId = Number(row.dataset.produtoId || 0);
            const tecnicoItemId = Number(row.dataset.tecnicoItemId || 0);
            return {
                id: Number(row.dataset.itemId || 0) || null,
                ordem_idx: idx,
                codigo: row.querySelector('input[name=codigo]').value.trim(),
                descricao: row.querySelector('input[name=descricao]').value.trim(),
                produto_id: produtoId > 0 ? produtoId : null,
                tecnico_item_id: tecnicoItemId > 0 ? tecnicoItemId : null,
                qtd,
                unidade: row.querySelector('input[name=unidade]').value.trim() || 'un',
                valor_unit: vu,
                valor_total: qtd * vu,
                em_estoque: 0,
            };
        }).filter(item => item.descricao !== '');

        const subtotalPecas = itens.reduce((acc, item) => acc + item.valor_total, 0);
        cabecalho.total = subtotalPecas + cabecalho.mo_valor;

        // 9J-1: motivo_gratuidade — presente apenas quando total=0 e select existe na UI.
        // Quando ausente (null), o backend preserva o valor atual do banco.
        const motivoEl = card.querySelector('select[name=motivo_gratuidade]');
        const motivoGratuidade = motivoEl !== null ? (motivoEl.value || null) : undefined;

        const payload = {
            os_id: card.dataset.osId,
            equip_idx: Number(card.dataset.equipIdx),
            cabecalho,
            itens,
        };
        // Só inclui no payload se o select existe (total=0); undefined não serializa em JSON.
        if (motivoGratuidade !== undefined) {
            payload.motivo_gratuidade = motivoGratuidade;
        }
        return payload;
    }

    async function saveClientObservation(card) {
        const obsCliField = card.querySelector('textarea[name=obs_cli]');
        if (!obsCliField) return;

        const obsIntField = card.querySelector('input[name=obs_int]');
        await api(
            'PUT',
            `/api/tecnico/equipamento/${encodeURIComponent(card.dataset.osId)}/${encodeURIComponent(card.dataset.equipIdx)}/laudo`,
            {
                obs_int: obsIntField ? obsIntField.value : '',
                obs_cli: obsCliField.value,
            }
        );

        const preview = card.querySelector('[data-role=obs-cli-preview]');
        if (preview) {
            preview.innerHTML = escapeHtml(obsCliField.value).replace(/\n/g, '<br>');
        }
    }

    function addItemRow(card) {
        const tbody = card.querySelector('[data-role=itens-body]');
        const empty = tbody.querySelector('[data-role=empty]');
        if (empty) empty.remove();

        const tr = document.createElement('tr');
        tr.className = 'orc-item-row';
        tr.dataset.produtoId = '0';
        tr.dataset.tecnicoItemId = '0';
        tr.innerHTML = `
            <td><input type="text" name="codigo" value="" class="form-control form-control-sm text-mono"></td>
            <td><input type="text" name="descricao" value="" class="form-control form-control-sm" required></td>
            <td><input type="number" name="qtd" step="0.001" min="0" value="1" class="form-control form-control-sm" data-role="qtd"></td>
            <td><input type="text" name="unidade" value="un" class="form-control form-control-sm"></td>
            <td><input type="number" name="valor_unit" step="0.01" min="0" value="0" class="form-control form-control-sm" data-role="vu"></td>
            <td class="text-mono align-middle" data-role="vt">R$ 0,00</td>
            <td><button class="btn-icon text-danger" data-role="remover-item" title="Remover"><i class="ph ph-x"></i></button></td>
        `;
        tbody.appendChild(tr);
        tr.querySelector('input[name=descricao]').focus();
        recalcCard(card);
    }

    function removeItemRow(card, row) {
        row.remove();
        const tbody = card.querySelector('[data-role=itens-body]');
        if (tbody.querySelectorAll('.orc-item-row').length === 0) {
            tbody.innerHTML = `
                <tr class="empty-row" data-role="empty">
                    <td colspan="7" class="text-body-secondary text-center py-3">
                        Nenhum item. Clique em "+ Adicionar item".
                    </td>
                </tr>
            `;
        }
        recalcCard(card);
    }

    function applyOrcamentoToCard(card, orc) {
        if (!orc) return;

        card.dataset.orcId = String(orc.id);
        card.dataset.status = orc.status;

        setStatusBadgeState(card.querySelector('[data-role=status-badge]'), orc.status);
        setStatusBadgeState(navById.get(card.id)?.querySelector('[data-role=nav-status-badge]'), orc.status);

        const select = card.querySelector('[data-role=status-select]');
        if (select) {
            select.disabled = false;
            select.value = orc.status;
        }

        card.querySelectorAll('[data-role=aplicar-status], [data-role=toggle-pago], [data-role=wpp-orcamento], [data-role=enviar-email-orcamento]').forEach(btn => {
            btn.disabled = false;
        });

        const togglePago = card.querySelector('[data-role=toggle-pago]');
        if (togglePago) togglePago.textContent = orc.pago ? 'Desfazer pago' : 'Marcar como pago';

        let pagoBadge = card.querySelector('[data-role=pago-badge]');
        if (orc.pago && !pagoBadge) {
            pagoBadge = document.createElement('span');
            pagoBadge.className = 'status-badge status-badge--success';
            pagoBadge.dataset.role = 'pago-badge';
            pagoBadge.textContent = 'pago';
            card.querySelector('[data-role=status-badge]').insertAdjacentElement('afterend', pagoBadge);
        } else if (!orc.pago && pagoBadge) {
            pagoBadge.remove();
        }

        syncCardSummary(card);
    }

    function abrirModalPecasCliente(card) {
        if (!pecasClienteModal || !pecasClienteLista || !btnConfirmarPecasCliente) return;
        const orcId = card.dataset.orcId;
        if (!orcId) {
            toast('Salve o orçamento antes de marcar peças fornecidas pelo cliente.', 'err');
            return;
        }
        if (card.dataset.dirty === '1') {
            toast('Salve o orçamento antes de marcar peças fornecidas pelo cliente.', 'err');
            card.querySelector('[data-role=salvar]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        const rows = Array.from(card.querySelectorAll('.orc-item-row[data-item-id]'))
            .filter(row => row.dataset.fornecidoCliente !== '1' && Number(row.dataset.itemId || 0) > 0);

        if (rows.length === 0) {
            toast('Não há peças elegíveis neste orçamento.', 'err');
            return;
        }

        pecasClienteLista.innerHTML = rows.map((row, idx) => {
            const id = Number(row.dataset.itemId);
            const codigo = row.querySelector('input[name=codigo]')?.value.trim() || '';
            const descricao = row.querySelector('input[name=descricao]')?.value.trim() || 'Item sem descrição';
            const qtd = row.querySelector('[data-role=qtd]')?.value || '1';
            const total = row.querySelector('[data-role=vt]')?.textContent.trim() || fmtBrl(0);
            return `
                <label class="border rounded p-2 d-flex align-items-start gap-2">
                    <input class="form-check-input mt-1" type="checkbox" value="${id}" ${idx === 0 ? 'checked' : ''}>
                    <span class="flex-grow-1">
                        <span class="fw-semibold">${escapeHtml(descricao)}</span>
                        <span class="d-block small text-body-secondary">
                            ${codigo ? 'Código ' + escapeHtml(codigo) + ' · ' : ''}Qtd ${escapeHtml(qtd)} · ${escapeHtml(total)}
                        </span>
                    </span>
                </label>
            `;
        }).join('');

        pecasClienteState = { card, orcId };
        btnConfirmarPecasCliente.disabled = false;
        pecasClienteModal.show();
    }

    if (btnConfirmarPecasCliente) {
        btnConfirmarPecasCliente.addEventListener('click', async () => {
            if (!pecasClienteState || !pecasClienteLista) return;
            const itemIds = Array.from(pecasClienteLista.querySelectorAll('input[type=checkbox]:checked'))
                .map(input => Number(input.value))
                .filter(id => id > 0);
            if (itemIds.length === 0) {
                toast('Selecione ao menos uma peça.', 'err');
                return;
            }

            btnConfirmarPecasCliente.disabled = true;
            try {
                await api('POST', `/api/orcamentos/${pecasClienteState.orcId}/pecas-fornecidas-cliente`, {
                    item_ids: itemIds,
                    motivo: 'Cliente trouxe as peças',
                    liberar_montagem: true,
                });
                pecasClienteModal?.hide();
                window.location.reload();
            } catch (e) {
                toast('Erro ao confirmar peças: ' + e.message, 'err');
                btnConfirmarPecasCliente.disabled = false;
            }
        });
    }

    if (pecasClienteModalEl) {
        pecasClienteModalEl.addEventListener('hidden.bs.modal', () => {
            pecasClienteState = null;
            if (pecasClienteLista) pecasClienteLista.innerHTML = '';
            if (btnConfirmarPecasCliente) btnConfirmarPecasCliente.disabled = false;
        });
    }

    function abrirModalReverterCancelamento(card, btn) {
        if (!reverterCancelamentoModal || !btnConfirmarReverterCancelamento) return;
        const orcId = btn.dataset.orcId || card.dataset.orcId;
        if (!orcId) {
            toast('Orçamento não encontrado para reversão.', 'err');
            return;
        }

        reverterCancelamentoState = { card, btn, orcId };
        if (reverterCancelamentoEquipNome) {
            reverterCancelamentoEquipNome.textContent = btn.dataset.equipNome || 'Equipamento';
        }
        if (reverterCancelamentoMotivo) {
            reverterCancelamentoMotivo.value = '';
            reverterCancelamentoMotivo.classList.remove('is-invalid');
        }
        reverterCancelamentoErro?.classList.remove('d-block');
        btnConfirmarReverterCancelamento.disabled = false;
        reverterCancelamentoModal.show();
        setTimeout(() => reverterCancelamentoMotivo?.focus(), 150);
    }

    btnConfirmarReverterCancelamento?.addEventListener('click', async () => {
        if (!reverterCancelamentoState || !reverterCancelamentoMotivo) return;

        const motivo = reverterCancelamentoMotivo.value.trim();
        if (motivo === '') {
            reverterCancelamentoMotivo.classList.add('is-invalid');
            reverterCancelamentoErro?.classList.add('d-block');
            reverterCancelamentoMotivo.focus();
            return;
        }

        btnConfirmarReverterCancelamento.disabled = true;
        try {
            await api('POST', `/api/orcamentos/${reverterCancelamentoState.orcId}/reverter-cancelamento`, {
                motivo,
            });
            reverterCancelamentoModal?.hide();
            toast('Cancelamento revertido. Recarregando...');
            setTimeout(() => {
                window.location.reload();
            }, 600);
        } catch (e) {
            toast('Erro ao reverter cancelamento: ' + e.message, 'err');
            btnConfirmarReverterCancelamento.disabled = false;
        }
    });

    reverterCancelamentoMotivo?.addEventListener('input', () => {
        reverterCancelamentoMotivo.classList.remove('is-invalid');
        reverterCancelamentoErro?.classList.remove('d-block');
    });

    reverterCancelamentoModalEl?.addEventListener('hidden.bs.modal', () => {
        reverterCancelamentoState = null;
        if (reverterCancelamentoMotivo) {
            reverterCancelamentoMotivo.value = '';
            reverterCancelamentoMotivo.classList.remove('is-invalid');
        }
        reverterCancelamentoErro?.classList.remove('d-block');
        if (btnConfirmarReverterCancelamento) {
            btnConfirmarReverterCancelamento.disabled = false;
        }
    });

    function abrirModalRetiradaSemCusto(card, btn) {
        if (!retiradaSemCustoModal || !btnConfirmarRetiradaSemCusto) return;
        const orcId = btn.dataset.orcId || card.dataset.orcId;
        if (!orcId) {
            toast('Orçamento não encontrado para retirada sem custo.', 'err');
            return;
        }

        retiradaSemCustoState = { card, btn, orcId };
        if (retiradaSemCustoEquipNome) {
            retiradaSemCustoEquipNome.textContent = btn.dataset.equipNome || 'Equipamento';
        }
        btnConfirmarRetiradaSemCusto.disabled = false;
        btnConfirmarRetiradaSemCusto.innerHTML = '<i class="ph ph-check me-1"></i> Confirmar retirada sem custo';
        retiradaSemCustoModal.show();
    }

    btnConfirmarRetiradaSemCusto?.addEventListener('click', async () => {
        if (!retiradaSemCustoState) return;

        btnConfirmarRetiradaSemCusto.disabled = true;
        btnConfirmarRetiradaSemCusto.textContent = 'Registrando...';
        try {
            await api('POST', `/api/orcamentos/${retiradaSemCustoState.orcId}/retirada-sem-custo`, {});
            retiradaSemCustoModal?.hide();
            toast('Retirada sem custo registrada. Recarregando...');
            setTimeout(() => {
                window.location.reload();
            }, 600);
        } catch (e) {
            toast('Erro ao registrar retirada sem custo: ' + e.message, 'err');
            btnConfirmarRetiradaSemCusto.disabled = false;
            btnConfirmarRetiradaSemCusto.innerHTML = '<i class="ph ph-check me-1"></i> Confirmar retirada sem custo';
        }
    });

    retiradaSemCustoModalEl?.addEventListener('hidden.bs.modal', () => {
        retiradaSemCustoState = null;
        if (btnConfirmarRetiradaSemCusto) {
            btnConfirmarRetiradaSemCusto.disabled = false;
            btnConfirmarRetiradaSemCusto.innerHTML = '<i class="ph ph-check me-1"></i> Confirmar retirada sem custo';
        }
    });

    function updateStepButtons() {
        cards.forEach((card, index) => {
            const prevBtn = card.querySelector('[data-role=equip-prev]');
            const nextBtn = card.querySelector('[data-role=equip-next]');
            if (prevBtn) prevBtn.disabled = index === 0;
            if (nextBtn) nextBtn.disabled = index === cards.length - 1;
        });
    }

    async function obterPreviewWhatsapp(orcId, confirmarItensZerados) {
        return api('POST', `/api/orcamentos/${orcId}/whatsapp`, {
            confirmar_itens_zerados: confirmarItensZerados,
            registrar_envio: false,
        });
    }

    async function confirmarEnvioWhatsapp(orcId, confirmarItensZerados) {
        return api('POST', `/api/orcamentos/${orcId}/whatsapp`, {
            confirmar_itens_zerados: confirmarItensZerados,
            registrar_envio: true,
        });
    }

    if (whatsappPreviewConfirmBtn) {
        whatsappPreviewConfirmBtn.addEventListener('click', async () => {
            if (!whatsappPreviewState) return;

            const { card, btn, orcId, confirmarItensZerados } = whatsappPreviewState;
            whatsappPreviewConfirmBtn.disabled = true;

            try {
                const r = await confirmarEnvioWhatsapp(orcId, confirmarItensZerados);
                whatsappPreviewModal?.hide();
                window.open(r.wpp_url, '_blank');
                applyOrcamentoToCard(card, r.orcamento);
                toast('Link do WhatsApp gerado.');
            } catch (e) {
                toast('Erro ao gerar WhatsApp: ' + e.message, 'err');
            } finally {
                whatsappPreviewConfirmBtn.disabled = false;
                if (btn) btn.disabled = false;
                whatsappPreviewState = null;
            }
        });
    }

    if (whatsappPreviewModalEl) {
        whatsappPreviewModalEl.addEventListener('hidden.bs.modal', () => {
            if (whatsappPreviewConfirmBtn) whatsappPreviewConfirmBtn.disabled = false;
            if (whatsappPreviewState?.btn) {
                whatsappPreviewState.btn.disabled = false;
            }
            whatsappPreviewState = null;
            if (whatsappPreviewField) whatsappPreviewField.value = '';
        });
    }

    function setActiveCard(nextCard, opts = {}) {
        if (!nextCard) return;
        const options = {
            updateHash: true,
            scrollPanel: false,
            focusNav: false,
            ...opts,
        };

        activeCard = nextCard;

        cards.forEach(card => {
            const isActive = card === nextCard;
            card.hidden = !isActive;
            card.classList.toggle('is-active', isActive);
        });

        navButtons.forEach(btn => {
            const isActive = btn.dataset.target === nextCard.id;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        updateStepButtons();

        if (options.updateHash) {
            history.replaceState(null, '', '#' + nextCard.id);
        }

        if (options.scrollPanel) {
            nextCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (options.focusNav) {
            navById.get(nextCard.id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        }
    }

    function getCardFromHash() {
        const hash = window.location.hash.replace('#', '').trim();
        return hash ? cardById.get(hash) : null;
    }

    let catalogoCarregado = false;
    let cardAtivoParaCatalogo = null;

    async function abrirCatalogo(card) {
        cardAtivoParaCatalogo = card;
        const modal = document.getElementById('modalCatalogoMo');
        const container = document.getElementById('catalogoMoContainer');
        bootstrap.Modal.getOrCreateInstance(modal).show();

        if (!catalogoCarregado) {
            container.innerHTML = '<div class="text-center py-4">Carregando...</div>';
            try {
                const r = await api('GET', '/api/mao-de-obra');
                if (!r.ok) throw new Error('Erro ao carregar catalogo');

                let html = '';
                for (const [cat, itens] of Object.entries(r.catalogo)) {
                    if (itens.length === 0) continue;
                    const catName = cat.charAt(0).toUpperCase() + cat.slice(1);
                    html += `<h4 style="margin-top:1rem;margin-bottom:.5rem;border-bottom:1px solid var(--bs-border-color);padding-bottom:.25rem;">${catName}</h4>`;
                    html += '<ul style="list-style:none;padding:0;margin:0;display:grid;gap:.5rem;">';
                    itens.forEach(it => {
                        html += `
                            <li style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.65rem .75rem;background:var(--bs-secondary-bg);border-radius:.75rem;">
                                <span>${escapeHtml(it.nome)}</span>
                                <div style="display:flex;gap:.5rem;align-items:center;">
                                    <strong style="font-family:var(--bs-font-monospace);">${fmtBrl(it.valor_padrao)}</strong>
                                    <button class="btn btn-sm btn-secondary" onclick="window.selecionarItemCatalogo('${escapeHtml(it.categoria)}', '${escapeHtml(it.nome)}', ${it.valor_padrao})">Adicionar</button>
                                </div>
                            </li>
                        `;
                    });
                    html += '</ul>';
                }
                container.innerHTML = html || '<div style="padding:1rem;">Catalogo vazio.</div>';
                catalogoCarregado = true;
            } catch (e) {
                container.innerHTML = `<div class="text-danger p-3">${escapeHtml(e.message)}</div>`;
            }
        }
    }

    window.selecionarItemCatalogo = function(categoria, nome, valor) {
        if (!cardAtivoParaCatalogo) return;

        const card = cardAtivoParaCatalogo;
        if (categoria === 'servico') {
            const tbody = card.querySelector('[data-role=itens-body]');
            const empty = tbody.querySelector('[data-role=empty]');
            if (empty) empty.remove();

            const tr = document.createElement('tr');
            tr.className = 'orc-item-row';
            tr.dataset.produtoId = '0';
            tr.dataset.tecnicoItemId = '0';
            tr.innerHTML = `
                <td><input type="text" name="codigo" value="" class="form-control form-control-sm text-mono"></td>
                <td><input type="text" name="descricao" value="${escapeHtml(nome)}" class="form-control form-control-sm" required></td>
                <td><input type="number" name="qtd" step="0.001" min="0" value="1" class="form-control form-control-sm" data-role="qtd"></td>
                <td><input type="text" name="unidade" value="un" class="form-control form-control-sm"></td>
                <td><input type="number" name="valor_unit" step="0.01" min="0" value="${valor}" class="form-control form-control-sm" data-role="vu"></td>
                <td class="text-mono align-middle" data-role="vt">${fmtBrl(valor)}</td>
                <td><button class="btn-icon text-danger" data-role="remover-item" title="Remover"><i class="ph ph-x"></i></button></td>
            `;
            tbody.appendChild(tr);
        } else {
            card.querySelector('input[name=mo_valor]').value = Number(valor).toFixed(2);
        }

        recalcCard(card);
        markDirty(card); // 10B-1
        bootstrap.Modal.getInstance(document.getElementById('modalCatalogoMo'))?.hide();
        toast('Adicionado: ' + nome);
    };

    // ── Importação de itens técnicos ─────────────────────────────────────────
    // `silent = true` → sem toast de erro, sem foco — usado na auto-importação.
    // `silent = false` → feedback completo — usado no clique manual.
    async function importarItensTecnicos(card, osId, equipIdx, silent) {
        const r = await api('GET',
            `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${encodeURIComponent(equipIdx)}/itens-para-orcamento`
        );

        if (!r.itens || r.itens.length === 0) {
            if (!silent) toast('Nenhum item registrado pelo tecnico para este equipamento.', 'err');
            return;
        }

        const codigosExistentes = new Set(
            Array.from(card.querySelectorAll('.orc-item-row input[name=codigo]'))
                .map(el => el.value.trim().toLowerCase())
                .filter(Boolean)
        );

        const tbody       = card.querySelector('[data-role=itens-body]');
        const emptyRow    = tbody.querySelector('[data-role=empty]');
        let adicionados   = 0;
        let ignorados     = 0;
        let semPrecoQtd   = 0;
        let primeiroInput = null;

        r.itens.forEach(item => {
            const codigoNorm = (item.codigo || '').trim().toLowerCase();
            if (!item.descricao) return;
            if (codigoNorm && codigosExistentes.has(codigoNorm)) { ignorados++; return; }

            if (emptyRow) emptyRow.remove();

            const vuVal    = Number(item.valor_unit || 0);
            const qtdVal   = Number(item.qtd || 1);
            const vtVal    = qtdVal * vuVal;
            const semPreco = vuVal <= 0;

            const tr = document.createElement('tr');
            tr.className = 'orc-item-row';
            tr.dataset.produtoId = String(Number(item.produto_id || 0));
            tr.dataset.tecnicoItemId = String(Number(item.id || item.tecnico_item_id || 0));
            tr.innerHTML = `
                <td><input type="text" name="codigo" value="${escapeHtml(item.codigo || '')}" class="form-control form-control-sm text-mono"></td>
                <td><input type="text" name="descricao" value="${escapeHtml(item.descricao)}" class="form-control form-control-sm" required></td>
                <td><input type="number" name="qtd" step="0.001" min="0" value="${qtdVal.toFixed(3)}" class="form-control form-control-sm" data-role="qtd"></td>
                <td><input type="text" name="unidade" value="${escapeHtml(item.unidade || 'un')}" class="form-control form-control-sm"></td>
                <td><input type="number" name="valor_unit" step="0.01" min="0" value="${vuVal.toFixed(2)}" class="form-control form-control-sm" data-role="vu"${semPreco ? ' title="Preencha o preco unitario"' : ''}></td>
                <td class="text-mono align-middle" data-role="vt">${fmtBrl(vtVal)}</td>
                <td><button class="btn-icon text-danger" data-role="remover-item" title="Remover"><i class="ph ph-x"></i></button></td>
            `;
            tbody.appendChild(tr);

            if (semPreco) {
                semPrecoQtd++;
                if (!primeiroInput) {
                    primeiroInput = tr.querySelector('[data-role=vu]');
                    primeiroInput.style.borderColor = 'var(--bs-warning)';
                    primeiroInput.setAttribute('title', 'Informe o preco unitario');
                }
            }

            if (codigoNorm) codigosExistentes.add(codigoNorm);
            adicionados++;
        });

        recalcCard(card);

        if (adicionados === 0) {
            if (!silent) toast(`Todos os itens do tecnico (${ignorados}) ja estao no orcamento.`, 'err');
            return;
        }

        // 10B-1: itens foram adicionados ao DOM mas ainda não salvos → marca dirty.
        markDirty(card);

        const msgIgnorados = ignorados > 0 ? ` (${ignorados} ja existiam)` : '';
        const msgSemPreco  = semPrecoQtd > 0 ? ` — ${semPrecoQtd} sem preco, preencha manualmente` : '';
        const prefixo      = silent ? 'Auto-importado: ' : '';
        toast(`${prefixo}${adicionados} item(ns) importado(s)${msgIgnorados}${msgSemPreco}.`);

        if (!silent && primeiroInput) {
            primeiroInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => primeiroInput.focus(), 300);
        }
    }

    cards.forEach((card, index) => {
        recalcCard(card);

        card.addEventListener('input', (ev) => {
            const target = ev.target;
            if (target.matches('[data-role=qtd], [data-role=vu], input[name=mo_valor]')) {
                recalcCard(card);
                // 9K-4: recepção preencheu M.O. manualmente → remove aviso de item não cadastrado.
                if (target.matches('input[name=mo_valor]') && Number(target.value || 0) > 0) {
                    limparAvisoMoNaoCadastrada(card);
                }
            }
            // 10B-1: marcar dirty em qualquer campo de item, M.O. ou obs. do cliente.
            if (target.matches(
                'input[name=codigo], input[name=descricao], [data-role=qtd], input[name=unidade], [data-role=vu], input[name=mo_valor], textarea[name=obs_cli]'
            )) {
                markDirty(card);
            }
        });

        card.querySelector('[data-role=adicionar-item]')?.addEventListener('click', () => {
            addItemRow(card);
            markDirty(card); // 10B-1
        });

        card.querySelector('[data-role=abrir-catalogo-mo]')?.addEventListener('click', () => {
            abrirCatalogo(card);
        });

        card.querySelector('[data-role=pecas-fornecidas-cliente]')?.addEventListener('click', () => {
            abrirModalPecasCliente(card);
        });

        card.querySelector('[data-role=reverter-cancelamento]')?.addEventListener('click', (ev) => {
            abrirModalReverterCancelamento(card, ev.currentTarget);
        });

        card.querySelector('[data-role=retirada-sem-custo]')?.addEventListener('click', (ev) => {
            abrirModalRetiradaSemCusto(card, ev.currentTarget);
        });

        const btnPrev = card.querySelector('[data-role=equip-prev]');
        if (btnPrev) {
            btnPrev.addEventListener('click', () => {
                setActiveCard(cards[index - 1], { scrollPanel: true, focusNav: true });
            });
        }

        const btnNext = card.querySelector('[data-role=equip-next]');
        if (btnNext) {
            btnNext.addEventListener('click', () => {
                setActiveCard(cards[index + 1], { scrollPanel: true, focusNav: true });
            });
        }

        const btnImportar = card.querySelector('[data-role=importar-tecnico]');
        if (btnImportar) {
            btnImportar.addEventListener('click', async () => {
                const osId     = btnImportar.dataset.osId;
                const equipIdx = btnImportar.dataset.equipIdx;
                const htmlOrig = btnImportar.innerHTML;
                btnImportar.disabled = true;
                btnImportar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Buscando...';
                try {
                    await importarItensTecnicos(card, osId, equipIdx, false);
                } finally {
                    btnImportar.disabled = false;
                    btnImportar.innerHTML = htmlOrig;
                }
            });
        }

        const btnSugerir = card.querySelector('[data-role=sugerir-mo]');
        if (btnSugerir) {
            const htmlOriginal = btnSugerir.innerHTML;
            btnSugerir.addEventListener('click', async () => {
                const equipName = card.querySelector('[data-role=equip-title]').textContent.replace(/#\d+/, '').trim();
                btnSugerir.disabled = true;
                btnSugerir.textContent = '...';
                try {
                    const r = await api('POST', '/api/mao-de-obra/sugerir', { equipamento: equipName });
                    if (r.encontrado) {
                        const moInput = card.querySelector('input[name=mo_valor]');
                        const moAtual = Number(moInput.value || 0);
                        if (moAtual > 0 && !confirm(`M.O. já está em ${fmtBrl(moAtual)}. Substituir pela sugestão ${fmtBrl(r.valor)}?`)) {
                            return;
                        }
                        moInput.value = r.valor.toFixed(2);
                        recalcCard(card);
                        limparAvisoMoNaoCadastrada(card);
                        toast(`Sugerido: ${r.match} (${fmtBrl(r.valor)})`);
                    } else {
                        // 9K-4: aviso específico com link para cadastro
                        toast('M.O. nao cadastrada para este equipamento. Cadastre na tabela ou informe manualmente.', 'err');
                        mostrarAvisoMoNaoCadastrada(card, equipName);
                    }
                } catch (e) {
                    toast('Erro: ' + e.message, 'err');
                } finally {
                    btnSugerir.disabled = false;
                    btnSugerir.innerHTML = htmlOriginal;
                }
            });
        }

        const moSelect = card.querySelector('[data-role=mo-select]');
        // 9K-1: não processar M.O. em cards bloqueados (aprovado/cancelado).
        if (moSelect && !moSelect.disabled) {
            // Carrega as opções da tabela M.O. — idempotente (protegida por dataset.loaded).
            const preencherMo = async () => {
                if (moSelect.dataset.loaded) return;
                const tipo = card.dataset.orcTipo || 'maquina';
                try {
                    const r = await api('GET', `/api/mao-obra?tipo=${encodeURIComponent(tipo)}`);
                    // Remove opções anteriores, mantendo apenas o placeholder
                    while (moSelect.options.length > 1) moSelect.remove(1);
                    if (!r.itens || r.itens.length === 0) {
                        const opt = document.createElement('option');
                        opt.disabled = true;
                        opt.textContent = `Nenhuma M.O. cadastrada para o tipo "${tipo}". Informe manualmente ou revise o cadastro de M.O.`;
                        moSelect.appendChild(opt);
                    } else {
                        r.itens.forEach(it => {
                            const opt = document.createElement('option');
                            opt.value = Number(it.valor_padrao).toFixed(2);
                            opt.textContent = `${it.nome} — ${fmtBrl(Number(it.valor_padrao))}`;
                            opt.dataset.nome = it.nome;
                            moSelect.appendChild(opt);
                        });
                    }
                    moSelect.dataset.loaded = '1';
                } catch (e) {
                    toast('Erro ao carregar M.O.: ' + e.message, 'err');
                }
            };

            // Carrega automaticamente ao inicializar o card (não espera o foco).
            preencherMo();
            // Mantém focus como fallback para retry (ex: erro de rede na primeira tentativa).
            moSelect.addEventListener('focus', preencherMo);

            moSelect.addEventListener('change', () => {
                const val = moSelect.value;
                if (!val) return;

                const moInput = card.querySelector('input[name=mo_valor]');
                const moAtual = Number(moInput.value || 0);
                const nomeSelecionado = moSelect.selectedOptions[0]?.dataset.nome || val;

                if (moAtual > 0 && !confirm(`M.O. já está em ${fmtBrl(moAtual)}. Substituir por ${fmtBrl(Number(val))} (${nomeSelecionado})?`)) {
                    moSelect.value = '';
                    return;
                }

                moInput.value = val;
                recalcCard(card);
                markDirty(card); // 10B-1
                toast(`M.O. selecionada: ${nomeSelecionado} (${fmtBrl(Number(val))})`);
                moSelect.value = ''; // Reset para permitir nova seleção
            });

            // 9K-1: auto-sugerir M.O. quando mo_valor=0 e orçamento editável.
            // Usa /api/mao-de-obra/sugerir (por nome do equipamento) — mais preciso que listar por tipo.
            // Não sobrescreve valor manual já preenchido. Falha silenciosa — não bloqueia a tela.
            const moInputAuto = card.querySelector('input[name=mo_valor]');
            if (moInputAuto && !moInputAuto.readOnly && Number(moInputAuto.value || 0) === 0) {
                const equipNameAuto = card.querySelector('[data-role=equip-title]')
                    ?.textContent.replace(/#\d+/, '').trim() || '';
                if (equipNameAuto !== '') {
                    api('POST', '/api/mao-de-obra/sugerir', { equipamento: equipNameAuto })
                        .then(r => {
                            // Verifica novamente: usuário pode ter digitado algo durante a requisição.
                            if (r.encontrado && Number(moInputAuto.value || 0) === 0) {
                                moInputAuto.value = r.valor.toFixed(2);
                                recalcCard(card);
                                markDirty(card); // 10B-2: auto-sugestão altera DOM sem salvar
                                limparAvisoMoNaoCadastrada(card);
                            } else if (!r.encontrado && Number(moInputAuto.value || 0) === 0) {
                                // 9K-4: aviso discreto quando não há M.O. cadastrada para o equipamento.
                                // Não exibir quando mo_valor já foi preenchido manualmente.
                                mostrarAvisoMoNaoCadastrada(card, equipNameAuto);
                            }
                        })
                        .catch(() => {}); // falha silenciosa — erro de rede não exibe aviso de catálogo
                }
            }
        }

        card.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-role=remover-item]');
            if (!btn) return;
            ev.preventDefault();
            const row = btn.closest('.orc-item-row');
            if (row) {
                removeItemRow(card, row);
                markDirty(card); // 10B-1
            }
        });

        card.querySelector('[data-role=salvar]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const payload = buildPayload(card);

            if (payload.cabecalho.mo_valor <= 0 && payload.itens.length === 0) {
                if (!confirm('Salvar orcamento vazio (sem mao de obra e sem itens)?')) return;
            }

            btn.disabled = true;
            try {
                const r = await api('POST', '/api/orcamentos', payload);
                applyOrcamentoToCard(card, r.orcamento);
                markClean(card); // 10B-1: orçamento persistido → libera WhatsApp

                try {
                    await saveClientObservation(card);
                    toast('Orcamento salvo. Total ' + fmtBrl(payload.cabecalho.total) + '. Recarregando...');
                    setTimeout(() => {
                        window.location.reload();
                    }, 600);
                } catch (obsErr) {
                    toast('Orcamento salvo, mas nao foi possivel salvar a observacao para o cliente: ' + obsErr.message, 'err');
                }
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        });

        card.querySelector('[data-role=aplicar-status]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const orcId = card.dataset.orcId;
            if (!orcId) {
                toast('Salve o orcamento antes de mudar o status.', 'err');
                return;
            }

            const novoStatus = card.querySelector('[data-role=status-select]').value;
            if (!confirm(`Mudar status para "${novoStatus}"?`)) return;

            const extras = { status: novoStatus };
            const hoje = new Date().toISOString().slice(0, 10);
            if (novoStatus === 'aprovado') extras.data_aprovado = hoje;
            if (novoStatus === 'pronto') extras.data_pronto = hoje;
            if (novoStatus === 'retirado') extras.data_retirada = hoje;
            if (novoStatus === 'enviado') extras.wpp_enviado_em = new Date().toISOString().slice(0, 19).replace('T', ' ');

            btn.disabled = true;
            try {
                const r = await api('PATCH', '/api/orcamentos/' + orcId, extras);
                applyOrcamentoToCard(card, r.orcamento);
                toast('Status atualizado para ' + novoStatus);
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        });

        card.querySelector('[data-role=toggle-pago]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const orcId = card.dataset.orcId;
            if (!orcId) {
                toast('Salve o orcamento primeiro.', 'err');
                return;
            }

            const atual = card.querySelector('[data-role=pago-badge]') !== null;
            const novo = atual ? 0 : 1;

            btn.disabled = true;
            try {
                const r = await api('PATCH', '/api/orcamentos/' + orcId, { pago: novo });
                applyOrcamentoToCard(card, r.orcamento);
                toast(novo === 1 ? 'Marcado como pago.' : 'Pagamento removido.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        });

        card.querySelector('[data-role=pre-aprovar]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            if (!confirm('Pre-aprovar este orcamento?')) return;

            btn.disabled = true;
            try {
                const r = await api('POST', '/api/orcamentos/pre-aprovar', {
                    os_id: card.dataset.osId,
                    equip_idx: Number(card.dataset.equipIdx),
                });
                applyOrcamentoToCard(card, r.orcamento);
                toast('Orcamento pre-aprovado.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        });

        const btnWpp = card.querySelector('[data-role=wpp-orcamento]');
        if (btnWpp) {
            btnWpp.addEventListener('click', async (ev) => {
                const btn = ev.currentTarget;
                const orcId = card.dataset.orcId;
                if (!orcId) {
                    toast('Salve o orcamento antes de gerar o WhatsApp.', 'err');
                    return;
                }
                // 10B-1: bloquear se há alterações não salvas no card.
                if (card.dataset.dirty === '1') {
                    toast('Salve o orcamento antes de enviar pelo WhatsApp.', 'err');
                    card.querySelector('[data-role=salvar]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }

                btn.disabled = true;
                try {
                    await saveClientObservation(card);
                    let confirmarItensZerados = false;
                    let r = await obterPreviewWhatsapp(orcId, confirmarItensZerados);

                    if (r.needs_confirmation) {
                        const ok = confirm(`${r.error}\n\nDeseja enviar mesmo assim?`);
                        if (!ok) {
                            btn.disabled = false;
                            return;
                        }
                        confirmarItensZerados = true;
                        r = await obterPreviewWhatsapp(orcId, confirmarItensZerados);
                    }

                    if (!whatsappPreviewModal || !whatsappPreviewField) {
                        const envio = await confirmarEnvioWhatsapp(orcId, confirmarItensZerados);
                        window.open(envio.wpp_url, '_blank');
                        applyOrcamentoToCard(card, envio.orcamento);
                        toast('Link do WhatsApp gerado.');
                        return;
                    }

                    whatsappPreviewField.value = r.mensagem || '';
                    whatsappPreviewState = {
                        card,
                        btn,
                        orcId,
                        confirmarItensZerados,
                    };
                    whatsappPreviewModal.show();
                } catch (e) {
                    toast('Erro ao gerar WhatsApp: ' + e.message, 'err');
                    btn.disabled = false;
                } finally {
                    if (!whatsappPreviewState) {
                        btn.disabled = false;
                    }
                }
            });
        }

        // ── Enviar orçamento por e-mail (10C-3) ──────────────────────────────
        const btnEmail = card.querySelector('[data-role=enviar-email-orcamento]');
        if (btnEmail) {
            btnEmail.addEventListener('click', async (ev) => {
                const btn = ev.currentTarget;
                const orcId = card.dataset.orcId;
                if (!orcId) {
                    toast('Salve o orçamento antes de enviar por e-mail.', 'err');
                    return;
                }
                if (card.dataset.dirty === '1') {
                    toast('Salve o orçamento antes de enviar por e-mail.', 'err');
                    card.querySelector('[data-role=salvar]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }
                if (!confirm('Enviar orçamento por e-mail para o cliente?')) return;
                btn.disabled = true;
                try {
                    const r = await api('POST', `/api/orcamentos/${orcId}/email`, {});
                    applyOrcamentoToCard(card, r.orcamento);
                    toast('Orçamento enviado por e-mail para ' + r.email + '.');
                } catch (e) {
                    toast('Erro ao enviar e-mail: ' + e.message, 'err');
                } finally {
                    btn.disabled = false;
                }
            });
        }

        // ── Imprimir / PDF do orçamento (10C-1) ─────────────────────────────
        const btnPdf = card.querySelector('[data-role=orcamento-pdf]');
        if (btnPdf) {
            btnPdf.addEventListener('click', () => {
                const orcId = card.dataset.orcId;
                if (!orcId) {
                    toast('Salve o orçamento antes de gerar o PDF.', 'err');
                    return;
                }
                if (card.dataset.dirty === '1') {
                    toast('Salve o orçamento antes de gerar o PDF.', 'err');
                    card.querySelector('[data-role=salvar]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }
                window.open(`/orcamento/${orcId}/pdf`, '_blank');
            });
        }

        // ── Aprovar / Cancelar orçamento (9G-1) ──────────────────────────────
        card.querySelector('[data-role=aprovar-orcamento]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const orcId = card.dataset.orcId;
            if (!orcId) {
                toast('Salve o orçamento antes de aprovar.', 'err');
                return;
            }
            if (!confirm('Confirmar aprovação deste orçamento pelo cliente?')) return;
            btn.disabled = true;
            try {
                await api('PATCH', '/api/orcamentos/' + orcId, {
                    status: 'aprovado',
                    data_aprovado: new Date().toISOString().slice(0, 10),
                });
                window.location.reload();
            } catch (e) {
                toast('Erro ao aprovar: ' + e.message, 'err');
                btn.disabled = false;
            }
        });

        card.querySelector('[data-role=cancelar-orcamento]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const orcId = card.dataset.orcId;
            if (!orcId) {
                toast('Salve o orçamento antes de registrar recusa.', 'err');
                return;
            }
            if (!confirm('Confirmar que o cliente recusou este orçamento?')) return;
            btn.disabled = true;
            try {
                await api('PATCH', '/api/orcamentos/' + orcId, {
                    status: 'cancelado',
                });
                window.location.reload();
            } catch (e) {
                toast('Erro ao registrar recusa: ' + e.message, 'err');
                btn.disabled = false;
            }
        });
    });

    // ── Auto-importação de itens técnicos no carregamento da página ──────────
    // Dispara silenciosamente para cada card elegível (data-autoimport="1").
    // Condição já calculada no PHP: orc=rascunho, sem itens, tem itens técnicos,
    // equip não finalizado. O botão manual continua como fallback.
    cards.forEach(card => {
        if (card.dataset.autoimport !== '1') return;
        const osId     = card.dataset.osId;
        const equipIdx = card.dataset.equipIdx;
        if (!osId || equipIdx === undefined) return;
        importarItensTecnicos(card, osId, equipIdx, true).catch(() => {
            // falha silenciosa no auto-import — o botão manual ainda está disponível
        });
    });

    // ── Autocomplete de produtos nas linhas de item ──────────────────────────
    // Delegação de eventos no card — captura inputs em linhas criadas dinamicamente.

    // IDs de M.O. principal — não podem ser lançados como item de orçamento.
    // A M.O. principal deve ser aplicada via campo mo_valor (tabela M.O.).
    const _MO_PRINCIPAL = new Set([4297, 4298, 4299, 4300, 4301]);

    let _acDrop  = null;  // dropdown DOM único compartilhado
    let _acTimer = null;  // debounce

    function _acDropEl() {
        if (!_acDrop) {
            _acDrop = document.createElement('ul');
            _acDrop.id = 'prod-ac-dropdown';
            _acDrop.setAttribute('role', 'listbox');
            _acDrop.style.cssText =
                'position:absolute;z-index:1060;' +
                'background:var(--bs-body-bg);' +
                'border:1px solid var(--bs-border-color);' +
                'border-radius:.5rem;max-height:260px;overflow-y:auto;' +
                'min-width:280px;list-style:none;padding:.25rem 0;margin:0;' +
                'box-shadow:0 4px 16px rgba(0,0,0,.15);display:none;';
            document.body.appendChild(_acDrop);
        }
        return _acDrop;
    }

    function _acPos(input) {
        const drop = _acDropEl();
        const rect = input.getBoundingClientRect();
        drop.style.top   = (rect.bottom + window.scrollY + 2) + 'px';
        drop.style.left  = (rect.left   + window.scrollX) + 'px';
        drop.style.width = Math.max(300, rect.width) + 'px';
    }

    function _acClose() {
        clearTimeout(_acTimer);
        const drop = _acDropEl();
        drop.style.display = 'none';
        drop.innerHTML = '';
    }

    function _acApply(row, card, prod) {
        row.dataset.produtoId = String(Number(prod.id || 0));
        row.querySelector('input[name=codigo]').value    = prod.codigo    || '';
        row.querySelector('input[name=descricao]').value = prod.descricao || '';
        row.querySelector('input[name=unidade]').value   = prod.unidade   || 'un';

        const vuInput = row.querySelector('[data-role=vu]');
        if (prod.valor_venda !== undefined) {
            vuInput.value = Number(prod.valor_venda).toFixed(2);
            vuInput.style.borderColor = '';
        }

        // Badge Serviço/M.O.
        let badge = row.querySelector('[data-role=servico-badge]');
        if (!parseInt(prod.controla_estoque ?? 1)) {
            if (!badge) {
                badge = document.createElement('span');
                badge.dataset.role  = 'servico-badge';
                badge.className     = 'badge bg-info-subtle text-info-emphasis border border-info-subtle';
                badge.style.cssText = 'font-size:.65rem;display:block;margin-top:.2rem;';
                badge.textContent   = 'Serviço/M.O. — não baixa estoque';
                row.querySelector('input[name=descricao]').insertAdjacentElement('afterend', badge);
            }
        } else {
            if (badge) badge.remove();
        }

        _acClose();
        recalcCard(card);
    }

    function _acShow(input, row, card, produtos) {
        const drop = _acDropEl();
        drop.innerHTML = '';

        if (produtos.length === 0) {
            drop.style.display = 'none';
            return;
        }

        produtos.forEach(prod => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');

            const bloqueadoMO = _MO_PRINCIPAL.has(Number(prod.id));
            const isServico   = !parseInt(prod.controla_estoque ?? 1);

            li.style.cssText =
                'padding:.4rem .75rem;display:flex;' +
                'justify-content:space-between;align-items:center;gap:.5rem;' +
                'border-bottom:1px solid var(--bs-border-color-translucent);' +
                (bloqueadoMO ? 'cursor:not-allowed;opacity:.55;' : 'cursor:pointer;');

            if (bloqueadoMO) {
                li.title = 'M.O. principal — use o campo Mão de Obra (tabela M.O.)';
            }

            // Coluna esquerda: descrição + meta
            const leftDiv  = document.createElement('div');
            leftDiv.style.cssText = 'display:flex;flex-direction:column;gap:.05rem;flex:1;min-width:0;';

            const descSpan = document.createElement('span');
            descSpan.style.cssText = 'font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            descSpan.textContent = prod.descricao;

            const metaParts = [];
            if (prod.codigo)  metaParts.push(prod.codigo);
            if (prod.marca)   metaParts.push(prod.marca);
            if (bloqueadoMO)  metaParts.push('⚠ M.O. — use tabela M.O.');
            else if (isServico) metaParts.push('Serviço/M.O.');

            const metaSpan = document.createElement('span');
            metaSpan.style.cssText = 'font-size:.7rem;color:' +
                (bloqueadoMO ? 'var(--bs-warning-text-emphasis)' : 'var(--bs-secondary-color)') +
                ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            metaSpan.textContent = metaParts.join(' · ');

            leftDiv.appendChild(descSpan);
            if (metaParts.length) leftDiv.appendChild(metaSpan);

            // Coluna direita: preço + estoque (só para não bloqueados)
            const rightDiv = document.createElement('div');
            rightDiv.style.cssText = 'display:flex;flex-direction:column;align-items:flex-end;gap:.05rem;flex-shrink:0;';

            if (!bloqueadoMO && prod.valor_venda !== undefined) {
                const priceSpan = document.createElement('span');
                priceSpan.style.cssText = 'font-size:.8rem;font-family:var(--bs-font-monospace);font-weight:600;';
                priceSpan.textContent = fmtBrl(prod.valor_venda);
                rightDiv.appendChild(priceSpan);
            }

            if (!bloqueadoMO) {
                const estSpan = document.createElement('span');
                estSpan.style.cssText = 'font-size:.68rem;color:var(--bs-secondary-color);';
                estSpan.textContent = 'Est: ' + Number(prod.estoque_qty || 0).toLocaleString('pt-BR');
                rightDiv.appendChild(estSpan);
            }

            li.appendChild(leftDiv);
            li.appendChild(rightDiv);

            li.addEventListener('mousedown', (ev) => {
                ev.preventDefault();
                if (bloqueadoMO) {
                    toast('M.O. principal deve ser aplicada pelo campo Mão de Obra (tabela M.O.), não como item.', 'err');
                    return;
                }
                _acApply(row, card, prod);
            });
            if (!bloqueadoMO) {
                li.addEventListener('mouseenter', () => { li.style.background = 'var(--bs-secondary-bg)'; });
                li.addEventListener('mouseleave', () => { li.style.background = ''; });
            }

            drop.appendChild(li);
        });

        _acPos(input);
        drop.style.display = 'block';
    }

    // Fecha ao clicar fora do dropdown
    document.addEventListener('mousedown', (ev) => {
        if (_acDrop && !_acDrop.contains(ev.target)) _acClose();
    });

    cards.forEach(card => {
        card.addEventListener('input', (ev) => {
            const target = ev.target;
            const row = target.closest('.orc-item-row');
            if (!row) return;

            let mode = null;
            if (target.matches('input[name=codigo]'))         mode = 'codigo';
            else if (target.matches('input[name=descricao]')) mode = 'descricao';
            else return;

            row.dataset.produtoId = '0';

            const q = target.value.trim();
            clearTimeout(_acTimer);

            if (q.length < 2) { _acClose(); return; }

            _acTimer = setTimeout(async () => {
                try {
                    const r = await api('GET',
                        `/api/produtos/busca?q=${encodeURIComponent(q)}&mode=${encodeURIComponent(mode)}&limit=10`
                    );
                    _acShow(target, row, card, r.produtos || []);
                } catch (_) {
                    // autocomplete falha silenciosamente — não interrompe edição manual
                }
            }, 280);
        });

        // Fecha ao sair do input (delay para permitir clique no item do dropdown)
        card.addEventListener('focusout', (ev) => {
            const target = ev.target;
            if (!target.closest('.orc-item-row')) return;
            if (target.matches('input[name=codigo], input[name=descricao]')) {
                setTimeout(() => {
                    if (_acDrop && _acDrop.style.display !== 'none') _acClose();
                }, 150);
            }
        });
    });

    navButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const card = cardById.get(btn.dataset.target);
            setActiveCard(card, { scrollPanel: true });
        });
    });

    window.addEventListener('hashchange', () => {
        const card = getCardFromHash();
        if (card) {
            setActiveCard(card, { updateHash: false, focusNav: true });
        }
    });

    updateStepButtons();
    setActiveCard(getCardFromHash() || cards[0], {
        updateHash: window.location.hash === '',
        focusNav: false,
    });

    // ── Registrar devolução por equipamento (9G-4) ───────────────────────────
    (function initDevolverOrc() {
        const osId = wrap.dataset.osId;
        document.querySelectorAll('[data-role=devolver-equip-orc]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const equipIdx  = parseInt(btn.dataset.equipIdx, 10);
                const equipNome = btn.dataset.equipNome || '';
                if (!confirm(
                    `Confirmar devolução física de "${equipNome}" ao cliente?\n\n` +
                    `Esta ação registra que o equipamento foi devolvido e não pode ser desfeita.`
                )) return;

                btn.disabled = true;
                try {
                    const r = await api('POST', `/api/os/${encodeURIComponent(osId)}/equip/${equipIdx}/devolver`, {});
                    toast('Devolução registrada.');
                    window.location.reload();
                } catch (e) {
                    toast('Erro ao registrar devolução: ' + e.message, 'err');
                    btn.disabled = false;
                }
            });
        });
    }());

    // ── Autorizar descarte por equipamento (9G-4) ────────────────────────────
    (function initAutorizarDescarteOrc() {
        const osId    = wrap.dataset.osId;
        const modalEl = document.getElementById('modalAutorizarDescarteOrc');
        if (!modalEl) return;

        const bsModal = new bootstrap.Modal(modalEl);
        let descarteEquipIdx = -1;

        document.querySelectorAll('[data-role=autorizar-descarte-orc]').forEach(btn => {
            btn.addEventListener('click', () => {
                descarteEquipIdx = parseInt(btn.dataset.equipIdx, 10);
                document.getElementById('descarteOrcEquipNome').textContent = btn.dataset.equipNome || '';
                document.getElementById('inpDescarteOrcAutorizadoPor').value = '';
                document.getElementById('selDescarteOrcMeio').value = '';
                const btnConf = document.getElementById('btnConfirmarAutorizarDescarteOrc');
                btnConf.disabled    = false;
                btnConf.textContent = 'Registrar Autorização';
                bsModal.show();
            });
        });

        document.getElementById('btnConfirmarAutorizarDescarteOrc')?.addEventListener('click', async () => {
            const autorizadoPor = document.getElementById('inpDescarteOrcAutorizadoPor').value.trim();
            const meio          = document.getElementById('selDescarteOrcMeio').value;
            const btnConf       = document.getElementById('btnConfirmarAutorizarDescarteOrc');

            if (!autorizadoPor) {
                toast('Informe o nome de quem autorizou o descarte.', 'err');
                return;
            }
            if (!meio) {
                toast('Selecione o meio pelo qual o descarte foi autorizado.', 'err');
                return;
            }

            btnConf.disabled    = true;
            btnConf.textContent = 'Registrando...';

            try {
                await api(
                    'POST',
                    `/api/os/${encodeURIComponent(osId)}/equip/${descarteEquipIdx}/autorizar-descarte`,
                    { autorizado_por: autorizadoPor, descarte_meio: meio }
                );
                bsModal.hide();
                toast('Autorização de descarte registrada.');
                window.location.reload();
            } catch (e) {
                toast('Erro ao registrar autorização: ' + e.message, 'err');
                btnConf.disabled    = false;
                btnConf.textContent = 'Registrar Autorização';
            }
        });
    }());

    (function initDesfazerRetiradaOrc() {
        const osId = wrap.dataset.osId;
        const modalEl = document.getElementById('modalDesfazerRetiradaEquipOrc');
        if (!modalEl) return;

        const bsModal = new bootstrap.Modal(modalEl);

        document.querySelectorAll('.btn-desfazer-retirada-equip-orc').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('inpDesfazerEquipIdxOrc').value = btn.dataset.equipIdx;
                document.getElementById('txtDesfazerEquipNomeOrc').textContent = btn.dataset.equipNome;
                document.getElementById('inpJustificativaDesfazerOrc').value = '';
                bsModal.show();
            });
        });

        const btnConfirm = document.getElementById('btnConfirmarDesfazerRetiradaOrc');
        if (btnConfirm) {
            btnConfirm.addEventListener('click', async () => {
                const justificativa = document.getElementById('inpJustificativaDesfazerOrc').value.trim();
                if (!justificativa) {
                    alert('A justificativa é obrigatória.');
                    return;
                }

                const equipIdx = document.getElementById('inpDesfazerEquipIdxOrc').value;
                btnConfirm.disabled = true;
                btnConfirm.innerHTML = '<i class="ph ph-spinner ph-spin me-1"></i>Revertendo...';

                try {
                    const res = await fetch(`/api/os/${osId}/equip/${equipIdx}/desfazer-retirada`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': CSRF,
                        },
                        body: JSON.stringify({ justificativa }),
                    });
                    const json = await res.json();

                    if (json.ok) {
                        window.location.reload();
                    } else {
                        alert('Erro ao desfazer retirada: ' + json.error);
                        btnConfirm.disabled = false;
                        btnConfirm.innerHTML = '<i class="ph ph-check-circle me-1"></i>Confirmar Reversão';
                    }
                } catch (err) {
                    console.error(err);
                    alert('Erro ao desfazer retirada.');
                    btnConfirm.disabled = false;
                    btnConfirm.innerHTML = '<i class="ph ph-check-circle me-1"></i>Confirmar Reversão';
                }
            });
        }
    })();

    // ── Retirada por Equipamento (9C-12) ─────────────────────────────────────
    // ── Registrar Pagamento Antecipado (10D-3) ──────────────────────────────
    (function initPagarAntecipado() {
        const osId    = wrap.dataset.osId;
        const modalEl = document.getElementById('modalPagarAntecipadoOrc');
        if (!modalEl) return;

        const bsModal = new bootstrap.Modal(modalEl);
        let equipIdx  = -1;

        document.querySelectorAll('.btn-pagar-antecipado-orc').forEach(btn => {
            btn.addEventListener('click', () => {
                equipIdx = parseInt(btn.dataset.equipIdx, 10);
                document.getElementById('pagarOrcEquipNome').textContent  = btn.dataset.equipNome || '';
                document.getElementById('selFormaPagAntecipadoOrc').value = '';
                const btnConf = document.getElementById('btnConfirmarPagarAntecipadoOrc');
                btnConf.disabled    = false;
                btnConf.textContent = 'Registrar Pagamento';
                bsModal.show();
            });
        });

        document.getElementById('btnConfirmarPagarAntecipadoOrc')?.addEventListener('click', async () => {
            const forma   = document.getElementById('selFormaPagAntecipadoOrc').value;
            const btnConf = document.getElementById('btnConfirmarPagarAntecipadoOrc');

            if (!forma) {
                toast('Selecione a forma de pagamento.', 'err');
                return;
            }

            btnConf.disabled    = true;
            btnConf.textContent = 'Registrando...';

            try {
                await api('POST', `/api/os/${encodeURIComponent(osId)}/equip/${equipIdx}/pagar-antecipado`, {
                    forma_pagamento: forma,
                });
                bsModal.hide();
                toast('Pagamento registrado. Equipamento aguardando retirada.');
                window.location.reload();
            } catch (e) {
                toast('Erro ao registrar pagamento: ' + e.message, 'err');
                btnConf.disabled    = false;
                btnConf.textContent = 'Registrar Pagamento';
            }
        });
    }());

    // Reutiliza o endpoint POST /api/os/{os_id}/equip/{equip_idx}/retirar.
    // A regra de exibição do botão é controlada pelo PHP (status_equip = pronto + orc aprovado).
    // 10D-3: quando data-ja-pago="1", oculta seção de pagamento (não cobra de novo).
    (function initRetiradaOrc() {
        const osId   = wrap.dataset.osId;
        const modalEl = document.getElementById('modalRetiradaEquipOrc');
        if (!modalEl) return;

        const bsModal = new bootstrap.Modal(modalEl);
        let equipIdx  = -1;
        let temValor  = false;
        let jaPago    = false;
        let valTotal  = 0;

        function atualizarResumo() {
            const desc = Math.max(0, parseFloat(document.getElementById('inpDescontoOrc').value) || 0);
            document.getElementById('spanValorOriginalOrc').textContent = fmtBrl(valTotal);
            document.getElementById('spanDescontoOrc').textContent      = fmtBrl(desc);
            document.getElementById('spanValorLiquidoOrc').textContent  = fmtBrl(Math.max(0, valTotal - desc));
        }

        document.querySelectorAll('.btn-retirar-equip-orc').forEach(btn => {
            btn.addEventListener('click', () => {
                equipIdx = parseInt(btn.dataset.equipIdx, 10);
                temValor = btn.dataset.temValor === '1';
                jaPago   = btn.dataset.jaPago   === '1';
                valTotal = parseFloat(btn.dataset.valorTotal || '0');

                document.getElementById('retiradaOrcEquipNome').textContent = btn.dataset.equipNome;
                document.getElementById('inpRetiradoPorOrc').value = '';
                document.getElementById('selFormaPagOrc').value    = '';
                document.getElementById('inpNumPedidoOrc').value   = '';
                document.getElementById('inpDescontoOrc').value    = '0';
                document.getElementById('divNumPedidoOrc').classList.add('d-none');

                // Se já pago antecipadamente: oculta seção financeira.
                const mostrarPagamento = temValor && !jaPago;
                document.getElementById('divResumoOrc').classList.toggle('d-none', !mostrarPagamento);
                document.getElementById('secPagamentoOrc').classList.toggle('d-none', !mostrarPagamento);
                if (mostrarPagamento) atualizarResumo();

                bsModal.show();
            });
        });

        document.getElementById('selFormaPagOrc').addEventListener('change', function () {
            document.getElementById('divNumPedidoOrc').classList.toggle('d-none', this.value !== 'faturado');
        });

        document.getElementById('inpDescontoOrc').addEventListener('input', atualizarResumo);

        document.getElementById('btnConfirmarRetiradaOrc').addEventListener('click', async () => {
            const forma   = document.getElementById('selFormaPagOrc').value;
            const retPor  = document.getElementById('inpRetiradoPorOrc').value.trim();
            const numPed  = document.getElementById('inpNumPedidoOrc').value.trim();
            const desc    = Math.max(0, parseFloat(document.getElementById('inpDescontoOrc').value) || 0);
            const btnConf = document.getElementById('btnConfirmarRetiradaOrc');

            // 10D-3: quando já pago, não exige forma de pagamento.
            if (temValor && !jaPago && !forma) {
                alert('Selecione uma forma de pagamento (este equipamento possui valor a receber).');
                return;
            }
            if (!retPor) {
                alert('Informe o nome de quem está retirando.');
                return;
            }
            if (!jaPago && valTotal > 0 && desc > valTotal) {
                alert('Desconto inválido. Deve ser entre 0 e ' + fmtBrl(valTotal) + '.');
                return;
            }
            if (!jaPago && desc > 0 && !confirm('Aplicar desconto de ' + fmtBrl(desc) + '?\nValor a receber: ' + fmtBrl(Math.max(0, valTotal - desc)))) {
                return;
            }

            const body = { retirado_por: retPor };
            // 10D-3: não envia forma_pagamento quando já pago antecipadamente.
            if (!jaPago && forma) {
                body.forma_pagamento = forma;
                if (forma === 'faturado' && numPed) body.numero_pedido = numPed;
            }
            if (!jaPago && desc > 0) body.desconto_valor = desc;

            btnConf.disabled    = true;
            btnConf.textContent = 'Processando...';

            try {
                await api('POST', `/api/os/${osId}/equip/${equipIdx}/retirar`, body);
                window.location.reload();
            } catch (err) {
                alert('Erro: ' + err.message);
                btnConf.disabled    = false;
                btnConf.textContent = 'Confirmar Retirada';
            }
        });
    }());

    cards.forEach(syncCardSummary);
})();
