/**
 * Catalog API v3 — Felap + ToolServiceNet + Bosch Tool Service + Milwaukee Tool
 *
 * GET /api/fontes
 * GET /api/marcas[?fonte=felap|tsn|bosch|milwaukee]
 * GET /api/modelos?fonte=felap&marca=2[&q=busca]
 * GET /api/modelos?fonte=tsn&brand=DW[&q=busca]
 * GET /api/produto?fonte=tsn&modelo=DCD996B_1
 * GET /api/produto?fonte=bosch&modelo=GSB+13+RE
 * GET /api/produto?fonte=bosch&typenr=060113C518
 * GET /api/produto?fonte=milwaukee&modelo=2804-20
 * GET /api/pdf?marca=2&modelo=DCD776-TIPO10.pdf   (Felap retrocompat)
 * DELETE /api/cache
 *
 * npm install express cors node-fetch
 * node felap-api.js
 */

const express = require('express');
const cors    = require('cors');

const app  = express();
const PORT = parseInt(process.env.PORT ?? '3001', 10);
// Em produção escuta SÓ no loopback — o PHP é o único cliente, atrás do nginx.
// Para expor publicamente, defina HOST=0.0.0.0 no .env (não recomendado).
const HOST = process.env.HOST ?? '127.0.0.1';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
const FELAP_BASE      = 'https://www.ferramentasfelap.com.br/pecasdereposicao';
const FELAP_PDF       = `${FELAP_BASE}/PDFs`;
const TSN_BASE        = 'https://www.toolservicenet.com/en';
const BOSCH_BASE      = 'https://www.boschtoolservice.com';
const BOSCH_LOCALE    = '/br/pt/bosch-pt/spareparts';
const MILWAUKEE_BASE  = 'https://www.milwaukeetool.com';
const MILWAUKEE_MAN   = `${MILWAUKEE_BASE}/support/manuals-and-downloads`;

const FELAP_MARCAS = {
  1:'Bosch',2:'DeWalt',3:'Makita',4:'Metabo',
  5:'Black & Decker',6:'Skil',7:'Dremel',
  8:'Milwaukee',9:'Hitachi / HiKOKI',10:'Ryobi',
};

const TSN_MARCAS = {
  DW:'DeWalt',CRM:'Craftsman',BD:'Black & Decker',
  BT:'Bostitch',PC:'Porter Cable',PR:'Proto',
};

const TSN_DW_CATS = [
  'DW007','DW008','DW009','DW010','DW011','DW012','DW013','DW014',
  'DW015','DW016','DW017','DW018','DW019','DW020','DW021','DW022',
  'DW023','DW024','DW025','DW026','DW027','DW028',
];

const BOSCH_MARCAS = ['Bosch Power Tools', 'Dremel'];
const MILWAUKEE_BRANDS = ['Milwaukee Tool'];

// Cache: key → { ts, data }
const cache    = new Map();
const CACHE_1H = 60*60*1000;
const CACHE_4H = 4*60*60*1000;

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------
async function getFetch() {
  try { return (await import('node-fetch')).default; }
  catch { return globalThis.fetch; }
}

async function fetchHTML(url, extraHeaders = {}) {
  const fetch = await getFetch();
  const res = await fetch(url, {
    headers: {
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
      'Accept': 'text/html,application/xhtml+xml,*/*',
      'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8',
      ...extraHeaders,
    },
    timeout: 18000,
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.text();
}

async function fetchJSON(url, extraHeaders = {}) {
  const fetch = await getFetch();
  const res = await fetch(url, {
    headers: {
      'User-Agent': 'Mozilla/5.0 (compatible; CatalogAPI/3.0)',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...extraHeaders,
    },
    timeout: 18000,
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

function cached(key, ttl, fn) {
  const hit = cache.get(key);
  if (hit && Date.now()-hit.ts < ttl) return Promise.resolve(hit.data);
  return fn().then(data => { cache.set(key,{ts:Date.now(),data}); return data; });
}

function sleep(ms) { return new Promise(r=>setTimeout(r,ms)); }

function extractInlineJSON(html, patterns) {
  for (const p of patterns) {
    const m = html.match(p);
    if (m) try { return JSON.parse(m[1]); } catch {}
  }
  return null;
}

// ---------------------------------------------------------------------------
// Felap
// ---------------------------------------------------------------------------
function parseFelapModelos(html) {
  const out = [];
  const m = html.match(/<select[^>]+id=["']?modelo["']?[^>]*>([\s\S]*?)<\/select>/i);
  if (!m) return out;
  const re = /<option[^>]+value=["']([^"']+)["'][^>]*>([^<]*)<\/option>/gi;
  let hit;
  while ((hit=re.exec(m[1]))!==null) {
    const v=hit[1].trim(), d=hit[2].trim();
    if (!v||v==='0') continue;
    const arquivo = v.endsWith('.pdf')?v:`${v}.pdf`;
    out.push({modelo:d||v,arquivo,url:`${FELAP_PDF}/${arquivo}`,fonte:'felap'});
  }
  return out;
}

function getFelapModelos(marcaId) {
  const nome = FELAP_MARCAS[marcaId];
  if (!nome) throw new Error(`Marca Felap inválida: ${marcaId}`);
  return cached(`felap_${marcaId}`, CACHE_1H, async () => {
    const url = `${FELAP_BASE}/buscarCatalogo.php?marca=${marcaId}&desc_marca=${encodeURIComponent(nome)}`;
    return parseFelapModelos(await fetchHTML(url));
  });
}

// ---------------------------------------------------------------------------
// TSN
// ---------------------------------------------------------------------------
function parseTSNLinks(html) {
  const out = new Map();
  const re = /href=["']([^"']*\/p\/([A-Z0-9][A-Z0-9_\-]+))["']/gi;
  let m;
  while ((m=re.exec(html))!==null) {
    const code=m[2],path=m[1];
    if (!out.has(code))
      out.set(code,{modelo:code,url:path.startsWith('http')?path:`${TSN_BASE}${path}`,fonte:'toolservicenet'});
  }
  return [...out.values()];
}

function parseTSNProduct(html, codigo, pageUrl) {
  const n = html.match(/<h1[^>]*>([^<]+)<\/h1>/i)||html.match(/<title>([^|<]+)/i);
  const nome = n?n[1].trim().replace(/ \| ServiceNet.*$/,''):codigo;
  const imagens = [];
  const ir = /src=["']([^"']+\.(gif|jpg|jpeg|png|svg))["']/gi;
  let m;
  while ((m=ir.exec(html))!==null) {
    if (/loader|logo|flag|icon/i.test(m[1])) continue;
    const f = m[1].startsWith('http')?m[1]:`https://www.toolservicenet.com${m[1]}`;
    if (!imagens.includes(f)) imagens.push(f);
  }
  return {codigo,nome,url:pageUrl,imagensVistaExplodida:imagens.slice(0,10),fonte:'toolservicenet'};
}

async function fetchTSNProduto(codigo) {
  return cached(`tsn_${codigo}`, CACHE_4H, async () => {
    const urls = [
      `https://www.toolservicenet.com/en/p/${codigo}`,
      `https://www.toolservicenet.com/en/search?text=${encodeURIComponent(codigo.replace('_',' '))}`,
    ];
    for (const url of urls) {
      try {
        const html = await fetchHTML(url);
        if (html.includes('addToCart')||html.includes('product-details'))
          return parseTSNProduct(html,codigo,url);
        const r = parseTSNLinks(html);
        if (r.length>0) { const h=await fetchHTML(r[0].url); return parseTSNProduct(h,r[0].modelo,r[0].url); }
      } catch {}
    }
    return null;
  });
}

async function getTSNModelos(brandCode) {
  const cats = brandCode==='DW'?TSN_DW_CATS:[];
  return cached(`tsn_cat_${brandCode}`, CACHE_4H, async () => {
    const todos = new Map();
    for (const cat of cats) {
      const url = `https://www.toolservicenet.com/en/Brand/${brandCode}/${cat}/c/${cat}?q=%3Arelevance%3Abrand%3A${brandCode}&pageSize=100`;
      try { parseTSNLinks(await fetchHTML(url)).forEach(p=>todos.set(p.modelo,p)); } catch {}
      await sleep(400);
    }
    return [...todos.values()];
  });
}

// ---------------------------------------------------------------------------
// Bosch Tool Service
// ---------------------------------------------------------------------------
function parseBoschLinks(html) {
  const out = new Map();
  const re = /href=["']([^"']*\/spareparts\/products\/([^/"']+)\/([^/"'?]+))["']/gi;
  let m;
  while ((m=re.exec(html))!==null) {
    const key = m[3];
    if (!out.has(key))
      out.set(key,{
        modelo: m[2].replace(/-/g,' ').replace(/\b\w/g,c=>c.toUpperCase()),
        typenr: key,
        url:    m[1].startsWith('http')?m[1]:`${BOSCH_BASE}${m[1]}`,
        fonte:  'bosch',
      });
  }
  return [...out.values()];
}

function parseBoschProduct(html, typenr, pageUrl) {
  const n = html.match(/<h1[^>]*>([^<]+)<\/h1>/i)||html.match(/<title>([^|<-]+)/i);
  const nome = n?n[1].trim():typenr;
  const imagens = [];
  const ir = /src=["']([^"']+\.(?:gif|jpg|jpeg|png))["']/gi;
  let m;
  while ((m=ir.exec(html))!==null) {
    if (/logo|icon|flag|banner|sprite|css/i.test(m[1])) continue;
    const f = m[1].startsWith('http')?m[1]:`${BOSCH_BASE}${m[1]}`;
    if (!imagens.includes(f)) imagens.push(f);
  }
  const partRe = /\b(1\d{9}|2\d{9}|F\d{9}|3\d{9})\b/g;
  const parts = [...new Set([...html.matchAll(partRe)].map(x=>x[1]))];
  return {typenr,nome,url:pageUrl,imagensVistaExplodida:imagens.slice(0,10),partNumbers:parts.slice(0,60),totalPecas:parts.length,fonte:'bosch'};
}

async function searchBoschProduto(query) {
  return cached(`bosch_${query}`, CACHE_4H, async () => {
    const searchUrl = `${BOSCH_BASE}${BOSCH_LOCALE}/search?q=${encodeURIComponent(query)}`;

    // Tenta JSON primeiro
    try {
      const apiUrl = `${BOSCH_BASE}${BOSCH_LOCALE}/search/products?q=${encodeURIComponent(query)}&pageSize=30`;
      const data   = await fetchJSON(apiUrl,{'Referer':searchUrl});
      if (data?.products?.length || data?.results?.length) {
        const lista = data.products??data.results??[];
        return lista.map(p=>({
          modelo:  p.name??p.code??query,
          typenr:  p.typeNumber??p.code??'',
          url:     p.url?(p.url.startsWith('http')?p.url:`${BOSCH_BASE}${p.url}`):'',
          imagem:  p.images?.[0]?.url??'',
          fonte:   'bosch',
        }));
      }
    } catch {}

    // Fallback HTML
    try {
      const html = await fetchHTML(searchUrl,{'Referer':`${BOSCH_BASE}${BOSCH_LOCALE}/search`});
      const res  = parseBoschLinks(html);
      if (res.length) return res;

      // Extrai via JSON embutido
      const state = extractInlineJSON(html,[
        /window\.ACC\s*=\s*(\{[\s\S]+?\});\s*<\/script>/,
        /__NEXT_DATA__\s*=\s*(\{[\s\S]+?\})\s*<\/script>/,
      ]);
      if (state?.searchPageData?.results)
        return state.searchPageData.results.map(p=>({
          modelo:p.name??p.code??query,typenr:p.typeNumber??'',
          url:p.url?(p.url.startsWith('http')?p.url:`${BOSCH_BASE}${p.url}`):'',fonte:'bosch',
        }));
    } catch {}
    return [];
  });
}

async function fetchBoschByTypenr(typenr) {
  return cached(`bosch_typenr_${typenr}`, CACHE_4H, async () => {
    const url  = `${BOSCH_BASE}${BOSCH_LOCALE}/search?q=${typenr}`;
    try {
      const html = await fetchHTML(url);
      const res  = parseBoschLinks(html);
      if (res.length) {
        // Busca a página de produto do primeiro resultado para extrair detalhes
        const prodHTML = await fetchHTML(res[0].url);
        return parseBoschProduct(prodHTML, res[0].typenr, res[0].url);
      }
    } catch (err) {
      console.error('[Bosch] Erro:', err.message);
    }
    return null;
  });
}

// ---------------------------------------------------------------------------
// Milwaukee Tool
// ---------------------------------------------------------------------------
function parseMilwaukeeHTML(html, modelo) {
  const docs = [];
  // Tenta JSON embutido (Next.js)
  const state = extractInlineJSON(html, [
    /__NEXT_DATA__\s*=\s*(\{[\s\S]+?\})\s*<\/script>/,
    /window\.__STATE__\s*=\s*(\{[\s\S]+?\})\s*;/,
  ]);
  if (state) {
    const str   = JSON.stringify(state);
    const pdfRe = /"(?:url|fileUrl|downloadUrl|href)"\s*:\s*"([^"]+\.pdf)"/gi;
    const titRe = /"(?:title|name|productName)"\s*:\s*"([^"]+)"/gi;
    const pdfs  = [...str.matchAll(pdfRe)].map(m=>m[1]);
    const tits  = [...str.matchAll(titRe)].map(m=>m[1]);
    if (pdfs.length) {
      pdfs.forEach((url,i) => {
        const f = url.startsWith('http')?url:`${MILWAUKEE_BASE}${url}`;
        docs.push({modelo,titulo:tits[i]??`Documento ${i+1}`,urlPDF:f,tipo:'PDF',fonte:'milwaukee'});
      });
      return docs;
    }
  }
  // Fallback: links PDF/ZIP
  const re = /href=["']([^"']+\.(?:pdf|zip))["'][^>]*>([^<]*)</gi;
  const vis = new Set();
  let m;
  while ((m=re.exec(html))!==null) {
    const url = m[1].startsWith('http')?m[1]:`${MILWAUKEE_BASE}${m[1]}`;
    if (vis.has(url)) continue; vis.add(url);
    const tipo = /manual|instruction/i.test(url)?'Manual':/part|spare|explod/i.test(url)?'Vista Explodida':'Download';
    docs.push({modelo,titulo:m[2].trim()||tipo,urlPDF:url,tipo,fonte:'milwaukee'});
  }
  return docs;
}

async function searchMilwaukee(modelo) {
  return cached(`mil_${modelo}`, CACHE_4H, async () => {
    // Tenta API JSON
    const apiUrls = [
      `${MILWAUKEE_BASE}/api/search/manuals?search=${encodeURIComponent(modelo)}&format=json`,
      `${MILWAUKEE_BASE}/support/manuals-and-downloads.json?search=${encodeURIComponent(modelo)}`,
    ];
    for (const url of apiUrls) {
      try {
        const data = await fetchJSON(url,{'Referer':MILWAUKEE_MAN});
        const lista = data.results??data.manuals??(Array.isArray(data)?data:[]);
        if (lista.length) return lista.map(item=>({
          modelo,sku:item.sku??'',titulo:item.title??item.name??'',
          tipo:item.documentType??'Manual',urlPDF:item.url??item.fileUrl??'',fonte:'milwaukee',
        }));
      } catch {}
    }
    // Fallback HTML
    const url  = `${MILWAUKEE_MAN}?search=${encodeURIComponent(modelo)}`;
    const html = await fetchHTML(url,{'Referer':MILWAUKEE_MAN});
    return parseMilwaukeeHTML(html, modelo);
  });
}

// ---------------------------------------------------------------------------
// Middleware
// ---------------------------------------------------------------------------
app.use(cors());
app.use(express.json());
app.use((req,_,next) => { console.log(`${new Date().toISOString()} ${req.method} ${req.url}`); next(); });

// ---------------------------------------------------------------------------
// Rotas
// ---------------------------------------------------------------------------

// GET /api/fontes
app.get('/api/fontes', (_,res) => res.json({ ok:true, fontes:[
  {id:'felap',     nome:'Ferramentas Felap (BR)',           url:`${FELAP_BASE}/search`,tipo:'PDF vistas explodidas'},
  {id:'tsn',       nome:'ToolServiceNet - Stanley B&D (US)',url:`https://www.toolservicenet.com`,tipo:'Catálogo peças + vistas'},
  {id:'bosch',     nome:'Bosch Tool Service (BR/PT)',       url:`${BOSCH_BASE}${BOSCH_LOCALE}/search`,tipo:'Peças + vistas explodidas'},
  {id:'milwaukee', nome:'Milwaukee Tool (US)',               url:MILWAUKEE_MAN,tipo:'Manuais + listas de peças PDF'},
]}));

// GET /api/marcas[?fonte=...]
app.get('/api/marcas', (req,res) => {
  const fonte = req.query.fonte ?? 'ambos';
  const lista = [];
  if (fonte!=='tsn'&&fonte!=='bosch'&&fonte!=='milwaukee')
    Object.entries(FELAP_MARCAS).forEach(([id,nome])=>lista.push({fonte:'felap',id:Number(id),nome}));
  if (fonte!=='felap'&&fonte!=='bosch'&&fonte!=='milwaukee')
    Object.entries(TSN_MARCAS).forEach(([code,nome])=>lista.push({fonte:'tsn',brandCode:code,nome}));
  if (fonte!=='felap'&&fonte!=='tsn'&&fonte!=='milwaukee')
    BOSCH_MARCAS.forEach(nome=>lista.push({fonte:'bosch',nome}));
  if (fonte!=='felap'&&fonte!=='tsn'&&fonte!=='bosch')
    MILWAUKEE_BRANDS.forEach(nome=>lista.push({fonte:'milwaukee',nome}));
  res.json({ok:true,total:lista.length,marcas:lista});
});

// GET /api/modelos?fonte=felap&marca=2  ou  ?fonte=tsn&brand=DW
app.get('/api/modelos', async (req,res) => {
  const fonte   = req.query.fonte  ?? 'felap';
  const marcaId = parseInt(req.query.marca);
  const brand   = req.query.brand  ?? 'DW';
  const busca   = (req.query.q??'').trim().toUpperCase();

  try {
    let modelos = [];
    if ((fonte==='felap'||fonte==='ambos') && marcaId) modelos = modelos.concat(await getFelapModelos(marcaId));
    if (fonte==='tsn'||fonte==='ambos') modelos = modelos.concat(await getTSNModelos(brand));

    if (busca) modelos = modelos.filter(m=>(m.modelo||'').toUpperCase().includes(busca));
    res.json({ok:true,total:modelos.length,modelos});
  } catch (err) { res.status(500).json({ok:false,erro:err.message}); }
});

// GET /api/produto?fonte=bosch&modelo=...  |  ?fonte=bosch&typenr=...  |  ?fonte=milwaukee&modelo=...  |  ?fonte=tsn&modelo=...
app.get('/api/produto', async (req,res) => {
  const fonte   = req.query.fonte  ?? 'tsn';
  const modelo  = req.query.modelo ?? '';
  const typenr  = req.query.typenr ?? '';

  if (!modelo && !typenr) return res.status(400).json({ok:false,erro:'"modelo" ou "typenr" obrigatório'});

  try {
    if (fonte==='tsn') {
      const prod = await fetchTSNProduto(modelo);
      if (!prod) return res.status(404).json({ok:false,erro:'Produto não encontrado no TSN'});
      return res.json({ok:true,produto:prod});
    }

    if (fonte==='bosch') {
      const prod = typenr
        ? await fetchBoschByTypenr(typenr)
        : (await searchBoschProduto(modelo));
      if (!prod) return res.status(404).json({ok:false,erro:'Produto não encontrado na Bosch'});
      const resultado = Array.isArray(prod) ? prod : [prod];
      return res.json({ok:true,total:resultado.length,produtos:resultado});
    }

    if (fonte==='milwaukee') {
      const docs = await searchMilwaukee(modelo);
      return res.json({ok:true,modelo,total:docs.length,documentos:docs});
    }

    if (fonte==='felap') {
      const arquivo = modelo.endsWith('.pdf')?modelo:`${modelo}.pdf`;
      return res.redirect(302,`${FELAP_PDF}/${arquivo}`);
    }

    res.status(400).json({ok:false,erro:'fonte inválida. Use: felap | tsn | bosch | milwaukee'});
  } catch (err) { res.status(500).json({ok:false,erro:err.message}); }
});

// GET /api/pdf?marca=2&modelo=... (Felap — retrocompat)
app.get('/api/pdf', async (req,res) => {
  const marcaId = parseInt(req.query.marca);
  const modelo  = req.query.modelo??'';
  if (!marcaId||!modelo) return res.status(400).json({ok:false,erro:'Parâmetros: marca e modelo obrigatórios'});
  try {
    const lista = await getFelapModelos(marcaId);
    const found = lista.find(m=>m.arquivo?.toLowerCase()===modelo.toLowerCase()||m.modelo?.toLowerCase()===modelo.toLowerCase());
    if (!found) return res.status(404).json({ok:false,erro:'Modelo não encontrado'});
    res.redirect(302,found.url);
  } catch (err) { res.status(500).json({ok:false,erro:err.message}); }
});

// DELETE /api/cache
app.delete('/api/cache', (_,res) => { cache.clear(); res.json({ok:true,mensagem:'Cache limpo'}); });

// Healthcheck (PM2/nginx)
app.get('/health', (_,res) => res.json({ ok:true, ts: Date.now() }));

// ---------------------------------------------------------------------------
app.listen(PORT, HOST, () => {
  console.log(`\n🔧 Catalog API v3 em http://${HOST}:${PORT}\n`);
  console.log(`  GET /api/fontes`);
  console.log(`  GET /api/marcas[?fonte=felap|tsn|bosch|milwaukee]`);
  console.log(`  GET /api/modelos?fonte=felap&marca=2`);
  console.log(`  GET /api/modelos?fonte=tsn&brand=DW`);
  console.log(`  GET /api/produto?fonte=bosch&modelo=GSB+13+RE`);
  console.log(`  GET /api/produto?fonte=bosch&typenr=060113C518`);
  console.log(`  GET /api/produto?fonte=milwaukee&modelo=2804-20`);
  console.log(`  GET /api/produto?fonte=tsn&modelo=DCD996B_1`);
  console.log(`  GET /api/pdf?marca=2&modelo=DCD776-TIPO10.pdf`);
  console.log(`  DELETE /api/cache`);
  console.log(`  GET /health\n`);
});

module.exports = app;
