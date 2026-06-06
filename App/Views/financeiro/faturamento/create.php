<?php
use App\Core\View;
use App\Core\Flash;
/**
 * @var string $modo                'selecionar_cliente' | 'selecionar_os'
 * @var array  $clientesPendentes   [opcional, modo=selecionar_cliente]
 * @var array  $cliente             [opcional, modo=selecionar_os]
 * @var array  $pendentes           [opcional, modo=selecionar_os]
 * @var string $csrf_token
 */
$money = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$dt    = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Novo Relatório de Faturamento</h1>
            <p class="page-header__subtitle">
                <?php if ($modo === 'selecionar_cliente'): ?>
                    Escolha o cliente B2B com OSs aguardando fatura.
                <?php else: ?>
                    Selecione as OSs que devem entrar neste relatório de cobrança.
                <?php endif; ?>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/financeiro/faturamento" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <!-- Stepper visual -->
    <div class="d-flex align-items-center gap-3 small">
        <span class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill <?= $modo === 'selecionar_cliente'
            ? 'bg-primary text-white'
            : 'bg-success-subtle text-success-emphasis' ?>">
            <i class="ph ph-<?= $modo === 'selecionar_cliente' ? 'circle-dashed' : 'check-circle' ?>"></i>
            <span class="fw-medium">1. Cliente</span>
        </span>
        <div class="flex-grow-1 border-bottom"></div>
        <span class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill <?= $modo === 'selecionar_os'
            ? 'bg-primary text-white'
            : 'bg-body-secondary text-body-secondary' ?>">
            <i class="ph ph-<?= $modo === 'selecionar_os' ? 'circle-dashed' : 'circle' ?>"></i>
            <span class="fw-medium">2. OSs e PO</span>
        </span>
    </div>

    <!-- Passo 1: escolher cliente -->
    <?php if ($modo === 'selecionar_cliente'): ?>

        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-users me-1"></i>
                Clientes com OSs aguardando fatura
            </div>

            <?php if (empty($clientesPendentes)): ?>
                <div class="card-body py-5">
                    <div class="empty-state">
                        <div class="empty-state__icon text-success">
                            <i class="ph ph-confetti"></i>
                        </div>
                        <h3 class="empty-state__title">Nada pendente!</h3>
                        <p class="empty-state__desc">
                            Não há OSs retiradas com pagamento "faturado" aguardando cobrança.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>CPF/CNPJ</th>
                                <th class="text-center">OSs pendentes</th>
                                <th class="text-end">Valor a faturar</th>
                                <th class="text-end">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clientesPendentes as $c): ?>
                            <tr>
                                <td>
                                    <div class="fw-medium"><?= View::e($c['nome']) ?></div>
                                    <?php if (!empty($c['nome_fantasia'])): ?>
                                        <div class="small text-body-secondary"><?= View::e($c['nome_fantasia']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-mono text-body-secondary text-nowrap">
                                    <?= View::e($c['cpf_cnpj'] ?: '—') ?>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge status-badge--warning">
                                        <?= (int)$c['qtd_os'] ?>
                                    </span>
                                </td>
                                <td class="text-end text-mono fw-medium text-nowrap">
                                    <?= $money((float)$c['valor_total']) ?>
                                </td>
                                <td class="text-end">
                                    <a href="/financeiro/faturamento/novo?cliente_id=<?= (int)$c['id'] ?>"
                                       class="btn btn-sm btn-primary">
                                        Faturar <i class="ph ph-arrow-right ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <!-- Passo 2: escolher OSs do cliente -->
    <?php else:
        $oldPo  = Flash::old('numero_po');
        $oldObs = Flash::old('observacoes');
    ?>

        <!-- Cabecalho do cliente -->
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="d-flex align-items-center justify-content-center rounded bg-primary-subtle flex-shrink-0" style="width:44px;height:44px;">
                            <i class="ph ph-buildings fs-5 text-primary"></i>
                        </div>
                        <div>
                            <div class="small fw-semibold text-uppercase text-body-secondary mb-1">Cliente selecionado</div>
                            <div class="fw-bold"><?= View::e($cliente['nome']) ?></div>
                            <?php if (!empty($cliente['nome_fantasia'])): ?>
                                <div class="small text-body-secondary"><?= View::e($cliente['nome_fantasia']) ?></div>
                            <?php endif; ?>
                            <div class="small text-mono text-body-secondary mt-1">
                                <?= View::e($cliente['cpf_cnpj'] ?: '—') ?>
                            </div>
                        </div>
                    </div>
                    <a href="/financeiro/faturamento/novo"
                       class="text-decoration-none fw-medium d-inline-flex align-items-center gap-1">
                        <i class="ph ph-arrows-clockwise"></i> Trocar cliente
                    </a>
                </div>
            </div>
        </div>

        <?php if (empty($pendentes)): ?>
            <div class="card shadow-sm">
                <div class="card-body py-5">
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <i class="ph ph-tray"></i>
                        </div>
                        <h3 class="empty-state__title">Sem OSs pendentes</h3>
                        <p class="empty-state__desc">Este cliente não possui OSs aguardando fatura no momento.</p>
                        <a href="/financeiro/faturamento/novo" class="btn btn-outline-secondary mt-3">
                            Escolher outro cliente
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>

            <form method="POST" action="/financeiro/faturamento" id="formFaturamento" class="d-flex flex-column gap-4">
                <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                <input type="hidden" name="cliente_id" value="<?= (int)$cliente['id'] ?>">

                <!-- Dados do relatorio -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <i class="ph ph-file-text me-1"></i>
                        Dados do relatório
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    Número do Pedido / PO <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="numero_po" required maxlength="100" autofocus
                                       value="<?= View::e($oldPo) ?>"
                                       placeholder="Ex.: PO-2026-0042"
                                       class="form-control">
                                <div class="form-text">
                                    Pedido de compra informado pelo cliente — agrupa as OSs sob um mesmo documento.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">
                                    Observações
                                </label>
                                <input type="text" name="observacoes" maxlength="500"
                                       value="<?= View::e($oldObs) ?>"
                                       placeholder="Anotações internas (opcional)"
                                       class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- OSs pendentes -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>
                            <i class="ph ph-list-checks me-1"></i>
                            OSs aguardando fatura (<?= count($pendentes) ?>)
                        </span>
                        <label class="d-inline-flex align-items-center gap-2 cursor-pointer mb-0">
                            <input type="checkbox" id="chkAll" class="form-check-input">
                            <span class="small fw-medium">Selecionar todas</span>
                        </label>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:48px;"></th>
                                    <th>OS</th>
                                    <th>Retirada</th>
                                    <th>Pedido informado</th>
                                    <th>Descrição</th>
                                    <th class="text-end">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendentes as $os): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="os_ids[]"
                                               value="<?= View::e((string)$os['os_id']) ?>"
                                               data-valor="<?= View::e((string)$os['valor']) ?>"
                                               class="chk-os form-check-input">
                                    </td>
                                    <td class="text-mono small fw-semibold text-nowrap">
                                        #<?= View::e((string)$os['os_id']) ?>
                                    </td>
                                    <td class="small text-mono text-body-secondary text-nowrap">
                                        <?= $dt($os['data_retirada'] ?? null) ?>
                                    </td>
                                    <td class="text-mono text-nowrap">
                                        <?= View::e($os['numero_pedido'] ?? '—') ?>
                                    </td>
                                    <td class="text-body-secondary">
                                        <?= View::e($os['descricao'] ?? '—') ?>
                                    </td>
                                    <td class="text-end text-mono fw-medium text-nowrap">
                                        <?= $money((float)$os['valor']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-end fw-semibold">
                                        Total selecionado:
                                    </td>
                                    <td class="text-end text-mono fw-bold text-primary fs-6">
                                        <span id="totalSelecionado">R$ 0,00</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Acoes -->
                <div class="d-flex flex-column-reverse flex-sm-row align-items-sm-center justify-content-sm-end gap-2">
                    <a href="/financeiro/faturamento" class="btn btn-outline-secondary">
                        Cancelar
                    </a>
                    <button type="submit" id="btnSalvar" disabled class="btn btn-primary btn-lg">
                        <i class="ph ph-floppy-disk me-1"></i> Criar relatório (rascunho)
                    </button>
                </div>
            </form>

            <script>
            (function() {
                const chkAll  = document.getElementById('chkAll');
                const chks    = document.querySelectorAll('.chk-os');
                const total   = document.getElementById('totalSelecionado');
                const btn     = document.getElementById('btnSalvar');

                function fmt(v) {
                    return 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                }
                function recalc() {
                    let sum = 0, n = 0;
                    chks.forEach(c => { if (c.checked) { sum += parseFloat(c.dataset.valor || '0'); n++; } });
                    total.textContent = fmt(sum);
                    btn.disabled = (n === 0);
                }
                chkAll?.addEventListener('change', e => {
                    chks.forEach(c => c.checked = e.target.checked);
                    recalc();
                });
                chks.forEach(c => c.addEventListener('change', recalc));
                recalc();
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
