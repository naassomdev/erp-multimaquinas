<?php
use App\Core\View;

/** @var array $usuario */
/** @var array<int, array<string, mixed>> $itens */
/** @var array{status:string, os_id:string, q:string, tipo:string, fabricante:string, agrupar:string, modo:string} $filtros */
/** @var array<int, string> $fabricantesDisponiveis */
/** @var array{pendente:int, comprado:int, cancelado:int, manual_pendente:int, cadastrado_pendente:int, comprado_com_entrada:int, comprado_sem_entrada:int} $kpis */
/** @var array{page:int, per_page:int, total:int, total_pages:int} $paginacao */
/** @var string $csrf_token */

$pg = $paginacao;

$statusBadge = static fn(string $s): string => match ($s) {
    'pendente'  => 'status-badge--warning',
    'comprado'  => 'status-badge--success',
    'cancelado' => 'status-badge--neutral',
    default     => 'status-badge--neutral',
};

$statusLabel = static fn(string $s): string => match ($s) {
    'pendente'  => 'Pendente',
    'comprado'  => 'Comprado',
    'cancelado' => 'Cancelado',
    default     => ucfirst($s),
};

$equipStatusBadge = static fn(?string $s): string => match ($s) {
    'montagem'  => 'status-badge--brand',
    'pronto'    => 'status-badge--success',
    'andamento' => 'status-badge--primary',
    'aberta'    => 'status-badge--warning',
    'retirado'  => 'status-badge--neutral',
    'cancelado' => 'status-badge--neutral',
    default     => 'status-badge--neutral',
};

$equipStatusLabel = static fn(?string $s): string => match ($s) {
    'montagem'  => 'Montagem',
    'pronto'    => 'Pronto',
    'andamento' => 'Andamento',
    'aberta'    => 'Aberta',
    'retirado'  => 'Retirado',
    'cancelado' => 'Cancelado',
    default     => '',
};

$filtroStatus     = $filtros['status']     ?? 'pendente';
$filtroOsId       = $filtros['os_id']      ?? '';
$filtroQ          = $filtros['q']          ?? '';
$filtroTipo       = $filtros['tipo']       ?? '';
$filtroFabricante = $filtros['fabricante'] ?? '';
$filtroAgrupar    = ($filtros['agrupar'] ?? '') === '1';
$filtroModo       = $filtros['modo']       ?? '';

$temFiltro = ($filtroStatus !== 'pendente' || $filtroOsId !== '' || $filtroQ !== ''
    || $filtroTipo !== '' || $filtroFabricante !== '' || $filtroAgrupar || $filtroModo !== '');

// Monta URL base para links de paginação e botões
$queryBase = http_build_query(array_filter([
    'status'     => $filtroStatus,
    'os_id'      => $filtroOsId,
    'q'          => $filtroQ,
    'tipo'       => $filtroTipo,
    'fabricante' => $filtroFabricante,
    'agrupar'    => $filtroAgrupar ? '1' : '',
    'modo'       => $filtroModo,
], static fn($v) => $v !== '' && $v !== 'pendente' || $v === $filtroStatus));

// URL para entrar/sair do modo pedido (preserva todos os filtros, altera só 'modo')
$urlPedido = http_build_query(array_filter([
    'status'     => $filtroStatus !== 'pendente' ? $filtroStatus : '',
    'os_id'      => $filtroOsId,
    'q'          => $filtroQ,
    'tipo'       => $filtroTipo,
    'fabricante' => $filtroFabricante,
    'agrupar'    => '1',         // forçar agrupamento no modo pedido
    'modo'       => 'pedido',
], static fn($v) => $v !== ''));

$urlSairPedido = http_build_query(array_filter([
    'status'     => $filtroStatus !== 'pendente' ? $filtroStatus : '',
    'os_id'      => $filtroOsId,
    'q'          => $filtroQ,
    'tipo'       => $filtroTipo,
    'fabricante' => $filtroFabricante,
    'agrupar'    => $filtroAgrupar ? '1' : '',
], static fn($v) => $v !== ''));
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabeçalho -->
    <?php if ($filtroModo === 'pedido'): ?>
    <!-- Cabeçalho modo pedido (oculto na impressão — o título de impressão fica abaixo) -->
    <div class="page-header d-print-none">
        <div>
            <h1 class="page-header__title">
                <i class="ph ph-list-bullets me-2"></i>Pedido<?= $filtroFabricante !== '' ? ' — ' . View::e($filtroFabricante) : '' ?>
            </h1>
            <p class="page-header__subtitle"><?= number_format($pg['total'], 0, ',', '.') ?> item(s)</p>
        </div>
        <div class="page-header__actions">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="ph ph-printer me-1"></i> Imprimir
            </button>
            <button id="btn-copiar-lista" class="btn btn-outline-secondary btn-sm">
                <i class="ph ph-copy me-1"></i> Copiar lista
            </button>
            <a href="/compras/necessidades<?= $urlSairPedido !== '' ? '?' . $urlSairPedido : '' ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="ph ph-x me-1"></i> Sair
            </a>
        </div>
    </div>
    <!-- Título visível apenas na impressão -->
    <div class="d-none d-print-block mb-3 pb-2 border-bottom">
        <h4 class="mb-0">Pedido<?= $filtroFabricante !== '' ? ' — ' . View::e($filtroFabricante) : ' — Todos os fabricantes' ?></h4>
        <small class="text-muted">Gerado em <?= date('d/m/Y H:i') ?> &middot; <?= number_format($pg['total'], 0, ',', '.') ?> item(s) &middot; status: <?= View::e($filtroStatus) ?></small>
    </div>
    <?php else: ?>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Necessidades de Compra</h1>
            <p class="page-header__subtitle">
                <?= number_format($pg['total'], 0, ',', '.') ?> item(s) encontrado(s)
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/dashboard" class="btn btn-outline-secondary">
                <i class="ph ph-squares-four me-1"></i> Dashboard
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="row g-3<?= $filtroModo === 'pedido' ? ' d-print-none' : '' ?>">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-warning"><?= number_format($kpis['pendente'], 0, ',', '.') ?></div>
                <div class="small text-body-secondary">Pendentes</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-success"><?= number_format($kpis['comprado'], 0, ',', '.') ?></div>
                <div class="small text-body-secondary">Comprados</div>
                <?php if ($kpis['comprado'] > 0): ?>
                <div class="x-small text-body-tertiary">
                    <?= $kpis['comprado_com_entrada'] ?> c/ entrada &middot; <?= $kpis['comprado_sem_entrada'] ?> s/ entrada
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-secondary"><?= number_format($kpis['cancelado'], 0, ',', '.') ?></div>
                <div class="small text-body-secondary">Cancelados</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-info"><?= number_format($kpis['manual_pendente'], 0, ',', '.') ?></div>
                <div class="small text-body-secondary">Manuais pendentes</div>
                <div class="x-small text-body-tertiary"><?= number_format($kpis['cadastrado_pendente'], 0, ',', '.') ?> cadastrados</div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm<?= $filtroModo === 'pedido' ? ' d-print-none' : '' ?>">
        <div class="card-body">
            <form method="GET" action="/compras/necessidades" class="row g-3 align-items-end">
                <div class="col-lg">
                    <label class="form-label">Buscar</label>
                    <div class="input-icon">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" name="q" value="<?= View::e($filtroQ) ?>"
                               placeholder="Código ou descrição..." autofocus class="form-control">
                    </div>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="pendente"  <?= $filtroStatus === 'pendente'  ? 'selected' : '' ?>>Pendente</option>
                        <option value="comprado"  <?= $filtroStatus === 'comprado'  ? 'selected' : '' ?>>Comprado</option>
                        <option value="cancelado" <?= $filtroStatus === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                        <option value="todos"     <?= $filtroStatus === 'todos'     ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value=""          <?= $filtroTipo === ''           ? 'selected' : '' ?>>Todos</option>
                        <option value="manual"    <?= $filtroTipo === 'manual'     ? 'selected' : '' ?>>Manuais</option>
                        <option value="cadastrado"<?= $filtroTipo === 'cadastrado' ? 'selected' : '' ?>>Cadastrados</option>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">OS</label>
                    <input type="text" name="os_id" value="<?= View::e($filtroOsId) ?>"
                           placeholder="ID da OS..." class="form-control text-mono">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Fabricante</label>
                    <select name="fabricante" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($fabricantesDisponiveis ?? [] as $f): ?>
                            <option value="<?= View::e((string) $f) ?>"
                                <?= $filtroFabricante === (string) $f ? 'selected' : '' ?>>
                                <?= View::e((string) $f) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2 align-items-end">
                    <div class="form-check mb-0 ms-1" title="Ordena e agrupa a listagem por fabricante">
                        <input class="form-check-input" type="checkbox" name="agrupar" value="1"
                               id="chk-agrupar" <?= $filtroAgrupar ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="chk-agrupar">Agrupar</label>
                    </div>
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ph ph-magnifying-glass me-1"></i> Filtrar
                    </button>
                    <?php if ($filtroModo !== 'pedido'): ?>
                    <a href="/compras/necessidades?<?= $urlPedido ?>"
                       class="btn btn-outline-secondary"
                       title="Visão limpa para copiar ou imprimir o pedido">
                        <i class="ph ph-list-bullets me-1"></i> Ver pedido
                    </a>
                    <?php endif; ?>
                    <?php if ($temFiltro): ?>
                        <a href="/compras/necessidades" class="btn btn-outline-secondary">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Listagem -->
    <?php if ($filtroModo === 'pedido'): ?>
    <!-- ══════════ MODO PEDIDO ══════════ -->
    <div class="card shadow-sm">
        <div class="card-header d-print-none">
            <span><i class="ph ph-list-bullets me-1"></i>
                <?= $filtroFabricante !== '' ? View::e($filtroFabricante) : 'Todos os fabricantes' ?>
                — <?= number_format($pg['total'], 0, ',', '.') ?> item(s)
            </span>
        </div>
        <?php if (empty($itens)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="ph ph-check-circle"></i></div>
                    <h3 class="empty-state__title">Nenhuma necessidade encontrada</h3>
                    <p class="empty-state__desc">Nenhum resultado para os filtros aplicados.</p>
                </div>
            </div>
        <?php else: ?>
            <?php
                $pedidoGrupoAtual = null;
                $pedidoTotalQtd   = 0.0;
                $pedidoMostrarFabr = ($filtroFabricante === ''); // coluna fabricante só quando nenhum filtrado
            ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="tabela-pedido">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Descrição</th>
                            <th class="text-center">Qtd</th>
                            <th>OS / Equipamento</th>
                            <th>Cliente</th>
                            <?php if ($pedidoMostrarFabr): ?>
                            <th>Fabricante</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <?php
                                $fabrPedido = trim((string) ($item['produto_marca']     ?? ''));
                                if ($fabrPedido === '') $fabrPedido = trim((string) ($item['equip_fabricante'] ?? ''));
                                $pedidoTotalQtd += (float) $item['qtd'];
                            ?>
                            <?php if ($pedidoMostrarFabr && $fabrPedido !== $pedidoGrupoAtual): ?>
                                <?php $pedidoGrupoAtual = $fabrPedido; ?>
                                <tr class="table-secondary">
                                    <td colspan="6" class="py-1 px-3 fw-semibold small text-body-secondary">
                                        <i class="ph ph-tag me-1"></i>
                                        <?= $fabrPedido !== '' ? View::e($fabrPedido) : '<span class="fst-italic">Sem fabricante</span>' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="text-mono small"><?= View::e((string) $item['codigo']) ?></td>
                                <td><?= View::e((string) $item['descricao']) ?></td>
                                <td class="text-center text-mono"><?= number_format((float) $item['qtd'], 3, ',', '.') ?></td>
                                <td class="small">
                                    <span class="text-mono fw-semibold"><?= View::e((string) $item['os_id']) ?></span>
                                    &middot; Equip.&nbsp;#<?= (int) $item['equip_idx'] ?>
                                    <?php if (!empty($item['equip_nome'])): ?>
                                        <br><span class="text-body-tertiary"><?= View::e((string) $item['equip_nome']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-body-secondary"><?= View::e((string) ($item['nome_cliente'] ?? '')) ?></td>
                                <?php if ($pedidoMostrarFabr): ?>
                                <td class="small text-body-secondary">
                                    <?= $fabrPedido !== '' ? View::e($fabrPedido) : '<span class="fst-italic text-body-tertiary">—</span>' ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center small text-body-secondary">
                <span><?= number_format($pg['total'], 0, ',', '.') ?> item(s)</span>
                <span class="d-print-none">Qtd total: <?= number_format($pedidoTotalQtd, 3, ',', '.') ?></span>
            </div>
        <?php endif; ?>
    </div>
    <!-- ══════════ FIM MODO PEDIDO ══════════ -->
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="ph ph-shopping-cart-simple me-1"></i> Itens</span>
            <small class="text-body-secondary">
                Página <?= $pg['page'] ?> de <?= $pg['total_pages'] ?>
            </small>
        </div>

        <?php if (empty($itens)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="ph ph-check-circle"></i></div>
                    <h3 class="empty-state__title">Nenhuma necessidade encontrada</h3>
                    <p class="empty-state__desc">
                        <?= $temFiltro ? 'Nenhum resultado para os filtros aplicados.' : 'Nenhuma necessidade de compra cadastrada ainda.' ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>OS / Equip.</th>
                            <th>Código</th>
                            <th>Descrição</th>
                            <th class="text-center">Qtd</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th>Estoque</th>
                            <th>Criado em</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="nc-list">
                        <?php
                            $grupoAtual = null; // tracker para agrupamento por fabricante
                        ?>
                        <?php foreach ($itens as $item): ?>
                            <?php
                                $manual           = $item['produto_id'] === null || $item['produto_id'] === '';
                                $itemId           = (int) $item['id'];
                                $entradaRegistrada= (int) ($item['entrada_registrada'] ?? 0) === 1;

                                // Fabricante resolvido desta linha
                                $fabrLinha = trim((string) ($item['produto_marca']     ?? ''));
                                if ($fabrLinha === '') $fabrLinha = trim((string) ($item['equip_fabricante'] ?? ''));
                            ?>
                            <?php if ($filtroAgrupar && $fabrLinha !== $grupoAtual): ?>
                                <?php $grupoAtual = $fabrLinha; ?>
                                <tr class="table-secondary">
                                    <td colspan="9" class="py-1 px-3 fw-semibold small text-body-secondary">
                                        <i class="ph ph-tag me-1"></i>
                                        <?= $fabrLinha !== '' ? View::e($fabrLinha) : '<span class="fst-italic">Sem fabricante</span>' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr id="nc-row-<?= $itemId ?>">
                                <td>
                                    <a href="/orcamento/<?= rawurlencode((string) $item['os_id']) ?>#equip-<?= (int) $item['equip_idx'] ?>"
                                       class="text-mono fw-semibold link-primary"
                                       title="Abrir orçamento do equipamento">
                                        <?= View::e((string) $item['os_id']) ?>
                                    </a>
                                    <div class="small text-body-secondary">
                                        Equip. #<?= (int) $item['equip_idx'] ?>
                                        <?php if (!empty($item['equip_nome'])): ?>
                                            · <?= View::e((string) $item['equip_nome']) ?>
                                        <?php endif; ?>
                                        <?php $se = (string) ($item['status_equip'] ?? ''); if ($se !== ''): ?>
                                            <span class="status-badge <?= $equipStatusBadge($se) ?> ms-1" style="font-size:.65rem;padding:1px 5px"><?= $equipStatusLabel($se) ?></span>
                                        <?php endif; ?>
                                        <?php
                                            $fabr = trim((string) ($item['produto_marca'] ?? ''));
                                            if ($fabr === '') $fabr = trim((string) ($item['equip_fabricante'] ?? ''));
                                            if ($fabr !== ''): ?>
                                            · <span class="text-body-tertiary" style="font-size:.75rem"><?= View::e($fabr) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($item['nome_cliente'])): ?>
                                        <div class="x-small text-body-tertiary"><?= View::e((string) $item['nome_cliente']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-mono"><?= View::e((string) $item['codigo']) ?></td>
                                <td>
                                    <?= View::e((string) $item['descricao']) ?>
                                    <?php if (!empty($item['chave_nfe'])): ?>
                                        <div class="small text-success" title="NF-e vinculada">
                                            <i class="ph ph-receipt me-1"></i><span class="text-mono"><?= substr((string) $item['chave_nfe'], 0, 12) ?>…</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-mono"><?= number_format((float) $item['qtd'], 3, ',', '.') ?></td>
                                <td>
                                    <?php if ($manual): ?>
                                        <span class="status-badge status-badge--info" title="Produto não cadastrado no catálogo">
                                            <i class="ph ph-warning me-1"></i>Manual
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-badge--neutral"
                                              title="produto_id=<?= (int) $item['produto_id'] ?>">
                                            Cadastrado
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $statusBadge((string) $item['status']) ?> nc-status-badge"
                                          data-id="<?= $itemId ?>">
                                        <?= $statusLabel((string) $item['status']) ?>
                                    </span>
                                    <?php if ($entradaRegistrada): ?>
                                        <div class="mt-1">
                                            <span class="status-badge status-badge--success nc-entrada-badge"
                                                  data-id="<?= $itemId ?>"
                                                  title="Entrada de estoque já registrada para esta necessidade">
                                                <i class="ph ph-check-circle me-1"></i>Entrada feita
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-mono small">
                                    <?php if (!$manual && $item['estoque_qty'] !== null): ?>
                                        <?= number_format((float) $item['estoque_qty'], 3, ',', '.') ?>
                                    <?php else: ?>
                                        <span class="text-body-tertiary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-body-secondary text-nowrap">
                                    <?= !empty($item['criado_em'])
                                        ? date('d/m/Y H:i', strtotime((string) $item['criado_em']))
                                        : '—' ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ((string) $item['status'] === 'pendente'): ?>
                                        <button class="btn btn-xs btn-success nc-btn-acao"
                                                data-id="<?= $itemId ?>" data-status="comprado"
                                                title="Marcar como comprado">
                                            <i class="ph ph-check"></i>
                                        </button>
                                        <button class="btn btn-xs btn-outline-secondary nc-btn-acao ms-1"
                                                data-id="<?= $itemId ?>" data-status="cancelado"
                                                title="Cancelar necessidade">
                                            <i class="ph ph-x"></i>
                                        </button>
                                        <?php if (!$manual): ?>
                                            <button class="btn btn-xs btn-outline-secondary ms-1"
                                                    disabled title="Marque como comprado antes de dar entrada">
                                                <i class="ph ph-arrow-circle-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php elseif ((string) $item['status'] === 'comprado'): ?>
                                        <button class="btn btn-xs btn-outline-warning nc-btn-acao"
                                                data-id="<?= $itemId ?>" data-status="pendente"
                                                title="Reabrir como pendente">
                                            <i class="ph ph-arrow-counter-clockwise"></i>
                                        </button>
                                        <?php if (!$manual): ?>
                                            <?php if ($entradaRegistrada): ?>
                                                <button class="btn btn-xs btn-outline-secondary ms-1 nc-btn-entrada"
                                                        data-id="<?= $itemId ?>"
                                                        data-qtd="<?= number_format((float) $item['qtd'], 3, '.', '') ?>"
                                                        data-desc="<?= View::e((string) $item['descricao']) ?>"
                                                        disabled
                                                        title="Entrada já registrada no estoque">
                                                    <i class="ph ph-check-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-xs btn-primary ms-1 nc-btn-entrada"
                                                        data-id="<?= $itemId ?>"
                                                        data-qtd="<?= number_format((float) $item['qtd'], 3, '.', '') ?>"
                                                        data-desc="<?= View::e((string) $item['descricao']) ?>"
                                                        title="Dar entrada no estoque">
                                                    <i class="ph ph-arrow-circle-down"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="ms-1 text-body-tertiary small" title="Cadastre o produto antes de dar entrada">
                                                <i class="ph ph-warning"></i>
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ((string) $item['status'] === 'cancelado'): ?>
                                        <button class="btn btn-xs btn-outline-warning nc-btn-acao"
                                                data-id="<?= $itemId ?>" data-status="pendente"
                                                title="Reabrir como pendente">
                                            <i class="ph ph-arrow-counter-clockwise"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$manual && !empty($item['produto_id'])): ?>
                                        <a href="/estoque?q=<?= rawurlencode((string) $item['codigo']) ?>"
                                           class="btn btn-xs btn-outline-secondary ms-1"
                                           title="Ver produto no estoque" target="_blank">
                                            <i class="ph ph-package"></i>
                                        </a>
                                    <?php elseif ($manual && (string) $item['status'] !== 'cancelado'): ?>
                                        <button class="btn btn-xs btn-outline-primary ms-1 nc-btn-vincular-produto"
                                                data-id="<?= $itemId ?>"
                                                data-desc="<?= View::e((string) $item['descricao']) ?>"
                                                data-codigo="<?= View::e((string) $item['codigo']) ?>"
                                                title="Vincular produto cadastrado">
                                            <i class="ph ph-link"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginação -->
            <?php if ($pg['total_pages'] > 1): ?>
                <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <small class="text-body-secondary">
                        Total: <?= number_format($pg['total'], 0, ',', '.') ?> itens
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($pg['page'] > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= $queryBase ?>&p=<?= $pg['page'] - 1 ?>">
                                        <i class="ph ph-caret-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php for ($p = max(1, $pg['page'] - 2); $p <= min($pg['total_pages'], $pg['page'] + 2); $p++): ?>
                                <li class="page-item <?= $p === $pg['page'] ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= $queryBase ?>&p=<?= $p ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($pg['page'] < $pg['total_pages']): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= $queryBase ?>&p=<?= $pg['page'] + 1 ?>">
                                        <i class="ph ph-caret-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($itens)): ?>
        <div class="alert alert-info small mb-0">
            <i class="ph ph-info me-1"></i>
            <strong>Itens manuais</strong> não têm produto cadastrado — cadastre o produto antes de dar entrada no estoque.
            Marcar como <em>Comprado</em> não altera o estoque nem o financeiro.
        </div>
    <?php endif; ?>

    <?php endif; // fim else (modo normal) ?>

</div>

<?php if ($filtroModo !== 'pedido'): ?>
<!-- Modal: Dar entrada no estoque -->
<div class="modal fade" id="modal-entrada" tabindex="-1" aria-labelledby="modal-entrada-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-entrada-label">
                    <i class="ph ph-arrow-circle-down me-1"></i> Dar entrada no estoque
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3">
                    <i class="ph ph-warning me-1"></i>
                    <strong>Atenção:</strong> Esta ação aumenta o estoque físico do produto.
                    Não é possível desfazer sem nova movimentação.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Produto</label>
                    <div id="entrada-desc" class="form-control-plaintext text-body-secondary"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="entrada-qtd">Quantidade <span class="text-danger">*</span></label>
                    <input type="number" id="entrada-qtd" class="form-control" min="0.001" step="0.001"
                           placeholder="Ex: 1.000">
                    <div class="form-text">Pré-preenchido com a qtd da necessidade. Ajuste se necessário.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="entrada-nfe">Chave NF-e (opcional)</label>
                    <input type="text" id="entrada-nfe" class="form-control text-mono"
                           maxlength="44" placeholder="44 dígitos (sem pontuação)">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="entrada-obs">Observação (opcional)</label>
                    <input type="text" id="entrada-obs" class="form-control"
                           maxlength="200" placeholder="Nota interna...">
                </div>
                <div id="entrada-erro" class="alert alert-danger small d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-entrada">
                    <i class="ph ph-arrow-circle-down me-1"></i> Confirmar entrada
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Vincular produto a item manual -->
<div class="modal fade" id="modal-vincular-produto" tabindex="-1" aria-labelledby="modal-vincular-produto-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-vincular-produto-label">
                    <i class="ph ph-link me-1"></i> Vincular produto
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <i class="ph ph-info me-1"></i>
                    Esta ação apenas vincula o item manual ao produto cadastrado. Não baixa estoque e não cria movimentação.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Item manual</label>
                    <div id="vincular-item-desc" class="form-control-plaintext text-body-secondary"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" for="vincular-produto-busca">Produto <span class="text-danger">*</span></label>
                    <input type="text" id="vincular-produto-busca" class="form-control"
                           placeholder="Buscar por código ou descrição..." autocomplete="off">
                </div>
                <div id="vincular-produto-resultados" class="list-group small mb-3"></div>
                <div id="vincular-produto-selecionado" class="alert alert-secondary small d-none mb-3"></div>
                <div id="vincular-produto-erro" class="alert alert-danger small d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-vincular-produto" disabled>
                    <i class="ph ph-link me-1"></i> Confirmar vínculo
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    async function api(method, url, body) {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(body),
        });
        return res.json();
    }

    const statusLabel = { pendente: 'Pendente', comprado: 'Comprado', cancelado: 'Cancelado' };
    const statusBadge = {
        pendente:  'status-badge--warning',
        comprado:  'status-badge--success',
        cancelado: 'status-badge--neutral',
    };

    // ─── Handler de status (marcar comprado / cancelado / reabrir) ───────
    document.getElementById('nc-list')?.addEventListener('click', async function (e) {
        const btn = e.target.closest('.nc-btn-acao');
        if (!btn) return;

        const id     = btn.dataset.id;
        const status = btn.dataset.status;
        if (!id || !status) return;

        btn.disabled = true;

        try {
            const data = await api('PATCH', `/api/compras/necessidades/${id}/status`, { status });
            if (!data.ok) {
                alert(data.error ?? 'Erro ao atualizar.');
                btn.disabled = false;
                return;
            }

            // Atualizar badge de status na linha
            const badge = document.querySelector(`.nc-status-badge[data-id="${id}"]`);
            if (badge) {
                badge.textContent = statusLabel[status] ?? status;
                badge.className   = `status-badge ${statusBadge[status] ?? 'status-badge--neutral'} nc-status-badge`;
                badge.dataset.id  = id;
            }

            // Atualizar botões da linha
            const row = document.getElementById(`nc-row-${id}`);
            if (row) {
                const btnCell = row.querySelector('td:last-child');
                if (btnCell) {
                    const storageLink = btnCell.querySelector('a[href*="/estoque"]');
                    const entradaBtn  = btnCell.querySelector('.nc-btn-entrada');
                    const vincularBtn = btnCell.querySelector('.nc-btn-vincular-produto');
                    const qtd   = entradaBtn?.dataset.qtd   ?? '';
                    const desc  = entradaBtn?.dataset.desc  ?? '';
                    let html = '';
                    if (status === 'comprado') {
                        html += `<button class="btn btn-xs btn-outline-warning nc-btn-acao" data-id="${id}" data-status="pendente" title="Reabrir como pendente"><i class="ph ph-arrow-counter-clockwise"></i></button>`;
                        if (qtd !== '') {
                            html += ` <button class="btn btn-xs btn-primary ms-1 nc-btn-entrada" data-id="${id}" data-qtd="${qtd}" data-desc="${desc}" title="Dar entrada no estoque"><i class="ph ph-arrow-circle-down"></i></button>`;
                        }
                    } else if (status === 'cancelado') {
                        html += `<button class="btn btn-xs btn-outline-warning nc-btn-acao" data-id="${id}" data-status="pendente" title="Reabrir como pendente"><i class="ph ph-arrow-counter-clockwise"></i></button>`;
                    } else {
                        // pendente
                        html += `<button class="btn btn-xs btn-success nc-btn-acao" data-id="${id}" data-status="comprado" title="Marcar como comprado"><i class="ph ph-check"></i></button>`;
                        html += ` <button class="btn btn-xs btn-outline-secondary nc-btn-acao ms-1" data-id="${id}" data-status="cancelado" title="Cancelar necessidade"><i class="ph ph-x"></i></button>`;
                        if (qtd !== '') {
                            html += ` <button class="btn btn-xs btn-outline-secondary ms-1" disabled title="Marque como comprado antes de dar entrada"><i class="ph ph-arrow-circle-down"></i></button>`;
                        }
                    }
                    if (storageLink) html += ' ' + storageLink.outerHTML;
                    if (vincularBtn && status !== 'cancelado') html += ' ' + vincularBtn.outerHTML;
                    btnCell.innerHTML = html;
                }
            }
        } catch (err) {
            alert('Erro de comunicação.');
            btn.disabled = false;
        }
    });

    // ─── Handler de vínculo de produto para item manual ─────────────────
    let _vincularNecId = null;
    let _vincularProdutoId = null;
    let _vincularBuscaTimer = null;

    const modalVincularEl = document.getElementById('modal-vincular-produto');
    const vincularBusca = document.getElementById('vincular-produto-busca');
    const vincularResultados = document.getElementById('vincular-produto-resultados');
    const vincularSelecionado = document.getElementById('vincular-produto-selecionado');
    const vincularErro = document.getElementById('vincular-produto-erro');
    const btnConfirmarVinculo = document.getElementById('btn-confirmar-vincular-produto');

    function limparVinculo() {
        _vincularProdutoId = null;
        if (vincularResultados) vincularResultados.innerHTML = '';
        if (vincularSelecionado) {
            vincularSelecionado.classList.add('d-none');
            vincularSelecionado.textContent = '';
        }
        if (vincularErro) {
            vincularErro.classList.add('d-none');
            vincularErro.textContent = '';
        }
        if (btnConfirmarVinculo) btnConfirmarVinculo.disabled = true;
    }

    function renderProdutoResultado(produto) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'list-group-item list-group-item-action';
        btn.dataset.produtoId = String(produto.id || '');

        const titulo = document.createElement('div');
        titulo.className = 'fw-semibold';
        titulo.textContent = `${produto.codigo || 's/cod'} - ${produto.descricao || 'Produto'}`;

        const meta = document.createElement('div');
        meta.className = 'text-body-secondary';
        const controla = Number(produto.controla_estoque ?? 1) === 1 ? 'controla estoque' : 'nao controla estoque';
        const saldo = Number(produto.estoque_qty ?? 0).toFixed(3).replace('.', ',');
        meta.textContent = `Estoque: ${saldo} - ${controla}`;

        btn.append(titulo, meta);
        btn.addEventListener('click', () => {
            _vincularProdutoId = Number(produto.id || 0);
            if (vincularSelecionado) {
                vincularSelecionado.textContent = `Selecionado: ${produto.codigo || 's/cod'} - ${produto.descricao || 'Produto'}`;
                vincularSelecionado.classList.remove('d-none');
            }
            if (btnConfirmarVinculo) btnConfirmarVinculo.disabled = _vincularProdutoId <= 0;
        });
        return btn;
    }

    async function buscarProdutosParaVinculo(q) {
        if (!vincularResultados) return;
        if (q.length < 2) {
            vincularResultados.innerHTML = '';
            return;
        }

        vincularResultados.innerHTML = '<div class="list-group-item text-body-secondary">Buscando...</div>';
        try {
            const res = await fetch(`/api/produtos/busca?q=${encodeURIComponent(q)}&limit=8`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            const produtos = Array.isArray(data.produtos) ? data.produtos : [];
            vincularResultados.innerHTML = '';
            if (produtos.length === 0) {
                vincularResultados.innerHTML = '<div class="list-group-item text-body-secondary">Nenhum produto encontrado.</div>';
                return;
            }
            produtos.forEach(produto => vincularResultados.appendChild(renderProdutoResultado(produto)));
        } catch (err) {
            vincularResultados.innerHTML = '<div class="list-group-item text-danger">Erro ao buscar produtos.</div>';
        }
    }

    document.getElementById('nc-list')?.addEventListener('click', function (e) {
        const btn = e.target.closest('.nc-btn-vincular-produto');
        if (!btn) return;

        _vincularNecId = btn.dataset.id || null;
        limparVinculo();
        const codigo = btn.dataset.codigo || '';
        const desc = btn.dataset.desc || '';
        document.getElementById('vincular-item-desc').textContent = `${codigo ? codigo + ' - ' : ''}${desc || '(sem descricao)'}`;
        if (vincularBusca) {
            vincularBusca.value = codigo || desc;
            setTimeout(() => vincularBusca.focus(), 150);
            buscarProdutosParaVinculo(vincularBusca.value.trim());
        }
        bootstrap.Modal.getOrCreateInstance(modalVincularEl).show();
    });

    vincularBusca?.addEventListener('input', function () {
        if (_vincularBuscaTimer) clearTimeout(_vincularBuscaTimer);
        limparVinculo();
        const q = this.value.trim();
        _vincularBuscaTimer = setTimeout(() => buscarProdutosParaVinculo(q), 250);
    });

    btnConfirmarVinculo?.addEventListener('click', async function () {
        if (!_vincularNecId || !_vincularProdutoId) return;
        this.disabled = true;
        if (vincularErro) vincularErro.classList.add('d-none');

        try {
            const data = await api('POST', `/api/compras/necessidades/${_vincularNecId}/vincular-produto`, {
                produto_id: _vincularProdutoId,
            });
            if (!data.ok) {
                if (vincularErro) {
                    vincularErro.textContent = data.error ?? 'Erro ao vincular produto.';
                    vincularErro.classList.remove('d-none');
                }
                this.disabled = false;
                return;
            }

            bootstrap.Modal.getInstance(modalVincularEl)?.hide();
            window.location.reload();
        } catch (err) {
            if (vincularErro) {
                vincularErro.textContent = 'Erro de comunicacao.';
                vincularErro.classList.remove('d-none');
            }
            this.disabled = false;
        }
    });

    // ─── Handler de entrada no estoque ────────────────────────────────────
    let _entradaNecId   = null;

    document.getElementById('nc-list')?.addEventListener('click', function (e) {
        const btn = e.target.closest('.nc-btn-entrada');
        if (!btn) return;

        _entradaNecId = btn.dataset.id;
        const qtd     = btn.dataset.qtd  ?? '';
        const desc    = btn.dataset.desc ?? '';

        document.getElementById('entrada-desc').textContent = desc || '(sem descrição)';
        document.getElementById('entrada-qtd').value        = qtd;
        document.getElementById('entrada-nfe').value        = '';
        document.getElementById('entrada-obs').value        = '';
        document.getElementById('entrada-erro').classList.add('d-none');
        document.getElementById('btn-confirmar-entrada').disabled = false;

        const modal = bootstrap.Modal.getOrCreate(document.getElementById('modal-entrada'));
        modal.show();
    });

    document.getElementById('btn-confirmar-entrada')?.addEventListener('click', async function () {
        if (!_entradaNecId) return;

        const qtdEntrada = parseFloat(document.getElementById('entrada-qtd').value);
        const chaveNfe   = document.getElementById('entrada-nfe').value.trim();
        const observacao = document.getElementById('entrada-obs').value.trim();
        const erroEl     = document.getElementById('entrada-erro');

        if (!(qtdEntrada > 0)) {
            erroEl.textContent = 'Informe uma quantidade válida (maior que zero).';
            erroEl.classList.remove('d-none');
            return;
        }
        if (chaveNfe !== '' && chaveNfe.length !== 44) {
            erroEl.textContent = 'A chave NF-e deve ter exatamente 44 dígitos ou ficar em branco.';
            erroEl.classList.remove('d-none');
            return;
        }

        this.disabled = true;
        erroEl.classList.add('d-none');

        try {
            const data = await api('POST', `/api/compras/necessidades/${_entradaNecId}/entrada-estoque`, {
                qtd_entrada: qtdEntrada,
                chave_nfe:   chaveNfe,
                observacao:  observacao,
            });

            if (!data.ok) {
                erroEl.textContent = data.error ?? 'Erro ao registrar entrada.';
                erroEl.classList.remove('d-none');
                this.disabled = false;
                return;
            }

            // Fechar modal e atualizar célula de estoque na linha
            bootstrap.Modal.getInstance(document.getElementById('modal-entrada'))?.hide();

            const row = document.getElementById(`nc-row-${_entradaNecId}`);
            if (row) {
                // Coluna "Estoque" (índice 6)
                const cells = row.querySelectorAll('td');
                if (cells[6]) {
                    const novoSaldo = typeof data.saldo_pos === 'number'
                        ? data.saldo_pos.toFixed(3).replace('.', ',')
                        : data.saldo_pos;
                    cells[6].textContent = novoSaldo;
                    cells[6].classList.remove('text-body-tertiary');
                }
                // Desabilitar o botão de entrada (já registrada)
                const entradaBtn = row.querySelector('.nc-btn-entrada');
                if (entradaBtn) {
                    entradaBtn.disabled = true;
                    entradaBtn.title    = 'Entrada já registrada no estoque';
                    entradaBtn.innerHTML = '<i class="ph ph-check-circle"></i>';
                    entradaBtn.classList.remove('btn-primary');
                    entradaBtn.classList.add('btn-outline-secondary');
                }
                // Inserir badge "Entrada feita" abaixo do status badge
                const statusBadgeEl = row.querySelector(`.nc-status-badge[data-id="${_entradaNecId}"]`);
                if (statusBadgeEl && !row.querySelector('.nc-entrada-badge')) {
                    const badgeDiv = document.createElement('div');
                    badgeDiv.className = 'mt-1';
                    badgeDiv.innerHTML = `<span class="status-badge status-badge--success nc-entrada-badge" data-id="${_entradaNecId}" title="Entrada de estoque já registrada para esta necessidade"><i class="ph ph-check-circle me-1"></i>Entrada feita</span>`;
                    statusBadgeEl.parentNode.appendChild(badgeDiv);
                }
            }

            // Mensagem contextual baseada no resultado da promoção automática
            let msgFinal;
            if (data.promovido_montagem) {
                msgFinal = 'Entrada registrada. Equipamento liberado para montagem'
                    + (data.notificacao_criada ? ' — técnico notificado.' : '.');
            } else if (data.bloqueantes_restantes) {
                msgFinal = 'Entrada registrada. Ainda há peças pendentes para este equipamento.';
            } else {
                msgFinal = data.msg ?? 'Entrada registrada com sucesso!';
            }
            alert(msgFinal);
        } catch (err) {
            erroEl.textContent = 'Erro de comunicação com o servidor.';
            erroEl.classList.remove('d-none');
            this.disabled = false;
        }
    });
})();
</script>
<?php endif; // modo !== pedido (modal + script normal) ?>

<?php if ($filtroModo === 'pedido'): ?>
<script>
(function () {
    'use strict';

    // ─── Copiar lista ─────────────────────────────────────────────────────
    document.getElementById('btn-copiar-lista')?.addEventListener('click', function () {
        const rows = document.querySelectorAll('#tabela-pedido tbody tr:not(.table-secondary)');
        const lines = [];
        rows.forEach(function (tr) {
            const tds  = tr.querySelectorAll('td');
            if (tds.length < 3) return;
            const codigo = tds[0].textContent.trim();
            const desc   = tds[1].textContent.trim();
            const qtd    = tds[2].textContent.trim().replace(',', '.'); // normaliza decimal
            lines.push(codigo + '\t' + desc + '\t' + qtd);
        });
        if (lines.length === 0) { alert('Nenhum item para copiar.'); return; }
        const texto = lines.join('\n');
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(texto).then(function () {
                alert('Lista copiada! (' + lines.length + ' item(s))\nCole no Excel ou WhatsApp.');
            }).catch(function () {
                _copiarFallback(texto);
            });
        } else {
            _copiarFallback(texto);
        }
    });

    function _copiarFallback(texto) {
        const ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        alert('Lista copiada!');
    }
})();
</script>
<?php endif; ?>
