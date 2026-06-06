<?php
use App\Core\View;
/**
 * @var array $registro
 * @var bool  $auto_print
 */
$cliente = $registro['cliente'] ?? [];
$item = $registro['item'] ?? [];
$detalhes = $registro['detalhes'] ?? [];
$condicoes = $registro['condicoes'] ?? [];

$vantagens = array_values(array_filter((array)($detalhes['vantagens'] ?? []), static fn($v): bool => trim((string)$v) !== ''));
$aplicacoes = array_values(array_filter((array)($detalhes['aplicacoes'] ?? []), static fn($v): bool => trim((string)$v) !== ''));
$especificacoes = array_values(array_filter((array)($detalhes['especificacoes'] ?? []), static function($s): bool {
    return is_array($s)
        && trim((string)($s['chave'] ?? '')) !== ''
        && trim((string)($s['valor'] ?? '')) !== '';
}));

$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt = static function(?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    return $t ? date('d/m/Y', $t) : '';
};

$validadePadrao = '';
if (!empty($registro['criado_em'])) {
    $validadePadrao = 'Orcamento valido por 15 dias (ate ' . date('d/m/Y', strtotime($registro['criado_em']) + (15 * 86400)) . ')';
}

$prazoEntrega = trim((string)($condicoes['prazo_entrega'] ?? ''));
$condPagamento = trim((string)($condicoes['cond_pagamento'] ?? ''));
$validadeOrcamento = trim((string)($condicoes['validade_orcamento'] ?? ''));
if ($prazoEntrega === '') $prazoEntrega = 'Sob consulta';
if ($condPagamento === '') $condPagamento = 'A combinar';
if ($validadeOrcamento === '') $validadeOrcamento = $validadePadrao !== '' ? $validadePadrao : 'Orcamento valido por 15 dias';
?>
<div class="pp-pub-wrap">
    <div class="a4-paper">

        <header class="pdf-header <?= !empty($registro['foto_url']) ? 'has-photo' : 'is-centered' ?>">
            <?php if (!empty($registro['foto_url'])): ?>
                <div class="pdf-header-photo">
                    <img src="<?= View::e((string)$registro['foto_url']) ?>" alt="Foto do item">
                </div>
            <?php endif; ?>
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
                    No <?= View::e($registro['numero'] ?? '—') ?><br>
                    Data: <?= View::e($dt($registro['criado_em'] ?? null)) ?>
                </div>
            </div>
        </header>

        <section class="pdf-section">
            <div class="pdf-section-title">Dados do Cliente</div>
            <div class="kv-grid">
                <div><strong>Nome:</strong> <?= View::e($cliente['nome'] ?? '—') ?></div>
                <div><strong>WhatsApp:</strong> <?= View::e($cliente['telefone'] ?? '—') ?></div>
                <div class="span-2"><strong>E-mail:</strong> <?= View::e($cliente['email'] ?? '—') ?: '—' ?></div>
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
                        <td><?= View::e($item['descricao'] ?? '—') ?></td>
                        <td style="text-align:center;"><?= (int)($item['qtd'] ?? 0) ?></td>
                        <td style="text-align:right;"><?= $money((float)($item['valor'] ?? 0)) ?></td>
                        <td style="text-align:right;font-weight:bold;"><?= $money((float)($item['total'] ?? 0)) ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right;font-weight:bold;">Total Geral</td>
                        <td style="text-align:right;font-weight:bold;font-size:1.05rem;">
                            <?= $money((float)($item['total'] ?? 0)) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <?php if (!empty($vantagens)): ?>
        <section class="pdf-section">
            <div class="pdf-section-title">Vantagens</div>
            <ul class="pdf-list">
                <?php foreach ($vantagens as $v): ?>
                    <li><?= View::e((string)$v) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if (!empty($aplicacoes)): ?>
        <section class="pdf-section">
            <div class="pdf-section-title">Aplicacoes</div>
            <ul class="pdf-list">
                <?php foreach ($aplicacoes as $a): ?>
                    <li><?= View::e((string)$a) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if (!empty($especificacoes)): ?>
        <section class="pdf-section">
            <div class="pdf-section-title">Especificacoes Tecnicas</div>
            <table class="pdf-table">
                <tbody>
                <?php foreach ($especificacoes as $spec): ?>
                    <tr>
                        <td style="width:34%;font-weight:bold;"><?= View::e((string)$spec['chave']) ?></td>
                        <td><?= View::e((string)$spec['valor']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <section class="pdf-section pdf-terms">
            <div class="pdf-section-title">Condicoes de Fechamento</div>
            <div class="kv-grid">
                <div class="span-2"><strong>Prazo de Entrega:</strong> <?= View::e($prazoEntrega) ?></div>
                <div class="span-2"><strong>Condicoes de Pagamento:</strong> <?= View::e($condPagamento) ?></div>
                <div class="span-2"><strong>Validade:</strong> <?= View::e($validadeOrcamento) ?></div>
            </div>
        </section>

        <footer class="pdf-footer">
            <p><strong>Multimaquinas Assistencia Tecnica</strong> - Orcamento gerado em <?= View::e($dt($registro['criado_em'] ?? null)) ?></p>
            <?php if (!empty($registro['criado_por'])): ?>
                <p style="font-size:0.72rem;">Atendido por: <?= View::e((string)$registro['criado_por']) ?></p>
            <?php endif; ?>
        </footer>

    </div>

    <div class="pp-toolbar no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="ph ph-printer"></i> Imprimir / Salvar PDF</button>
        <a href="/pre-pedido" class="btn btn-outline-secondary"><i class="ph ph-arrow-left"></i> Voltar ao gerador</a>
    </div>
</div>

<style>
.pp-pub-wrap {
    background: var(--bs-body-bg, #f8f9fa);
    min-height: 100vh;
    padding: 1.5rem 0;
}
.pp-toolbar {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 100;
    display: flex;
    gap: 0.5rem;
    padding: 0.5rem;
    background: #fff;
    border: 1px solid var(--bs-border-color);
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.a4-paper {
    background: #fff;
    width: 100%;
    max-width: 21cm;
    min-height: 29.7cm;
    margin: 0 auto;
    padding: 2cm;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    border-radius: 4px;
    font-family: 'Times New Roman', Times, serif;
    color: #333;
    box-sizing: border-box;
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
.pdf-header-brand {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    text-align: right;
    gap: 0.4rem;
    flex: 1 1 auto;
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
.pdf-section { margin-bottom: 1.5rem; }
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
    background: #fdf5d3;
    color: #2c3e50;
    padding: 0.5rem;
    border-bottom: 2px solid #efb810;
    font-size: 0.9rem;
    text-align: left;
}
.pdf-table td {
    padding: 0.7rem 0.5rem;
    border-bottom: 1px solid #eee;
    font-size: 0.95rem;
}
.pdf-table tfoot td { border-bottom: 0; padding-top: 0.85rem; }
.pdf-list {
    margin: 0;
    padding-left: 1.1rem;
    font-size: 0.9rem;
}
.pdf-list li { margin-bottom: 0.25rem; }
.pdf-footer {
    margin-top: 2rem;
    padding-top: 1rem;
    border-top: 1px solid #eee;
    text-align: center;
    font-size: 0.8rem;
    color: #777;
}
@media print {
    .pp-pub-wrap { background: #fff; padding: 0; }
    .no-print { display: none !important; }
    .a4-paper { box-shadow: none; padding: 1.5cm; max-width: none; }
    @page { size: A4; margin: 0; }
}
</style>

<?php if ($auto_print): ?>
<script>
window.addEventListener('load', () => {
    setTimeout(() => window.print(), 350);
});
</script>
<?php endif; ?>
