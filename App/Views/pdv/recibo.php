<?php
declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $recibo */
/** @var bool $auto_print */

$jsVer = substr(md5_file(BASE_PATH . '/public/assets/js/pdv.js'), 0, 8);

$empresa = $recibo['empresa'] ?? [];
$venda = $recibo['venda'] ?? [];
$cliente = $recibo['cliente'] ?? [];
$operador = $recibo['operador'] ?? [];
$itens = is_array($recibo['itens'] ?? null) ? $recibo['itens'] : [];
$pagamentos = is_array($recibo['pagamentos'] ?? null) ? $recibo['pagamentos'] : [];
$documentosFiscais = is_array($recibo['documentos_fiscais'] ?? null) ? $recibo['documentos_fiscais'] : [];
$isCancelada = !empty($venda['is_cancelada']);
$isEstornada = !empty($venda['is_estornada']);
$isHistoricoCancelado = $isCancelada || $isEstornada;
$historicoLabel = $isEstornada ? 'VENDA ESTORNADA' : 'VENDA CANCELADA';

$fmtBrl = static fn(float $valor): string => 'R$ ' . number_format($valor, 2, ',', '.');
$fmtQtd = static fn(float $valor): string => number_format($valor, 3, ',', '.');
$fmtDataHora = static function (?string $valor): string {
    if ($valor === null || trim($valor) === '') {
        return '—';
    }
    $ts = strtotime($valor);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
};

$saleId = (int)($venda['id'] ?? 0);
$saleNumber = (string)($venda['numero'] ?? $saleId);
$reciboPath = $saleId > 0 ? '/pdv/vendas/' . $saleId . '/recibo' : '/pdv';
$appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
$reciboUrl = ($appUrl !== '' ? $appUrl : '') . $reciboPath;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($titulo ?? 'Recibo não fiscal PDV') ?></title>
    <style>
        :root {
            color-scheme: light;
            --border: #d8dee4;
            --ink: #1f2328;
            --muted: #59636e;
            --soft: #f6f8fa;
            --warn-bg: #fff5d6;
            --warn-ink: #8a5b00;
            --danger-bg: rgba(220, 53, 69, 0.08);
            --danger-ink: #b42318;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background: #eef2f6;
            padding: 24px;
        }
        .toolbar {
            max-width: 920px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .toolbar button, .toolbar a {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--ink);
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }
        .toolbar {
            flex-wrap: wrap;
        }
        .toolbar__group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .paper {
            width: 100%;
            max-width: 920px;
            margin: 0 auto;
            background: #fff;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            position: relative;
        }
        .watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            font-weight: 700;
            color: rgba(180, 35, 24, 0.12);
            transform: rotate(-18deg);
            pointer-events: none;
            user-select: none;
            letter-spacing: 0.08em;
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0 0 6px;
            font-size: 28px;
            letter-spacing: 0.04em;
        }
        .subtitle, .meta, .muted {
            color: var(--muted);
        }
        .alert-box {
            border: 1px solid #f2d487;
            background: var(--warn-bg);
            color: var(--warn-ink);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 700;
        }
        .cancel-box {
            border: 1px solid rgba(180, 35, 24, 0.2);
            background: var(--danger-bg);
            color: var(--danger-ink);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .section {
            margin-bottom: 20px;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }
        .section-title {
            background: var(--soft);
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .section-body {
            padding: 14px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 18px;
        }
        .grid .full {
            grid-column: 1 / -1;
        }
        .label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .value {
            font-size: 15px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }
        th {
            background: var(--soft);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        td.num, th.num {
            text-align: right;
            white-space: nowrap;
        }
        .totals {
            width: 100%;
            max-width: 340px;
            margin-left: auto;
        }
        .totals tr:last-child td {
            font-size: 16px;
            font-weight: 700;
        }
        .footer-note {
            margin-top: 24px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .paper {
                box-shadow: none;
                margin: 0;
                max-width: none;
                border-radius: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body<?= $auto_print ? ' onload="window.print()"' : '' ?>>
    <div class="toolbar">
        <div class="toolbar__group" data-pdv-share-receipt-url="<?= View::e($reciboUrl) ?>" data-pdv-share-sale-number="<?= View::e($saleNumber) ?>">
            <a href="<?= View::e($reciboUrl) ?>" target="_blank" rel="noopener" class="toolbar__share-btn" data-pdv-share-action="whatsapp">Compartilhar WhatsApp</a>
            <a href="<?= View::e($reciboUrl) ?>" class="toolbar__share-btn" data-pdv-share-action="email">Enviar por e-mail</a>
            <button type="button" class="toolbar__share-btn" data-pdv-share-action="copy">Copiar link</button>
            <button type="button" onclick="window.print()">Imprimir</button>
        </div>
        <div class="toolbar__group">
            <button type="button" onclick="window.close()">Fechar</button>
        </div>
    </div>

    <div class="paper">
        <?php if ($isHistoricoCancelado): ?>
            <div class="watermark"><?= View::e($historicoLabel) ?></div>
        <?php endif; ?>

        <header class="header">
            <div>
                <div class="meta"><?= View::e((string)($empresa['nome'] ?? 'ERP Multimáquinas')) ?></div>
                <?php if (!empty($empresa['cnpj'])): ?>
                    <div class="meta">CNPJ <?= View::e((string)$empresa['cnpj']) ?></div>
                <?php endif; ?>
                <h1>RECIBO NÃO FISCAL</h1>
                <div class="subtitle">NÃO SUBSTITUI DOCUMENTO FISCAL</div>
            </div>
            <div class="meta" style="text-align:right">
                <div><strong>Venda #<?= View::e((string)($venda['numero'] ?? $venda['id'] ?? '')) ?></strong></div>
                <div>Criada em <?= View::e($fmtDataHora((string)($venda['created_at'] ?? ''))) ?></div>
                <div>Operador: <?= View::e((string)($operador['nome'] ?? 'Não informado')) ?></div>
                <div>Status: <?= View::e((string)($venda['status_venda'] ?? '')) ?></div>
            </div>
        </header>

        <?php if ($documentosFiscais === []): ?>
            <div class="alert-box">
                RECIBO NÃO FISCAL — NÃO SUBSTITUI DOCUMENTO FISCAL
            </div>
        <?php else: ?>
            <section class="section">
                <div class="section-title">Documento fiscal vinculado</div>
                <div class="section-body">
                    <?php foreach ($documentosFiscais as $documento): ?>
                        <div class="grid" style="margin-bottom:12px">
                            <div>
                                <span class="label">Tipo/modelo</span>
                                <div class="value">
                                    <?= View::e(strtoupper((string)($documento['tipo_documento'] ?? ''))) ?>
                                    <?= View::e((string)($documento['modelo'] ?? '')) ?>
                                </div>
                            </div>
                            <div>
                                <span class="label">Número/série</span>
                                <div class="value">
                                    nº <?= View::e((string)($documento['numero'] ?? '—')) ?>
                                    série <?= View::e((string)($documento['serie'] ?? '—')) ?>
                                </div>
                            </div>
                            <?php if (!empty($documento['chave_acesso'])): ?>
                                <div class="full">
                                    <span class="label">Chave de acesso</span>
                                    <div class="value"><?= View::e((string)$documento['chave_acesso']) ?></div>
                                </div>
                            <?php endif; ?>
                            <div>
                                <span class="label">Origem</span>
                                <div class="value"><?= !empty($documento['emitido_externamente']) ? 'Emitido externamente' : 'Registrado no sistema' ?></div>
                            </div>
                            <?php if (!empty($documento['link_consulta'])): ?>
                                <div>
                                    <span class="label">Consulta</span>
                                    <div class="value"><a href="<?= View::e((string)$documento['link_consulta']) ?>" target="_blank" rel="noopener">Abrir link</a></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($isHistoricoCancelado): ?>
            <div class="cancel-box">
                <strong><?= View::e($historicoLabel) ?>.</strong>
                <?php if (!empty($venda['cancelled_at'])): ?>
                    <?= $isEstornada ? 'Estornada' : 'Cancelada' ?> em <?= View::e($fmtDataHora((string)$venda['cancelled_at'])) ?>.
                <?php endif; ?>
                <?php if (!empty($venda['cancel_reason'])): ?>
                    Motivo: <?= View::e((string)$venda['cancel_reason']) ?>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="section">
            <div class="section-title">Cliente</div>
            <div class="section-body">
                <div class="grid">
                    <div>
                        <span class="label">Nome</span>
                        <div class="value"><?= View::e((string)($cliente['nome'] ?? 'Cliente não informado')) ?></div>
                    </div>
                    <div>
                        <span class="label">Documento</span>
                        <div class="value"><?= View::e((string)($cliente['documento'] ?? '')) ?: 'Não informado' ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="section-title">Itens</div>
            <div class="section-body" style="padding:0">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descrição</th>
                            <th class="num">Qtd</th>
                            <th class="num">V. Unit.</th>
                            <th class="num">Subtotal</th>
                            <th class="num">Desconto</th>
                            <th class="num">Acréscimo</th>
                            <th class="num">Total líquido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td><?= View::e((string)($item['codigo'] ?? '')) ?: '—' ?></td>
                                <td><?= View::e((string)($item['descricao'] ?? '')) ?: '—' ?></td>
                                <td class="num"><?= View::e($fmtQtd((float)($item['quantidade'] ?? 0))) ?></td>
                                <td class="num"><?= View::e($fmtBrl((float)($item['valor_unitario'] ?? 0))) ?></td>
                                <td class="num"><?= View::e($fmtBrl((float)($item['subtotal'] ?? 0))) ?></td>
                                <td class="num"><?= View::e($fmtBrl((float)($item['desconto'] ?? 0))) ?></td>
                                <td class="num"><?= View::e($fmtBrl((float)($item['acrescimo'] ?? 0))) ?></td>
                                <td class="num"><?= View::e($fmtBrl((float)($item['total_liquido'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($itens === []): ?>
                            <tr>
                                <td colspan="8" class="muted">Nenhum item registrado nesta venda.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section">
            <div class="section-title">Totais</div>
            <div class="section-body">
                <table class="totals">
                    <tbody>
                        <tr><td>Total bruto</td><td class="num"><?= View::e($fmtBrl((float)($venda['total_bruto'] ?? 0))) ?></td></tr>
                        <tr><td>Total desconto</td><td class="num"><?= View::e($fmtBrl((float)($venda['total_desconto'] ?? 0))) ?></td></tr>
                        <tr><td>Desconto geral</td><td class="num"><?= View::e($fmtBrl((float)($venda['desconto_geral'] ?? 0))) ?></td></tr>
                        <tr><td>Total acréscimo</td><td class="num"><?= View::e($fmtBrl((float)($venda['total_acrescimo'] ?? 0))) ?></td></tr>
                        <tr><td>Total líquido</td><td class="num"><?= View::e($fmtBrl((float)($venda['total_liquido'] ?? 0))) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section">
            <div class="section-title">Pagamentos</div>
            <div class="section-body" style="padding:0">
                <table>
                    <thead>
                        <tr>
                            <th>Forma</th>
                            <th>Status</th>
                            <th class="num">Valor</th>
                            <th>Pago em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagamentos as $pagamento): ?>
                            <tr>
                                <td><?= View::e((string)($pagamento['forma_pagamento'] ?? '')) ?: '—' ?></td>
                                <td><?= View::e((string)($pagamento['status'] ?? '')) ?: '—' ?></td>
                                <td class="num"><?= View::e($fmtBrl((float)($pagamento['valor'] ?? 0))) ?></td>
                                <td><?= View::e($fmtDataHora((string)($pagamento['pago_em'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($pagamentos === []): ?>
                            <tr>
                                <td colspan="4" class="muted">Nenhum pagamento registrado nesta venda.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="footer-note">
            <div>Documento gerado para conferência interna.</div>
            <div>Este recibo não substitui NF-e, NFC-e, NFS-e, cupom fiscal, SAT, MFE ou ECF quando exigidos.</div>
        </div>
    </div>
    <script src="/assets/js/pdv.js?v=<?= $jsVer ?>" defer></script>
</body>
</html>
