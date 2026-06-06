<?php
use App\Core\View;
?>

        <article class="tecnico-summary-card tecnico-summary-card--status">
            <div class="tecnico-summary-card__header">
                <span class="tecnico-summary-card__eyebrow">Card 2</span>
                <h2 class="tecnico-summary-card__title">Andamento da OS</h2>
            </div>

            <div class="tecnico-summary-card__content">
                <div class="tecnico-summary-card__grid">
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Status atual</span>
                        <strong class="tecnico-summary-card__value">
                            <span id="status-kpi-main" class="status-badge <?= $resumoAndamento['status_badge'] ?> fs-6 py-1 px-3"><?= View::e($resumoAndamento['status_atual']) ?></span>
                        </strong>
                    </div>
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Desde quando</span>
                        <strong class="tecnico-summary-card__value tecnico-summary-card__value--small"><?= View::e($resumoAndamento['status_desde'] !== '' ? $resumoAndamento['status_desde'] : 'Nao informado') ?></strong>
                    </div>
                </div>

                <div class="tecnico-summary-card__block tecnico-summary-card__block--wide">
                    <span class="tecnico-summary-card__label">Situacao comercial</span>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="tecnico-chip tecnico-chip--success">Orcamento: <?= View::e($resumoAndamento['situacao_comercial']) ?></span>
                        <span class="status-badge <?= $resumoAndamento['situacao_comercial_badge'] ?>">
                            <i class="ph <?= $orcAvisoIco ?> me-1"></i><?= View::e($resumoAndamento['observacao_comercial']) ?>
                        </span>
                    </div>
                </div>

                <?php if ($necessidades_pendentes > 0): ?>
                <div class="tecnico-summary-card__notice">
                    <i class="ph ph-clock me-1"></i>
                    <strong>Aguardando pecas:</strong>
                    <?php
                        $partes = [];
                        if ($necessidades_resumo['pendentes'] > 0) {
                            $partes[] = $necessidades_resumo['pendentes'] . ' pendente(s)';
                        }
                        if ($necessidades_resumo['compradas_sem_entrada'] > 0) {
                            $partes[] = $necessidades_resumo['compradas_sem_entrada'] . ' comprada(s) sem entrada';
                        }
                        if ($necessidades_resumo['manuais_sem_entrada'] > 0) {
                            $partes[] = $necessidades_resumo['manuais_sem_entrada'] . ' item(ns) manual(is) sem produto vinculado';
                        }
                        echo View::e(!empty($partes) ? implode(' · ', $partes) : 'Itens bloqueando montagem.');
                    ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($resumoAndamento['acoes'])): ?>
                <div class="tecnico-summary-card__action d-flex flex-column flex-sm-row flex-wrap gap-2">
                    <?php foreach ($resumoAndamento['acoes'] as $acao): ?>
                        <button type="button"
                                id="<?= View::e((string) ($acao['id'] ?? '')) ?>"
                                class="btn <?= View::e((string) ($acao['class'] ?? 'btn-outline-secondary')) ?> flex-fill flex-sm-grow-0"
                                <?php if (!empty($acao['title'])): ?>title="<?= View::e((string) $acao['title']) ?>"<?php endif; ?>>
                            <?php if (!empty($acao['icon'])): ?><i class="ph <?= View::e((string) $acao['icon']) ?> me-1"></i><?php endif; ?>
                            <?= View::e((string) ($acao['label'] ?? '')) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php elseif (!empty($resumoAndamento['acao_principal'])): ?>
                <div class="tecnico-summary-card__action">
                    <a href="<?= View::e((string) $resumoAndamento['acao_principal']['href']) ?>" class="btn <?= View::e((string) $resumoAndamento['acao_principal']['class']) ?> w-100">
                        <?= View::e((string) $resumoAndamento['acao_principal']['label']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </article>
