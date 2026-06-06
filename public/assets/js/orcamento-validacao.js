/* ── ORÇAMENTO: Validação de OS e Seletor de Múltiplas OS ── */
/* Depende de: api/buscar_multiplas_os.php, api/validar_os_90_dias.php, api/buscar_os.php */

// ── Seletor de múltiplas OS (popup) ─────────────────────────────────────────

function abrirSeletorOS(ordens) {
  const existente = document.getElementById('overlay-multi-os');
  if (existente) existente.remove();

  const html = `
    <div id="overlay-multi-os" class="overlay open" style="align-items:center">
      <div class="modal" style="max-width:520px">
        <div class="modal-header">
          <h3>Múltiplas OS encontradas</h3>
          <button class="modal-close" onclick="fecharSeletorOS()">×</button>
        </div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
          Este telefone possui ${ordens.length} ordem(s) de serviço. Selecione qual deseja abrir:
        </p>
        <div style="display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto">
          ${ordens.map(os => `
            <button onclick="selecionarOS('${esc(os.id)}')"
              style="display:flex;align-items:center;justify-content:space-between;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:14px 16px;cursor:pointer;text-align:left;transition:border-color .15s;color:var(--txt);font-family:'Barlow',sans-serif"
              onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='var(--bd)'">
              <div>
                <div style="font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:800;color:var(--acc)">OS ${esc(os.id)}</div>
                <div style="font-size:13px;font-weight:600;margin-top:2px">${esc(os.nome_cliente || os.nome || '—')}</div>
                <div style="font-size:12px;color:var(--muted);margin-top:2px">${esc(os.equipamentos || '—')}</div>
              </div>
              <div style="text-align:right;flex-shrink:0;margin-left:14px">
                <div style="font-size:11px;color:var(--muted)">${esc(os.data_entrada || os.data || '—')}</div>
                <div style="margin-top:4px">${badgeStatusSimples(os.status)}</div>
              </div>
            </button>`).join('')}
        </div>
        <div class="modal-btns" style="margin-top:16px">
          <button class="btn btn-ghost" onclick="fecharSeletorOS()">Cancelar</button>
        </div>
      </div>
    </div>`;

  document.body.insertAdjacentHTML('beforeend', html);
}

function fecharSeletorOS() {
  const el = document.getElementById('overlay-multi-os');
  if (el) el.remove();
}

async function selecionarOS(osId) {
  fecharSeletorOS();
  const errEl = document.getElementById('busca-err');
  try {
    const r = await fetch('api/buscar_os.php?id=' + encodeURIComponent(osId));
    const data = await r.json();
    if (data.error) {
      if (errEl) { errEl.textContent = data.error; errEl.style.display = 'block'; }
      return;
    }
    carregarOS(data);
  } catch(e) {
    if (errEl) { errEl.textContent = 'Erro ao carregar OS.'; errEl.style.display = 'block'; }
  }
}

function badgeStatusSimples(s) {
  const map = { rascunho:'RASCUNHO', enviado:'ENVIADO', aprovado:'APROVADO', cancelado:'CANCELADO', pronto:'PRONTO', retirado:'RETIRADO' };
  const cor  = { rascunho:'#555', enviado:'var(--blue)', aprovado:'var(--green)', cancelado:'var(--red)', pronto:'var(--acc)', retirado:'#555' };
  const bg   = { rascunho:'rgba(85,85,85,.15)', enviado:'rgba(59,130,246,.12)', aprovado:'rgba(34,197,94,.12)', cancelado:'rgba(239,68,68,.1)', pronto:'rgba(245,158,11,.12)', retirado:'rgba(85,85,85,.1)' };
  return `<span style="font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;background:${bg[s]||bg.rascunho};color:${cor[s]||cor.rascunho}">${map[s]||s}</span>`;
}

// ── Validação de 90 dias ──────────────────────────────────────────────────────

async function validarOS90Dias(osId) {
  try {
    const r = await fetch('api/validar_os_90_dias.php?os_id=' + encodeURIComponent(osId));
    const d = await r.json();
    if (d.ok && d.bloqueado) {
      mostrarAlertaBloqueio(d.mensagem);
      return false;
    }
    return true;
  } catch(e) {
    return true; // se falhar, permite edição
  }
}

function mostrarAlertaBloqueio(mensagem) {
  const existente = document.getElementById('alerta-90-dias');
  if (existente) existente.remove();

  const html = `
    <div id="alerta-90-dias"
      style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.35);border-radius:8px;
             padding:14px 18px;margin-bottom:16px;display:flex;align-items:flex-start;gap:12px">
      <span style="font-size:20px;flex-shrink:0">⚠️</span>
      <div>
        <div style="font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;
                    color:var(--red);letter-spacing:.5px;margin-bottom:4px">EDIÇÃO BLOQUEADA</div>
        <div style="font-size:13px;color:#fca5a5">${esc(mensagem)}</div>
      </div>
    </div>`;

  const tela = document.getElementById('tela-os');
  if (tela) tela.insertAdjacentHTML('afterbegin', html);
}

function esc(s) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(String(s || '')));
  return d.innerHTML;
}
