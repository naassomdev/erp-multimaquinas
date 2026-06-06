(() => {
    'use strict';

    const wrap = document.querySelector('[data-os-id]');
    if (!wrap) return;

    const osId = wrap.dataset.osId;
    const equipIdx = wrap.dataset.equipIdx;
    const equipNome = (wrap.dataset.equipNome || '').trim();
    const equipSerie = (wrap.dataset.equipSerie || '').trim();
    const equipDefeito = (wrap.dataset.equipDefeito || '').trim();
    const initialStatusEquip = (wrap.dataset.statusEquip || '').trim();
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const CSRF = csrfMeta ? csrfMeta.content : '';
    let diagnosticoConcluido = wrap.dataset.diagnosticoConcluido === '1';
    let conclusaoEmAndamento = false;
    let pendingNavigationUrl = '';

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function toUpperSafe(value) {
        return String(value || '').toUpperCase();
    }

    function pickModelHint() {
        if (equipSerie) return equipSerie;
        const fromName = equipNome.match(/\b[A-Z0-9-]{4,}\b/g);
        if (fromName && fromName.length) return fromName[0];
        return equipNome;
    }

    function pickBrandHint() {
        const haystack = `${equipNome} ${equipDefeito}`.toLowerCase();
        const aliases = [
            ['dewalt', ['dewalt', 'de walt']],
            ['bosch', ['bosch']],
            ['makita', ['makita']],
            ['milwaukee', ['milwaukee']],
            ['black & decker', ['black decker', 'black & decker', 'black+decker']],
            ['skil', ['skil']],
            ['dremel', ['dremel']],
            ['metabo', ['metabo']],
            ['ryobi', ['ryobi']],
            ['hitachi / hikoki', ['hikoki', 'hitachi']],
            ['craftsman', ['craftsman']],
            ['bostitch', ['bostitch']],
            ['porter cable', ['porter cable', 'portercable']],
            ['proto', ['proto']],
        ];

        for (const [label, terms] of aliases) {
            if (terms.some(term => haystack.includes(term))) {
                return label;
            }
        }

        return '';
    }

    // ── Toast ──────────────────────────────────────
    const toastEl = byId('toast');
    let toastTimer = null;

    function toast(msg, kind = 'ok') {
        if (!toastEl) return;
        const toastMsg = byId('toast-msg');
        if (toastMsg) toastMsg.textContent = msg;
        toastEl.className = 'alert alert-' + (kind === 'ok' ? 'success' : 'danger') + ' alert-dismissible position-fixed top-0 end-0 m-3';
        toastEl.style.zIndex = '1070';
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            toastEl.hidden = true;
        }, 3500);
    }

    // ── HTTP helper (JSON) ─────────────────────────
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
        try {
            data = await r.json();
        } catch (_) {
            // noop
        }
        if (!r.ok || !data || data.ok === false) {
            const err = (data && (data.error || data.erro)) || ('HTTP ' + r.status);
            throw new Error(err);
        }
        return data;
    }

    // ── HTTP helper (multipart com progresso) ──────
    function uploadFile(method, path, file, progressEl) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open(method, path);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', CSRF);
            xhr.withCredentials = true;

            if (progressEl) {
                progressEl.hidden = false;
                progressEl.value = 0;
                xhr.upload.addEventListener('progress', ev => {
                    if (ev.lengthComputable) {
                        progressEl.value = Math.round((ev.loaded / ev.total) * 100);
                    }
                });
            }

            xhr.onload = () => {
                if (progressEl) progressEl.hidden = true;
                let data = null;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (_) {
                    // noop
                }
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok !== false) {
                    resolve(data);
                } else {
                    reject(new Error((data && (data.error || data.erro)) || ('HTTP ' + xhr.status)));
                }
            };
            xhr.onerror = () => {
                if (progressEl) progressEl.hidden = true;
                reject(new Error('Erro de rede'));
            };

            const fd = new FormData();
            fd.append('file', file);
            xhr.send(fd);
        });
    }

    // ── Workspace por seções ───────────────────────
    const navButtons = Array.from(document.querySelectorAll('[data-tech-target]'));
    const panels = Array.from(document.querySelectorAll('[data-tech-panel]'));
    const currentLabel = byId('tech-current-label');
    const prevBtn = byId('tech-prev');
    const nextBtn = byId('tech-next');
    const panelOrder = panels.map(panel => panel.dataset.techPanel);
    let activePanel = panelOrder[0] || 'painel';
    const cxAlert = byId('cx-required-alert');

    function setActivePanel(target) {
        if (!panelOrder.includes(target)) return;
        activePanel = target;

        navButtons.forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.techTarget === target);
        });

        panels.forEach(panel => {
            const isActive = panel.dataset.techPanel === target;
            panel.hidden = !isActive;
            panel.classList.toggle('is-active', isActive);
            if (isActive && currentLabel) {
                currentLabel.textContent = panel.dataset.techLabel || 'Área ativa';
            }
        });

        const currentIndex = panelOrder.indexOf(target);
        if (prevBtn) prevBtn.disabled = currentIndex <= 0;
        if (nextBtn) nextBtn.disabled = currentIndex >= panelOrder.length - 1;
    }

    navButtons.forEach(btn => {
        btn.addEventListener('click', () => setActivePanel(btn.dataset.techTarget));
    });

    prevBtn?.addEventListener('click', () => {
        const idx = panelOrder.indexOf(activePanel);
        if (idx > 0) setActivePanel(panelOrder[idx - 1]);
    });

    nextBtn?.addEventListener('click', () => {
        const idx = panelOrder.indexOf(activePanel);
        if (idx < panelOrder.length - 1) setActivePanel(panelOrder[idx + 1]);
    });

    setActivePanel(activePanel);

    // ── Helpers de KPI / badges ────────────────────
    const statusClassMap = {
        aberta: 'info',
        andamento: 'warning',
        montagem: 'brand',
        pronto: 'success',
        retirado: 'neutral',
        cancelado: 'danger',
    };

    function updateStatusUi(status) {
        const variant = statusClassMap[status] || 'neutral';
        ['status-atual', 'status-kpi', 'status-kpi-main', 'status-sticky-mobile'].forEach(id => {
            const el = byId(id);
            if (!el) return;
            el.textContent = status;
            el.className = 'status-badge status-badge--' + variant + (id === 'status-atual' || id === 'status-kpi-main' ? ' fs-6' : '');
        });

        // Atualiza o item ativo na barra lateral de equipamentos
        const activeSidebarBadge = document.querySelector('.tecnico-sidebar-equip-item.border-primary .status-badge');
        if (activeSidebarBadge) {
            activeSidebarBadge.textContent = status;
            activeSidebarBadge.className = 'status-badge status-badge--' + variant + ' ms-2 py-0 px-2';
        }

        // Atualiza a opção no select dropdown móvel
        const mobileSelect = byId('mobile-equip-select');
        if (mobileSelect) {
            const activeOption = mobileSelect.options[mobileSelect.selectedIndex];
            if (activeOption) {
                const text = activeOption.textContent.trim();
                const parts = text.split('(');
                if (parts.length > 1) {
                    parts[parts.length - 1] = status + ')';
                    activeOption.textContent = parts.join('(');
                }
            }
        }
    }

    function updateItemCounts(count) {
        const value = String(count);
        const tableCount = byId('tabela-itens-count');
        const tabCount = byId('tab-pecas-count');
        if (tableCount) tableCount.textContent = value;
        if (tabCount) tabCount.textContent = value;
    }

    function updatePhotoCounts(analysisCount, receptionCount) {
        const tabCount = byId('tab-midias-count');
        if (tabCount) tabCount.textContent = String(Number(analysisCount || 0) + Number(receptionCount || 0));
    }

    function getReceptionPhotoCount() {
        return Number(document.querySelectorAll('#tech-panel-midias .card-header .badge')[1]?.textContent?.match(/\d+/)?.[0] || 0);
    }

    function updateVistaKpi(hasVista) {
        const el = byId('tab-vista-count');
        if (el) el.textContent = hasVista ? '1' : '0';
    }

    function syncCxAlert(forceVisible = null) {
        if (!cxAlert) return;
        const cxValue = (byId('input-cx')?.value || byId('span-cx')?.textContent || '').trim();
        const shouldShow = forceVisible !== null ? forceVisible : (cxValue === '' || cxValue === '—');
        cxAlert.classList.toggle('d-none', !shouldShow);
    }

    function statusAtualEquipamento() {
        return (byId('status-atual')?.textContent || byId('status-kpi-main')?.textContent || initialStatusEquip || '').trim();
    }

    function temConteudoDiagnostico() {
        const itemRows = document.querySelectorAll('#tabela-itens tbody tr[data-item-id]').length;
        const obsInt = (byId('obs-int')?.value || '').trim();
        const obsCli = (byId('obs-cli')?.value || '').trim();
        return itemRows > 0 || obsInt !== '' || obsCli !== '';
    }

    function deveConfirmarConclusaoDiagnostico() {
        return !diagnosticoConcluido
            && !conclusaoEmAndamento
            && statusAtualEquipamento() === 'andamento'
            && temConteudoDiagnostico();
    }

    function setDiagnosticoConcluido() {
        diagnosticoConcluido = true;
        wrap.dataset.diagnosticoConcluido = '1';
        byId('btn-concluir-diagnostico')?.remove();

        if (!byId('diagnostico-concluido-alert')) {
            const alert = document.createElement('div');
            alert.id = 'diagnostico-concluido-alert';
            alert.className = 'alert alert-info d-flex align-items-start gap-2 shadow-sm';
            alert.innerHTML = '<i class="ph ph-clipboard-text fs-5 mt-1"></i><div><strong>Diagnóstico concluído e enviado para a recepção.</strong><div class="small text-body-secondary">Aguardando orçamento, aprovação ou cancelamento administrativo.</div></div>';
            const firstPanel = document.querySelector('.tecnico-main .tecnico-panel');
            if (firstPanel?.parentNode) {
                firstPanel.parentNode.insertBefore(alert, firstPanel);
            }
        }
    }

    function invalidarDiagnosticoConcluido() {
        diagnosticoConcluido = false;
        wrap.dataset.diagnosticoConcluido = '0';
        byId('diagnostico-concluido-alert')?.remove();
    }

    async function concluirDiagnostico() {
        conclusaoEmAndamento = true;
        try {
            const r = await api(
                'POST',
                `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/concluir-diagnostico`,
                {}
            );
            setDiagnosticoConcluido();
            toast(r.message || 'Diagnóstico concluído e enviado para a recepção.');
            return true;
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
            return false;
        } finally {
            conclusaoEmAndamento = false;
        }
    }

    const modalConcluirEl = byId('modal-concluir-diagnostico');
    const modalConcluir = modalConcluirEl && window.bootstrap ? new bootstrap.Modal(modalConcluirEl) : null;
    const btnConfirmarConcluir = byId('btn-confirmar-concluir-diagnostico');

    function abrirModalConclusao(destinoUrl = '') {
        pendingNavigationUrl = destinoUrl;
        if (!modalConcluir) {
            if (window.confirm('Deseja concluir o diagnóstico deste equipamento e enviar para a recepção?')) {
                concluirDiagnostico().then(ok => {
                    if (ok && pendingNavigationUrl) window.location.href = pendingNavigationUrl;
                });
            }
            return;
        }
        if (btnConfirmarConcluir) btnConfirmarConcluir.disabled = false;
        modalConcluir.show();
    }

    byId('btn-concluir-diagnostico')?.addEventListener('click', () => abrirModalConclusao(''));

    btnConfirmarConcluir?.addEventListener('click', async () => {
        btnConfirmarConcluir.disabled = true;
        const ok = await concluirDiagnostico();
        if (!ok) {
            btnConfirmarConcluir.disabled = false;
            return;
        }
        modalConcluir?.hide();
        if (pendingNavigationUrl) {
            window.location.href = pendingNavigationUrl;
        }
    });

    document.addEventListener('click', ev => {
        const link = ev.target.closest('a[href]');
        if (!link || !deveConfirmarConclusaoDiagnostico()) return;
        const href = link.getAttribute('href') || '';
        if (!href || href.startsWith('#') || link.target === '_blank') return;
        if (href.startsWith('/tecnico')) {
            ev.preventDefault();
            abrirModalConclusao(href);
        }
    }, true);

    byId('mobile-equip-select')?.addEventListener('change', ev => {
        const url = ev.currentTarget.value;
        if (!url) return;
        if (deveConfirmarConclusaoDiagnostico()) {
            ev.preventDefault();
            abrirModalConclusao(url);
            return;
        }
        window.location.href = url;
    });

    window.addEventListener('beforeunload', ev => {
        if (!deveConfirmarConclusaoDiagnostico()) return;
        ev.preventDefault();
        ev.returnValue = '';
    });

    // ── Mudar status do equipamento ────────────────
    document.querySelectorAll('.js-mudar-status').forEach(btn => {
        btn.addEventListener('click', async () => {
            const novoStatus = btn.dataset.status || byId('status-target')?.value || '';
            const obsAppend = (byId('obs-append')?.value || '').trim();
            if (!novoStatus) return;

            if (novoStatus !== 'aberta') {
                const cxInput = byId('input-cx');
                const cxValue = (cxInput ? cxInput.value.trim() : '').trim();
                if (!cxValue) {
                    syncCxAlert(true);
                    setActivePanel('painel');
                    return;
                }
            }

            if (!window.confirm(`Mudar status do equipamento para "${novoStatus}"?`)) return;

            btn.disabled = true;
            try {
                const r = await api(
                    'PATCH',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/status`,
                    { status: novoStatus, obs_int_append: obsAppend }
                );

                updateStatusUi(r.status_equip);
                const statusTarget = byId('status-target');
                if (statusTarget) statusTarget.value = r.status_equip;
                const appendInput = byId('obs-append');
                if (appendInput) appendInput.value = '';
                toast(`Status atualizado · OS: ${r.os_status}`);

                if (novoStatus === 'andamento') {
                    try {
                        const check = await api(
                            'POST',
                            `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/verificar-montagem`,
                            {}
                        );
                        if (check.pode_montar) {
                            updateStatusUi(check.status || 'montagem');
                            toast('Equipamento pronto para montagem!', 'ok');
                        }
                    } catch (_) {
                        // ignora erro secundário
                    }
                }
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // ── Remontar equipamento para devolução ───────────
    const btnRemontar = byId('btn-remontar-equipamento');
    if (btnRemontar) {
        btnRemontar.addEventListener('click', async () => {
            if (!window.confirm('Confirmar início da remontagem para devolução?')) return;
            btnRemontar.disabled = true;
            try {
                const r = await api(
                    'PATCH',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/status`,
                    { status: 'montagem', obs_int_append: '' }
                );
                updateStatusUi(r.status_equip);
                const statusTarget = byId('status-target');
                if (statusTarget) statusTarget.value = r.status_equip;
                btnRemontar.closest('div')?.remove();
                // Exibe banner de remontagem para devolução
                let bannerEl = byId('banner-devolucao');
                if (!bannerEl) {
                    bannerEl = document.createElement('div');
                    bannerEl.id = 'banner-devolucao';
                    bannerEl.className = 'alert alert-warning py-2 mb-0 small';
                    bannerEl.innerHTML = '<i class="ph ph-arrow-counter-clockwise me-1"></i> <strong>Remontagem para devolução</strong> — marque como Pronto ao concluir.';
                    const obsInput = byId('obs-append');
                    if (obsInput) obsInput.parentNode.insertBefore(bannerEl, obsInput);
                }
                toast('Remontagem iniciada — marque como Pronto ao concluir.');
                setTimeout(() => {
                    window.location.reload();
                }, 600);
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
                btnRemontar.disabled = false;
            }
        });
    }

    // ── Iniciar montagem (orçamento aprovado + andamento + sem bloqueantes) ───
    const btnIniciarMontagem = byId('btn-iniciar-montagem');
    if (btnIniciarMontagem) {
        btnIniciarMontagem.addEventListener('click', async () => {
            if (!window.confirm('Confirmar início da montagem/conserto?')) return;
            btnIniciarMontagem.disabled = true;
            try {
                const r = await api(
                    'PATCH',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/status`,
                    { status: 'montagem', obs_int_append: '' }
                );
                updateStatusUi(r.status_equip);
                const statusTarget = byId('status-target');
                if (statusTarget) statusTarget.value = r.status_equip;
                byId('banner-iniciar-montagem')?.remove();
                // Exibe banner de montagem em andamento
                let bannerEl = byId('banner-montagem-andamento');
                if (!bannerEl) {
                    bannerEl = document.createElement('div');
                    bannerEl.id = 'banner-montagem-andamento';
                    bannerEl.className = 'alert alert-primary py-2 mb-0 small';
                    bannerEl.innerHTML = '<i class="ph ph-wrench me-1"></i> <strong>Montagem/conserto em andamento.</strong><br><span class="text-body-secondary">Quando finalizar, marque este equipamento como Pronto.</span>';
                    const obsInput = byId('obs-append');
                    if (obsInput) obsInput.parentNode.insertBefore(bannerEl, obsInput);
                }
                toast('Montagem iniciada.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
                btnIniciarMontagem.disabled = false;
            }
        });
    }

    byId('btn-adiar-montagem')?.addEventListener('click', () => {
        byId('banner-iniciar-montagem')?.remove();
    });

    // ── Indicar sem conserto (diagnóstico inviável) ───────────────────────────
    const btnSemConserto = byId('btn-sem-conserto');
    if (btnSemConserto) {
        const modalEl      = byId('modal-sem-conserto');
        const modalBs      = modalEl ? new bootstrap.Modal(modalEl) : null;
        const inputMotivo  = byId('input-motivo-sem-conserto');
        const erroMotivo   = byId('erro-motivo-sem-conserto');
        const btnConfirmar = byId('btn-confirmar-sem-conserto');

        btnSemConserto.addEventListener('click', () => {
            if (!modalBs) return;
            if (inputMotivo)  inputMotivo.value = '';
            if (erroMotivo)   erroMotivo.classList.add('d-none');
            if (btnConfirmar) btnConfirmar.disabled = false;
            modalBs.show();
        });

        inputMotivo?.addEventListener('input', () => {
            erroMotivo?.classList.add('d-none');
        });

        btnConfirmar?.addEventListener('click', async () => {
            const motivo = (inputMotivo?.value || '').trim();
            if (motivo.length < 10) {
                erroMotivo?.classList.remove('d-none');
                inputMotivo?.focus();
                return;
            }
            btnConfirmar.disabled = true;
            try {
                const r = await api(
                    'PATCH',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/status`,
                    { status: 'cancelado', obs_int_append: `Sem conserto viável: ${motivo}` }
                );
                modalBs.hide();
                updateStatusUi(r.status_equip);
                const statusTarget = byId('status-target');
                if (statusTarget) statusTarget.value = r.status_equip;
                btnSemConserto.closest('div')?.remove();
                toast('Equipamento indicado sem conserto. Motivo registrado no laudo.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
                btnConfirmar.disabled = false;
            }
        });
    }

    // ── Serviço terceirizado / recondicionamento ────────────────────────────
    const modalServicoTerceiroEl = byId('modal-servico-terceiro');
    const modalServicoTerceiro = modalServicoTerceiroEl && window.bootstrap
        ? new bootstrap.Modal(modalServicoTerceiroEl)
        : null;
    const modalRetornoTerceiroEl = byId('modal-servico-terceiro-retorno');
    const modalRetornoTerceiro = modalRetornoTerceiroEl && window.bootstrap
        ? new bootstrap.Modal(modalRetornoTerceiroEl)
        : null;
    const btnServicoTerceiro = byId('btn-servico-terceiro');
    const btnSalvarServicoTerceiro = byId('btn-salvar-servico-terceiro');
    const btnConfirmarRetornoTerceiro = byId('btn-confirmar-servico-terceiro-retorno');

    function reloadDepoisDoFeedback() {
        setTimeout(() => {
            window.location.reload();
        }, 600);
    }

    btnServicoTerceiro?.addEventListener('click', () => {
        if (!modalServicoTerceiro) return;
        const tipo = byId('servico-terceiro-tipo');
        const item = byId('servico-terceiro-item');
        const fornecedor = byId('servico-terceiro-fornecedor');
        const saida = byId('servico-terceiro-saida');
        const previsao = byId('servico-terceiro-previsao');
        const observacao = byId('servico-terceiro-observacao');
        if (tipo) tipo.value = 'rebobinamento';
        if (item) item.value = '';
        if (fornecedor) fornecedor.value = '';
        if (saida) saida.value = '';
        if (previsao) previsao.value = '';
        if (observacao) observacao.value = '';
        if (btnSalvarServicoTerceiro) btnSalvarServicoTerceiro.disabled = false;
        modalServicoTerceiro.show();
    });

    btnSalvarServicoTerceiro?.addEventListener('click', async () => {
        const payload = {
            tipo: byId('servico-terceiro-tipo')?.value || 'rebobinamento',
            tecnico_item_id: byId('servico-terceiro-item')?.value || '',
            fornecedor_nome: (byId('servico-terceiro-fornecedor')?.value || '').trim(),
            saida_em: byId('servico-terceiro-saida')?.value || '',
            previsao_retorno: byId('servico-terceiro-previsao')?.value || '',
            observacao: (byId('servico-terceiro-observacao')?.value || '').trim(),
        };

        btnSalvarServicoTerceiro.disabled = true;
        try {
            await api(
                'POST',
                `/api/tecnico/os/${encodeURIComponent(osId)}/equipamentos/${equipIdx}/servicos-terceiros`,
                payload
            );
            modalServicoTerceiro?.hide();
            toast('Serviço terceirizado registrado.');
            reloadDepoisDoFeedback();
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
            btnSalvarServicoTerceiro.disabled = false;
        }
    });

    document.addEventListener('click', ev => {
        const btnRetorno = ev.target.closest('.js-servico-terceiro-retorno');
        if (btnRetorno) {
            const id = btnRetorno.dataset.id || '';
            const inputId = byId('servico-terceiro-retorno-id');
            const obs = byId('servico-terceiro-retorno-observacao');
            if (inputId) inputId.value = id;
            if (obs) obs.value = '';
            if (btnConfirmarRetornoTerceiro) btnConfirmarRetornoTerceiro.disabled = false;
            modalRetornoTerceiro?.show();
            return;
        }

        const btnCancelar = ev.target.closest('.js-servico-terceiro-cancelar');
        if (!btnCancelar) return;
        const id = btnCancelar.dataset.id || '';
        if (!id || !window.confirm('Cancelar este serviço terceirizado?')) return;
        btnCancelar.disabled = true;
        api('PATCH', `/api/tecnico/servicos-terceiros/${encodeURIComponent(id)}/cancelar`, {})
            .then(() => {
                toast('Serviço terceirizado cancelado.');
                reloadDepoisDoFeedback();
            })
            .catch(e => {
                toast('Erro: ' + e.message, 'err');
                btnCancelar.disabled = false;
            });
    });

    btnConfirmarRetornoTerceiro?.addEventListener('click', async () => {
        const id = byId('servico-terceiro-retorno-id')?.value || '';
        if (!id) return;
        btnConfirmarRetornoTerceiro.disabled = true;
        try {
            await api(
                'PATCH',
                `/api/tecnico/servicos-terceiros/${encodeURIComponent(id)}/retorno`,
                { observacao_retorno: (byId('servico-terceiro-retorno-observacao')?.value || '').trim() }
            );
            modalRetornoTerceiro?.hide();
            toast('Retorno do serviço terceirizado registrado.');
            reloadDepoisDoFeedback();
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
            btnConfirmarRetornoTerceiro.disabled = false;
        }
    });

    // ── Marcar como pronto (conserto aprovado em montagem) ────────────────────
    const btnMarcarPronto = byId('btn-marcar-pronto');
    if (btnMarcarPronto) {
        btnMarcarPronto.addEventListener('click', async () => {
            if (!window.confirm('Confirmar que o conserto foi concluído?')) return;
            btnMarcarPronto.disabled = true;
            try {
                const r = await api(
                    'PATCH',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/status`,
                    { status: 'pronto', obs_int_append: '' }
                );
                updateStatusUi(r.status_equip);
                const statusTarget = byId('status-target');
                if (statusTarget) statusTarget.value = r.status_equip;
                byId('banner-montagem-andamento')?.remove();
                toast('Equipamento marcado como pronto.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
                btnMarcarPronto.disabled = false;
            }
        });
    }

    // ── Marcar como pronto para devolução (remontagem cancelada concluída) ────
    const btnMarcarProntoDev = byId('btn-marcar-pronto-devolucao');
    if (btnMarcarProntoDev) {
        btnMarcarProntoDev.addEventListener('click', async () => {
            if (!window.confirm('Confirmar que a remontagem para devolução foi concluída?')) return;
            btnMarcarProntoDev.disabled = true;
            try {
                const r = await api(
                    'PATCH',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/status`,
                    { status: 'pronto', obs_int_append: '' }
                );
                updateStatusUi(r.status_equip);
                const statusTarget = byId('status-target');
                if (statusTarget) statusTarget.value = r.status_equip;
                byId('banner-devolucao')?.remove();
                // Exibe banner de aguardando devolução pela recepção
                let bannerAguardEl = byId('banner-aguard-devolucao');
                if (!bannerAguardEl) {
                    bannerAguardEl = document.createElement('div');
                    bannerAguardEl.id = 'banner-aguard-devolucao';
                    bannerAguardEl.className = 'alert alert-info py-2 mb-0 small';
                    bannerAguardEl.innerHTML = '<i class="ph ph-check-circle me-1"></i> <strong>Remontagem concluída</strong> — aguardando devolução pela recepção.';
                    const obsInput = byId('obs-append');
                    if (obsInput) obsInput.parentNode.insertBefore(bannerAguardEl, obsInput);
                }
                toast('Equipamento pronto para devolução.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
                btnMarcarProntoDev.disabled = false;
            }
        });
    }

    // ── Editar nome do equipamento ─────────────────
    const btnEditarNome = byId('btn-editar-nome');
    const btnSalvarNome = byId('btn-salvar-nome');
    const btnCancelarNome = byId('btn-cancelar-nome');
    const viewNomeWrap = byId('view-nome');
    const editNomeWrap = byId('edit-nome');
    const inputNomeEquip = byId('input-nome-equip');
    const spanNomeEquip = byId('span-nome-equip');

    if (btnEditarNome && btnSalvarNome && btnCancelarNome && viewNomeWrap && editNomeWrap && inputNomeEquip && spanNomeEquip) {
        btnEditarNome.addEventListener('click', () => {
            viewNomeWrap.hidden = true;
            editNomeWrap.hidden = false;
            inputNomeEquip.focus();
        });

        btnCancelarNome.addEventListener('click', () => {
            viewNomeWrap.hidden = false;
            editNomeWrap.hidden = true;
            inputNomeEquip.value = spanNomeEquip.textContent;
        });

        inputNomeEquip.addEventListener('input', () => {
            inputNomeEquip.value = toUpperSafe(inputNomeEquip.value);
        });

        btnSalvarNome.addEventListener('click', async () => {
            const novoNome = inputNomeEquip.value.trim().toUpperCase();
            if (!novoNome) return;

            btnSalvarNome.disabled = true;
            try {
                const r = await api('PATCH', `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/nome`, { nome: novoNome });
                spanNomeEquip.textContent = r.nome;
                const sidebarTitle = document.querySelector('.tecnico-sidebar__title');
                if (sidebarTitle) sidebarTitle.textContent = r.nome;
                const headerTitle = document.querySelector('.page-header__title');
                const activeRailName = document.querySelector('.tecnico-equip-rail__item.is-active .tecnico-equip-rail__name');
                if (headerTitle) {
                    const small = headerTitle.querySelector('small');
                    headerTitle.textContent = r.nome;
                    if (small) headerTitle.append(' ', small);
                }
                if (activeRailName) activeRailName.textContent = r.nome;

                // Atualiza o item ativo na barra lateral de equipamentos
                const activeSidebarItemName = document.querySelector('.tecnico-sidebar-equip-item.border-primary span.text-truncate');
                if (activeSidebarItemName) {
                    activeSidebarItemName.textContent = `${Number(equipIdx) + 1}. ${r.nome}`;
                }

                // Atualiza a opção ativa no select dropdown móvel
                const mobileSelect = byId('mobile-equip-select');
                if (mobileSelect) {
                    const activeOption = mobileSelect.options[mobileSelect.selectedIndex];
                    if (activeOption) {
                        const statusName = byId('status-atual')?.textContent || '';
                        activeOption.textContent = `${Number(equipIdx) + 1} de ${mobileSelect.options.length}: ${r.nome} (${statusName})`;
                    }
                }

                viewNomeWrap.hidden = false;
                editNomeWrap.hidden = true;
                toast('Nome atualizado');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
            } finally {
                btnSalvarNome.disabled = false;
            }
        });
    }

    // ── Editar série, voltagem, caixa ─────────────
    function setupFieldEditor(fieldName, fieldBaseName) {
        const viewDiv = byId(`view-${fieldBaseName}`);
        const editDiv = byId(`edit-${fieldBaseName}`);
        const btnEdit = byId(`btn-editar-${fieldBaseName}`);
        const btnSave = byId(`btn-salvar-${fieldBaseName}`);
        const btnCancel = byId(`btn-cancelar-${fieldBaseName}`);
        const input = byId(`input-${fieldBaseName}`);
        const span = byId(`span-${fieldBaseName}`);

        if (!btnEdit || !input || !viewDiv || !editDiv || !btnSave || !btnCancel || !span) return;

        btnEdit.addEventListener('click', e => {
            e.preventDefault();
            viewDiv.hidden = true;
            editDiv.hidden = false;
            input.focus();
        });

        btnCancel.addEventListener('click', e => {
            e.preventDefault();
            viewDiv.hidden = false;
            editDiv.hidden = true;
            input.value = span.textContent.trim() === '—' ? '' : span.textContent.trim();
        });

        input.addEventListener('input', () => {
            input.value = toUpperSafe(input.value);
        });

        btnSave.addEventListener('click', async e => {
            e.preventDefault();
            const valor = input.value.trim().toUpperCase();
            if (!valor && fieldName !== 'cx') return;

            btnSave.disabled = true;
            try {
                const payload = {};
                payload[fieldName] = valor;
                const r = await api('PATCH', `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/dados`, payload);
                span.textContent = r[fieldName] || '—';
                if (fieldName === 'cx') {
                    const focusCx = byId('focus-cx-main');
                    const activeRailMeta = document.querySelector('.tecnico-equip-rail__item.is-active .tecnico-equip-rail__meta');
                    if (focusCx) focusCx.textContent = r[fieldName] || 'Pendente';
                    if (activeRailMeta) {
                        const textMono = activeRailMeta.querySelector('.text-mono');
                        const parts = [];
                        if (textMono) parts.push(textMono.outerHTML);
                        if (r[fieldName]) parts.push(`<span>Caixa ${escapeHtml(r[fieldName])}</span>`);
                        activeRailMeta.innerHTML = parts.join('');
                    }
                    syncCxAlert();
                }
                viewDiv.hidden = false;
                editDiv.hidden = true;
                toast(`${fieldName.charAt(0).toUpperCase() + fieldName.slice(1)} atualizado`);
            } catch (err) {
                toast('Erro: ' + err.message, 'err');
            } finally {
                btnSave.disabled = false;
            }
        });
    }

    setupFieldEditor('serie', 'serie');
    setupFieldEditor('voltagem', 'voltagem');
    setupFieldEditor('cx', 'cx');

    // ── Salvar laudo ───────────────────────────────
    byId('btn-salvar-laudo')?.addEventListener('click', async ev => {
        const btn = ev.currentTarget;
        const obsInt = byId('obs-int')?.value || '';
        const obsCli = byId('obs-cli')?.value || '';

        btn.disabled = true;
        try {
            await api(
                'PUT',
                `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/laudo`,
                { obs_int: obsInt, obs_cli: obsCli }
            );
            invalidarDiagnosticoConcluido();
            toast('Laudo salvo');
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
        } finally {
            btn.disabled = false;
        }
    });

    // ── Adicionar / remover item ──────────────────
    const formNovoItem = byId('form-novo-item');

    formNovoItem?.addEventListener('submit', async ev => {
        ev.preventDefault();
        const fd = new FormData(formNovoItem);
        const payload = {
            codigo: (fd.get('codigo') || '').toString().trim(),
            descricao: (fd.get('descricao') || '').toString().trim(),
            produto_id: Number(fd.get('produto_id') || 0),
            qtd: Number(fd.get('qtd') || 1),
            valor_unit: 0,
        };

        if (!payload.descricao) {
            toast('Descrição obrigatória', 'err');
            return;
        }

        const submitBtn = formNovoItem.querySelector('button[type=submit]');
        if (submitBtn) submitBtn.disabled = true;
        try {
            const r = await api(
                'POST',
                `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/itens`,
                payload
            );
            renderItens(r.itens);
            invalidarDiagnosticoConcluido();
            hideAutocomplete();
            formNovoItem.reset();
            formNovoItem.querySelector('input[name=qtd]').value = 1;
            formNovoItem.querySelector('input[name=valor_unit]').value = 0;
            formNovoItem.querySelector('input[name=produto_id]').value = '';
            toast('Item adicionado');
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    byId('tabela-itens')?.addEventListener('click', async ev => {
        const compraBtn = ev.target.closest('.js-solicitar-compra');
        if (compraBtn) {
            const id = compraBtn.dataset.id;
            if (!id) return;

            compraBtn.disabled = true;
            try {
                const r = await api(
                    'POST',
                    `/api/tecnico/itens/${encodeURIComponent(id)}/solicitar-compra`,
                    {}
                );
                const data = await api('GET', `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}`);
                renderItens(data.itens);
                toast(r.message || 'Solicitação de compra registrada.');
            } catch (e) {
                toast('Erro: ' + e.message, 'err');
                compraBtn.disabled = false;
            }
            return;
        }

        const btn = ev.target.closest('.js-remover-item');
        if (!btn) return;
        const id = btn.dataset.id;
        if (!window.confirm('Remover este item?')) return;

        btn.disabled = true;
        try {
            await api('DELETE', `/api/tecnico/itens/${encodeURIComponent(id)}`);
            const data = await api('GET', `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}`);
            renderItens(data.itens);
            invalidarDiagnosticoConcluido();
            toast('Item removido');
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
            btn.disabled = false;
        }
    });

    function itemCompravel(it) {
        const produtoId = Number(it?.produto_id || 0);
        const controla = it?.controla_estoque;
        return produtoId <= 0 || controla === null || controla === undefined || Number(controla) !== 0;
    }

    function compraCell(it) {
        const nc = it?.necessidade_compra || null;
        const status = nc?.status || '';
        const labels = {
            pendente: ['Compra solicitada', 'bg-warning-subtle text-warning-emphasis border-warning-subtle'],
            comprado: ['Comprado', 'bg-success-subtle text-success-emphasis border-success-subtle'],
            cancelado: ['Compra cancelada', 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'],
        };
        const badge = labels[status]
            ? `<span class="badge border ${labels[status][1]}">${escapeHtml(labels[status][0])}</span>`
            : '';
        const podeSolicitar = itemCompravel(it) && status !== 'pendente' && status !== 'comprado';
        const button = podeSolicitar
            ? `<button type="button" class="btn btn-sm btn-outline-warning js-solicitar-compra" data-id="${escapeHtml(it.id)}">
                    <i class="ph ph-shopping-cart-simple me-1"></i> Solicitar compra
               </button>`
            : '';

        return `<div class="d-flex flex-wrap align-items-center gap-1">${badge}${button}</div>`;
    }

    function renderItens(itens) {
        const tbody = document.querySelector('#tabela-itens tbody');
        if (!tbody) return;

        if (!Array.isArray(itens) || itens.length === 0) {
            tbody.innerHTML = '<tr class="empty-row"><td colspan="5" class="text-body-secondary text-center py-3">Nenhum item adicionado.</td></tr>';
            updateItemCounts(0);
        } else {
            tbody.innerHTML = itens.map(it => `
                <tr data-item-id="${escapeHtml(it.id)}">
                    <td class="text-mono">${escapeHtml(it.codigo || '')}</td>
                    <td>${escapeHtml(it.descricao)}</td>
                    <td>${escapeHtml(it.qtd)}</td>
                    <td>${compraCell(it)}</td>
                    <td>
                        <button class="btn-icon text-danger js-remover-item" data-id="${escapeHtml(it.id)}" title="Remover">
                            <i class="ph ph-x"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
            updateItemCounts(itens.length);
        }
    }

    // ── Autocomplete de produtos ───────────────────
    const inputDesc = byId('input-descricao');
    const inputCodigo = byId('input-codigo');
    const inputProdutoId = formNovoItem?.querySelector('input[name=produto_id]');
    const inputVU = formNovoItem?.querySelector('input[name=valor_unit]');
    const acList = byId('autocomplete-results');
    const wrapCodigo = byId('wrap-codigo');
    const wrapDescricao = byId('wrap-descricao');

    // IDs de M.O. principal — não podem ser lançados como item técnico.
    // A M.O. principal deve ser aplicada no orçamento via tabela M.O. (campo mo_valor).
    const MO_PRINCIPAL_IDS = new Set([4297, 4298, 4299, 4300, 4301]);

    let acTimer = null;
    let acAbort = null;

    function positionDropdown(input) {
        const wrapper = input === inputCodigo ? wrapCodigo : wrapDescricao;
        if (!wrapper || !acList) return;
        if (acList.parentNode !== wrapper) {
            wrapper.appendChild(acList);
        }
        wrapCodigo?.classList.remove('show');
        wrapDescricao?.classList.remove('show');
        acList.classList.remove('show');
        wrapper.classList.add('show');
        acList.classList.add('show');
    }

    function clearProdutoSelecionado() {
        if (inputProdutoId) inputProdutoId.value = '';
        if (inputVU) inputVU.value = '0';
    }

    function hideAutocomplete() {
        wrapCodigo?.classList.remove('show');
        wrapDescricao?.classList.remove('show');
        acList?.classList.remove('show');
        if (acList) acList.innerHTML = '';
    }

    inputCodigo?.addEventListener('input', () => {
        inputCodigo.value = toUpperSafe(inputCodigo.value);
        clearProdutoSelecionado();
        clearTimeout(acTimer);
        const q = inputCodigo.value.trim();
        if (q.length < 2) {
            hideAutocomplete();
            return;
        }
        acTimer = setTimeout(() => buscarProdutos(q, 'codigo', inputCodigo), 280);
    });

    inputDesc?.addEventListener('input', () => {
        inputDesc.value = toUpperSafe(inputDesc.value);
        clearProdutoSelecionado();
        clearTimeout(acTimer);
        const q = inputDesc.value.trim();
        if (q.length < 2) {
            hideAutocomplete();
            return;
        }
        acTimer = setTimeout(() => buscarProdutos(q, 'descricao', inputDesc), 280);
    });

    document.addEventListener('click', ev => {
        if (ev.target !== inputCodigo && ev.target !== inputDesc && !acList?.contains(ev.target)) {
            hideAutocomplete();
        }
    });

    async function buscarProdutos(q, mode, triggerInput) {
        if (acAbort) acAbort.abort();
        acAbort = new AbortController();

        if (!acList) return;
        acList.innerHTML = '<li class="ac-empty"><span class="spinner-border spinner-border-sm me-1"></span>Buscando...</li>';
        positionDropdown(triggerInput);

        try {
            const params = new URLSearchParams({ q, mode, context: 'tecnico' });
            const r = await fetch(`/api/produtos/busca?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: acAbort.signal,
            });
            const data = await r.json();
            if (!data.ok) {
                hideAutocomplete();
                return;
            }
            renderAutocomplete(data.produtos || [], triggerInput);
        } catch (e) {
            if (e.name !== 'AbortError') console.warn('autocomplete:', e);
            hideAutocomplete();
        }
    }

    function renderAutocomplete(produtos, triggerInput) {
        if (!acList) return;
        if (!produtos.length) {
            acList.innerHTML = '<li class="ac-empty">Nenhum produto encontrado no estoque</li>';
            positionDropdown(triggerInput);
            return;
        }

        acList.innerHTML = produtos.map(p => {
            const bloqueado   = MO_PRINCIPAL_IDS.has(Number(p.id));
            const estoqueSpan = Number(p.estoque_qty) > 0
                ? `<span class="ac-stock ac-stock-ok">est: ${Number(p.estoque_qty).toLocaleString('pt-BR')}</span>`
                : '<span class="ac-stock ac-stock-zero">sem estoque</span>';

            if (bloqueado) {
                return `
                    <li class="ac-item ac-item--bloqueado"
                        data-bloqueado="1"
                        data-id="${escapeHtml(p.id)}"
                        title="M.O. principal — use o campo Mão de Obra no orçamento"
                        style="opacity:.55;cursor:not-allowed;">
                        <div>
                            <strong>${escapeHtml(p.descricao)}</strong>
                            <span class="ac-marca" style="color:var(--bs-warning-text-emphasis);">⚠ M.O. — use tabela M.O.</span>
                        </div>
                        <div class="ac-meta">
                            <span class="text-mono">${escapeHtml(p.codigo)}</span>
                        </div>
                    </li>`;
            }

            // Fase 4: item encontrado por código ANTIGO/alternativo.
            const viaLabel = {
                antigo: 'código antigo',
                fornecedor: 'código do fornecedor',
                fabricante: 'código do fabricante',
                outro: 'código alternativo',
            };
            const viaAviso = p.via_codigo_alternativo
                ? `<div class="ac-via-antigo" style="color:var(--bs-warning-text-emphasis);font-size:.78rem;margin-top:.15rem;">
                       <i class="ph ph-arrows-merge"></i> ${viaLabel[p.via_codigo_tipo] || 'código alternativo'}
                       <span class="text-mono">${escapeHtml(p.via_codigo_alternativo)}</span>
                       → atual <span class="text-mono">${escapeHtml(p.codigo)}</span>
                   </div>`
                : '';

            return `
                <li class="ac-item${p.via_codigo_alternativo ? ' ac-item--via-antigo' : ''}"
                    data-id="${escapeHtml(p.id)}"
                    data-codigo="${escapeHtml(p.codigo)}"
                    data-descricao="${escapeHtml(p.descricao)}">
                    <div>
                        <strong>${escapeHtml(p.descricao)}</strong>
                        ${p.marca ? `<span class="ac-marca">${escapeHtml(p.marca)}</span>` : ''}
                        ${viaAviso}
                    </div>
                    <div class="ac-meta">
                        <span class="text-mono">${escapeHtml(p.codigo)}</span>
                        ${estoqueSpan}
                    </div>
                </li>`;
        }).join('');

        positionDropdown(triggerInput);
    }

    acList?.addEventListener('mousedown', ev => {
        const item = ev.target.closest('.ac-item');
        if (!item || !formNovoItem) return;
        ev.preventDefault();

        if (item.dataset.bloqueado) {
            toast('M.O. principal deve ser aplicada no orçamento pela tabela M.O., não como item técnico.', 'err');
            return;
        }

        if (inputCodigo) inputCodigo.value = item.dataset.codigo || '';
        if (inputDesc) inputDesc.value = item.dataset.descricao || '';
        if (inputProdutoId) inputProdutoId.value = item.dataset.id || '';
        if (inputVU) inputVU.value = '0';

        hideAutocomplete();

        const qtdInput = formNovoItem.querySelector('input[name=qtd]');
        if (qtdInput) {
            qtdInput.select();
            qtdInput.focus();
        }
    });

    // ── Upload de fotos ────────────────────────────
    const inputFotos = byId('input-fotos');
    const progFotos = byId('prog-fotos');
    const fotosGrid = byId('fotos-grid');
    const fotosCount = byId('fotos-count');

    inputFotos?.addEventListener('change', async () => {
        const files = Array.from(inputFotos.files || []);
        if (!files.length) return;

        let added = 0;
        for (const file of files) {
            try {
                const r = await uploadFile(
                    'POST',
                    `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/fotos`,
                    file,
                    progFotos
                );
                appendFoto(r.url);
                if (Array.isArray(r.fotos)) updateFotosCount(r.fotos.length);
                added++;
            } catch (e) {
                toast(`${file.name}: ${e.message}`, 'err');
            }
        }
        inputFotos.value = '';
        if (added > 0) toast(`${added} foto(s) adicionada(s)`);
    });

    fotosGrid?.addEventListener('click', async ev => {
        const btn = ev.target.closest('.js-remover-foto');
        if (!btn) return;
        const url = btn.dataset.url;
        if (!window.confirm('Remover esta foto?')) return;

        btn.disabled = true;
        try {
            const r = await api(
                'DELETE',
                `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/fotos`,
                { url }
            );
            const item = btn.closest('.foto-item');
            if (item) item.remove();
            const remaining = Array.isArray(r.fotos) ? r.fotos.length : 0;
            updateFotosCount(remaining);
            if (remaining === 0) showFotosEmpty();
            toast('Foto removida');
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
            btn.disabled = false;
        }
    });

    function appendFoto(url) {
        if (!fotosGrid) return;
        byId('fotos-empty')?.remove();
        const item = document.createElement('div');
        item.className = 'col-6 col-sm-4 col-md-3 foto-item';
        item.dataset.url = url;
        item.innerHTML = `
            <div class="position-relative">
                <a href="${escapeHtml(url)}" target="_blank" rel="noopener">
                    <img src="${escapeHtml(url)}" alt="Foto da análise" loading="lazy" class="img-fluid rounded border tecnico-media-thumb">
                </a>
                <button class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 js-remover-foto" data-url="${escapeHtml(url)}" title="Remover" style="line-height:1;padding:2px 6px">
                    <i class="ph ph-x"></i>
                </button>
            </div>
        `;
        fotosGrid.appendChild(item);
    }

    function showFotosEmpty() {
        if (!fotosGrid || byId('fotos-empty')) return;
        const p = document.createElement('p');
        p.id = 'fotos-empty';
        p.className = 'text-body-secondary small mb-0';
        p.textContent = 'Nenhuma foto enviada.';
        fotosGrid.appendChild(p);
    }

    function updateFotosCount(n) {
        if (fotosCount) fotosCount.textContent = `${n} arquivo(s)`;
        updatePhotoCounts(n, getReceptionPhotoCount());
    }

    // ── Vista explodida: upload / remoção ──────────
    const inputVista = byId('input-vista');
    const progVista = byId('prog-vista');
    const vistaCurrent = byId('vista-current');

    inputVista?.addEventListener('change', async () => {
        const file = inputVista.files && inputVista.files[0];
        if (!file) return;

        try {
            const r = await uploadFile(
                'POST',
                `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/vista`,
                file,
                progVista
            );
            renderVista(r.url);
            toast('Vista enviada');
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
        } finally {
            inputVista.value = '';
        }
    });

    document.addEventListener('click', async ev => {
        const btn = ev.target.closest('#btn-remover-vista');
        if (!btn) return;
        if (!window.confirm('Remover a vista atual?')) return;
        btn.disabled = true;
        try {
            await api(
                'DELETE',
                `/api/tecnico/equipamento/${encodeURIComponent(osId)}/${equipIdx}/vista`
            );
            renderVista('');
            toast('Vista removida');
        } catch (e) {
            toast('Erro: ' + e.message, 'err');
            btn.disabled = false;
        }
    });

    function renderVista(url) {
        if (!vistaCurrent) return;
        if (url) {
            vistaCurrent.innerHTML = `
                <div class="tecnico-vista-current__filled">
                    <span class="tecnico-chip tecnico-chip--success">Vinculada</span>
                    <a href="${escapeHtml(url)}" target="_blank" rel="noopener" class="fw-medium">
                        <i class="ph ph-arrow-square-out me-1"></i> Abrir vista atual
                    </a>
                    <button id="btn-remover-vista" class="btn btn-sm btn-outline-danger">Remover</button>
                </div>
            `;
            updateVistaKpi(true);
        } else {
            vistaCurrent.innerHTML = `
                <div class="tecnico-vista-current__empty">
                    <span class="tecnico-chip">Sem arquivo</span>
                    <p class="text-body-secondary small mb-0">Busque em uma fonte do catálogo ou envie manualmente o PDF correto.</p>
                </div>
            `;
            updateVistaKpi(false);
        }
    }

    // ── Vista explodida: catálogo dinâmico ────────
    const sourceDataEl = byId('catalog-fontes-data');
    let catalogSources = [];
    try {
        catalogSources = JSON.parse(sourceDataEl?.textContent || '[]');
    } catch (_) {
        catalogSources = [];
    }

    const sourceSelect = byId('cat-fonte');
    const sourceControls = byId('cat-controls');
    const sourceSummary = byId('cat-source-summary');
    const sourceHelp = byId('cat-source-help');
    const sourcePills = byId('cat-source-pills');
    const btnPrefill = byId('btn-cat-prefill');
    const searchInput = byId('cat-busca');
    const searchLabel = byId('cat-busca-label');
    const btnBuscar = byId('btn-cat-buscar');
    const results = byId('cat-results');

    function currentSource() {
        return catalogSources.find(source => source.id === sourceSelect?.value) || null;
    }

    function renderSourceOptions() {
        if (!sourceSelect) return;
        if (!catalogSources.length) {
            sourceSelect.innerHTML = '<option value="">Nenhuma fonte ativa</option>';
            return;
        }
        sourceSelect.innerHTML = catalogSources.map(source => `
            <option value="${escapeHtml(source.id)}">${escapeHtml(source.label)}</option>
        `).join('');
    }

    function renderSourcePills() {
        if (!sourcePills) return;
        const pills = [];
        if (equipNome) pills.push({ label: equipNome, value: equipNome });
        if (pickModelHint() && pickModelHint() !== equipNome) pills.push({ label: `Modelo: ${pickModelHint()}`, value: pickModelHint() });
        if (equipSerie && equipSerie !== pickModelHint()) pills.push({ label: `Série: ${equipSerie}`, value: equipSerie });

        if (!pills.length) {
            sourcePills.innerHTML = '';
            return;
        }

        sourcePills.innerHTML = pills.map(pill => `
            <button type="button" class="tecnico-pill-action" data-value="${escapeHtml(pill.value)}">${escapeHtml(pill.label)}</button>
        `).join('');

        sourcePills.querySelectorAll('[data-value]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (searchInput) searchInput.value = btn.dataset.value || '';
            });
        });
    }

    function buildSelect(id, label, options, valueKey = 'value') {
        return `
            <div class="col-lg-4">
                <label class="form-label small">${escapeHtml(label)}</label>
                <select id="${escapeHtml(id)}" class="form-select">
                    ${options.map(option => `
                        <option value="${escapeHtml(option[valueKey])}">${escapeHtml(option.label)}</option>
                    `).join('')}
                </select>
            </div>
        `;
    }

    function syncSourceUi() {
        const source = currentSource();
        if (!source || !sourceControls || !searchInput || !searchLabel || !sourceSummary || !sourceHelp) return;

        searchLabel.textContent = source.search_label || 'Modelo / busca';
        searchInput.placeholder = source.search_placeholder || 'Digite o modelo...';
        sourceSummary.textContent = source.description || 'Use a fonte e refine pelo modelo correto.';
        sourceHelp.textContent = source.site_url
            ? `Origem: ${source.site_url}`
            : 'Use o modelo e a marca do equipamento para refinar a busca.';
        sourceControls.innerHTML = '';

        if (Array.isArray(source.brand_options) && source.brand_options.length) {
            sourceControls.insertAdjacentHTML('beforeend', buildSelect('cat-brand', source.brand_label || 'Marca', source.brand_options));
        }

        if (Array.isArray(source.mode_options) && source.mode_options.length) {
            sourceControls.insertAdjacentHTML('beforeend', buildSelect('cat-mode', source.mode_label || 'Modo', source.mode_options));
        }

        if (source.driver === 'custom') {
            sourceControls.insertAdjacentHTML('beforeend', `
                <div class="col-lg-4">
                    <label class="form-label small">Marca (opcional)</label>
                    <input type="text" id="cat-custom-marca" class="form-control" placeholder="Ex.: DeWalt">
                </div>
            `);
        }

        if (results) results.innerHTML = '';
    }

    function getSelectedOptionLabel(selectId) {
        const select = byId(selectId);
        if (!select) return '';
        const option = select.options[select.selectedIndex];
        return option ? option.textContent : '';
    }

    function prefillSource() {
        const source = currentSource();
        if (!source || !searchInput) return;

        const modelHint = pickModelHint();
        if (modelHint) searchInput.value = modelHint;

        const brandHint = pickBrandHint();

        if (source.driver === 'custom') {
            const customBrand = byId('cat-custom-marca');
            if (customBrand && brandHint) customBrand.value = brandHint;
        }

        if (Array.isArray(source.brand_options) && source.brand_options.length && brandHint) {
            const brandSelect = byId('cat-brand');
            if (brandSelect) {
                const match = source.brand_options.find(option => {
                    const label = String(option.label || '').toLowerCase();
                    return label.includes(brandHint) || brandHint.includes(label.replace(/\s*\(.+\)\s*$/, '').trim());
                });
                if (match) brandSelect.value = match.value;
            }
        }
    }

    function showCatalogError(message) {
        if (!results) return;
        results.innerHTML = `<div class="cat-error"><i class="ph ph-warning me-1"></i>${escapeHtml(message)}</div>`;
    }

    function showCatalogEmpty(message) {
        if (!results) return;
        results.innerHTML = `<div class="cat-empty">${escapeHtml(message)}</div>`;
    }

    function renderCatalogResults(source, payload) {
        if (!results) return;
        const items = [];

        if (source.driver === 'felap' || source.driver === 'tsn') {
            (payload.modelos || []).forEach(modelo => {
                items.push({
                    title: modelo.modelo || modelo.codigo || modelo.arquivo,
                    sub: source.driver === 'felap' ? `${source.label} · PDF` : `${source.label} · Catálogo`,
                    url: modelo.url,
                    thumb: null,
                    pdfHint: source.driver === 'felap',
                    canVincular: true,
                });
            });
        } else if (source.driver === 'bosch') {
            (payload.produtos || []).forEach(produto => {
                const img = (produto.imagensVistaExplodida && produto.imagensVistaExplodida[0]) || produto.imagem || null;
                items.push({
                    title: produto.nome || produto.modelo || produto.typenr,
                    sub: `${source.label} · typenr ${produto.typenr || '—'}${produto.totalPecas ? ` · ${produto.totalPecas} peças` : ''}`,
                    url: produto.url,
                    thumb: img,
                    pdfHint: false,
                    canVincular: true,
                });
            });
        } else {
            (payload.documentos || []).forEach(documento => {
                items.push({
                    title: documento.titulo || documento.modelo || source.label,
                    sub: documento.description || `${source.label} · ${documento.tipo || 'Documento'}`,
                    url: documento.urlPDF || documento.url,
                    thumb: null,
                    pdfHint: true,
                    canVincular: documento.canVincular !== false,
                    type: documento.tipo || 'Documento',
                });
            });
        }

        if (!items.length) {
            showCatalogEmpty('Nenhum resultado encontrado.');
            return;
        }

        results.innerHTML = items.map(item => `
            <div class="cat-result-card">
                <div class="cat-thumb">
                    ${item.thumb
                        ? `<img src="${escapeHtml(item.thumb)}" alt="" loading="lazy">`
                        : `<i class="ph ph-${item.pdfHint ? 'file-pdf' : 'wrench'}" style="font-size:1.5rem"></i>`}
                </div>
                <div class="cat-info">
                    <strong>${escapeHtml(item.title)}</strong>
                    <small>${escapeHtml(item.sub)}</small>
                </div>
                <div class="cat-actions">
                    <a href="${escapeHtml(item.url)}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                        <i class="ph ph-eye me-1"></i>Abrir
                    </a>
                    ${item.canVincular
                        ? `<button type="button" class="btn btn-primary btn-sm js-vincular" data-url="${escapeHtml(item.url)}">
                                <i class="ph ph-link me-1"></i>Vincular
                           </button>`
                        : `<span class="tecnico-chip">Validar na origem</span>`}
                </div>
            </div>
        `).join('');

        results.querySelectorAll('.js-vincular').forEach(btn => {
            btn.addEventListener('click', () => vincularVista(btn.dataset.url, btn));
        });
    }

    async function vincularVista(url, btn) {
        if (!url) {
            window.alert('URL inválida.');
            return;
        }
        if (!window.confirm('Vincular esta vista explodida ao equipamento?\n\n' + url)) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
        try {
            const res = await fetch(`/api/tecnico/equipamento/${encodeURIComponent(osId)}/${encodeURIComponent(equipIdx)}/vista-url`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF,
                },
                body: JSON.stringify({ url }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || json.erro || `HTTP ${res.status}`);

            renderVista(json.url);
            btn.innerHTML = '<i class="ph ph-check me-1"></i>Vinculada';
            btn.closest('.cat-result-card')?.style.setProperty('border-color', 'var(--bs-success)');
            toast('Vista explodida vinculada!');
        } catch (e) {
            btn.disabled = false;
            btn.innerHTML = '<i class="ph ph-link me-1"></i>Vincular';
            window.alert('Erro ao vincular: ' + e.message);
        }
    }

    async function buscarCatalogo() {
        const source = currentSource();
        if (!source || !results || !searchInput) return;

        const q = searchInput.value.trim();
        results.innerHTML = '<div class="cat-loading"><span class="spinner-border spinner-border-sm me-2"></span>Buscando...</div>';

        try {
            let params = null;
            let endpoint = '/api/catalogo/produto';

            if (source.driver === 'felap') {
                endpoint = '/api/catalogo/modelos';
                params = {
                    fonte: source.id,
                    marca: byId('cat-brand')?.value || '',
                    q,
                };
            } else if (source.driver === 'tsn') {
                endpoint = '/api/catalogo/modelos';
                params = {
                    fonte: source.id,
                    brand: byId('cat-brand')?.value || '',
                    q,
                };
            } else if (source.driver === 'bosch') {
                if (!q) {
                    showCatalogError('Informe o modelo ou número de tipo da Bosch.');
                    return;
                }
                const mode = byId('cat-mode')?.value || 'modelo';
                params = mode === 'typenr'
                    ? { fonte: source.id, typenr: q }
                    : { fonte: source.id, modelo: q };
            } else if (source.driver === 'milwaukee') {
                if (!q) {
                    showCatalogError('Informe o modelo Milwaukee.');
                    return;
                }
                params = { fonte: source.id, modelo: q };
            } else {
                const customBrand = byId('cat-custom-marca')?.value.trim() || '';
                const query = q || `${customBrand} ${pickModelHint()}`.trim();
                if (!query) {
                    showCatalogError('Informe marca e/ou modelo para a fonte configurada.');
                    return;
                }
                params = {
                    fonte: source.id,
                    modelo: q,
                    marca_nome: customBrand,
                    q: query,
                };
            }

            const url = `${endpoint}?${new URLSearchParams(params)}`;
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) {
                showCatalogError(json.erro || json.error || `HTTP ${res.status}`);
                return;
            }
            renderCatalogResults(source, json);
        } catch (e) {
            showCatalogError('Erro de rede: ' + e.message);
        }
    }

    sourceSelect?.addEventListener('change', syncSourceUi);
    btnPrefill?.addEventListener('click', prefillSource);
    btnBuscar?.addEventListener('click', buscarCatalogo);
    searchInput?.addEventListener('keydown', ev => {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            buscarCatalogo();
        }
    });

    renderSourceOptions();
    renderSourcePills();
    syncSourceUi();
    prefillSource();

    updatePhotoCounts(
        Number(byId('fotos-count')?.textContent?.match(/\d+/)?.[0] || 0),
        getReceptionPhotoCount()
    );
    updateItemCounts(Number(byId('tabela-itens-count')?.textContent || 0));
    updateVistaKpi(Boolean(vistaCurrent?.querySelector('a')));
    syncCxAlert();
})();
