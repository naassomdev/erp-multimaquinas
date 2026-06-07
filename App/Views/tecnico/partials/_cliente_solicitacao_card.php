<?php
use App\Core\View;
/** @var array $resumoClienteSolicitacao */

$contato = trim(
    ($resumoClienteSolicitacao['contato_nome'] ?? '')
    . ($resumoClienteSolicitacao['contato_telefone'] !== '' ? ' · ' . $resumoClienteSolicitacao['contato_telefone'] : ''),
    ' ·'
);
?>

        <article class="tecnico-summary-card">
            <div class="tecnico-summary-card__header">
                <span class="tecnico-summary-card__eyebrow">Cliente</span>
                <h2 class="tecnico-summary-card__title"><?= View::e($resumoClienteSolicitacao['cliente'] !== '' ? $resumoClienteSolicitacao['cliente'] : 'Cliente nao informado') ?></h2>
            </div>

            <div class="tecnico-summary-card__content">
                <?php if ($resumoClienteSolicitacao['documento'] !== ''): ?>
                <div class="tecnico-summary-card__block">
                    <span class="tecnico-summary-card__label">CPF / CNPJ</span>
                    <strong class="tecnico-summary-card__value text-mono"><?= View::e($resumoClienteSolicitacao['documento']) ?></strong>
                </div>
                <?php endif; ?>

                <?php if ($resumoClienteSolicitacao['telefone'] !== ''): ?>
                <div class="tecnico-summary-card__block">
                    <span class="tecnico-summary-card__label">Telefone / WhatsApp</span>
                    <a href="tel:<?= View::e((string) preg_replace('/\D+/', '', $resumoClienteSolicitacao['telefone'])) ?>" class="tecnico-summary-card__link">
                        <?= View::e($resumoClienteSolicitacao['telefone']) ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($contato !== ''): ?>
                <div class="tecnico-summary-card__block">
                    <span class="tecnico-summary-card__label">Contato</span>
                    <strong class="tecnico-summary-card__value"><?= View::e($contato) ?></strong>
                </div>
                <?php endif; ?>

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
