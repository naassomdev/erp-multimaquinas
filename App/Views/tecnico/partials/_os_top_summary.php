<?php
use App\Core\View;
?>

    <div class="page-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 pb-3 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-dark fs-6">OS <?= View::e((string) $os['id']) ?></span>
            </div>
            <h1 class="page-header__title fs-3 mt-2 mb-0">
                <?= View::e($nomeEquip !== '' ? $nomeEquip : 'Equipamento sem nome') ?>
                <small class="text-muted fs-6 d-block d-md-inline ms-md-2">(<?= $totalEquipamentosOs > 0 ? $equipamentoAtualNumero . ' de ' . $totalEquipamentosOs : 'Equipamento' ?>)</small>
            </h1>
            <?php // Specs do equipamento agora ficam no card "Equipamento" (mais legível). ?>
        </div>
        <div class="page-header__actions d-flex gap-2">
            <?php if ($podeVerFontesPdf): ?>
            <a href="/tecnico/catalogo-fontes" class="btn btn-outline-secondary btn-sm">
                <i class="ph ph-sliders me-1"></i> Fontes PDF
            </a>
            <?php endif; ?>
            <a href="/tecnico" class="btn btn-outline-secondary btn-sm">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="/dashboard" class="btn btn-outline-secondary btn-sm d-none d-md-inline-block">
                <i class="ph ph-squares-four me-1"></i> Dashboard
            </a>
        </div>
    </div>
