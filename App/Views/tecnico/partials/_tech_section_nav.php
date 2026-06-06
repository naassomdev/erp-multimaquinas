            <!-- Navegação Horizontal Premium de Abas -->
            <nav class="tecnico-nav-tabs mb-3 d-flex overflow-x-auto gap-2 py-1" aria-label="Seções do equipamento" style="scrollbar-width: none;">
                <button type="button" class="tecnico-nav__item is-active btn btn-light px-3 py-2 border rounded-pill text-nowrap d-flex align-items-center gap-2" data-tech-target="painel" title="Dados e status" aria-label="Dados e status">
                    <i class="ph ph-wrench"></i>
                    <span class="tecnico-nav-label tecnico-nav-label--full fw-bold small">Dados e status</span>
                    <span class="tecnico-nav-label tecnico-nav-label--short fw-bold small" aria-hidden="true">Dados</span>
                </button>
                <button type="button" class="tecnico-nav__item btn btn-light px-3 py-2 border rounded-pill text-nowrap d-flex align-items-center gap-2" data-tech-target="pecas" title="Peças e serviços" aria-label="Peças e serviços">
                    <i class="ph ph-package"></i>
                    <span class="tecnico-nav-label tecnico-nav-label--full fw-bold small">Peças e serviços</span>
                    <span class="tecnico-nav-label tecnico-nav-label--short fw-bold small" aria-hidden="true">Peças</span>
                    <span class="tecnico-nav-badge" id="tab-pecas-count"><?= number_format($totalItens, 0, ',', '.') ?></span>
                </button>
                <button type="button" class="tecnico-nav__item btn btn-light px-3 py-2 border rounded-pill text-nowrap d-flex align-items-center gap-2" data-tech-target="laudo" title="Laudo técnico" aria-label="Laudo técnico">
                    <i class="ph ph-clipboard-text"></i>
                    <span class="tecnico-nav-label tecnico-nav-label--full fw-bold small">Laudo técnico</span>
                    <span class="tecnico-nav-label tecnico-nav-label--short fw-bold small" aria-hidden="true">Laudo</span>
                </button>
                <button type="button" class="tecnico-nav__item btn btn-light px-3 py-2 border rounded-pill text-nowrap d-flex align-items-center gap-2" data-tech-target="midias" title="Fotos e evidências" aria-label="Fotos e evidências">
                    <i class="ph ph-camera"></i>
                    <span class="tecnico-nav-label tecnico-nav-label--full fw-bold small">Fotos e evidências</span>
                    <span class="tecnico-nav-label tecnico-nav-label--short fw-bold small" aria-hidden="true">Fotos</span>
                    <span class="tecnico-nav-badge" id="tab-midias-count"><?= number_format($totalFotos + $totalFotosRecepcao, 0, ',', '.') ?></span>
                </button>
                <button type="button" class="tecnico-nav__item btn btn-light px-3 py-2 border rounded-pill text-nowrap d-flex align-items-center gap-2" data-tech-target="vista" title="Vista explodida" aria-label="Vista explodida">
                    <i class="ph ph-blueprint"></i>
                    <span class="tecnico-nav-label tecnico-nav-label--full fw-bold small">Vista explodida</span>
                    <span class="tecnico-nav-label tecnico-nav-label--short fw-bold small" aria-hidden="true">Vistas</span>
                    <span class="tecnico-nav-badge" id="tab-vista-count"><?= $vista !== '' ? '1' : '0' ?></span>
                </button>
            </nav>

            <div class="tecnico-main__toolbar mb-3">
                <div>
                    <span class="tecnico-main__eyebrow">Área ativa</span>
                    <strong id="tech-current-label">Dados e status</strong>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="tech-prev">
                        <i class="ph ph-caret-left me-1"></i> Anterior
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="tech-next">
                        Próxima <i class="ph ph-caret-right ms-1"></i>
                    </button>
                </div>
            </div>
