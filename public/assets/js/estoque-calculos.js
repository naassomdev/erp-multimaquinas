/* ── ESTOQUE: Cálculo Automático de Preços ── */
/* Depende de: #p-custo, #p-margem, #p-valor, api/estoque_calcular.php */

let _calcTimer = null;

function onCustoOuMargemChange() {
  clearTimeout(_calcTimer);
  _calcTimer = setTimeout(calcularPrecoVenda, 400);
}

function onVendaChange() {
  clearTimeout(_calcTimer);
  _calcTimer = setTimeout(calcularMargem, 400);
}

async function calcularPrecoVenda() {
  const custo  = parseFloat(document.getElementById('p-custo')?.value)  || 0;
  const margem = parseFloat(document.getElementById('p-margem')?.value) || 0;
  if (custo <= 0) return;

  try {
    const r = await fetch('api/estoque_calcular.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tipo: 'venda', preco_custo: custo, margem_lucro: margem }),
    });
    const d = await r.json();
    if (d.ok) {
      const inp = document.getElementById('p-valor');
      if (inp) inp.value = d.preco_venda.toFixed(2);
    }
  } catch(e) {
    // fallback: cálculo local
    const venda = custo * (1 + margem / 100);
    const inp = document.getElementById('p-valor');
    if (inp) inp.value = venda.toFixed(2);
  }
}

async function calcularMargem() {
  const custo = parseFloat(document.getElementById('p-custo')?.value)  || 0;
  const venda = parseFloat(document.getElementById('p-valor')?.value)  || 0;
  if (custo <= 0 || venda <= 0) return;

  try {
    const r = await fetch('api/estoque_calcular.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tipo: 'margem', preco_custo: custo, preco_venda: venda }),
    });
    const d = await r.json();
    if (d.ok) {
      const inp = document.getElementById('p-margem');
      if (inp) inp.value = d.margem_lucro.toFixed(2);
    }
  } catch(e) {
    // fallback: cálculo local
    const margem = ((venda - custo) / custo) * 100;
    const inp = document.getElementById('p-margem');
    if (inp) inp.value = margem.toFixed(2);
  }
}

function limparCamposPreco() {
  ['p-custo','p-margem'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '0';
  });
}

function preencherCamposPreco(produto) {
  const campos = {
    'p-custo':  produto.preco_custo  || 0,
    'p-margem': produto.margem_lucro || 0,
  };
  Object.entries(campos).forEach(([id, val]) => {
    const el = document.getElementById(id);
    if (el) el.value = parseFloat(val).toFixed(2);
  });
}
