<?php
use App\Core\View;
?>

        <article class="tecnico-summary-card tecnico-summary-card--primary">
            <div class="tecnico-summary-card__header">
                <span class="tecnico-summary-card__eyebrow">Card 1</span>
                <h2 class="tecnico-summary-card__title">Cliente e solicitacao</h2>
            </div>

            <div class="tecnico-summary-card__content">
                <div class="tecnico-summary-card__block">
                    <span class="tecnico-summary-card__label">Cliente</span>
                    <strong class="tecnico-summary-card__value"><?= View::e($resumoClienteSolicitacao['cliente'] !== '' ? $resumoClienteSolicitacao['cliente'] : 'Cliente nao informado') ?></strong>
                </div>

                <?php if ($resumoClienteSolicitacao['telefone'] !== ''): ?>
                <div class="tecnico-summary-card__block">
                    <span class="tecnico-summary-card__label">Telefone / WhatsApp</span>
                    <a href="tel:<?= View::e((string) preg_replace('/\D+/', '', $resumoClienteSolicitacao['telefone'])) ?>" class="tecnico-summary-card__link">
                        <?= View::e($resumoClienteSolicitacao['telefone']) ?>
                    </a>
                </div>
                <?php endif; ?>

                <div class="tecnico-summary-card__block tecnico-summary-card__block--wide">
                    <span class="tecnico-summary-card__label">Defeito relatado</span>
                    <p class="tecnico-summary-card__text mb-0"><?= View::e($resumoClienteSolicitacao['defeito']) ?></p>
                </div>

                <?php if ($resumoClienteSolicitacao['observacao_recepcao'] !== ''): ?>
                <div class="tecnico-summary-card__block tecnico-summary-card__block--wide">
                    <span class="tecnico-summary-card__label">Observacao da recepcao</span>
                    <p class="tecnico-summary-card__text mb-0"><?= View::e($resumoClienteSolicitacao['observacao_recepcao']) ?></p>
                </div>
                <?php endif; ?>

                <div class="tecnico-summary-card__footer">
                    <?php if ($resumoClienteSolicitacao['data_entrada'] !== ''): ?>
                        <span><i class="ph ph-calendar me-1"></i>Entrada: <?= View::e($resumoClienteSolicitacao['data_entrada']) ?></span>
                    <?php endif; ?>
                    <?php if ($resumoClienteSolicitacao['prazo'] !== '' && $resumoClienteSolicitacao['prazo'] !== $resumoClienteSolicitacao['data_entrada']): ?>
                        <span><i class="ph ph-hourglass me-1"></i>Prazo: <?= View::e($resumoClienteSolicitacao['prazo']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
