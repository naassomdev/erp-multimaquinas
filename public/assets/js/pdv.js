(() => {
    'use strict';

    const formatSaleNumber = (value) => {
        const digits = String(value ?? '').replace(/\D+/g, '');
        if (digits === '') return '';
        return '#' + digits.padStart(6, '0');
    };

    const buildReceiptSharePayload = (receiptUrl, saleNumber) => {
        const normalizedUrl = String(receiptUrl || '').trim();
        const normalizedNumber = formatSaleNumber(saleNumber);
        const saleLabel = normalizedNumber || 'da venda PDV';
        const whatsappText = `Olá! Segue o recibo não fiscal da venda PDV ${saleLabel}: ${normalizedUrl}. Este documento não substitui documento fiscal.`;
        const emailSubject = `Recibo não fiscal - Venda PDV ${saleLabel}`;
        const emailBody = `Olá, segue o recibo não fiscal da venda PDV ${saleLabel}: ${normalizedUrl}. Este documento não substitui documento fiscal.`;

        return {
            receiptUrl: normalizedUrl,
            whatsappUrl: `https://wa.me/?text=${encodeURIComponent(whatsappText)}`,
            emailUrl: `mailto:?subject=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`,
            whatsappText,
            emailSubject,
            emailBody,
        };
    };

    async function copyToClipboard(text) {
        const normalized = String(text || '').trim();
        if (normalized === '') {
            throw new Error('Link do recibo indisponível para cópia.');
        }

        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(normalized);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = normalized;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        const copied = document.execCommand('copy');
        document.body.removeChild(textarea);

        if (!copied) {
            throw new Error('Não foi possível copiar o link do recibo.');
        }
    }

    function initReceiptShareActions(scope = document) {
        const containers = Array.from(scope.querySelectorAll('[data-pdv-share-receipt-url]'));
        containers.forEach((container) => {
            if (container.dataset.pdvShareReady === '1') {
                return;
            }

            const payload = buildReceiptSharePayload(
                container.dataset.pdvShareReceiptUrl || '',
                container.dataset.pdvShareSaleNumber || ''
            );

            container.querySelectorAll('[data-pdv-share-action]').forEach((node) => {
                const action = node.dataset.pdvShareAction || '';
                if (action === 'whatsapp') {
                    if (node.tagName === 'A') {
                        node.setAttribute('href', payload.whatsappUrl);
                        node.setAttribute('target', '_blank');
                        node.setAttribute('rel', 'noopener');
                    }
                    node.setAttribute('title', 'Abrir compartilhamento manual no WhatsApp');
                } else if (action === 'email') {
                    if (node.tagName === 'A') {
                        node.setAttribute('href', payload.emailUrl);
                    }
                    node.setAttribute('title', 'Abrir e-mail com assunto e corpo preenchidos');
                } else if (action === 'copy') {
                    node.setAttribute('title', 'Copiar link protegido do recibo');
                    node.addEventListener('click', async (event) => {
                        event.preventDefault();
                        try {
                            await copyToClipboard(payload.receiptUrl);
                            window.alert('Link do recibo copiado.');
                        } catch (error) {
                            window.alert(error.message || 'Não foi possível copiar o link do recibo.');
                        }
                    });
                }
            });

            container.dataset.pdvShareReady = '1';
        });
    }

    initReceiptShareActions(document);

    function initFiscalManualBridge(scope = document) {
        const modalEl = scope.getElementById ? scope.getElementById('pdv-fiscal-manual-modal') : document.getElementById('pdv-fiscal-manual-modal');
        const form = document.getElementById('pdv-fiscal-manual-form');
        const feedback = document.getElementById('pdv-fiscal-manual-feedback');
        const submitBtn = document.getElementById('pdv-fiscal-manual-submit');
        const vendaIdEl = document.getElementById('pdv-fiscal-manual-venda-id');
        const tipoEl = document.getElementById('pdv-fiscal-manual-tipo');
        const modeloEl = document.getElementById('pdv-fiscal-manual-modelo');
        const valorEl = document.getElementById('pdv-fiscal-manual-valor');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content?.trim() ?? '';

        if (!modalEl || !form || !vendaIdEl || !tipoEl || !modeloEl || !valorEl) {
            return;
        }

        const modal = window.bootstrap?.Modal ? new window.bootstrap.Modal(modalEl) : null;
        let submitting = false;

        const setFeedback = (message, tone = 'secondary') => {
            if (!feedback) return;
            feedback.className = `pdv-fiscal-manual-feedback small mt-3 text-${tone}`;
            feedback.textContent = message || '';
        };

        const defaultModelForType = (type) => ({
            nfe: '55',
            nfce: '65',
            nfse: 'nfse',
            cupom_fiscal: 'cupom',
        }[type] || '');

        tipoEl.addEventListener('change', () => {
            modeloEl.value = defaultModelForType(tipoEl.value);
        });

        document.querySelectorAll('[data-pdv-fiscal-manual-open="1"]').forEach((button) => {
            button.addEventListener('click', () => {
                form.reset();
                const saleId = button.getAttribute('data-venda-id') || '';
                const saleTotal = button.getAttribute('data-venda-total') || '';
                vendaIdEl.value = saleId;
                tipoEl.value = 'nfe';
                modeloEl.value = '55';
                valorEl.value = saleTotal;
                setFeedback('');

                if (modal) {
                    modal.show();
                } else {
                    modalEl.removeAttribute('aria-hidden');
                    modalEl.style.display = 'block';
                    modalEl.classList.add('show');
                }
            });
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitting) return;

            const saleId = Number(vendaIdEl.value || 0);
            if (!saleId) {
                setFeedback('Venda inválida para vínculo fiscal manual.', 'danger');
                return;
            }

            const payload = {};
            const formData = new FormData(form);
            formData.forEach((value, key) => {
                payload[key] = String(value).trim();
            });
            payload.confirmar_valor_divergente = formData.has('confirmar_valor_divergente');

            submitting = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Registrando...';
            }
            setFeedback('Registrando vínculo manual. Nenhuma transmissão fiscal será feita.', 'secondary');

            try {
                const response = await fetch(`/api/pdv/vendas/${saleId}/documento-fiscal-manual`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json().catch(() => null);
                if (!response.ok || !data || data.ok === false) {
                    throw new Error((data && data.error) || `HTTP ${response.status}`);
                }

                setFeedback('Documento fiscal externo vinculado com sucesso.', 'success');
                window.setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                setFeedback(error.message || 'Falha ao registrar vínculo fiscal manual.', 'danger');
            } finally {
                submitting = false;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Registrar vínculo manual';
                }
            }
        });
    }

    initFiscalManualBridge(document);

    const root = document.querySelector('[data-pdv-root="1"], [data-pdv-readonly="1"]');
    if (!root) return;

    const clienteInput = document.getElementById('pdv-cliente-busca');
    const produtoInput = document.getElementById('pdv-produto-busca');
    const clientesLista = document.getElementById('pdv-clientes-lista');
    const produtosLista = document.getElementById('pdv-produtos-lista');
    const clienteSelecionado = document.getElementById('pdv-cliente-selecionado');
    const clienteDocumento = document.getElementById('pdv-cliente-documento');
    const clienteLimparBtn = document.getElementById('pdv-cliente-limpar');
    const observacoesVendaEl = document.getElementById('pdv-observacoes-venda');
    const rascunhosLista = document.getElementById('pdv-rascunhos-lista');
    const rascunhoDetalhe = document.getElementById('pdv-rascunho-detalhe');
    const carrinhoBody = document.getElementById('pdv-carrinho-body');
    const subtotalEl = document.getElementById('pdv-total-subtotal');
    const descontoItensEl = document.getElementById('pdv-total-desconto-itens');
    const descontoGeralEl = document.getElementById('pdv-total-desconto-geral');
    const totalEl = document.getElementById('pdv-total-geral');
    const descontoGeralTipo = document.getElementById('pdv-desconto-geral-tipo');
    const descontoGeralValor = document.getElementById('pdv-desconto-geral-valor');
    const descontoGeralResumo = document.getElementById('pdv-desconto-geral-resumo');
    const descontoGeralAplicarBtn = document.getElementById('pdv-desconto-geral-aplicar');
    const descontoGeralSyncStatus = document.getElementById('pdv-desconto-geral-sync-status');
    const persistirCarrinhoBtn = document.getElementById('pdv-persistir-carrinho-btn');
    const persistenciaStatus = document.getElementById('pdv-persistencia-status');
    const registrarPagamentoBtn = document.getElementById('pdv-registrar-pagamento-btn');
    const pagamentoSyncStatus = document.getElementById('pdv-pagamento-sync-status');
    const statusBadge = document.getElementById('pdv-status-badge');
    const writeWarningTitle = document.getElementById('pdv-write-warning-title');
    const writeWarningDetail = document.getElementById('pdv-write-warning-detail');
    const draftsModeHint = document.getElementById('pdv-drafts-mode-hint');
    const finalizacaoHint = document.getElementById('pdv-finalizacao-hint');
    const finalizarVendaBtn = document.getElementById('pdv-finalizar-venda-btn');
    const payButtons = Array.from(document.querySelectorAll('.pdv-pay-btn'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content?.trim() ?? '';
    const currentUserRole = String(root.dataset.pdvUserRole || '').trim();
    const initialVendaId = Number(root.dataset.pdvInitialVendaId || 0);

    let clienteAtual = null;
    let carrinho = [];
    let clienteAbort = null;
    let produtoAbort = null;
    let clienteTimer = null;
    let produtoTimer = null;
    let pagamentoVisual = 'dinheiro';
    let localCartSequence = 1;
    let clienteRequestSeq = 0;
    let produtoRequestSeq = 0;
    let draftRequestSeq = 0;
    let draftDetailSeq = 0;
    let rascunhoSelecionadoId = null;
    let currentDraftDetails = null;
    let savingGeneralDiscount = false;
    let persistingCart = false;
    let creatingDraft = false;
    let registeringPayment = false;
    let finalizingSale = false;
    let reversingSale = false;
    let removingPersistedItemLocalId = null;
    let discountSyncFeedback = null;
    let finalizationFeedback = null;
    let persistenceFeedback = null;
    let paymentFeedback = null;
    let pdvStatus = {
        enabled: root.dataset.pdvMode !== 'off',
        mode: root.dataset.pdvMode || 'off',
        fiscal_enabled: false,
        recibo_enabled: true,
        write_enabled: false,
        write_admin_only: true,
        finalizacao_disponivel: false,
    };

    const fmtBrl = (n) => 'R$ ' + Number(n || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[char]));

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const roundMoney = (value) => Math.round(Number(value || 0) * 100) / 100;
    const immediatePaymentTypes = new Set(['dinheiro', 'pix', 'cartao']);
    const deferredPaymentTypes = new Set(['boleto', 'faturado']);

    function parseMoneyInput(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }

    function canWritePdv() {
        if (!(pdvStatus.enabled && pdvStatus.mode === 'live' && !!pdvStatus.write_enabled)) {
            return false;
        }

        if (pdvStatus.write_admin_only) {
            return currentUserRole === 'admin';
        }

        return currentUserRole === 'admin' || currentUserRole === 'recepcao';
    }

    function hasPendingSync() {
        return creatingDraft || persistingCart || savingGeneralDiscount || registeringPayment || finalizingSale || reversingSale || removingPersistedItemLocalId !== null;
    }

    async function getJson(url, signal) {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal,
        });

        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || ('HTTP ' + response.status));
        }
        return data;
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.ok === false) {
            const error = new Error((data && data.error) || ('HTTP ' + response.status));
            error.status = response.status;
            error.payload = data;
            throw error;
        }
        return data;
    }

    function debounce(handler, key) {
        if (key === 'cliente') {
            clearTimeout(clienteTimer);
            clienteTimer = window.setTimeout(handler, 250);
            return;
        }
        clearTimeout(produtoTimer);
        produtoTimer = window.setTimeout(handler, 250);
    }

    function clearClienteResults() {
        clientesLista.innerHTML = '';
    }

    function clearProdutoResults() {
        produtosLista.innerHTML = '';
    }

    function clearRascunhoDetalhe() {
        if (!rascunhoDetalhe) return;
        currentDraftDetails = null;
        carrinho = [];
        discountSyncFeedback = null;
        finalizationFeedback = null;
        rascunhoDetalhe.innerHTML = '<div class="text-body-secondary small">Selecione um rascunho para visualizar os detalhes.</div>';
        recalc();
        syncPdvModeUI();
    }

    function resetClienteSearch({ focus = false } = {}) {
        clienteRequestSeq += 1;
        if (clienteAbort) {
            clienteAbort.abort();
            clienteAbort = null;
        }
        clearTimeout(clienteTimer);
        if (clienteInput) {
            clienteInput.value = '';
            if (focus) clienteInput.focus();
        }
        clearClienteResults();
    }

    function resetProdutoSearch({ focus = false } = {}) {
        produtoRequestSeq += 1;
        if (produtoAbort) {
            produtoAbort.abort();
            produtoAbort = null;
        }
        clearTimeout(produtoTimer);
        if (produtoInput) {
            produtoInput.value = '';
            if (focus) produtoInput.focus();
        }
        clearProdutoResults();
    }

    function renderClientes(clientes) {
        if (!clientes.length) {
            clientesLista.innerHTML = '<div class="list-group-item text-body-secondary">Nenhum cliente encontrado.</div>';
            return;
        }

        clientesLista.innerHTML = clientes.map((cliente) => `
            <button type="button" class="list-group-item list-group-item-action" data-id="${cliente.id}">
                <div class="fw-semibold">${escapeHtml(cliente.nome)}</div>
                <div class="small text-body-secondary">${escapeHtml(cliente.documento || 'Documento não informado')}</div>
            </button>
        `).join('');

        clientesLista.querySelectorAll('[data-id]').forEach((button, index) => {
            button.addEventListener('click', () => {
                clienteAtual = clientes[index];
                renderClienteSelecionado();
                resetClienteSearch();
                produtoInput?.focus();
            });
        });
    }

    function stockState(qty) {
        if (qty <= 0) return 'zero';
        if (qty <= 2) return 'low';
        return 'ok';
    }

    function stockLabel(qty) {
        if (qty <= 0) return 'Estoque zerado';
        if (qty <= 2) return 'Estoque baixo';
        return 'Estoque disponível';
    }

    function renderProdutos(produtos) {
        if (!produtos.length) {
            produtosLista.innerHTML = '<div class="list-group-item text-body-secondary">Nenhum produto encontrado.</div>';
            return;
        }

        produtosLista.innerHTML = produtos.map((produto) => `
            <button type="button" class="list-group-item list-group-item-action pdv-result-item" data-id="${produto.id}">
                <div class="d-flex justify-content-between gap-3 align-items-start">
                    <div>
                        <div class="fw-semibold">${escapeHtml(produto.descricao)}</div>
                        <div class="pdv-result-item__meta">${escapeHtml(produto.codigo || 'Sem código')}${produto.marca ? ' • ' + escapeHtml(produto.marca) : ''}</div>
                    </div>
                    <div class="text-end">
                        <div class="fw-semibold">${fmtBrl(produto.valor_venda)}</div>
                        <div class="small text-body-secondary">Estoque atual: ${Number(produto.estoque_qty || 0).toLocaleString('pt-BR')}</div>
                        <span class="pdv-stock-pill pdv-stock-pill--${stockState(Number(produto.estoque_qty || 0))}">
                            <i class="ph ph-warning-circle"></i>
                            ${stockLabel(Number(produto.estoque_qty || 0))}
                        </span>
                    </div>
                </div>
            </button>
        `).join('');

        produtosLista.querySelectorAll('[data-id]').forEach((button, index) => {
            button.addEventListener('click', () => {
                adicionarAoCarrinho(produtos[index]);
                resetProdutoSearch({ focus: true });
            });
        });
    }

    function selectedDraftIsEditable() {
        const venda = currentDraftDetails?.venda || null;
        if (!venda) return false;
        return ['rascunho', 'pendente_pagamento', 'parcialmente_pago', 'pago'].includes(String(venda.status_venda || ''));
    }

    function setPersistenceMessage(message, tone = 'muted') {
        persistenceFeedback = { message, tone };
        if (!persistenciaStatus) return;
        persistenciaStatus.textContent = message;
        persistenciaStatus.classList.remove('text-body-secondary', 'text-success', 'text-danger', 'text-warning');
        if (tone === 'success') {
            persistenciaStatus.classList.add('text-success');
        } else if (tone === 'danger') {
            persistenciaStatus.classList.add('text-danger');
        } else if (tone === 'warning') {
            persistenciaStatus.classList.add('text-warning');
        } else {
            persistenciaStatus.classList.add('text-body-secondary');
        }
    }

    function setPaymentMessage(message, tone = 'muted') {
        paymentFeedback = { message, tone };
        if (!pagamentoSyncStatus) return;
        pagamentoSyncStatus.textContent = message;
        pagamentoSyncStatus.classList.remove('text-body-secondary', 'text-success', 'text-danger', 'text-warning');
        if (tone === 'success') {
            pagamentoSyncStatus.classList.add('text-success');
        } else if (tone === 'danger') {
            pagamentoSyncStatus.classList.add('text-danger');
        } else if (tone === 'warning') {
            pagamentoSyncStatus.classList.add('text-warning');
        } else {
            pagamentoSyncStatus.classList.add('text-body-secondary');
        }
    }

    function renderRascunhos(rascunhos) {
        if (!rascunhosLista) return;

        if (!rascunhos.length) {
            rascunhosLista.innerHTML = currentDraftDetails?.venda?.id
                ? '<div class="text-body-secondary small">Nenhum rascunho aberto na lista. Mantendo a venda selecionada nos detalhes.</div>'
                : '<div class="text-body-secondary small">Nenhum rascunho PDV encontrado.</div>';
            if (!currentDraftDetails?.venda?.id) {
                clearRascunhoDetalhe();
            }
            return;
        }

        rascunhosLista.innerHTML = rascunhos.map((rascunho) => `
            <button type="button" class="pdv-draft-card text-start ${rascunho.id === rascunhoSelecionadoId ? 'is-active' : ''}" data-draft-id="${rascunho.id}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">Rascunho #${rascunho.id}</div>
                        <div class="small text-body-secondary">${escapeHtml(rascunho.origem_tipo || 'origem não informada')}</div>
                    </div>
                    ${rascunho.is_teste_controlado ? '<span class="pdv-draft-badge"><i class="ph ph-flask"></i>Teste controlado</span>' : ''}
                </div>
                <div class="small text-body-secondary mt-2">
                    Status: ${escapeHtml(rascunho.status_venda)} • Fiscal: ${escapeHtml(rascunho.status_fiscal)} • Total: ${fmtBrl(rascunho.total_liquido)}
                </div>
                <div class="small text-body-secondary mt-1">
                    ${escapeHtml(rascunho.observacoes || 'Sem observações')}
                </div>
            </button>
        `).join('');

        rascunhosLista.querySelectorAll('[data-draft-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = Number(button.dataset.draftId);
                if (!Number.isFinite(id) || id <= 0) return;
                carregarDetalhesRascunho(id);
            });
        });
    }

    function renderRascunhoDetalhe(detalhes) {
        if (!rascunhoDetalhe) return;

        currentDraftDetails = detalhes || null;
        syncCartFromDraftDetails(detalhes || {});
        const venda = detalhes.venda || {};
        const itens = Array.isArray(detalhes.itens) ? detalhes.itens : [];
        const pagamentos = Array.isArray(detalhes.pagamentos) ? detalhes.pagamentos : [];
        const documentos = Array.isArray(detalhes.documentos) ? detalhes.documentos : [];
        const cancelamento = detalhes.cancelamento || {};
        const statusVenda = String(venda.status_venda || '');
        const estornoState = selectedDraftCanReverse();
        const reciboDisponivel = Boolean(pdvStatus.recibo_enabled) && Number(venda.id || 0) > 0 && String(venda.status_venda || '') !== 'cancelado';
        const reciboPath = `/pdv/vendas/${encodeURIComponent(String(venda.id || ''))}/recibo`;
        const reciboUrl = new URL(reciboPath, window.location.origin).href;
        const saleNumber = String(venda.id || '');
        const reciboActionHtml = reciboDisponivel
            ? `
                <div class="d-inline-flex gap-2 flex-wrap" data-pdv-share-receipt-url="${escapeHtml(reciboUrl)}" data-pdv-share-sale-number="${escapeHtml(saleNumber)}">
                    <a href="${escapeHtml(reciboPath)}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">Visualizar recibo não fiscal</a>
                    <a href="${escapeHtml(reciboUrl)}" class="btn btn-outline-success btn-sm" data-pdv-share-action="whatsapp">WhatsApp</a>
                    <a href="${escapeHtml(reciboUrl)}" class="btn btn-outline-secondary btn-sm" data-pdv-share-action="email">E-mail</a>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-pdv-share-action="copy">Copiar link</button>
                </div>`
            : `<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="${escapeHtml(String(venda.status_venda || '') === 'cancelado' ? 'Recibo desabilitado na tela para vendas canceladas.' : 'Recibo não fiscal indisponível no modo atual.')}">Visualizar recibo não fiscal</button>`;
        let estornoActionHtml = '';

        if (statusVenda === 'finalizado') {
            if (currentUserRole === 'admin') {
                estornoActionHtml = `<button type="button" id="pdv-estornar-venda-btn" class="btn btn-outline-danger btn-sm" ${estornoState.allowed ? '' : 'disabled'} title="${escapeHtml(estornoState.reason)}">${reversingSale ? 'Estornando venda...' : 'Estornar venda'}</button>`;
            } else {
                estornoActionHtml = '<span class="small text-body-secondary">Estorno disponível apenas para administrador.</span>';
            }
        } else if (statusVenda === 'estornado') {
            estornoActionHtml = '<span class="small text-danger fw-semibold">Venda estornada.</span>';
        } else if (statusVenda === 'cancelado') {
            estornoActionHtml = '<span class="small text-body-secondary">Venda cancelada.</span>';
        }

        rascunhoDetalhe.innerHTML = `
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h3 class="h5 mb-0">Rascunho #${escapeHtml(venda.id || '')}</h3>
                        ${venda.is_teste_controlado ? '<span class="pdv-draft-badge"><i class="ph ph-flask"></i>Teste controlado</span>' : ''}
                    </div>
                    <div class="small text-body-secondary mt-1">
                        Origem: ${escapeHtml(venda.origem_tipo || 'não informada')} • Criado em: ${escapeHtml(venda.created_at || 'não informado')}
                    </div>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="${escapeHtml(cancelamento.motivo || 'Cancelamento indisponível no modo atual')}">
                    Cancelar rascunho
                </button>
            </div>

            <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap mt-3">
                ${estornoActionHtml}
                ${reciboActionHtml}
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Status da venda</div>
                    <div class="fw-semibold">${escapeHtml(venda.status_venda || '')}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Status fiscal</div>
                    <div class="fw-semibold">${escapeHtml(venda.status_fiscal || '')}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Cliente</div>
                    <div class="fw-semibold">${venda.cliente_id ? '#' + escapeHtml(venda.cliente_id) : 'Sem cliente'}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Total bruto</div>
                    <div class="fw-semibold">${fmtBrl(venda.total_bruto || 0)}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Total desconto</div>
                    <div class="fw-semibold">${fmtBrl(venda.total_desconto || 0)}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Desconto geral persistido</div>
                    <div class="fw-semibold">${fmtBrl(venda.desconto_geral || 0)}</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-body-secondary">Total líquido</div>
                    <div class="fw-semibold">${fmtBrl(venda.total_liquido || 0)}</div>
                </div>
            </div>

            <div class="mt-3">
                <div class="small text-body-secondary mb-1">Observações</div>
                <div>${escapeHtml(venda.observacoes || 'Sem observações')}</div>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-12 col-md-4">
                    <div class="small text-body-secondary">Itens</div>
                    <div class="fw-semibold">${itens.length}</div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="small text-body-secondary">Pagamentos</div>
                    <div class="fw-semibold">${pagamentos.length}</div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="small text-body-secondary">Documentos</div>
                    <div class="fw-semibold">${documentos.length}</div>
                </div>
            </div>

            <div class="small text-body-secondary mt-3">
                ${escapeHtml(cancelamento.motivo || 'Sem avaliação de cancelamento.')}
            </div>
        `;

        const estornarVendaBtn = document.getElementById('pdv-estornar-venda-btn');
        estornarVendaBtn?.addEventListener('click', () => {
            reverseSelectedDraft();
        });
        initReceiptShareActions(rascunhoDetalhe);

        syncPdvModeUI();
    }

    async function carregarRascunhos() {
        if (!rascunhosLista) return;

        const requestSeq = ++draftRequestSeq;
        rascunhosLista.innerHTML = '<div class="text-body-secondary small">Carregando rascunhos...</div>';

        try {
            const data = await getJson('/api/pdv/vendas/rascunhos?limit=20');
            if (requestSeq !== draftRequestSeq) return;
            const rascunhos = Array.isArray(data.rascunhos) ? data.rascunhos : [];
            renderRascunhos(rascunhos);
            if (rascunhos.length && !rascunhoSelecionadoId && initialVendaId <= 0) {
                carregarDetalhesRascunho(rascunhos[0].id);
            }
        } catch (error) {
            rascunhosLista.innerHTML = '<div class="text-danger small">Falha ao carregar rascunhos.</div>';
            clearRascunhoDetalhe();
        }
    }

    async function carregarDetalhesRascunho(id) {
        if (!rascunhoDetalhe || !rascunhosLista) return;

        rascunhoSelecionadoId = id;
        discountSyncFeedback = null;
        draftDetailSeq += 1;
        const requestSeq = draftDetailSeq;
        rascunhoDetalhe.innerHTML = '<div class="text-body-secondary small">Carregando detalhes do rascunho...</div>';

        try {
            const data = await getJson(`/api/pdv/vendas/${id}`);
            if (requestSeq !== draftDetailSeq) return;
            renderRascunhoDetalhe(data.detalhes || {});
            const loaded = await getJson('/api/pdv/vendas/rascunhos?limit=20');
            renderRascunhos(Array.isArray(loaded.rascunhos) ? loaded.rascunhos : []);
        } catch (error) {
            rascunhoDetalhe.innerHTML = '<div class="text-danger small">Falha ao carregar detalhes do rascunho.</div>';
        }
    }

    function createLocalCartItem(produto) {
        return {
            localId: localCartSequence++,
            backendItemId: null,
            produtoId: produto.id,
            codigo: produto.codigo,
            descricao: produto.descricao,
            marca: produto.marca,
            valor_venda: Number(produto.valor_venda || 0),
            quantidade: 1,
            estoque_qty: Number(produto.estoque_qty || 0),
            descontoTipo: 'valor',
            descontoValor: 0,
            persisted: false,
            estoque_movimentacao_id: null,
        };
    }

    function createPersistedCartItem(item) {
        return {
            localId: localCartSequence++,
            backendItemId: Number(item.id || 0) || null,
            produtoId: Number(item.produto_id || 0) || null,
            codigo: item.codigo || '',
            descricao: item.descricao || '',
            marca: item.marca || '',
            valor_venda: Number(item.valor_unitario || 0),
            quantidade: Number(item.quantidade || 0) || 1,
            estoque_qty: 0,
            descontoTipo: 'valor',
            descontoValor: Number(item.desconto ?? item.desconto_item ?? 0),
            persisted: true,
            estoque_movimentacao_id: Number(item.estoque_movimentacao_id || 0) || null,
        };
    }

    function syncCartFromDraftDetails(detalhes) {
        const itens = Array.isArray(detalhes?.itens) ? detalhes.itens : [];
        carrinho = itens.map((item) => createPersistedCartItem(item));
        recalc();
    }

    function adicionarAoCarrinho(produto) {
        const existing = carrinho.find((item) => item.produtoId === produto.id && !item.persisted);
        if (existing) {
            existing.quantidade += 1;
            recalc();
            return;
        }

        carrinho.push(createLocalCartItem(produto));

        recalc();
    }

    function itemSubtotalBruto(item) {
        return Number(item.quantidade || 0) * Number(item.valor_venda || 0);
    }

    function itemDiscountAmount(item) {
        const bruto = itemSubtotalBruto(item);
        const descontoValor = parseMoneyInput(item.descontoValor);

        if (bruto <= 0 || descontoValor <= 0) {
            return 0;
        }

        if (item.descontoTipo === 'percentual') {
            const percent = clamp(descontoValor, 0, 100);
            return clamp(bruto * (percent / 100), 0, bruto);
        }

        return clamp(descontoValor, 0, bruto);
    }

    function itemTotalLiquido(item) {
        return Math.max(0, itemSubtotalBruto(item) - itemDiscountAmount(item));
    }

    function currentGeneralDiscountInput() {
        return {
            tipo: descontoGeralTipo?.value === 'percentual' ? 'percentual' : 'valor',
            valor: parseMoneyInput(descontoGeralValor?.value ?? 0),
        };
    }

    function generalDiscountAmount(baseAfterItems) {
        const { tipo, valor } = currentGeneralDiscountInput();
        if (baseAfterItems <= 0 || valor <= 0) {
            return 0;
        }

        if (tipo === 'percentual') {
            const percent = clamp(valor, 0, 100);
            return clamp(baseAfterItems * (percent / 100), 0, baseAfterItems);
        }

        return clamp(valor, 0, baseAfterItems);
    }

    function getDraftBaseForGeneralDiscount() {
        if (!currentDraftDetails || !currentDraftDetails.venda) {
            return 0;
        }
        const venda = currentDraftDetails.venda;
        return Math.max(0, roundMoney(Number(venda.total_liquido || 0) + Number(venda.desconto_geral || 0)));
    }

    function getGeneralDiscountValueForDraft() {
        return roundMoney(generalDiscountAmount(getDraftBaseForGeneralDiscount()));
    }

    function canPersistGeneralDiscount() {
        return canWritePdv();
    }

    function selectedDraftId() {
        const id = Number(currentDraftDetails?.venda?.id || rascunhoSelecionadoId || 0);
        return Number.isFinite(id) && id > 0 ? id : null;
    }

    function setDiscountSyncMessage(message, tone = 'muted') {
        discountSyncFeedback = { message, tone };
        if (!descontoGeralSyncStatus) return;
        descontoGeralSyncStatus.textContent = message;
        descontoGeralSyncStatus.classList.remove('text-body-secondary', 'text-success', 'text-danger', 'text-warning');
        if (tone === 'success') {
            descontoGeralSyncStatus.classList.add('text-success');
        } else if (tone === 'danger') {
            descontoGeralSyncStatus.classList.add('text-danger');
        } else if (tone === 'warning') {
            descontoGeralSyncStatus.classList.add('text-warning');
        } else {
            descontoGeralSyncStatus.classList.add('text-body-secondary');
        }
    }

    function setFinalizationMessage(message, tone = 'muted') {
        finalizationFeedback = { message, tone };
        if (!finalizacaoHint) return;
        finalizacaoHint.textContent = message;
        finalizacaoHint.classList.remove('text-body-secondary', 'text-success', 'text-danger', 'text-warning');
        if (tone === 'success') {
            finalizacaoHint.classList.add('text-success');
        } else if (tone === 'danger') {
            finalizacaoHint.classList.add('text-danger');
        } else if (tone === 'warning') {
            finalizacaoHint.classList.add('text-warning');
        } else {
            finalizacaoHint.classList.add('text-body-secondary');
        }
    }

    function selectedDraftCanFinalize() {
        const draftId = selectedDraftId();
        const venda = currentDraftDetails?.venda || null;
        const itens = Array.isArray(currentDraftDetails?.itens) ? currentDraftDetails.itens : [];
        const pagamentos = Array.isArray(currentDraftDetails?.pagamentos) ? currentDraftDetails.pagamentos : [];
        const documentos = Array.isArray(currentDraftDetails?.documentos) ? currentDraftDetails.documentos : [];

        if (hasPendingSync()) {
            return { allowed: false, reason: 'Aguarde o término das persistências pendentes.' };
        }
        if (!pdvStatus.finalizacao_disponivel || !draftId || !venda) {
            return {
                allowed: false,
                reason: pdvStatus.mode === 'shadow'
                    ? 'Finalização indisponível em modo de teste.'
                    : 'Selecione um rascunho persistido para finalizar.',
            };
        }

        const statusVenda = String(venda.status_venda || '');
        if (statusVenda === 'cancelado') {
            return { allowed: false, reason: 'Venda cancelada não pode ser finalizada.' };
        }
        if (statusVenda === 'estornado') {
            return { allowed: false, reason: 'Venda estornada não pode ser finalizada.' };
        }
        if (statusVenda === 'finalizado') {
            return { allowed: false, reason: 'Venda já finalizada.' };
        }
        if (Number(venda.total_liquido || 0) <= 0) {
            return { allowed: false, reason: 'Venda com total_liquido inválido para finalização.' };
        }
        if (!itens.length) {
            return { allowed: false, reason: 'Venda sem itens para finalizar.' };
        }
        if (!pagamentos.length) {
            return { allowed: false, reason: 'Venda sem condição de pagamento registrada.' };
        }
        if (documentos.length) {
            return { allowed: false, reason: 'Venda já possui documento vinculado.' };
        }

        return {
            allowed: true,
            reason: `Pronto para finalizar a venda #${draftId}. Esta ação pode movimentar estoque e financeiro.`,
        };
    }

    function selectedDraftCanReverse() {
        const draftId = selectedDraftId();
        const venda = currentDraftDetails?.venda || null;

        if (hasPendingSync()) {
            return { allowed: false, reason: 'Aguarde o término das persistências pendentes.' };
        }
        if (!canWritePdv()) {
            return {
                allowed: false,
                reason: pdvStatus.mode === 'shadow'
                    ? 'Estorno indisponível em modo de teste.'
                    : 'Escrita do PDV indisponível no modo atual.',
            };
        }
        if (currentUserRole !== 'admin') {
            return { allowed: false, reason: 'Estorno disponível apenas para administrador.' };
        }
        if (!draftId || !venda) {
            return { allowed: false, reason: 'Selecione uma venda finalizada para estornar.' };
        }

        const statusVenda = String(venda.status_venda || '');
        if (statusVenda === 'estornado') {
            return { allowed: false, reason: 'Venda já estornada.' };
        }
        if (statusVenda === 'cancelado') {
            return { allowed: false, reason: 'Venda cancelada não pode ser estornada.' };
        }
        if (statusVenda !== 'finalizado') {
            return { allowed: false, reason: 'Somente vendas finalizadas podem ser estornadas.' };
        }

        return {
            allowed: true,
            reason: `Estornar a venda #${draftId}. Esta ação pode cancelar financeiro e reverter estoque.`,
        };
    }

    function selectedDraftCanRegisterPayment() {
        const draftId = selectedDraftId();
        const venda = currentDraftDetails?.venda || null;
        const itens = Array.isArray(currentDraftDetails?.itens) ? currentDraftDetails.itens : [];
        const pagamentos = Array.isArray(currentDraftDetails?.pagamentos) ? currentDraftDetails.pagamentos : [];

        if (hasPendingSync()) {
            return { allowed: false, reason: 'Aguarde o término das persistências pendentes.' };
        }
        if (!canWritePdv()) {
            return { allowed: false, reason: 'Modo de teste: nada será gravado.' };
        }
        if (!draftId || !venda || !selectedDraftIsEditable()) {
            return { allowed: false, reason: 'Selecione um rascunho persistido e editável.' };
        }
        if (!itens.length) {
            return { allowed: false, reason: 'Persista pelo menos um item antes de registrar pagamento.' };
        }
        if (pagamentos.some((pagamento) => String(pagamento.status || '') !== 'cancelado')) {
            return { allowed: false, reason: 'A venda já possui pagamento registrado.' };
        }
        if (Number(venda.total_liquido || 0) <= 0) {
            return { allowed: false, reason: 'Venda com total_liquido inválido para pagamento.' };
        }
        return {
            allowed: true,
            reason: `Registrar ${pagamentoVisual} para a venda #${draftId} no valor de ${fmtBrl(venda.total_liquido || 0)}.`,
        };
    }

    function persistedItemCanRemove(item) {
        const draftId = selectedDraftId();
        const venda = currentDraftDetails?.venda || null;
        const pagamentos = Array.isArray(currentDraftDetails?.pagamentos) ? currentDraftDetails.pagamentos : [];

        if (hasPendingSync() && removingPersistedItemLocalId !== item.localId) {
            return { allowed: false, reason: 'Aguarde o término das persistências pendentes.' };
        }
        if (!canWritePdv()) {
            return { allowed: false, reason: 'Modo de teste: nada será gravado.' };
        }
        if (!draftId || !venda || !selectedDraftIsEditable()) {
            return { allowed: false, reason: 'Selecione um rascunho persistido e editável.' };
        }
        if (!item.persisted || !item.backendItemId) {
            return { allowed: false, reason: 'Item ainda não foi persistido no backend.' };
        }
        if (Number(item.estoque_movimentacao_id || 0) > 0) {
            return { allowed: false, reason: 'Item com estoque já movimentado não pode ser removido.' };
        }
        if (pagamentos.some((pagamento) => String(pagamento.status || '') !== 'cancelado')) {
            return { allowed: false, reason: 'Remoção bloqueada: a venda possui pagamento ativo.' };
        }

        return {
            allowed: true,
            reason: `Remover o item persistido #${item.backendItemId} da venda #${draftId}.`,
        };
    }

    function localCartCanPersist() {
        const draftId = selectedDraftId();
        const unpersistedItems = carrinho.filter((item) => !item.persisted);
        if (hasPendingSync()) {
            return { allowed: false, reason: 'Aguarde o término das persistências pendentes.' };
        }
        if (!canWritePdv()) {
            return { allowed: false, reason: 'Modo de teste: nada será gravado.' };
        }
        if (!unpersistedItems.length) {
            return { allowed: false, reason: 'Nenhum item novo no carrinho para persistir.' };
        }
        if (draftId && !selectedDraftIsEditable()) {
            return { allowed: false, reason: 'O rascunho selecionado não aceita novos itens.' };
        }
        return {
            allowed: true,
            reason: draftId
                ? `Persistir ${unpersistedItems.length} item(ns) no rascunho #${draftId}.`
                : 'Criar um rascunho no backend e persistir o carrinho local.',
        };
    }

    function syncPdvModeUI() {
        const writable = canPersistGeneralDiscount();
        const draftId = selectedDraftId();
        const finalizationState = selectedDraftCanFinalize();
        const persistenceState = localCartCanPersist();
        const paymentState = selectedDraftCanRegisterPayment();
        root.dataset.pdvMode = pdvStatus.mode || 'off';
        root.dataset.pdvReadonly = writable ? '0' : '1';

        if (statusBadge) {
            if (writable) {
                statusBadge.innerHTML = pdvStatus.write_admin_only
                    ? '<i class="ph ph-lock-key-open"></i> Modo live / escrita admin'
                    : '<i class="ph ph-lock-key-open"></i> Modo live / escrita liberada';
            } else if (pdvStatus.mode === 'shadow') {
                statusBadge.innerHTML = '<i class="ph ph-flask"></i> Modo shadow / simulação';
            } else {
                statusBadge.innerHTML = '<i class="ph ph-lock"></i> Escrita bloqueada';
            }
        }

        if (writeWarningTitle) {
            writeWarningTitle.textContent = writable
                ? (pdvStatus.write_admin_only
                    ? 'Escrita do PDV liberada apenas para admin neste modo.'
                    : 'Escrita do PDV liberada para admin e recepção neste modo.')
                : 'Nenhuma venda será gravada neste modo.';
        }
        if (writeWarningDetail) {
            writeWarningDetail.textContent = writable
                ? 'Use ações explícitas. Finalização pode movimentar estoque e financeiro; fiscal, cupom e recibos automáticos continuam bloqueados.'
                : 'Escrita, estoque, financeiro, recibos e emissão fiscal permanecem bloqueados.';
        }
        if (draftsModeHint) {
            draftsModeHint.textContent = writable
                ? 'Leitura + ajustes explícitos em live'
                : 'Somente leitura em modo shadow';
        }

        if (persistirCarrinhoBtn) {
            persistirCarrinhoBtn.disabled = !persistenceState.allowed || persistingCart || creatingDraft;
            persistirCarrinhoBtn.textContent = creatingDraft
                ? 'Criando rascunho...'
                : persistingCart
                    ? 'Persistindo carrinho...'
                    : 'Criar/usar rascunho e persistir carrinho';
            persistirCarrinhoBtn.title = persistenceState.reason;
        }

        if (registrarPagamentoBtn) {
            registrarPagamentoBtn.disabled = !paymentState.allowed || registeringPayment;
            registrarPagamentoBtn.textContent = registeringPayment
                ? 'Registrando pagamento...'
                : 'Registrar pagamento no rascunho';
            registrarPagamentoBtn.title = paymentState.reason;
        }

        if (finalizarVendaBtn) {
            const disabled = !finalizationState.allowed || finalizingSale;
            finalizarVendaBtn.disabled = disabled;
            finalizarVendaBtn.textContent = finalizingSale ? 'Finalizando venda...' : 'Finalizar venda';
            finalizarVendaBtn.title = finalizingSale
                ? 'Aguarde a conclusão da finalização atual.'
                : finalizationState.reason;
        }

        if (descontoGeralAplicarBtn) {
            const disabled = !writable || !draftId || savingGeneralDiscount;
            descontoGeralAplicarBtn.disabled = disabled;
            if (savingGeneralDiscount) {
                descontoGeralAplicarBtn.textContent = 'Aplicando desconto geral...';
            } else {
                descontoGeralAplicarBtn.textContent = 'Aplicar desconto geral ao rascunho selecionado';
            }
            descontoGeralAplicarBtn.title = !writable
                ? 'Desconto geral apenas simulado neste modo'
                : !draftId
                    ? 'Selecione um rascunho para persistir o desconto geral'
                    : 'Envia o desconto geral calculado para o backend do PDV';
        }

        if (!writable) {
            setDiscountSyncMessage('Desconto geral apenas simulado neste modo.', 'muted');
        } else if (!draftId) {
            setDiscountSyncMessage('Selecione um rascunho para aplicar desconto geral no backend.', 'warning');
        } else if (!savingGeneralDiscount && (!discountSyncFeedback || !['success', 'danger'].includes(discountSyncFeedback.tone))) {
            const baseDraft = getDraftBaseForGeneralDiscount();
            setDiscountSyncMessage(`Pronto para enviar o desconto geral ao rascunho #${draftId} sobre ${fmtBrl(baseDraft)}.`, 'muted');
        }

        if (!finalizingSale && (!finalizationFeedback || !['success', 'danger'].includes(finalizationFeedback.tone))) {
            setFinalizationMessage(finalizationState.reason, finalizationState.allowed ? 'muted' : 'warning');
        }
        if ((!persistingCart && !creatingDraft) && (!persistenceFeedback || !['success', 'danger'].includes(persistenceFeedback.tone))) {
            setPersistenceMessage(persistenceState.reason, persistenceState.allowed ? 'muted' : 'warning');
        }
        if (!registeringPayment && (!paymentFeedback || !['success', 'danger'].includes(paymentFeedback.tone))) {
            setPaymentMessage(paymentState.reason, paymentState.allowed ? 'muted' : 'warning');
        }
    }

    async function loadPdvStatus() {
        try {
            const data = await getJson('/api/pdv/status');
            pdvStatus = {
                ...pdvStatus,
                ...(data.pdv || {}),
            };
        } catch (error) {
            pdvStatus = {
                ...pdvStatus,
                mode: 'shadow',
                write_enabled: false,
            };
            setDiscountSyncMessage('Falha ao consultar status do PDV. Mantendo simulação local.', 'warning');
        }
        syncPdvModeUI();
    }

    async function applyGeneralDiscountToSelectedDraft() {
        const draftId = selectedDraftId();
        if (!draftId) {
            setDiscountSyncMessage('Selecione um rascunho antes de aplicar desconto geral.', 'warning');
            return;
        }
        if (!canPersistGeneralDiscount()) {
            setDiscountSyncMessage('Desconto geral permanece apenas simulado enquanto o PDV estiver em shadow.', 'warning');
            return;
        }

        const descontoGeral = getGeneralDiscountValueForDraft();
        savingGeneralDiscount = true;
        syncPdvModeUI();

        try {
            const data = await postJson(`/api/pdv/vendas/${draftId}/totais`, {
                desconto_geral: descontoGeral,
                acrescimo_geral: 0,
            });
            await carregarDetalhesRascunho(draftId);
            const totalLiquido = Number(data?.resultado?.totais?.total_liquido || 0);
            setDiscountSyncMessage(`Desconto geral persistido no rascunho #${draftId}. Total líquido: ${fmtBrl(totalLiquido)}.`, 'success');
        } catch (error) {
            setDiscountSyncMessage(error.message || 'Falha ao aplicar desconto geral no backend.', 'danger');
        } finally {
            savingGeneralDiscount = false;
            syncPdvModeUI();
        }
    }

    function currentSaleObservation() {
        const raw = String(observacoesVendaEl?.value || '').trim();
        if (raw !== '') {
            return raw;
        }
        return 'Venda criada pela tela do PDV.';
    }

    async function ensureEditableDraft() {
        const draftId = selectedDraftId();
        if (draftId && selectedDraftIsEditable()) {
            return draftId;
        }

        if (!canWritePdv()) {
            throw new Error('Modo de teste: nada será gravado.');
        }

        creatingDraft = true;
        syncPdvModeUI();

        try {
            const observacoes = currentSaleObservation();
            const origemTipo = observacoes.includes('TESTE CONTROLADO') ? 'pdv_teste' : 'pdv_tela';
            const data = await postJson('/api/pdv/vendas/rascunho', {
                cliente_id: clienteAtual?.id || null,
                origem_tipo: origemTipo,
                observacoes,
            });
            const newDraftId = Number(data?.venda?.id || 0);
            if (!newDraftId) {
                throw new Error('Backend não retornou ID do rascunho criado.');
            }
            await carregarDetalhesRascunho(newDraftId);
            setPersistenceMessage(`Rascunho #${newDraftId} criado para a venda atual.`, 'success');
            return newDraftId;
        } finally {
            creatingDraft = false;
            syncPdvModeUI();
        }
    }

    async function persistLocalCart() {
        const persistenceState = localCartCanPersist();
        if (!persistenceState.allowed) {
            setPersistenceMessage(persistenceState.reason, 'warning');
            return;
        }

        persistingCart = true;
        syncPdvModeUI();

        try {
            const draftId = await ensureEditableDraft();
            for (const item of carrinho) {
                if (item.persisted) {
                    continue;
                }

                await postJson(`/api/pdv/vendas/${draftId}/itens`, {
                    produto_id: item.produtoId,
                    quantidade: item.quantidade,
                    valor_unitario: roundMoney(item.valor_venda),
                    desconto: roundMoney(itemDiscountAmount(item)),
                    acrescimo: 0,
                    codigo: item.codigo,
                    descricao: item.descricao,
                    marca: item.marca || '',
                });
                item.persisted = true;
            }
            await carregarDetalhesRascunho(draftId);
            recalc();
            setPersistenceMessage(`Carrinho local persistido no rascunho #${draftId}.`, 'success');
        } catch (error) {
            setPersistenceMessage(error.message || 'Falha ao persistir carrinho no backend.', 'danger');
        } finally {
            persistingCart = false;
            syncPdvModeUI();
        }
    }

    function currentPaymentPayload() {
        const total = Number(currentDraftDetails?.venda?.total_liquido || 0);
        const forma = pagamentoVisual || 'dinheiro';
        const status = immediatePaymentTypes.has(forma) ? 'pago' : deferredPaymentTypes.has(forma) ? 'pendente' : 'pago';
        return {
            forma_pagamento: forma,
            valor: roundMoney(total),
            status,
        };
    }

    async function registerPaymentForSelectedDraft() {
        const paymentState = selectedDraftCanRegisterPayment();
        if (!paymentState.allowed) {
            setPaymentMessage(paymentState.reason, 'warning');
            return;
        }

        registeringPayment = true;
        syncPdvModeUI();

        try {
            const draftId = selectedDraftId();
            const payload = currentPaymentPayload();
            await postJson(`/api/pdv/vendas/${draftId}/pagamentos`, payload);
            await carregarDetalhesRascunho(draftId);
            setPaymentMessage(`Pagamento ${payload.forma_pagamento} registrado na venda #${draftId}.`, 'success');
        } catch (error) {
            setPaymentMessage(error.message || 'Falha ao registrar pagamento no backend.', 'danger');
        } finally {
            registeringPayment = false;
            syncPdvModeUI();
        }
    }

    async function finalizeSelectedDraft() {
        const draftId = selectedDraftId();
        const finalizationState = selectedDraftCanFinalize();
        if (!draftId || !finalizationState.allowed) {
            setFinalizationMessage(finalizationState.reason, 'warning');
            return;
        }

        const confirmed = window.confirm('Confirmar finalização desta venda? Esta ação pode movimentar estoque e financeiro.');
        if (!confirmed) {
            setFinalizationMessage('Finalização cancelada pelo usuário.', 'muted');
            return;
        }

        finalizingSale = true;
        syncPdvModeUI();

        try {
            const data = await postJson(`/api/pdv/vendas/${draftId}/finalizar`, {});
            await carregarDetalhesRascunho(draftId);
            const venda = data?.resultado?.venda || currentDraftDetails?.venda || {};
            setFinalizationMessage(`Venda #${draftId} finalizada com sucesso. Status: ${String(venda.status_venda || 'finalizado')}.`, 'success');
        } catch (error) {
            setFinalizationMessage(error.message || 'Falha ao finalizar venda do PDV.', 'danger');
        } finally {
            finalizingSale = false;
            syncPdvModeUI();
        }
    }

    async function reverseSelectedDraft() {
        const draftId = selectedDraftId();
        const reverseState = selectedDraftCanReverse();
        if (!draftId || !reverseState.allowed) {
            setFinalizationMessage(reverseState.reason, 'warning');
            return;
        }

        const motivo = String(window.prompt('Informe o motivo do estorno desta venda:', '') || '').trim();
        if (motivo === '') {
            setFinalizationMessage('Motivo do estorno é obrigatório.', 'warning');
            return;
        }

        const confirmed = window.confirm('Confirmar estorno desta venda? Esta ação pode cancelar financeiro e reverter estoque.');
        if (!confirmed) {
            setFinalizationMessage('Estorno cancelado pelo usuário.', 'muted');
            return;
        }

        reversingSale = true;
        syncPdvModeUI();

        try {
            const data = await postJson(`/api/pdv/vendas/${draftId}/estornar`, {
                motivo,
            });
            await carregarDetalhesRascunho(draftId);
            const venda = data?.resultado?.venda || currentDraftDetails?.venda || {};
            setFinalizationMessage(`Venda #${draftId} estornada com sucesso. Status: ${String(venda.status_venda || 'estornado')}.`, 'success');
        } catch (error) {
            setFinalizationMessage(error.message || 'Falha ao estornar venda do PDV.', 'danger');
        } finally {
            reversingSale = false;
            syncPdvModeUI();
        }
    }

    async function removePersistedItem(item) {
        const removeState = persistedItemCanRemove(item);
        if (!removeState.allowed) {
            setPersistenceMessage(removeState.reason, 'warning');
            return;
        }

        const draftId = selectedDraftId();
        if (!draftId || !item.backendItemId) {
            setPersistenceMessage('Selecione um rascunho persistido antes de remover itens.', 'warning');
            return;
        }

        const confirmed = window.confirm('Confirmar remoção deste item persistido? A venda será recalculada no backend.');
        if (!confirmed) {
            setPersistenceMessage('Remoção cancelada pelo usuário.', 'muted');
            return;
        }

        removingPersistedItemLocalId = item.localId;
        renderCarrinho();
        syncPdvModeUI();

        try {
            await postJson(`/api/pdv/vendas/${draftId}/itens/${item.backendItemId}/remover`, {});
            await carregarDetalhesRascunho(draftId);
            setPersistenceMessage(`Item persistido removido da venda #${draftId}.`, 'success');
        } catch (error) {
            setPersistenceMessage(error.message || 'Falha ao remover item persistido do backend.', 'danger');
        } finally {
            removingPersistedItemLocalId = null;
            renderCarrinho();
            syncPdvModeUI();
        }
    }

    function renderClienteSelecionado() {
        if (!clienteAtual) {
            clienteSelecionado.textContent = 'Nenhum cliente selecionado';
            clienteDocumento.textContent = 'Sem documento selecionado';
            return;
        }

        clienteSelecionado.textContent = clienteAtual.nome || 'Cliente sem nome';
        clienteDocumento.textContent = clienteAtual.documento || 'Documento não informado';
    }

    function renderCarrinho() {
        if (!carrinho.length) {
            carrinhoBody.innerHTML = `
                <tr data-empty-row>
                    <td colspan="5" class="text-center text-body-secondary py-4">
                        Nenhum item no carrinho local.
                    </td>
                </tr>
            `;
            return;
        }

        carrinhoBody.innerHTML = carrinho.map((item) => {
            const persistedRemoveState = item.persisted
                ? persistedItemCanRemove(item)
                : { allowed: true, reason: 'Remover item local do carrinho.' };
            const removeDisabled = item.persisted
                ? (!persistedRemoveState.allowed || removingPersistedItemLocalId === item.localId)
                : false;

            return `
            <tr data-item-id="${item.localId}">
                <td>
                    <div class="fw-semibold">${escapeHtml(item.descricao)}</div>
                    <div class="small text-body-secondary">${escapeHtml(item.codigo || 'Sem código')}${item.marca ? ' • ' + escapeHtml(item.marca) : ''}</div>
                    <div class="small ${item.estoque_qty <= 0 ? 'text-danger' : item.estoque_qty <= 2 ? 'text-warning' : 'text-body-secondary'}">
                        ${item.estoque_qty <= 0 ? 'Estoque zerado no cadastro atual.' : item.estoque_qty <= 2 ? 'Atenção: estoque baixo.' : 'Estoque consultado em modo leitura.'}
                    </div>
                    ${item.persisted ? `<div class="small text-success mt-1">Item já persistido no backend${item.backendItemId ? ` (#${escapeHtml(String(item.backendItemId))})` : ''}.</div>` : ''}
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary" data-role="dec" ${item.persisted ? 'disabled title="Item já persistido no backend."' : ''}>-</button>
                        <input type="number" class="form-control form-control-sm text-center" min="1" step="1" value="${item.quantidade}" data-role="qtd" ${item.persisted ? 'disabled title="Item já persistido no backend."' : ''}>
                        <button type="button" class="btn btn-outline-secondary" data-role="inc" ${item.persisted ? 'disabled title="Item já persistido no backend."' : ''}>+</button>
                    </div>
                </td>
                <td>${fmtBrl(item.valor_venda)}</td>
                <td>
                    <div class="input-group input-group-sm pdv-cart-discount">
                        <select class="form-select" data-role="discount-type" ${item.persisted ? 'disabled title="Item já persistido no backend."' : ''}>
                            <option value="valor" ${item.descontoTipo === 'valor' ? 'selected' : ''}>R$</option>
                            <option value="percentual" ${item.descontoTipo === 'percentual' ? 'selected' : ''}>%</option>
                        </select>
                        <input type="number" class="form-control" min="0" step="0.01" value="${Number(item.descontoValor || 0)}" data-role="discount-value" ${item.persisted ? 'disabled title="Item já persistido no backend."' : ''}>
                    </div>
                    <div class="small text-body-secondary mt-1">
                        Desconto do item: ${fmtBrl(itemDiscountAmount(item))}
                    </div>
                </td>
                <td class="pdv-cart-line-total">
                    <div class="fw-semibold">Líquido: ${fmtBrl(itemTotalLiquido(item))}</div>
                    <div class="small text-body-secondary">Bruto: ${fmtBrl(itemSubtotalBruto(item))}</div>
                    <div class="small text-body-secondary">Desconto: ${fmtBrl(itemDiscountAmount(item))}</div>
                </td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-role="remove" ${removeDisabled ? 'disabled' : ''} title="${escapeHtml(item.persisted ? persistedRemoveState.reason : 'Remover item local do carrinho.')}">${item.persisted && removingPersistedItemLocalId === item.localId ? '…' : '×'}</button></td>
            </tr>
        `;
        }).join('');

        carrinhoBody.querySelectorAll('tr[data-item-id]').forEach((row) => {
            const itemId = Number(row.dataset.itemId);
            const qtyInput = row.querySelector('[data-role="qtd"]');
            const removeBtn = row.querySelector('[data-role="remove"]');
            const incBtn = row.querySelector('[data-role="inc"]');
            const decBtn = row.querySelector('[data-role="dec"]');
            const discountType = row.querySelector('[data-role="discount-type"]');
            const discountValue = row.querySelector('[data-role="discount-value"]');

            qtyInput.addEventListener('input', () => {
                const item = carrinho.find((entry) => entry.localId === itemId);
                if (!item) return;
                item.quantidade = Math.max(1, Number(qtyInput.value || 1));
                recalc();
            });

            incBtn.addEventListener('click', () => {
                const item = carrinho.find((entry) => entry.localId === itemId);
                if (!item) return;
                item.quantidade += 1;
                recalc();
            });

            decBtn.addEventListener('click', () => {
                const item = carrinho.find((entry) => entry.localId === itemId);
                if (!item) return;
                item.quantidade = Math.max(1, item.quantidade - 1);
                recalc();
            });

            discountType.addEventListener('change', () => {
                const item = carrinho.find((entry) => entry.localId === itemId);
                if (!item) return;
                item.descontoTipo = discountType.value === 'percentual' ? 'percentual' : 'valor';
                recalc();
            });

            discountValue.addEventListener('input', () => {
                const item = carrinho.find((entry) => entry.localId === itemId);
                if (!item) return;
                item.descontoValor = parseMoneyInput(discountValue.value);
                recalc();
            });

            removeBtn.addEventListener('click', () => {
                const item = carrinho.find((entry) => entry.localId === itemId);
                if (!item) return;
                if (item.persisted) {
                    removePersistedItem(item);
                    return;
                }
                carrinho = carrinho.filter((entry) => entry.localId !== itemId);
                recalc();
            });
        });
    }

    function recalc() {
        const subtotal = carrinho.reduce((acc, item) => acc + itemSubtotalBruto(item), 0);
        const totalDescontosItens = carrinho.reduce((acc, item) => acc + itemDiscountAmount(item), 0);
        const subtotalLiquidoItens = Math.max(0, subtotal - totalDescontosItens);
        const descontoGeralAplicado = generalDiscountAmount(subtotalLiquidoItens);
        const totalFinal = Math.max(0, subtotalLiquidoItens - descontoGeralAplicado);

        renderCarrinho();
        subtotalEl.textContent = fmtBrl(subtotal);
        descontoItensEl.textContent = fmtBrl(totalDescontosItens);
        descontoGeralEl.textContent = fmtBrl(descontoGeralAplicado);
        totalEl.textContent = fmtBrl(totalFinal);

        if (descontoGeralResumo) {
            const { tipo, valor } = currentGeneralDiscountInput();
            if (valor <= 0 || subtotalLiquidoItens <= 0) {
                descontoGeralResumo.textContent = 'Nenhum desconto geral aplicado.';
            } else if (tipo === 'percentual') {
                descontoGeralResumo.textContent = `${clamp(valor, 0, 100).toFixed(2)}% aplicado sobre ${fmtBrl(subtotalLiquidoItens)}.`;
            } else {
                descontoGeralResumo.textContent = `${fmtBrl(clamp(valor, 0, subtotalLiquidoItens))} aplicado como desconto geral.`;
            }
        }

        syncPdvModeUI();
    }

    clienteInput?.addEventListener('input', () => {
        const termo = clienteInput.value.trim();
        debounce(async () => {
            if (clienteAbort) clienteAbort.abort();
            if (termo.length < 2) {
                clearClienteResults();
                return;
            }

            const requestSeq = ++clienteRequestSeq;
            clienteAbort = new AbortController();
            try {
                const data = await getJson(`/api/pdv/clientes?q=${encodeURIComponent(termo)}&limit=10`, clienteAbort.signal);
                if (requestSeq !== clienteRequestSeq || clienteInput.value.trim() !== termo) {
                    return;
                }
                renderClientes(data.clientes || []);
            } catch (error) {
                if (error.name === 'AbortError') return;
                clientesLista.innerHTML = '<div class="list-group-item text-danger">Falha ao consultar clientes.</div>';
            }
        }, 'cliente');
    });

    produtoInput?.addEventListener('input', () => {
        const termo = produtoInput.value.trim();
        debounce(async () => {
            if (produtoAbort) produtoAbort.abort();
            if (termo.length < 2) {
                clearProdutoResults();
                return;
            }

            const requestSeq = ++produtoRequestSeq;
            produtoAbort = new AbortController();
            try {
                const data = await getJson(`/api/pdv/produtos?q=${encodeURIComponent(termo)}&limit=10`, produtoAbort.signal);
                if (requestSeq !== produtoRequestSeq || produtoInput.value.trim() !== termo) {
                    return;
                }
                renderProdutos(data.produtos || []);
            } catch (error) {
                if (error.name === 'AbortError') return;
                produtosLista.innerHTML = '<div class="list-group-item text-danger">Falha ao consultar produtos.</div>';
            }
        }, 'produto');
    });

    payButtons.forEach((button) => {
        button.addEventListener('click', () => {
            payButtons.forEach((entry) => {
                entry.classList.remove('active', 'btn-primary');
                entry.classList.add('btn-outline-secondary');
            });
            button.classList.add('active', 'btn-primary');
            button.classList.remove('btn-outline-secondary');
            pagamentoVisual = button.dataset.pay || 'dinheiro';
        });
    });

    clienteLimparBtn?.addEventListener('click', () => {
        clienteAtual = null;
        renderClienteSelecionado();
        resetClienteSearch({ focus: true });
    });

    descontoGeralTipo?.addEventListener('change', () => {
        discountSyncFeedback = null;
        recalc();
    });

    descontoGeralValor?.addEventListener('input', () => {
        discountSyncFeedback = null;
        recalc();
    });

    descontoGeralAplicarBtn?.addEventListener('click', () => {
        applyGeneralDiscountToSelectedDraft();
    });

    persistirCarrinhoBtn?.addEventListener('click', () => {
        persistLocalCart();
    });

    registrarPagamentoBtn?.addEventListener('click', () => {
        registerPaymentForSelectedDraft();
    });

    finalizarVendaBtn?.addEventListener('click', () => {
        finalizeSelectedDraft();
    });

    renderClienteSelecionado();
    recalc();
    clearRascunhoDetalhe();
    loadPdvStatus();
    carregarRascunhos().then(() => {
        if (initialVendaId > 0) {
            carregarDetalhesRascunho(initialVendaId);
        }
    });
})();
