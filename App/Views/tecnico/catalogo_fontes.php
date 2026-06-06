<?php
use App\Core\View;

/**
 * @var array<string, mixed> $settings
 * @var string $csrf_token
 */
$builtins = is_array($settings['builtins'] ?? null) ? $settings['builtins'] : [];
$extras = is_array($settings['extras'] ?? null) ? $settings['extras'] : [];
$active = is_array($settings['active'] ?? null) ? $settings['active'] : [];

$extraRows = $extras;
while (count($extraRows) < 5) {
    $extraRows[] = [
        'enabled' => false,
        'slug' => '',
        'label' => '',
        'kind' => 'search_page',
        'priority' => 100 + count($extraRows) * 10,
        'site_url' => '',
        'url_template' => '',
        'description' => '',
    ];
}
?>

<div class="catalog-admin-page d-flex flex-column gap-4">
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Catálogo de Fontes Técnicas</h1>
            <p class="page-header__subtitle">
                Configure quais fontes a equipe técnica usa na busca de vistas explodidas e PDFs.
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/tecnico" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="/tecnico/os/20260516-002/equipamento/0" class="btn btn-primary">
                <i class="ph ph-wrench me-1"></i> Abrir exemplo
            </a>
        </div>
    </div>

    <section class="catalog-admin-overview">
        <article class="catalog-admin-kpi">
            <span class="catalog-admin-kpi__label">Fontes ativas</span>
            <strong class="catalog-admin-kpi__value"><?= number_format(count($active), 0, ',', '.') ?></strong>
            <span class="catalog-admin-kpi__hint">Disponíveis para o técnico hoje.</span>
        </article>
        <article class="catalog-admin-kpi">
            <span class="catalog-admin-kpi__label">Adaptadores nativos</span>
            <strong class="catalog-admin-kpi__value"><?= number_format(count($builtins), 0, ',', '.') ?></strong>
            <span class="catalog-admin-kpi__hint">Felap, TSN, Bosch e Milwaukee.</span>
        </article>
        <article class="catalog-admin-kpi">
            <span class="catalog-admin-kpi__label">Fontes extras</span>
            <strong class="catalog-admin-kpi__value"><?= number_format(count($extras), 0, ',', '.') ?></strong>
            <span class="catalog-admin-kpi__hint">Links adicionais definidos pelo administrador.</span>
        </article>
    </section>

    <form method="POST" action="/tecnico/catalogo-fontes" class="d-flex flex-column gap-4" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <section class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-stack-plus me-1"></i> Fontes nativas
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <p class="text-body-secondary mb-0">
                    Estas fontes já possuem integração pronta no projeto. Aqui você controla nome exibido, ordem e se a fonte aparece para o técnico.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-0 catalog-admin-table">
                        <thead>
                            <tr>
                                <th style="width:80px">Ativa</th>
                                <th>Fonte</th>
                                <th style="width:120px">Ordem</th>
                                <th>Site</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($builtins as $fonte): ?>
                                <tr>
                                    <td>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox"
                                                   name="builtin_enabled[<?= View::e((string) $fonte['slug']) ?>]"
                                                   value="1"
                                                   <?= !empty($fonte['enabled']) ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="builtin_label[<?= View::e((string) $fonte['slug']) ?>]"
                                               value="<?= View::e((string) $fonte['label']) ?>"
                                               class="form-control mb-2">
                                        <div class="small text-body-secondary"><?= View::e((string) ($fonte['description'] ?? '')) ?></div>
                                    </td>
                                    <td>
                                        <input type="number"
                                               min="1"
                                               step="1"
                                               name="builtin_priority[<?= View::e((string) $fonte['slug']) ?>]"
                                               value="<?= View::e((string) $fonte['priority']) ?>"
                                               class="form-control text-mono">
                                    </td>
                                    <td>
                                        <a href="<?= View::e((string) ($fonte['site_url'] ?? '#')) ?>"
                                           target="_blank" rel="noreferrer"
                                           class="small text-decoration-none">
                                            <?= View::e((string) ($fonte['site_url'] ?? '')) ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-link-simple me-1"></i> Fontes extras configuráveis
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <p class="text-body-secondary mb-0">
                    Use estas linhas para cadastrar links adicionais sem alterar código. `PDF direto` pode ser vinculado ao equipamento; `Página de busca` abre a fonte externa para conferência manual.
                </p>

                <div class="alert alert-warning mb-0">
                    Placeholders suportados: <span class="text-mono">{marca}</span>, <span class="text-mono">{modelo}</span>, <span class="text-mono">{query}</span>, <span class="text-mono">{marca_url}</span>, <span class="text-mono">{modelo_url}</span>, <span class="text-mono">{query_url}</span>, <span class="text-mono">{marca_slug}</span> e <span class="text-mono">{modelo_slug}</span>.
                </div>

                <div class="catalog-admin-extras">
                    <?php foreach ($extraRows as $idx => $fonte): ?>
                        <article class="catalog-admin-extra-card">
                            <div class="catalog-admin-extra-card__top">
                                <strong>Fonte extra #<?= $idx + 1 ?></strong>
                                <label class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="extra_enabled[<?= $idx ?>]" value="1" <?= !empty($fonte['enabled']) ? 'checked' : '' ?>>
                                    <span class="small ms-2">Ativa</span>
                                </label>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Slug</label>
                                    <input type="text" name="extra_slug[<?= $idx ?>]" value="<?= View::e((string) ($fonte['slug'] ?? '')) ?>" class="form-control text-mono" placeholder="manualslib">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Nome exibido</label>
                                    <input type="text" name="extra_label[<?= $idx ?>]" value="<?= View::e((string) ($fonte['label'] ?? '')) ?>" class="form-control" placeholder="ManualsLib">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tipo</label>
                                    <select name="extra_kind[<?= $idx ?>]" class="form-select">
                                        <option value="search_page" <?= (($fonte['kind'] ?? '') === 'search_page') ? 'selected' : '' ?>>Página de busca</option>
                                        <option value="direct_pdf" <?= (($fonte['kind'] ?? '') === 'direct_pdf') ? 'selected' : '' ?>>PDF direto</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Ordem</label>
                                    <input type="number" min="1" step="1" name="extra_priority[<?= $idx ?>]" value="<?= View::e((string) ($fonte['priority'] ?? '100')) ?>" class="form-control text-mono">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">URL base / site</label>
                                    <input type="url" name="extra_base_url[<?= $idx ?>]" value="<?= View::e((string) ($fonte['site_url'] ?? '')) ?>" class="form-control text-mono" placeholder="https://example.com">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Template da URL</label>
                                    <input type="text" name="extra_url_template[<?= $idx ?>]" value="<?= View::e((string) ($fonte['url_template'] ?? '')) ?>" class="form-control text-mono" placeholder="https://example.com/search?q={query_url}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Observação</label>
                                    <input type="text" name="extra_notes[<?= $idx ?>]" value="<?= View::e((string) ($fonte['description'] ?? '')) ?>" class="form-control" placeholder="Como a equipe deve usar esta fonte">
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-lightbulb me-1"></i> Como funciona
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="alert alert-info mb-0">
                            As fontes nativas continuam sendo as mais confiáveis porque já possuem parser dentro do projeto.
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="alert alert-secondary mb-0">
                            Fontes extras do tipo `Página de busca` ajudam a localizar o documento, mas exigem validação manual antes de vincular.
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="alert alert-warning mb-0">
                            Se um site novo exigir clique, login ou scraping específico, será preciso criar um novo adapter no serviço de catálogo.
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="ph ph-floppy-disk me-1"></i> Salvar configuração
            </button>
        </div>
    </form>
</div>
