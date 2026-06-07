<?php
use App\Core\View;
/** @var array $resumoEquipamento */
?>

        <article class="tecnico-summary-card tecnico-summary-card--primary">
            <div class="tecnico-summary-card__header">
                <span class="tecnico-summary-card__eyebrow">Equipamento</span>
                <h2 class="tecnico-summary-card__title"><?= View::e($resumoEquipamento['nome'] !== '' ? $resumoEquipamento['nome'] : 'Equipamento sem nome') ?></h2>
            </div>

            <div class="tecnico-summary-card__content">
                <div class="tecnico-summary-card__grid">
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Marca</span>
                        <strong class="tecnico-summary-card__value"><?= View::e($resumoEquipamento['marca'] !== '' ? $resumoEquipamento['marca'] : '—') ?></strong>
                    </div>
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Modelo</span>
                        <strong class="tecnico-summary-card__value text-mono"><?= View::e($resumoEquipamento['modelo'] !== '' ? $resumoEquipamento['modelo'] : '—') ?></strong>
                    </div>
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Tensão</span>
                        <strong class="tecnico-summary-card__value"><?= View::e($resumoEquipamento['tensao'] !== '' ? $resumoEquipamento['tensao'] : '—') ?></strong>
                    </div>
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Série</span>
                        <strong class="tecnico-summary-card__value text-mono"><?= View::e($resumoEquipamento['serie'] !== '' ? $resumoEquipamento['serie'] : '—') ?></strong>
                    </div>
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Caixa</span>
                        <strong class="tecnico-summary-card__value" id="focus-cx-main"><?= View::e($resumoEquipamento['caixa'] !== '' ? $resumoEquipamento['caixa'] : 'Pendente') ?></strong>
                    </div>
                    <div class="tecnico-summary-card__block">
                        <span class="tecnico-summary-card__label">Garantia</span>
                        <strong class="tecnico-summary-card__value">
                            <?php if (!empty($resumoEquipamento['em_garantia'])): ?>
                                <span class="status-badge status-badge--warning"><?= View::e($resumoEquipamento['garantia']) ?></span>
                            <?php else: ?>
                                <?= View::e($resumoEquipamento['garantia']) ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                </div>

                <div class="tecnico-summary-card__block tecnico-summary-card__block--wide">
                    <span class="tecnico-summary-card__label">Defeito relatado</span>
                    <p class="tecnico-summary-card__text mb-0"><?= View::e($resumoEquipamento['defeito']) ?></p>
                </div>
            </div>
        </article>
