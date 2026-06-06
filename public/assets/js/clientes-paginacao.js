/* ── CLIENTES: Paginação e Busca ── */
/* Depende de: #inp-busca, #tbody, #paginacao, api/clientes_busca_avancada.php */

const POR_PAGINA = 20;
let paginaAtual = 1;
let _buscarTimer = null;
let todosClientes = [];
let filtrados = [];

async function carregar() {
  try {
    const r = await fetch('api/clientes.php');
    const d = await r.json();
    todosClientes = d.clientes || [];
    filtrados = todosClientes;
    renderTabela();
  } catch(e) {
    toast('Erro ao carregar clientes.', 'err');
  }
}

function buscar() {
  clearTimeout(_buscarTimer);
  _buscarTimer = setTimeout(() => {
    const q = document.getElementById('inp-busca').value.toLowerCase().trim();
    filtrados = q ? todosClientes.filter(c =>
      (c.nome||'').toLowerCase().includes(q) ||
      (c.telefone||'').includes(q) ||
      (c.fone||'').includes(q) ||
      (c.celular||'').includes(q) ||
      (c.email||'').toLowerCase().includes(q) ||
      (c.cidade||'').toLowerCase().includes(q) ||
      (c.nome_fantasia||'').toLowerCase().includes(q) ||
      (c.cpf_cnpj||'').replace(/\D/g,'').includes(q.replace(/\D/g,''))
    ) : todosClientes;
    paginaAtual = 1;
    renderTabela();
  }, 250);
}

function renderTabela() {
  const tbody = document.getElementById('tbody');
  const inicio = (paginaAtual - 1) * POR_PAGINA;
  const pagina = filtrados.slice(inicio, inicio + POR_PAGINA);
  if (!pagina.length) {
    tbody.innerHTML = '<tr class="empty-row"><td colspan="6">Nenhum cliente encontrado.</td></tr>';
    document.getElementById('paginacao').innerHTML = '';
    return;
  }
  tbody.innerHTML = pagina.map(c => `
    <tr>
      <td class="nome-cel">${esc(c.nome)}</td>
      <td class="tel-cel">${esc(c.fone || c.telefone || '—')}</td>
      <td class="doc-cel">${esc(c.cpf_cnpj || '—')}</td>
      <td style="color:var(--muted);font-size:12px">${esc(c.cidade || '—')}</td>
      <td><span style="font-family:'Barlow Condensed',sans-serif;font-size:13px;color:var(--muted)">${c.total_os || 0} OS</span></td>
      <td><div class="td-acoes">
        <button class="btn btn-ghost btn-sm" onclick="editarCliente(${c.id})">Editar</button>
        <a class="btn btn-ghost btn-sm" href="os.php?cliente_id=${c.id}&nome=${encodeURIComponent(c.nome)}&tel=${encodeURIComponent(c.fone||c.telefone||'')}&doc=${encodeURIComponent(c.cpf_cnpj||'')}">+ OS</a>
        <button class="btn btn-ghost btn-sm" onclick="abrirHistorico(${c.id}, '${esc(c.nome)}')">📋 Histórico</button>
        <button class="btn btn-red btn-sm" onclick="excluirCliente(${c.id},'${esc(c.nome)}')">Excluir</button>
      </div></td>
    </tr>`).join('');
  renderPaginacao();
}

function renderPaginacao() {
  const total = Math.ceil(filtrados.length / POR_PAGINA);
  const pg = document.getElementById('paginacao');
  if (total <= 1) {
    pg.innerHTML = `<span class="pg-info">${filtrados.length} cliente${filtrados.length !== 1 ? 's' : ''}</span>`;
    return;
  }
  const min = Math.max(1, paginaAtual - 3);
  const max = Math.min(total, paginaAtual + 3);
  let html = '';
  if (min > 1) html += `<button class="pg-btn" onclick="irPagina(1)">1</button>${min > 2 ? '<span style="color:var(--dim);padding:0 4px">…</span>' : ''}`;
  for (let i = min; i <= max; i++) {
    html += `<button class="pg-btn ${i === paginaAtual ? 'active' : ''}" onclick="irPagina(${i})">${i}</button>`;
  }
  if (max < total) html += `${max < total - 1 ? '<span style="color:var(--dim);padding:0 4px">…</span>' : ''}<button class="pg-btn" onclick="irPagina(${total})">${total}</button>`;
  html += `<span class="pg-info">${filtrados.length} clientes</span>`;
  pg.innerHTML = html;
}

function irPagina(p) { paginaAtual = p; renderTabela(); }
