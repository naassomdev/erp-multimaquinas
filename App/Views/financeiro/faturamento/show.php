<?php
use App\Core\View;
/**
 * @var array  $relatorio
 * @var ?array $cliente
 * @var array  $empresa
 * @var string $csrf_token
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt    = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$dtH   = fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';

// Cliente pode ter sido excluído após criação do relatório — normaliza.
$cliente = is_array($cliente ?? null) ? $cliente : [];

$valorTotal = 0.0;
foreach (($relatorio['ordens'] ?? []) as $os) {
    $valorTotal += (float) ($os['valor'] ?? 0);
}

$finalizado = ($relatorio['status'] ?? '') === 'finalizado';

$enderecoCli = trim(implode(', ', array_filter([
    trim(($cliente['endereco'] ?? '') . ' ' . ($cliente['numero'] ?? '')),
    $cliente['complemento'] ?? '',
    $cliente['bairro']      ?? '',
    trim(($cliente['cidade'] ?? '') . ($cliente['uf'] ? ' / ' . $cliente['uf'] : '')),
    $cliente['cep']         ?? '',
])));
?>

<style>
/* -- Estetica do documento (light mode + dark mode + print) -- */
.doc-paper {
    border-radius: var(--bs-border-radius-lg);
    box-shadow: 0 1px 3px rgba(0,0,0,.05), 0 1px 2px rgba(0,0,0,.03);
}

.doc-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.doc-table thead th {
    background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
    font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600;
    text-align: left; padding: .85rem 1rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.doc-table tbody td { padding: .9rem 1rem; font-size: .875rem; border-bottom: 1px solid var(--bs-border-color-translucent); }
.doc-table tfoot td { padding: .9rem 1rem; font-size: .875rem; background: var(--bs-tertiary-bg); }

.doc-block {
    border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius);
    padding: 1rem 1.25rem; background: var(--bs-tertiary-bg);
}
.doc-block-title {
    font-size: .65rem; letter-spacing: .08em; text-transform: uppercase;
    color: var(--bs-secondary-color); font-weight: 700; margin-bottom: .35rem;
}

@media print {
    @page { size: A4 portrait; margin: 14mm; }
    aside, header, .no-print, .flash-alert { display: none !important; }
    main, .erp-main { padding: 0 !important; background: #fff !important; overflow: visible !important; }
    body, html { background: #fff !important; color: #000 !important; }
    .doc-paper {
        box-shadow: none !important; border: none !important; border-radius: 0 !important;
        background: #fff !important; color: #000 !important;
        padding: 0 !important;
    }
    .doc-table thead th { background: #f1f5f9 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .doc-block { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .doc-block, .doc-table tbody tr { page-break-inside: avoid; }
}
</style>

<div class="d-flex flex-column gap-4">

    <!-- Barra de acoes (nao imprime) -->
    <div class="no-print page-header">
        <div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <h1 class="page-header__title">
                    Relatório de Faturamento <span class="text-mono">#<?= (int)$relatorio['id'] ?></span>
                </h1>
                <?php if ($finalizado): ?>
                    <span class="status-badge status-badge--success">
                        <i class="ph ph-check-circle"></i> Finalizado
                    </span>
                <?php else: ?>
                    <span class="status-badge status-badge--warning">
                        <i class="ph ph-file-text"></i> Rascunho
                    </span>
                <?php endif; ?>
            </div>
            <p class="page-header__subtitle">
                Pedido <strong class="text-mono"><?= View::e($relatorio['numero_po']) ?></strong>
                · criado em <span class="text-mono"><?= $dtH($relatorio['criado_em']) ?></span>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/financeiro/faturamento" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <button type="button" onclick="window.print()" class="btn btn-outline-secondary">
                <i class="ph ph-printer me-1"></i> Imprimir / PDF
            </button>
            <?php if (!$finalizado): ?>
                <form method="POST" action="/financeiro/faturamento/<?= (int)$relatorio['id'] ?>/finalizar"
                      onsubmit="return confirm('Finalizar este relatório? Os lançamentos a receber das OSs vinculadas serão quitados (pagos) e essa ação não pode ser desfeita.');">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <button type="submit" class="btn btn-success">
                        <i class="ph ph-check-circle me-1"></i> Finalizar e quitar
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Folha do relatorio (imprime) -->
    <div class="doc-paper card shadow-sm p-4 p-md-5">

        <!-- Cabecalho do emitente + numero -->
        <div class="d-flex flex-column flex-md-row align-items-md-start justify-content-md-between gap-4 pb-4 mb-4 border-bottom border-2">
            <div class="d-flex align-items-start gap-3">
                <div class="d-flex align-items-center justify-content-center rounded bg-primary text-white flex-shrink-0 shadow-sm" style="width:56px;height:56px;">
                    <i class="ph ph-wrench fs-3"></i>
                </div>
                <div>
                    <h2 class="fw-bold text-uppercase mb-1 fs-5">
                        <?= View::e($empresa['razao_social'] ?? 'MULTIMÁQUINAS ASSISTÊNCIA') ?>
                    </h2>
                    <div class="small text-body-secondary d-flex flex-column gap-1">
                        <?php if (!empty($empresa['cnpj'])): ?>
                            <div>CNPJ: <span class="text-mono"><?= View::e($empresa['cnpj']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($empresa['endereco'])): ?>
                            <div><?= View::e($empresa['endereco']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($empresa['telefone']) || !empty($empresa['email'])): ?>
                            <div>
                                <?= !empty($empresa['telefone']) ? 'Tel: ' . View::e($empresa['telefone']) : '' ?>
                                <?= !empty($empresa['telefone']) && !empty($empresa['email']) ? ' · ' : '' ?>
                                <?= !empty($empresa['email'])    ? View::e($empresa['email'])    : '' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="text-md-end">
                <div class="small fw-semibold text-uppercase text-body-secondary">Relatório de Faturamento</div>
                <div class="fs-2 fw-bold mt-1">N.o <?= (int)$relatorio['id'] ?></div>
                <div class="small text-body-secondary mt-1">
                    Pedido (PO): <strong class="text-mono"><?= View::e($relatorio['numero_po']) ?></strong>
                </div>
                <div class="small text-body-secondary">
                    Emitido em: <span class="text-mono"><?= $dtH($relatorio['criado_em']) ?></span>
                </div>
            </div>
        </div>

        <!-- Cliente (tomador) -->
        <div class="doc-block mb-4">
            <div class="doc-block-title">Cliente / Tomador</div>
            <div class="row row-cols-1 row-cols-md-2 gx-4 gy-1">
                <div>
                    <span class="text-body-secondary">Razão social:</span>
                    <strong><?= View::e($relatorio['cliente_nome']) ?></strong>
                </div>
                <div>
                    <span class="text-body-secondary">CPF/CNPJ:</span>
                    <strong class="text-mono"><?= View::e($cliente['cpf_cnpj'] ?? '—') ?></strong>
                </div>
                <?php if (!empty($cliente['nome_fantasia'])): ?>
                <div>
                    <span class="text-body-secondary">Nome fantasia:</span>
                    <?= View::e($cliente['nome_fantasia']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($cliente['telefone']) || !empty($cliente['celular'])): ?>
                <div>
                    <span class="text-body-secondary">Telefone:</span>
                    <span class="text-mono"><?= View::e($cliente['telefone'] ?: ($cliente['celular'] ?? '—')) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($cliente['email'])): ?>
                <div>
                    <span class="text-body-secondary">E-mail:</span>
                    <?= View::e($cliente['email']) ?>
                </div>
                <?php endif; ?>
                <?php if ($enderecoCli !== ''): ?>
                <div class="col-md-12">
                    <span class="text-body-secondary">Endereço:</span>
                    <?= View::e($enderecoCli) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabela de OSs -->
        <div class="mb-4">
            <h3 class="small fw-bold text-uppercase text-body-secondary mb-3">
                Ordens de Serviço incluídas
            </h3>
            <div class="rounded overflow-hidden border">
                <table class="doc-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">OS</th>
                            <th style="width:110px;">Retirada</th>
                            <th style="width:170px;">Pedido informado</th>
                            <th>Descrição do serviço</th>
                            <th style="width:140px; text-align:right;">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($relatorio['ordens'])): ?>
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">
                                Nenhuma OS vinculada a este relatório.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($relatorio['ordens'] as $os): ?>
                            <tr>
                                <td class="text-mono">#<?= View::e((string)$os['os_id']) ?></td>
                                <td class="text-mono text-body-secondary"><?= $dt($os['data_retirada'] ?? null) ?></td>
                                <td class="text-mono"><?= View::e($os['numero_pedido'] ?? '—') ?></td>
                                <td><?= View::e($os['descricao'] ?? '—') ?></td>
                                <td class="text-mono fw-medium" style="text-align:right;"><?= $money((float)$os['valor']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end text-body-secondary">
                                Quantidade de OSs: <strong><?= count($relatorio['ordens'] ?? []) ?></strong>
                            </td>
                            <td class="text-mono" style="text-align:right;">
                                <?= $money($valorTotal) ?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end fs-6">
                                <strong>VALOR TOTAL GERAL</strong>
                            </td>
                            <td class="text-mono text-primary" style="text-align:right; font-size: 1.25rem;">
                                <strong><?= $money($valorTotal) ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Observacoes -->
        <?php if (!empty($relatorio['observacoes'])): ?>
            <div class="doc-block mb-4">
                <div class="doc-block-title">Observações</div>
                <p class="mb-0" style="white-space:pre-line;"><?= View::e($relatorio['observacoes']) ?></p>
            </div>
        <?php endif; ?>

        <!-- Rodape -->
        <div class="small text-body-secondary mt-4 pt-3 border-top lh-lg">
            <p class="mb-1">
                Este relatório consolida as Ordens de Serviço retiradas com pagamento <em>faturado</em>
                vinculadas ao pedido <strong class="text-mono"><?= View::e($relatorio['numero_po']) ?></strong>
                do cliente <strong><?= View::e($relatorio['cliente_nome']) ?></strong>.
                Anexar à NFS-e correspondente para fins de cobrança.
            </p>
            <p class="mb-0">
                Status: <strong><?= $finalizado ? 'FINALIZADO (lançamentos quitados)' : 'RASCUNHO (aguardando finalização)' ?></strong>
                · Documento gerado em <?= date('d/m/Y H:i') ?>.
            </p>
        </div>
    </div>
</div>
