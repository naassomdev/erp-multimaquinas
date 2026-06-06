<?php
use App\Core\View;
/** @var array $usuario */
?>
<div class="d-flex flex-column gap-4">

    <div class="page-header">
        <div>
            <h1 class="page-header__title"><i class="ph ph-file-text"></i> Novo Orcamento (Pre-pedido)</h1>
            <p class="page-header__subtitle">Gere um PDF ou envie diretamente para o WhatsApp do cliente</p>
        </div>
        <div class="page-header__actions">
            <a href="/dashboard" class="btn btn-outline-secondary btn-sm"><i class="ph ph-arrow-left"></i> Voltar</a>
        </div>
    </div>

    <div class="row g-4">
        <!-- LADO ESQUERDO: FORMULARIO -->
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="card-title mb-0">Dados do Pre-pedido</h5>
                </div>
                <div class="card-body">
                    <form id="prePedidoForm" autocomplete="off">
                        <!-- 1) Dados do Cliente -->
                        <h6 class="fw-bold mb-3"><i class="ph ph-user"></i> Dados do Cliente</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label class="form-label">Nome Completo</label>
                                <input type="text" class="form-control" id="cli_nome" placeholder="Nome do cliente" value="Joao Silva">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefone / WhatsApp</label>
                                <input type="text" class="form-control" id="cli_telefone" placeholder="(00) 00000-0000" value="(11) 99999-9999">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail</label>
                                <input type="email" class="form-control" id="cli_email" placeholder="cliente@email.com">
                            </div>
                        </div>

                        <!-- 2) Dados do Item Principal -->
                        <h6 class="fw-bold mb-3"><i class="ph ph-wrench"></i> Dados do Item Principal</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label class="form-label">Descricao do Item</label>
                                <input type="text" class="form-control" id="item_desc" placeholder="Ex: Motor Eletrico 2CV Weg" value="Motor Eletrico 2CV Weg">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quantidade</label>
                                <input type="number" class="form-control" id="item_qtd" value="1" min="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Valor Unitario (R$)</label>
                                <input type="number" class="form-control" step="0.01" id="item_valor" value="450.00">
                            </div>
                        </div>

                        <!-- 3) Foto do Item -->
                        <h6 class="fw-bold mb-3"><i class="ph ph-camera"></i> Foto do Item (Opcional)</h6>
                        <div class="upload-area border rounded p-4 text-center cursor-pointer mb-3" id="uploadArea">
                            <input type="file" id="item_foto" accept="image/*" hidden>
                            <div class="upload-content">
                                <i class="ph ph-camera fs-1 text-body-secondary"></i>
                                <p class="mb-0 mt-2">Clique ou arraste uma foto aqui</p>
                                <small class="text-body-secondary">A foto saira no PDF para aprovacao</small>
                            </div>
                            <div id="foto_preview_container" style="display:none;" class="mt-2 text-center foto-preview-compact">
                                <img id="foto_preview" src="" class="rounded border" style="max-height:150px;">
                                <div class="mt-1">
                                    <button type="button" class="btn btn-sm btn-danger" id="btnRemoverFoto">Remover Foto</button>
                                </div>
                            </div>
                        </div>

                        <!-- 4) Divulgacao Progressiva: detalhes avancados -->
                        <button type="button" class="btn btn-outline-warning w-100 d-flex justify-content-between align-items-center mb-3" id="btnToggleAdvanced" aria-expanded="false" aria-controls="advancedDetails">
                            <span><i class="ph ph-plus-circle me-1"></i> Adicionar Detalhes Tecnicos e Comerciais</span>
                            <i class="ph ph-caret-down" id="advancedCaret"></i>
                        </button>
                        <div id="advancedDetails" class="advanced-panel" hidden>
                            <div class="border rounded p-3 mb-3 bg-light-subtle">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-semibold mb-0">Vantagens</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddVantagem">
                                        <i class="ph ph-plus"></i> Adicionar
                                    </button>
                                </div>
                                <div id="vantagensList" class="d-flex flex-column gap-2"></div>
                            </div>

                            <div class="border rounded p-3 mb-3 bg-light-subtle">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-semibold mb-0">Aplicacoes</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddAplicacao">
                                        <i class="ph ph-plus"></i> Adicionar
                                    </button>
                                </div>
                                <div id="aplicacoesList" class="d-flex flex-column gap-2"></div>
                            </div>

                            <div class="border rounded p-3 bg-light-subtle">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-semibold mb-0">Especificacoes Tecnicas</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddSpec">
                                        <i class="ph ph-plus"></i> Adicionar Linha
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width:38%;">Chave</th>
                                                <th>Valor</th>
                                                <th style="width:56px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="specsBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- 5) Condicoes de Fechamento -->
                        <div class="border rounded p-3 mt-3">
                            <h6 class="fw-bold mb-3"><i class="ph ph-seal-check me-1"></i> Condicoes de Fechamento</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Prazo de Entrega</label>
                                    <input type="text" class="form-control" id="cond_prazo_entrega" value="Entrega em ate 7 dias uteis apos confirmacao">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Validade do Orcamento</label>
                                    <input type="text" class="form-control" id="cond_validade" value="Orcamento valido por 15 dias">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Condicoes de Pagamento</label>
                                    <input type="text" class="form-control" id="cond_pagamento" value="50% na confirmacao e 50% na entrega">
                                </div>
                            </div>
                        </div>

                        <!-- Acoes -->
                        <div class="d-grid mt-4">
                            <button type="button" class="btn btn-warning text-white fw-bold" id="btnPreview">
                                <i class="ph ph-eye"></i> Atualizar Pre-visualizacao
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- LADO DIREITO: PREVIEW A4 -->
        <div class="col-lg-6">
            <div class="a4-paper" id="pdfPreview">
                <header class="pdf-header is-centered" id="previewHeader">
                    <div class="pdf-header-photo" id="prev_header_photo_wrap" style="display:none;">
                        <img id="prev_header_img" src="" alt="Foto do item">
                    </div>
                    <div class="pdf-header-brand">
                        <div class="pdf-logo-center-wrap">
                            <img src="/img/logo.png" alt="Multi Máquinas" class="pdf-logo-img">
                            <div class="pdf-company-address">
                                C.N.P.J: 24.834.490/0001-90 &nbsp; Inscr. Estadual: 190.231.391.110<br>
                                Av. Brg. Jose Vicente Faria Lima, 1477 - Atibaia Jardim, Atibaia - SP, 12942-655
                            </div>
                        </div>
                        <div class="pdf-company-info">
                            <strong>Orcamento Pre-pedido</strong><br>
                            No 0001 / <?= date('Y') ?><br>
                            Data: <?= date('d/m/Y') ?>
                        </div>
                    </div>
                </header>

                <section class="pdf-section">
                    <div class="pdf-section-title">Dados do Cliente</div>
                    <div class="kv-grid">
                        <div><strong>Nome:</strong> <span id="prev_nome">Joao Silva</span></div>
                        <div><strong>WhatsApp:</strong> <span id="prev_telefone">(11) 99999-9999</span></div>
                        <div class="span-2"><strong>E-mail:</strong> <span id="prev_email">Nao informado</span></div>
                    </div>
                </section>

                <section class="pdf-section">
                    <div class="pdf-section-title">Descricao do Pedido</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50%;">Item</th>
                                <th style="width:15%;text-align:center;">Qtd</th>
                                <th style="width:15%;text-align:right;">V. Unit.</th>
                                <th style="width:20%;text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="prev_desc">Motor Eletrico 2CV Weg</td>
                                <td id="prev_qtd" style="text-align:center;">1</td>
                                <td id="prev_unit" style="text-align:right;">R$ 450,00</td>
                                <td id="prev_total" style="text-align:right;font-weight:bold;">R$ 450,00</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="pdf-section" id="prev_vantagens_section" style="display:none;">
                    <div class="pdf-section-title">Vantagens</div>
                    <ul class="pdf-list" id="prev_vantagens"></ul>
                </section>

                <section class="pdf-section" id="prev_aplicacoes_section" style="display:none;">
                    <div class="pdf-section-title">Aplicacoes</div>
                    <ul class="pdf-list" id="prev_aplicacoes"></ul>
                </section>

                <section class="pdf-section" id="prev_specs_section" style="display:none;">
                    <div class="pdf-section-title">Especificacoes Tecnicas</div>
                    <table class="pdf-table">
                        <tbody id="prev_specs_body"></tbody>
                    </table>
                </section>

                <section class="pdf-section pdf-terms">
                    <div class="pdf-section-title">Condicoes de Fechamento</div>
                    <div class="kv-grid">
                        <div class="span-2"><strong>Prazo de Entrega:</strong> <span id="prev_prazo">Entrega em ate 7 dias uteis apos confirmacao</span></div>
                        <div class="span-2"><strong>Condicoes de Pagamento:</strong> <span id="prev_pagamento">50% na confirmacao e 50% na entrega</span></div>
                        <div class="span-2"><strong>Validade:</strong> <span id="prev_validade">Orcamento valido por 15 dias</span></div>
                    </div>
                </section>

                <footer class="pdf-footer">
                    <p><strong>Multimaquinas Assistencia Tecnica</strong> | Contato: (00) 0000-0000</p>
                </footer>
            </div>

            <div class="d-flex gap-2 justify-content-end flex-wrap mt-3">
                <button type="button" class="btn btn-outline-success" data-pp-action="whatsapp">
                    <i class="ph ph-whatsapp-logo"></i> Enviar WhatsApp
                </button>
                <button type="button" class="btn btn-outline-secondary" data-pp-action="email">
                    <i class="ph ph-envelope"></i> Enviar E-mail
                </button>
                <button type="button" class="btn btn-primary" data-pp-action="pdf">
                    <i class="ph ph-printer"></i> Imprimir / Salvar PDF
                </button>
            </div>
            <p id="pp_status" class="small text-body-secondary text-end mt-2" style="display:none;"></p>
        </div>
    </div>

</div>

<style>
.upload-area {
    border: 2px dashed var(--bs-border-color) !important;
    background-color: var(--bs-tertiary-bg);
    transition: all 0.2s ease;
}
.upload-area:hover,
.upload-area.is-dragover {
    border-color: var(--bs-warning) !important;
    background-color: var(--bs-warning-bg-subtle);
}
.upload-area.has-file .upload-content {
    display: none;
}
.foto-preview-compact {
    padding: 0.25rem 0;
}
.advanced-panel {
    animation: ppFadeIn 0.18s ease;
}
@keyframes ppFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Estilo do PDF A4 */
.a4-paper {
    background: #fff;
    width: 100%;
    max-width: 21cm;
    min-height: 29.7cm;
    margin: 0 auto;
    padding: 2cm;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    border-radius: 4px;
    font-family: 'Times New Roman', Times, serif;
    color: #333;
    box-sizing: border-box;
    position: relative;
}
.pdf-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #efb810;
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}
.pdf-header.is-centered .pdf-header-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 0.45rem;
}
.pdf-header.has-photo {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}
.pdf-header.is-centered {
    display: block;
}
.pdf-header-photo {
    flex: 0 0 36%;
    max-width: 220px;
}
.pdf-header-photo img {
    width: 100%;
    max-width: 220px;
    max-height: 140px;
    object-fit: contain;
    border: 0;
    box-shadow: none;
    background: transparent;
    padding: 0;
}
.pdf-header-brand {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    text-align: right;
    gap: 0.4rem;
    flex: 1 1 auto;
}
.pdf-logo-center-wrap {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}
.pdf-logo-img {
    max-height: 82px;
    width: auto;
}
.pdf-company-address {
    font-size: 0.65rem;
    color: #444;
    margin-top: 4px;
    line-height: 1.15;
    max-width: 390px;
    text-align: right;
}
.pdf-company-info {
    text-align: right;
    font-size: 0.85rem;
    line-height: 1.4;
    color: #555;
}
.pdf-header.is-centered .pdf-company-info {
    text-align: center;
}
.pdf-header.has-photo .pdf-company-info {
    text-align: right;
}
.pdf-header.is-centered .pdf-logo-center-wrap {
    align-items: center;
}
.pdf-header.is-centered .pdf-company-address {
    text-align: center;
}
.pdf-section { margin-bottom: 1.4rem; }
.pdf-section-title {
    font-size: 1.05rem;
    font-weight: bold;
    color: #2c3e50;
    border-left: 4px solid #efb810;
    padding-left: 0.55rem;
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.kv-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem 1rem;
    font-size: 0.92rem;
}
.kv-grid .span-2 { grid-column: span 2; }
.pdf-table { width: 100%; border-collapse: collapse; }
.pdf-table th {
    background-color: #fdf5d3;
    color: #2c3e50;
    padding: 0.45rem;
    border-bottom: 2px solid #efb810;
    font-size: 0.88rem;
    text-align: left;
}
.pdf-table td {
    padding: 0.62rem 0.5rem;
    border-bottom: 1px solid #eee;
    font-size: 0.92rem;
}
.pdf-list {
    margin: 0;
    padding-left: 1.1rem;
    font-size: 0.9rem;
}
.pdf-list li {
    margin-bottom: 0.25rem;
}
.pdf-footer {
    margin-top: 2rem;
    padding-top: 1rem;
    border-top: 1px solid #eee;
    text-align: center;
    font-size: 0.8rem;
    color: #777;
}
@media print {
    body * { visibility: hidden; }
    .a4-paper, .a4-paper * { visibility: visible; }
    .a4-paper {
        position: absolute;
        left: 0;
        top: 0;
        margin: 0;
        box-shadow: none;
        width: 100%;
        border-radius: 0;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const cliNome = document.getElementById('cli_nome');
    const cliTel = document.getElementById('cli_telefone');
    const cliEmail = document.getElementById('cli_email');
    const itemDesc = document.getElementById('item_desc');
    const itemQtd = document.getElementById('item_qtd');
    const itemValor = document.getElementById('item_valor');
    const condPrazo = document.getElementById('cond_prazo_entrega');
    const condPag = document.getElementById('cond_pagamento');
    const condVal = document.getElementById('cond_validade');

    const prevNome = document.getElementById('prev_nome');
    const prevTel = document.getElementById('prev_telefone');
    const prevEmail = document.getElementById('prev_email');
    const prevDesc = document.getElementById('prev_desc');
    const prevQtd = document.getElementById('prev_qtd');
    const prevUnit = document.getElementById('prev_unit');
    const prevTotal = document.getElementById('prev_total');
    const prevPrazo = document.getElementById('prev_prazo');
    const prevPag = document.getElementById('prev_pagamento');
    const prevVal = document.getElementById('prev_validade');

    const uploadArea = document.getElementById('uploadArea');
    const itemFoto = document.getElementById('item_foto');
    const fotoPreviewContainer = document.getElementById('foto_preview_container');
    const fotoPreview = document.getElementById('foto_preview');
    const btnRemoverFoto = document.getElementById('btnRemoverFoto');
    const previewHeader = document.getElementById('previewHeader');
    const prevHeaderPhotoWrap = document.getElementById('prev_header_photo_wrap');
    const prevHeaderImg = document.getElementById('prev_header_img');
    const btnPreview = document.getElementById('btnPreview');

    const btnToggleAdvanced = document.getElementById('btnToggleAdvanced');
    const advancedDetails = document.getElementById('advancedDetails');
    const advancedCaret = document.getElementById('advancedCaret');

    const vantagensList = document.getElementById('vantagensList');
    const aplicacoesList = document.getElementById('aplicacoesList');
    const specsBody = document.getElementById('specsBody');

    const prevVantagensSection = document.getElementById('prev_vantagens_section');
    const prevAplicacoesSection = document.getElementById('prev_aplicacoes_section');
    const prevSpecsSection = document.getElementById('prev_specs_section');
    const prevVantagens = document.getElementById('prev_vantagens');
    const prevAplicacoes = document.getElementById('prev_aplicacoes');
    const prevSpecsBody = document.getElementById('prev_specs_body');

    const statusEl = document.getElementById('pp_status');
    const actionBtns = document.querySelectorAll('[data-pp-action]');

    let cacheSlug = null;
    let cachePayload = null;
    let fotoBlobUrl = '';

    const fmtBrl = val => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);

    function addListRow(target, placeholder, value = '') {
        const row = document.createElement('div');
        row.className = 'input-group';
        row.innerHTML = `
            <input type="text" class="form-control pp-list-input" placeholder="${placeholder}" value="${escapeHtmlAttr(value)}">
            <button type="button" class="btn btn-outline-danger pp-remove-row"><i class="ph ph-trash"></i></button>
        `;
        target.appendChild(row);
    }

    function addSpecRow(chave = '', valor = '') {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control form-control-sm pp-spec-key" placeholder="Ex: Tensao" value="${escapeHtmlAttr(chave)}"></td>
            <td><input type="text" class="form-control form-control-sm pp-spec-value" placeholder="Ex: 220V" value="${escapeHtmlAttr(valor)}"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger pp-remove-spec"><i class="ph ph-trash"></i></button></td>
        `;
        specsBody.appendChild(tr);
    }

    function getListValues(target) {
        return Array.from(target.querySelectorAll('.pp-list-input'))
            .map(i => i.value.trim())
            .filter(Boolean);
    }

    function getSpecValues() {
        return Array.from(specsBody.querySelectorAll('tr')).map(row => {
            const chave = row.querySelector('.pp-spec-key')?.value.trim() || '';
            const valor = row.querySelector('.pp-spec-value')?.value.trim() || '';
            return { chave, valor };
        }).filter(s => s.chave && s.valor);
    }

    function renderConditionalList(sectionEl, listEl, values) {
        if (!values.length) {
            sectionEl.style.display = 'none';
            listEl.innerHTML = '';
            return;
        }
        listEl.innerHTML = values.map(v => `<li>${escapeHtml(v)}</li>`).join('');
        sectionEl.style.display = '';
    }

    function renderSpecs(values) {
        if (!values.length) {
            prevSpecsSection.style.display = 'none';
            prevSpecsBody.innerHTML = '';
            return;
        }
        prevSpecsBody.innerHTML = values.map(s => `
            <tr>
                <td style="width:34%;font-weight:bold;">${escapeHtml(s.chave)}</td>
                <td>${escapeHtml(s.valor)}</td>
            </tr>
        `).join('');
        prevSpecsSection.style.display = '';
    }

    function updateHeaderMode() {
        const hasPhoto = !!prevHeaderImg.getAttribute('src');
        if (hasPhoto) {
            previewHeader.classList.remove('is-centered');
            previewHeader.classList.add('has-photo');
            prevHeaderPhotoWrap.style.display = '';
        } else {
            previewHeader.classList.remove('has-photo');
            previewHeader.classList.add('is-centered');
            prevHeaderPhotoWrap.style.display = 'none';
        }
    }

    function updatePreview() {
        prevNome.textContent = cliNome.value.trim() || 'Nao informado';
        prevTel.textContent = cliTel.value.trim() || 'Nao informado';
        prevEmail.textContent = cliEmail.value.trim() || 'Nao informado';

        prevDesc.textContent = itemDesc.value.trim() || '-';
        prevQtd.textContent = itemQtd.value || '0';

        const valor = parseFloat(itemValor.value) || 0;
        const qtd = parseInt(itemQtd.value, 10) || 0;
        const total = valor * qtd;

        prevUnit.textContent = fmtBrl(valor);
        prevTotal.textContent = fmtBrl(total);

        prevPrazo.textContent = condPrazo.value.trim() || 'Sob consulta';
        prevPag.textContent = condPag.value.trim() || 'A combinar';
        prevVal.textContent = condVal.value.trim() || 'Orcamento valido por 15 dias';

        renderConditionalList(prevVantagensSection, prevVantagens, getListValues(vantagensList));
        renderConditionalList(prevAplicacoesSection, prevAplicacoes, getListValues(aplicacoesList));
        renderSpecs(getSpecValues());
        updateHeaderMode();
    }

    function setFotoFromFile(file) {
        if (!file) return;
        if (fotoBlobUrl) URL.revokeObjectURL(fotoBlobUrl);
        fotoBlobUrl = URL.createObjectURL(file);
        fotoPreview.src = fotoBlobUrl;
        uploadArea.classList.add('has-file');
        fotoPreviewContainer.style.display = 'block';

        prevHeaderImg.src = fotoBlobUrl;
        updateHeaderMode();
        cacheSlug = null;
    }

    function clearFoto() {
        itemFoto.value = '';
        fotoPreview.src = '';
        prevHeaderImg.removeAttribute('src');
        uploadArea.classList.remove('has-file');
        fotoPreviewContainer.style.display = 'none';
        updateHeaderMode();
        if (fotoBlobUrl) {
            URL.revokeObjectURL(fotoBlobUrl);
            fotoBlobUrl = '';
        }
        cacheSlug = null;
    }

    btnToggleAdvanced.addEventListener('click', () => {
        const expanded = btnToggleAdvanced.getAttribute('aria-expanded') === 'true';
        btnToggleAdvanced.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        advancedDetails.hidden = expanded;
        advancedCaret.className = expanded ? 'ph ph-caret-down' : 'ph ph-caret-up';
    });

    document.getElementById('btnAddVantagem').addEventListener('click', () => {
        addListRow(vantagensList, 'Ex: Maior durabilidade');
    });
    document.getElementById('btnAddAplicacao').addEventListener('click', () => {
        addListRow(aplicacoesList, 'Ex: Uso continuo industrial');
    });
    document.getElementById('btnAddSpec').addEventListener('click', () => {
        addSpecRow();
    });

    vantagensList.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.pp-remove-row');
        if (!btn) return;
        btn.closest('.input-group')?.remove();
        updatePreview();
    });
    aplicacoesList.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.pp-remove-row');
        if (!btn) return;
        btn.closest('.input-group')?.remove();
        updatePreview();
    });
    specsBody.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.pp-remove-spec');
        if (!btn) return;
        btn.closest('tr')?.remove();
        updatePreview();
    });

    [vantagensList, aplicacoesList, specsBody].forEach(el => {
        el.addEventListener('input', () => {
            cacheSlug = null;
            updatePreview();
        });
    });

    uploadArea.addEventListener('click', (ev) => {
        if (!ev.target.closest('#btnRemoverFoto')) {
            itemFoto.click();
        }
    });
    uploadArea.addEventListener('dragover', (ev) => {
        ev.preventDefault();
        uploadArea.classList.add('is-dragover');
    });
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('is-dragover');
    });
    uploadArea.addEventListener('drop', (ev) => {
        ev.preventDefault();
        uploadArea.classList.remove('is-dragover');
        const file = ev.dataTransfer?.files?.[0] || null;
        if (!file) return;
        if (!file.type.startsWith('image/')) return;
        const dt = new DataTransfer();
        dt.items.add(file);
        itemFoto.files = dt.files;
        setFotoFromFile(file);
    });

    itemFoto.addEventListener('change', () => {
        const file = itemFoto.files?.[0] || null;
        if (!file) return;
        setFotoFromFile(file);
    });
    btnRemoverFoto.addEventListener('click', clearFoto);

    btnPreview.addEventListener('click', updatePreview);

    [cliNome, cliTel, cliEmail, itemDesc, itemQtd, itemValor, condPrazo, condPag, condVal].forEach(input => {
        input.addEventListener('input', () => {
            cacheSlug = null;
            updatePreview();
        });
    });

    function showStatus(msg, kind = 'info') {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.display = 'block';
        statusEl.className = 'small text-end mt-2 ' + (kind === 'error' ? 'text-danger' : (kind === 'ok' ? 'text-success' : 'text-body-secondary'));
    }

    function snapshotForm() {
        return JSON.stringify({
            nome: cliNome.value,
            tel: cliTel.value,
            email: cliEmail.value,
            desc: itemDesc.value,
            qtd: itemQtd.value,
            valor: itemValor.value,
            vantagens: getListValues(vantagensList),
            aplicacoes: getListValues(aplicacoesList),
            specs: getSpecValues(),
            prazo: condPrazo.value,
            pag: condPag.value,
            validade: condVal.value,
            foto: (itemFoto.files[0]?.name || '') + (itemFoto.files[0]?.size || ''),
        });
    }

    function validar() {
        if (!cliNome.value.trim()) return 'Informe o nome do cliente.';
        if (!itemDesc.value.trim()) return 'Informe a descricao do item.';
        if ((parseInt(itemQtd.value, 10) || 0) < 1) return 'Quantidade deve ser >= 1.';
        if ((parseFloat(itemValor.value) || 0) <= 0) return 'Valor unitario invalido.';
        if (cliEmail.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cliEmail.value.trim())) {
            return 'E-mail invalido.';
        }
        return null;
    }

    let lastSnapshot = '';

    async function salvarPrePedido() {
        const erro = validar();
        if (erro) { showStatus(erro, 'error'); return null; }

        const snap = snapshotForm();
        if (cacheSlug && cachePayload && snap === lastSnapshot) {
            return cachePayload;
        }

        showStatus('Salvando orcamento...');
        actionBtns.forEach(b => b.disabled = true);

        try {
            const fd = new FormData();
            fd.append('nome', cliNome.value.trim());
            fd.append('telefone', cliTel.value.trim());
            fd.append('email', cliEmail.value.trim());
            fd.append('descricao', itemDesc.value.trim());
            fd.append('qtd', itemQtd.value);
            fd.append('valor', itemValor.value);
            fd.append('vantagens_json', JSON.stringify(getListValues(vantagensList)));
            fd.append('aplicacoes_json', JSON.stringify(getListValues(aplicacoesList)));
            fd.append('especificacoes_json', JSON.stringify(getSpecValues()));
            fd.append('prazo_entrega', condPrazo.value.trim());
            fd.append('cond_pagamento', condPag.value.trim());
            fd.append('validade_orcamento', condVal.value.trim());
            if (itemFoto.files[0]) fd.append('foto', itemFoto.files[0]);

            const res = await fetch('/api/pre-pedido', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => ({ ok: false, error: 'Resposta invalida' }));

            if (!res.ok || !json.ok) {
                showStatus(json.error || `Falha (HTTP ${res.status})`, 'error');
                return null;
            }

            cacheSlug = json.slug;
            cachePayload = json;
            lastSnapshot = snap;
            showStatus(`Orcamento No ${json.numero} salvo.`, 'ok');
            return json;
        } catch (err) {
            showStatus('Erro de rede: ' + err.message, 'error');
            return null;
        } finally {
            actionBtns.forEach(b => b.disabled = false);
        }
    }

    actionBtns.forEach(btn => {
        btn.addEventListener('click', async () => {
            const data = await salvarPrePedido();
            if (!data) return;

            const action = btn.dataset.ppAction;
            if (action === 'pdf') {
                window.open(data.url_imprimir, '_blank');
            } else if (action === 'whatsapp') {
                window.open(data.url_whatsapp, '_blank');
            } else if (action === 'email') {
                window.location.href = data.url_mailto;
            }
        });
    });

    addListRow(vantagensList, 'Ex: Maior durabilidade');
    addListRow(aplicacoesList, 'Ex: Uso continuo industrial');
    addSpecRow();
    updatePreview();
});

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function escapeHtmlAttr(str) {
    return escapeHtml(String(str ?? ''));
}
</script>
