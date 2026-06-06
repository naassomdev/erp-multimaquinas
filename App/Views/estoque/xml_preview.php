<?php
use App\Core\View;
/**
 * @var array  $preview     Estrutura retornada por NfeXmlImporter::parseXml()
 * @var string $csrf_token
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$num3  = fn(float $v): string => number_format($v, 3, ',', '.');
$dt    = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

$itens                  = $preview['itens'] ?? [];
$podeAlterarEmMassa     = \App\Core\Auth::temNivel('admin');
$margemPadraoEmMassa    = isset($itens[0]['margem_sugerida'])
    ? (float) $itens[0]['margem_sugerida']
    : \App\Services\Estoque\NfeXmlImporter::MARKUP_PADRAO;
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Conferencia da NF-e</h1>
            <p class="page-header__subtitle">
                Revise os itens, ajuste margem/preco e a vinculacao com pecas cadastradas.
                <strong>Nada e salvo ate voce clicar em "Confirmar Entrada".</strong>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/estoque/importar" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Cancelar
            </a>
        </div>
    </div>

    <!-- Cabecalho da nota -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="text-body-secondary text-uppercase small fw-semibold mb-1">Emitente</div>
                    <div class="fw-semibold"><?= View::e($preview['emitente']) ?></div>
                    <div class="text-mono small text-body-secondary mt-1">CNPJ <?= View::e($preview['cnpj']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-body-secondary text-uppercase small fw-semibold mb-1">Chave de acesso</div>
                    <div class="text-mono small break-all"><?= View::e($preview['chave_nfe']) ?></div>
                    <div class="text-body-secondary small mt-1">
                        Emissao: <span class="text-mono"><?= $dt($preview['data_emissao']) ?></span>
                        &middot; Vencimento (30d): <span class="text-mono"><?= $dt(date('Y-m-d', strtotime($preview['data_emissao'] . ' +30 days'))) ?></span>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <div class="text-body-secondary text-uppercase small fw-semibold mb-1">Valor total da NF-e</div>
                    <div class="fs-4 fw-bold text-primary text-mono"><?= $money((float)$preview['valor_total']) ?></div>
                    <div class="text-body-secondary small mt-1"><?= count($itens) ?> item(s)</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($itens)): ?>
        <div class="card shadow-sm">
            <div class="empty-state">
                <div class="empty-state__icon"><i class="ph ph-tray"></i></div>
                <h3 class="empty-state__title">XML sem itens</h3>
                <p class="empty-state__desc">O arquivo enviado nao contem itens (tag &lt;det&gt;).</p>
            </div>
        </div>
    <?php else: ?>

    <form method="POST" action="/estoque/importar/confirmar" id="formConfirmar">
        <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="chave_nfe"    value="<?= View::e($preview['chave_nfe']) ?>">
        <input type="hidden" name="emitente"     value="<?= View::e($preview['emitente']) ?>">
        <input type="hidden" name="cnpj"         value="<?= View::e($preview['cnpj']) ?>">
        <input type="hidden" name="valor_total"  value="<?= number_format((float)$preview['valor_total'], 2, '.', '') ?>">
        <input type="hidden" name="data_emissao" value="<?= View::e($preview['data_emissao']) ?>">

        <?php if ($podeAlterarEmMassa): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <div class="text-body-secondary text-uppercase small fw-semibold mb-1">Ajuste em massa</div>
                        <div class="fw-semibold">Aplicar a mesma margem percentual em todos os itens</div>
                        <div class="text-body-secondary small mt-1">
                            Use esta opcao para redefinir de uma vez a porcentagem sobre o custo para toda a NF-e.
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <label for="margem-massa" class="form-label small mb-1">Margem (%)</label>
                        <input type="text" inputmode="decimal" id="margem-massa"
                               class="form-control text-end text-mono"
                               value="<?= number_format($margemPadraoEmMassa, 2, ',', '') ?>">
                    </div>
                    <div class="col-sm-8 col-lg-4">
                        <button type="button" class="btn btn-outline-primary w-100" id="aplicar-margem-massa">
                            <i class="ph ph-arrows-clockwise me-1"></i> Aplicar em todos os itens
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="ph ph-list-checks me-1"></i> Itens da NF-e
                </div>
                <small class="text-body-secondary">
                    <strong>Margem (%)</strong> e <strong>Preco final</strong> sao editaveis &middot; ajustar um recalcula o outro.
                </small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>XML (cod./descricao)</th>
                            <th style="width:280px">Vincular a peca</th>
                            <th class="text-end">Qtd</th>
                            <th class="text-end">Custo</th>
                            <th class="text-end" style="width:120px">Margem (%)</th>
                            <th class="text-end" style="width:150px">Preco final</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($itens as $i => $it):
                        $idxName = "itens[{$i}]";
                        $vlrUnit = (float) $it['vlr_unit'];
                        $qty     = (float) $it['qty'];
                        $margem  = (float) $it['margem_sugerida'];
                        $preco   = (float) $it['preco_venda_sugerido'];
                        $sugId   = $it['sugestao_produto_id'] ?? null;
                        $sugTxt  = $sugId !== null
                            ? trim(($it['sugestao_codigo'] ?? '') . ' — ' . ($it['sugestao_descricao'] ?? ''), ' —')
                            : '';
                    ?>
                        <tr class="align-top" data-row-idx="<?= $i ?>">
                            <td>
                                <div class="fw-medium"><?= View::e($it['descricao']) ?></div>
                                <div class="text-mono small text-body-secondary mt-1">
                                    cod <?= View::e($it['codigo']) ?>
                                    <?php if (!empty($it['ean'])): ?>
                                        &middot; EAN <?= View::e((string)$it['ean']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($it['ncm'])): ?>
                                        &middot; NCM <?= View::e($it['ncm']) ?>
                                    <?php endif; ?>
                                    &middot; <?= View::e($it['unidade']) ?>
                                </div>
                                <?php if (!empty($it['necessidades_pendentes'])): ?>
                                    <span class="badge bg-warning text-dark mt-1" style="font-size:.65rem">
                                        <i class="ph ph-lightning me-1"></i>
                                        <?= (int)$it['necessidades_pendentes'] ?> necessidade(s) pendente(s)
                                    </span>
                                <?php endif; ?>

                                <input type="hidden" name="<?= $idxName ?>[codigo]"    value="<?= View::e($it['codigo']) ?>">
                                <input type="hidden" name="<?= $idxName ?>[descricao]" value="<?= View::e($it['descricao']) ?>">
                                <input type="hidden" name="<?= $idxName ?>[ncm]"       value="<?= View::e($it['ncm']) ?>">
                                <input type="hidden" name="<?= $idxName ?>[ean]"       value="<?= View::e((string)($it['ean'] ?? '')) ?>">
                                <input type="hidden" name="<?= $idxName ?>[unidade]"   value="<?= View::e($it['unidade']) ?>">
                                <input type="hidden" name="<?= $idxName ?>[qty]"       value="<?= number_format($qty, 3, '.', '') ?>">
                                <input type="hidden" name="<?= $idxName ?>[vlr_unit]"  value="<?= number_format($vlrUnit, 4, '.', '') ?>">
                            </td>

                            <td>
                                <div class="js-vinculo">
                                    <input type="hidden" name="<?= $idxName ?>[produto_id]" class="js-produto-id" value="<?= $sugId !== null ? (int)$sugId : '' ?>">

                                    <div class="js-vinculo-display border rounded p-2 small <?= $sugId !== null
                                            ? 'border-success bg-success-subtle'
                                            : 'border-info bg-info-subtle' ?>">
                                        <?php if ($sugId !== null): ?>
                                            <div class="fw-semibold text-success mb-1">
                                                <i class="ph ph-check-circle me-1"></i>
                                                Vinculada (match por <?= View::e((string)$it['sugestao_match']) ?>)
                                            </div>
                                            <div class="fw-medium"><?= View::e($sugTxt) ?></div>
                                            <div class="text-body-secondary mt-1" style="font-size:.7rem">
                                                Estoque: <strong><?= $num3((float)($it['estoque_atual'] ?? 0)) ?></strong>
                                                <?php if ($it['margem_atual'] !== null): ?>
                                                    &middot; Margem atual: <strong><?= number_format((float)$it['margem_atual'], 2, ',', '.') ?>%</strong>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="fw-semibold text-info">
                                                <i class="ph ph-plus-circle me-1"></i>
                                                Novo produto (sera cadastrado)
                                            </div>
                                            <div class="text-body-secondary mt-1" style="font-size:.7rem">
                                                Nenhuma peca encontrada por codigo ou EAN.
                                            </div>
                                        <?php endif; ?>
                                        <button type="button" class="js-trocar btn btn-link btn-sm p-0 mt-1" style="font-size:.7rem">
                                            Trocar / buscar manualmente
                                        </button>
                                    </div>

                                    <div class="js-vinculo-search d-none mt-2">
                                        <div class="input-icon">
                                            <i class="ph ph-magnifying-glass"></i>
                                            <input type="text" class="js-busca form-control form-control-sm"
                                                   placeholder="Codigo, descricao ou EAN..." autocomplete="off">
                                        </div>
                                        <div class="js-resultados mt-1 border rounded bg-body overflow-auto d-none" style="max-height:180px"></div>
                                        <div class="d-flex align-items-center gap-3 mt-2" style="font-size:.7rem">
                                            <button type="button" class="js-novo btn btn-link btn-sm p-0 text-info">+ Cadastrar como novo</button>
                                            <span class="text-body-secondary">&middot;</span>
                                            <button type="button" class="js-cancelar btn btn-link btn-sm p-0 text-body-secondary">Cancelar</button>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="text-end text-mono text-nowrap"><?= $num3($qty) ?></td>
                            <td class="text-end text-mono text-nowrap"><?= $money($vlrUnit) ?></td>

                            <td>
                                <input type="text" inputmode="decimal"
                                       name="<?= $idxName ?>[margem]"
                                       value="<?= number_format($margem, 2, ',', '') ?>"
                                       data-vlr-unit="<?= number_format($vlrUnit, 4, '.', '') ?>"
                                       class="js-margem form-control form-control-sm text-end text-mono">
                            </td>
                            <td>
                                <input type="text" inputmode="decimal"
                                       name="<?= $idxName ?>[preco_venda]"
                                       value="<?= number_format($preco, 2, ',', '') ?>"
                                       data-vlr-unit="<?= number_format($vlrUnit, 4, '.', '') ?>"
                                       class="js-preco form-control form-control-sm text-end text-mono fw-medium">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Acoes -->
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-between gap-3 mt-2">
            <a href="/estoque/importar" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar e enviar outro XML
            </a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="ph ph-check-circle me-1"></i> Confirmar Entrada (atualizar estoque)
            </button>
        </div>
    </form>

    <script>
    (function() {
        function parse(v) { return parseFloat(String(v).replace(',', '.')) || 0; }
        function fmt(v)   { return v.toFixed(2).replace('.', ','); }
        function atualizarPreco(inpMargem, inpPreco) {
            const vlrUnit = parse(inpMargem.dataset.vlrUnit);
            const margem = parse(inpMargem.value);
            inpPreco.value = fmt(vlrUnit * (1 + margem / 100));
        }
        function atualizarMargem(inpPreco, inpMargem) {
            const vlrUnit = parse(inpPreco.dataset.vlrUnit);
            const preco = parse(inpPreco.value);
            if (vlrUnit > 0) inpMargem.value = fmt((preco / vlrUnit - 1) * 100);
        }

        document.querySelectorAll('tr[data-row-idx]').forEach(tr => {
            const inpMargem = tr.querySelector('.js-margem');
            const inpPreco  = tr.querySelector('.js-preco');

            inpMargem.addEventListener('input', () => {
                atualizarPreco(inpMargem, inpPreco);
            });
            inpPreco.addEventListener('input', () => {
                atualizarMargem(inpPreco, inpMargem);
            });
        });

        const btnMargemMassa = document.getElementById('aplicar-margem-massa');
        const inpMargemMassa = document.getElementById('margem-massa');
        if (btnMargemMassa && inpMargemMassa) {
            btnMargemMassa.addEventListener('click', () => {
                const margem = fmt(parse(inpMargemMassa.value));
                inpMargemMassa.value = margem;

                document.querySelectorAll('.js-margem').forEach(inpMargem => {
                    inpMargem.value = margem;
                    const inpPreco = inpMargem.closest('tr')?.querySelector('.js-preco');
                    if (inpPreco) atualizarPreco(inpMargem, inpPreco);
                });
            });
        }

        document.querySelectorAll('.js-vinculo').forEach(box => {
            const display   = box.querySelector('.js-vinculo-display');
            const search    = box.querySelector('.js-vinculo-search');
            const inpId     = box.querySelector('.js-produto-id');
            const inpBusca  = box.querySelector('.js-busca');
            const results   = box.querySelector('.js-resultados');
            const btnTrocar = box.querySelector('.js-trocar');
            const btnNovo   = box.querySelector('.js-novo');
            const btnCancel = box.querySelector('.js-cancelar');

            btnTrocar.addEventListener('click', () => { display.classList.add('d-none'); search.classList.remove('d-none'); inpBusca.focus(); });
            btnCancel.addEventListener('click', () => { search.classList.add('d-none'); display.classList.remove('d-none'); results.classList.add('d-none'); });
            btnNovo.addEventListener('click', () => { inpId.value = ''; aplicarVisual(null, 'Novo produto (sera cadastrado)', 'Sera criado a partir dos dados do XML.'); });

            let timer = null;
            inpBusca.addEventListener('input', () => {
                const q = inpBusca.value.trim();
                clearTimeout(timer);
                if (q.length < 2) { results.classList.add('d-none'); results.innerHTML = ''; return; }
                timer = setTimeout(() => buscar(q), 300);
            });

            async function buscar(q) {
                try {
                    const r = await fetch('/api/produtos/busca?q=' + encodeURIComponent(q) + '&limit=10', { credentials: 'same-origin' });
                    const j = await r.json();
                    if (!j.ok || !Array.isArray(j.produtos) || j.produtos.length === 0) {
                        results.innerHTML = '<div class="p-3 text-body-secondary small">Nada encontrado.</div>';
                        results.classList.remove('d-none'); return;
                    }
                    results.innerHTML = j.produtos.map(p =>
                        `<button type="button" data-id="${p.id}" data-label="${escapeHtml(p.codigo)} — ${escapeHtml(p.descricao)}"
                                 class="d-block w-100 text-start px-3 py-2 small border-bottom btn btn-link text-decoration-none">
                            <div class="fw-medium">${escapeHtml(p.descricao)}</div>
                            <div class="text-body-secondary text-mono" style="font-size:.65rem">cod ${escapeHtml(p.codigo || '—')} · estoque ${Number(p.estoque_qty).toFixed(0)}</div>
                        </button>`
                    ).join('');
                    results.classList.remove('d-none');
                    results.querySelectorAll('button').forEach(b => {
                        b.addEventListener('click', () => {
                            inpId.value = b.dataset.id;
                            aplicarVisual(b.dataset.id, 'Vinculada manualmente', b.dataset.label);
                        });
                    });
                } catch (e) {
                    results.innerHTML = '<div class="p-3 text-danger small">Erro de rede.</div>';
                    results.classList.remove('d-none');
                }
            }

            function aplicarVisual(id, titulo, sub) {
                const isLinked = !!id;
                display.className = `js-vinculo-display border rounded p-2 small ${isLinked ? 'border-success bg-success-subtle' : 'border-info bg-info-subtle'}`;
                display.innerHTML = `
                    <div class="fw-semibold ${isLinked ? 'text-success' : 'text-info'} mb-1">
                        <i class="ph ph-${isLinked ? 'check-circle' : 'plus-circle'} me-1"></i> ${escapeHtml(titulo)}
                    </div>
                    <div class="fw-medium">${escapeHtml(sub)}</div>
                    <button type="button" class="js-trocar btn btn-link btn-sm p-0 mt-1" style="font-size:.7rem">Trocar / buscar manualmente</button>`;
                display.classList.remove('d-none');
                search.classList.add('d-none');
                results.classList.add('d-none');
                results.innerHTML = '';
                inpBusca.value = '';
                display.querySelector('.js-trocar').addEventListener('click', () => {
                    display.classList.add('d-none'); search.classList.remove('d-none'); inpBusca.focus();
                });
            }

            function escapeHtml(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }
        });
    })();
    </script>

    <?php endif; ?>
</div>
