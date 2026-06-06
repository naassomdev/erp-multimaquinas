<?php
use App\Core\View;
?>

            <section class="tecnico-panel is-active" id="tech-panel-painel" data-tech-panel="painel" data-tech-label="Dados e status">
                <div id="cx-required-alert" class="alert alert-warning d-flex align-items-start gap-2 mb-4<?= !empty($equip['cx']) ? ' d-none' : '' ?>">
                    <i class="ph ph-warning-circle fs-5 flex-shrink-0"></i>
                    <div>
                        <strong>Campo Caixa obrigatório.</strong>
                        Preencha a caixa antes de avançar para etapas de execução ou mudança de status.
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-xl-5 order-1 order-xl-2">
                        <div class="card shadow-sm h-100 tecnico-panel-card tecnico-panel-card--status">
                            <div class="card-header"><i class="ph ph-flag"></i> <span class="flex-grow-1">Status do equipamento</span></div>
                            <div class="card-body d-flex flex-column gap-3 tecnico-status-panel">
                                <div class="tecnico-status-card">
                                    <span class="text-body-secondary text-uppercase small fw-semibold">Status atual</span>
                                    <span id="status-atual" class="status-badge <?= $badgeCls ?> fs-6">
                                        <?= View::e($statusEq) ?>
                                    </span>
                                </div>

                                <?php if (!empty($servicoTerceiroEnviado)): ?>
                                <div class="alert alert-warning py-2 mb-0 small" id="banner-servico-terceiro">
                                    <i class="ph ph-timer me-1"></i>
                                    <strong>Serviço terceirizado em andamento.</strong>
                                    <div class="text-body-secondary mt-1">
                                        <?= View::e($tipoServicoTerceiroLabel((string) $servicoTerceiroEnviado['tipo'])) ?>
                                        <?php if (!empty($servicoTerceiroEnviado['fornecedor_nome'])): ?>
                                            · Fornecedor: <?= View::e((string) $servicoTerceiroEnviado['fornecedor_nome']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($servicoTerceiroEnviado['previsao_retorno'])): ?>
                                            · Previsão: <?= View::e($fmtDataTerceiro((string) $servicoTerceiroEnviado['previsao_retorno'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-body-secondary mt-1">
                                        Registre o retorno antes de marcar este equipamento como pronto.
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBtnRemontar): ?>
                                <div>
                                    <button type="button" id="btn-remontar-equipamento" class="btn btn-warning btn-sm w-100">
                                        <i class="ph ph-arrow-counter-clockwise me-1"></i> Remontar equipamento
                                    </button>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerIniciarMontagem): ?>
                                <div class="alert alert-success py-3 mb-0" id="banner-iniciar-montagem">
                                    <div class="fw-semibold small mb-1">
                                        <i class="ph ph-check-circle me-1"></i>Cliente aprovou o conserto deste equipamento.
                                    </div>
                                    <div class="text-body-secondary small mb-2">O equipamento está liberado para montagem/conserto.</div>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" id="btn-iniciar-montagem" class="btn btn-success btn-sm">
                                            <i class="ph ph-wrench me-1"></i> Iniciar montagem agora
                                        </button>
                                        <button type="button" id="btn-adiar-montagem" class="btn btn-outline-secondary btn-sm">
                                            Deixar para depois
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerAprovBloqueado): ?>
                                <?php $podeVerCompras = in_array($usuario['nivel_acesso'] ?? '', ['admin', 'recepcao'], true); ?>
                                <div class="alert alert-warning py-2 mb-0 small" id="banner-aprovado-bloqueado">
                                    <i class="ph ph-clock me-1"></i>
                                    <strong>Cliente aprovou o conserto, mas há peças pendentes.</strong><br>
                                    <span class="text-body-secondary">Aguarde a compra/entrada das peças antes de iniciar montagem.</span>
                                    <?php if ($podeVerCompras): ?>
                                    <div class="mt-2">
                                        <a href="/compras/necessidades?os_id=<?= rawurlencode((string) $os['id']) ?>&status=pendente"
                                           class="btn btn-outline-warning btn-sm">
                                            <i class="ph ph-shopping-cart me-1"></i> Ver peças pendentes
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="mt-1 text-body-secondary">Consulte a recepção sobre as peças pendentes.</div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerMontagemAndamento): ?>
                                <div class="alert alert-primary py-2 mb-0 small" id="banner-montagem-andamento">
                                    <i class="ph ph-wrench me-1"></i>
                                    <strong>Montagem/conserto em andamento.</strong><br>
                                    <span class="text-body-secondary">Quando finalizar, marque este equipamento como Pronto.</span>
                                    <div class="mt-2">
                                        <button type="button" id="btn-marcar-pronto"
                                                class="btn btn-primary btn-sm"
                                                title="Conserto/montagem concluído. A recepção poderá registrar a retirada.">
                                            <i class="ph ph-check-circle me-1"></i> Marcar como pronto
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerProntoAprovado): ?>
                                <div class="alert alert-success py-2 mb-0 small">
                                    <i class="ph ph-check-circle me-1"></i>
                                    <strong>Equipamento pronto</strong> — aguardando retirada pela recepção.
                                    <br><span class="text-body-secondary">Nenhuma ação técnica pendente neste equipamento.</span>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerRetirado): ?>
                                <div class="alert alert-secondary py-2 mb-0 small">
                                    <i class="ph ph-check-square me-1"></i>
                                    <strong>Equipamento retirado</strong> — entregue ao cliente.
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerDevolvido): ?>
                                <div class="alert alert-secondary py-2 mb-0 small">
                                    <i class="ph ph-check-square me-1"></i>
                                    <strong>Equipamento devolvido</strong> ao cliente.
                                </div>
                                <?php endif; ?>

                                <?php if ($temDescarteExecutado): ?>
                                <div class="alert alert-secondary py-2 mb-0 small">
                                    <i class="ph ph-trash me-1"></i>
                                    <strong>Equipamento descartado</strong> — descarte físico confirmado pela recepção.
                                    <?php if ($descarteExecutadoEm !== ''): ?>
                                        <br><span class="text-body-secondary">
                                            Confirmado em: <?= View::e(date('d/m/Y H:i', strtotime($descarteExecutadoEm))) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php elseif ($temDescarteAutorizado): ?>
                                <div class="alert alert-warning py-2 mb-0 small">
                                    <i class="ph ph-trash me-1"></i>
                                    <strong>Cliente autorizou descarte</strong> — realizar descarte físico deste equipamento.
                                    <?php if ($descarteAutorizadoPor !== ''): ?>
                                        <br><span class="text-body-secondary">
                                            Autorizado por: <?= View::e($descarteAutorizadoPor) ?>
                                            <?php if ($descarteMeio !== ''): ?>
                                                via <?= View::e($descarteMeio) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerDevolucao): ?>
                                <div class="alert alert-warning py-2 mb-0 small" id="banner-devolucao">
                                    <i class="ph ph-arrow-counter-clockwise me-1"></i>
                                    <strong>Remontagem para devolução</strong> — marque como Pronto ao concluir.
                                    <div class="mt-2">
                                        <button type="button" id="btn-marcar-pronto-devolucao"
                                                class="btn btn-warning btn-sm"
                                                title="Remontagem concluída. A recepção poderá registrar a devolução.">
                                            <i class="ph ph-check-circle me-1"></i> Marcar como pronto para devolução
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($mostrarBannerAguardDev): ?>
                                <div class="alert alert-info py-2 mb-0 small" id="banner-aguard-devolucao">
                                    <i class="ph ph-check-circle me-1"></i>
                                    <strong>Remontagem concluída</strong> — aguardando devolução pela recepção.
                                </div>
                                <?php endif; ?>

                                <?php if (!$equipFinalizado): ?>
                                <details class="tecnico-collapse-card tecnico-collapse-card--soft mt-2">
                                    <summary class="tecnico-collapse-card__summary">
                                        <span>
                                            <i class="ph ph-sliders-horizontal me-1"></i>Status avançado
                                        </span>
                                        <small>Ajuste manual e controles sensíveis</small>
                                    </summary>
                                    <div class="tecnico-collapse-card__content">
                                        <p class="text-body-secondary small mb-2">
                                            Use apenas em correções administrativas ou situações excepcionais. As ações contextuais acima são o fluxo correto.
                                        </p>
                                        <div class="tecnico-status-control">
                                            <select id="status-target" class="form-select form-select-sm">
                                                <option value="aberta" <?= $statusEq === 'aberta' ? 'selected' : '' ?>>Aberta</option>
                                                <option value="andamento" <?= $statusEq === 'andamento' ? 'selected' : '' ?>>Andamento</option>
                                                <option value="montagem" <?= $statusEq === 'montagem' ? 'selected' : '' ?>>Montagem</option>
                                                <option value="pronto" <?= $statusEq === 'pronto' ? 'selected' : '' ?>>Pronto</option>
                                                <option value="cancelado" <?= $statusEq === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                            </select>
                                            <button type="button" id="btn-status-apply" class="btn btn-outline-secondary btn-sm js-mudar-status">
                                                <i class="ph ph-check me-1"></i> Aplicar
                                            </button>
                                        </div>
                                    </div>
                                </details>
                                <?php endif; ?>

                                <input type="hidden" id="obs-append" value="">
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-7 order-2 order-xl-1">
                        <div class="card shadow-sm h-100 tecnico-panel-card tecnico-panel-card--details">
                            <div class="card-header">
                                <i class="ph ph-wrench"></i>
                                <span class="flex-grow-1">Detalhes do equipamento</span>
                                <span class="text-body-secondary small d-none d-md-inline">Dados completos e edição</span>
                            </div>
                            <div class="card-body">
                                <details class="tecnico-collapse-card">
                                    <summary class="tecnico-collapse-card__summary">
                                        <span>
                                            <i class="ph ph-caret-down me-1"></i>Detalhes do equipamento
                                        </span>
                                        <small>Série, voltagem, caixa, garantia e defeito</small>
                                    </summary>

                                    <div class="tecnico-collapse-card__content">
                                        <div id="view-nome" class="tecnico-detail-row tecnico-detail-row--name">
                                            <div class="tecnico-detail-row__main">
                                                <span class="tecnico-detail-row__label">Nome técnico</span>
                                                <strong id="span-nome-equip" class="tecnico-detail-row__value"><?= View::e($nomeEquip !== '' ? $nomeEquip : 'Sem nome') ?></strong>
                                            </div>
                                            <?php if (!$equipFinalizado): ?>
                                            <button id="btn-editar-nome" class="btn btn-sm btn-outline-primary py-0">
                                                <i class="ph ph-pencil-simple me-1"></i>Renomear
                                            </button>
                                            <?php endif; ?>
                                        </div>

                                        <div id="edit-nome" class="mb-3" hidden>
                                            <label class="form-label small fw-semibold">Novo nome (será salvo em CAIXA ALTA)</label>
                                            <div class="input-group">
                                                <input type="text" id="input-nome-equip" class="form-control" value="<?= View::e($nomeEquip) ?>">
                                                <button id="btn-salvar-nome" class="btn btn-primary">Salvar</button>
                                                <button id="btn-cancelar-nome" class="btn btn-outline-secondary">Cancelar</button>
                                            </div>
                                        </div>

                                        <div class="tecnico-detail-list">
                                            <div class="tecnico-detail-item">
                                                <span class="tecnico-detail-item__label">Série</span>
                                                <div id="view-serie" class="tecnico-detail-item__view">
                                                    <span id="span-serie" class="tecnico-detail-item__value text-mono"><?= View::e((string) ($equip['serie'] ?: '—')) ?></span>
                                                    <?php if (!$equipFinalizado): ?>
                                                    <button id="btn-editar-serie" class="btn btn-xs btn-link p-0 tecnico-inline-edit">Editar</button>
                                                    <?php endif; ?>
                                                </div>
                                                <div id="edit-serie" hidden>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" id="input-serie" class="form-control" value="<?= View::e((string) ($equip['serie'] ?: '')) ?>">
                                                        <button id="btn-salvar-serie" class="btn btn-sm btn-primary">Salvar</button>
                                                        <button id="btn-cancelar-serie" class="btn btn-sm btn-outline-secondary">X</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="tecnico-detail-item">
                                                <span class="tecnico-detail-item__label">Voltagem</span>
                                                <div id="view-voltagem" class="tecnico-detail-item__view">
                                                    <span id="span-voltagem" class="tecnico-detail-item__value"><?= View::e((string) ($equip['voltagem'] ?: '—')) ?></span>
                                                    <?php if (!$equipFinalizado): ?>
                                                    <button id="btn-editar-voltagem" class="btn btn-xs btn-link p-0 tecnico-inline-edit">Editar</button>
                                                    <?php endif; ?>
                                                </div>
                                                <div id="edit-voltagem" hidden>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" id="input-voltagem" class="form-control" value="<?= View::e((string) ($equip['voltagem'] ?: '')) ?>">
                                                        <button id="btn-salvar-voltagem" class="btn btn-sm btn-primary">Salvar</button>
                                                        <button id="btn-cancelar-voltagem" class="btn btn-sm btn-outline-secondary">X</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="tecnico-detail-item">
                                                <span class="tecnico-detail-item__label">Caixa</span>
                                                <div id="view-cx" class="tecnico-detail-item__view">
                                                    <span id="span-cx" class="tecnico-detail-item__value"><?= View::e((string) ($equip['cx'] ?: '—')) ?></span>
                                                    <?php if (!$equipFinalizado): ?>
                                                    <button id="btn-editar-cx" class="btn btn-xs btn-link p-0 tecnico-inline-edit">Editar</button>
                                                    <?php endif; ?>
                                                </div>
                                                <div id="edit-cx" hidden>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" id="input-cx" class="form-control" value="<?= View::e((string) ($equip['cx'] ?: '')) ?>">
                                                        <button id="btn-salvar-cx" class="btn btn-sm btn-primary">Salvar</button>
                                                        <button id="btn-cancelar-cx" class="btn btn-sm btn-outline-secondary">X</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="tecnico-detail-item tecnico-detail-item--wide">
                                                <span class="tecnico-detail-item__label">Garantia</span>
                                                <div class="tecnico-detail-item__view tecnico-detail-item__view--stack">
                                                    <?php if ($emGarantia): ?>
                                                        <span class="status-badge status-badge--brand">
                                                            <?= $tipoGar !== '' ? View::e($tipoGar) : 'sim' ?>
                                                        </span>
                                                        <?php if ($garantiaAutorizacao !== ''): ?>
                                                            <span class="text-body-secondary small" title="Autorização / RMA">
                                                                <?= View::e($garantiaAutorizacao) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-body-secondary">não</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="tecnico-detail-item tecnico-detail-item--wide">
                                                <span class="tecnico-detail-item__label">Defeito relatado</span>
                                                <p class="tecnico-detail-item__text mb-0" id="span-descricao-equip"><?= View::e($defeito !== '' ? $defeito : '—') ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
