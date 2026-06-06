<?php
use App\Core\View;
use App\Core\Csrf;
/**
 * @var ?array         $os
 * @var array          $equipamentos
 * @var string         $csrf_token
 * @var string         $modo
 * @var array<string>  $fabricantesHints  lista de fabricantes já usados (autocomplete)
 */
$edit   = $modo === 'editar';
$action = $edit ? '/os/' . View::e($os['id']) : '/os';

$eqs = \App\Core\Flash::oldRaw('equipamentos', $equipamentos);
if (!is_array($eqs) || empty($eqs)) {
    $eqs = [['nome'=>'','fabricante'=>'','modelo'=>'','serie'=>'','defeito'=>'','voltagem'=>'','cx'=>'','em_garantia'=>0,'tipo_garantia'=>'']];
}

$fotosExistentes = [];
if ($edit && !empty($equipamentos[0]['fotos_os_json'])) {
    $decoded = json_decode((string)$equipamentos[0]['fotos_os_json'], true);
    if (is_array($decoded)) $fotosExistentes = $decoded;
}

$v = function (string $campo, $default = '') use ($os, $edit): string {
    $old = \App\Core\Flash::old($campo);
    if ($old !== '') return (string) $old;
    return $edit ? (string) ($os[$campo] ?? $default) : (string) $default;
};
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">
                <?= $edit ? 'Editar OS #' . View::e($os['id']) : 'Nova Ordem de Servico' ?>
            </h1>
            <p class="page-header__subtitle">
                <?= $edit ? 'Atualize os dados e equipamentos' : 'Preencha os dados do cliente e os equipamentos' ?>
            </p>
        </div>
        <div class="page-header__actions">
            <a href="/os" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <form id="osForm" method="POST" action="<?= $action ?>" class="d-flex flex-column gap-4" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="cliente_id" id="cliente_id" value="<?= View::e($v('cliente_id')) ?>">

        <!-- Card: Cliente -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-user"></i> Cliente
            </div>
            <div class="card-body">
                <div class="alert alert-info small d-flex align-items-start gap-2 mb-4">
                    <i class="ph ph-lightbulb flex-shrink-0 mt-1"></i>
                    <span>Digite ao menos 2 letras no nome, ou 4 digitos no telefone / CPF — o sistema busca automaticamente se o cliente ja existe.</span>
                </div>

                <div class="row g-3">
                    <div class="col-12 position-relative">
                        <label class="form-label">Nome do cliente <span class="required">*</span></label>
                        <input type="text" name="nome_cliente" id="nome_cliente"
                               value="<?= View::e($v('nome_cliente')) ?>"
                               data-cliente-search="nome"
                               autocomplete="off" required class="form-control">
                        <div id="ac_nome" class="autocomplete-dropdown" style="display:none;"></div>
                    </div>
                    <div class="col-md-6 position-relative">
                        <label class="form-label">Telefone</label>
                        <input type="tel" inputmode="tel" name="telefone" id="telefone"
                               value="<?= View::e($v('telefone')) ?>"
                               class="form-control mask-phone text-mono"
                               data-cliente-search="telefone" autocomplete="off">
                        <div id="ac_telefone" class="autocomplete-dropdown" style="display:none;"></div>
                    </div>
                    <div class="col-md-6 position-relative">
                        <label class="form-label">CPF / CNPJ</label>
                        <input type="text" inputmode="numeric" name="doc_cliente" id="doc_cliente"
                               value="<?= View::e($v('doc_cliente')) ?>"
                               class="form-control mask-doc text-mono"
                               data-cliente-search="doc" autocomplete="off">
                        <div id="ac_doc" class="autocomplete-dropdown" style="display:none;"></div>
                    </div>
                    <div class="col-12" id="cliente_status" style="display:none;"></div>
                </div>

                <!-- 10F-2: Contato responsável — opcional, para empresas com funcionário de contato -->
                <hr class="my-3">
                <p class="small text-body-secondary mb-2">
                    <i class="ph ph-user-circle me-1"></i>
                    <strong>Contato responsável</strong> (opcional) — preencha quando o equipamento for trazido por um funcionário ou contato diferente do titular da conta.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome do contato</label>
                        <input type="text" name="contato_nome" id="contato_nome"
                               value="<?= View::e($v('contato_nome')) ?>"
                               placeholder="Ex.: João da Silva (recepcionista)"
                               class="form-control" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">WhatsApp / telefone do contato</label>
                        <input type="tel" inputmode="tel" name="contato_telefone" id="contato_telefone"
                               value="<?= View::e($v('contato_telefone')) ?>"
                               placeholder="(DDD) 9xxxx-xxxx"
                               class="form-control mask-phone text-mono" autocomplete="off">
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: Equipamentos -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-wrench"></i>
                <span class="flex-grow-1">Equipamentos</span>
                <button type="button" id="btnAdicionarEquip" class="btn btn-outline-secondary btn-sm">
                    <i class="ph ph-plus me-1"></i> Adicionar equipamento
                </button>
            </div>

            <div id="equipamentos-container" class="card-body d-flex flex-column gap-3">
                <?php foreach ($eqs as $i => $eq): ?>
                <div class="equip-card position-relative border rounded-3 p-3 bg-body-tertiary" data-index="<?= $i ?>">
                    <button type="button" class="btn-remover-equip btn btn-sm btn-outline-danger position-absolute top-0 end-0 mt-2 me-2" title="Remover equipamento">
                        <i class="ph ph-x"></i>
                    </button>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Equipamento / Aparelho <span class="required">*</span></label>
                            <input type="text" name="equipamentos[<?= $i ?>][nome]"
                                   value="<?= View::e($eq['nome'] ?? '') ?>"
                                   placeholder="Ex.: Lavadora Brastemp 11kg" required class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fabricante / Marca</label>
                            <input type="text" name="equipamentos[<?= $i ?>][fabricante]"
                                   value="<?= View::e($eq['fabricante'] ?? '') ?>"
                                   placeholder="Ex.: MAKITA, BOSCH, DEWALT"
                                   list="fabricantes-hints"
                                   class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Modelo</label>
                            <input type="text" name="equipamentos[<?= $i ?>][modelo]"
                                   value="<?= View::e($eq['modelo'] ?? '') ?>"
                                   placeholder="Ex.: HR5210.C, GWS 22-230"
                                   class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Defeito relatado</label>
                            <input type="text" name="equipamentos[<?= $i ?>][defeito]"
                                   value="<?= View::e($eq['defeito'] ?? '') ?>"
                                   placeholder="O que esta acontecendo?" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">No Serie</label>
                            <input type="text" name="equipamentos[<?= $i ?>][serie]"
                                   value="<?= View::e($eq['serie'] ?? '') ?>" class="form-control text-mono">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Voltagem</label>
                            <select name="equipamentos[<?= $i ?>][voltagem]" class="form-select">
                                <option value="">Selecione...</option>
                                <option value="110V"       <?= ($eq['voltagem'] ?? '') === '110V'       ? 'selected' : '' ?>>110V / 127V</option>
                                <option value="220V"       <?= ($eq['voltagem'] ?? '') === '220V'       ? 'selected' : '' ?>>220V</option>
                                <option value="Bivolt"     <?= ($eq['voltagem'] ?? '') === 'Bivolt'     ? 'selected' : '' ?>>Bivolt</option>
                                <option value="Bateria"    <?= ($eq['voltagem'] ?? '') === 'Bateria'    ? 'selected' : '' ?>>Bateria</option>
                                <option value="Monofásico" <?= ($eq['voltagem'] ?? '') === 'Monofásico' ? 'selected' : '' ?>>Monofásico</option>
                                <option value="Trifásico"  <?= ($eq['voltagem'] ?? '') === 'Trifásico'  ? 'selected' : '' ?>>Trifásico</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Caixa / Local</label>
                            <input type="text" name="equipamentos[<?= $i ?>][cx]"
                                   value="<?= View::e($eq['cx'] ?? '') ?>"
                                   placeholder="No da prateleira ou caixa" class="form-control">
                        </div>

                        <div class="col-12 pt-2 border-top d-flex flex-wrap align-items-center gap-3">
                            <div class="form-check">
                                <input type="checkbox" name="equipamentos[<?= $i ?>][em_garantia]" value="1"
                                       class="form-check-input chk-garantia"
                                       id="chkGarantia<?= $i ?>"
                                       <?= !empty($eq['em_garantia']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="chkGarantia<?= $i ?>">Em garantia?</label>
                            </div>
                            <div class="tipo-garantia-wrap d-flex flex-wrap align-items-center gap-3"
                                 style="display:<?= !empty($eq['em_garantia']) ? 'flex' : 'none' ?> !important;">
                                <div class="form-check">
                                    <input type="radio" name="equipamentos[<?= $i ?>][tipo_garantia]" value="loja"
                                           class="form-check-input" id="grLoja<?= $i ?>"
                                           <?= ($eq['tipo_garantia'] ?? '') === 'loja' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="grLoja<?= $i ?>">Nossa loja</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="equipamentos[<?= $i ?>][tipo_garantia]" value="fabricante"
                                           class="form-check-input" id="grFab<?= $i ?>"
                                           <?= ($eq['tipo_garantia'] ?? '') === 'fabricante' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="grFab<?= $i ?>">Fabricante</label>
                                </div>
                                <div>
                                    <input type="text" name="equipamentos[<?= $i ?>][garantia_autorizacao]"
                                           value="<?= View::e($eq['garantia_autorizacao'] ?? '') ?>"
                                           placeholder="Autorização / RMA do fabricante"
                                           class="form-control form-control-sm" style="min-width:200px;" maxlength="50">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Card: Fotos da recepcao -->
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-camera"></i> Fotos da recepcao
            </div>
            <div class="card-body">
                <p class="small text-body-secondary mb-3">
                    Tire fotos do estado em que o equipamento chegou (riscos, amassados, acessorios). Vale para todos os equipamentos desta OS.
                </p>

                <div class="file-drop p-3">
                    <label for="fotos_recepcao" class="foto-capture-btn">
                        <span class="foto-capture-icon"><i class="ph ph-camera" style="font-size:1.75rem"></i></span>
                        <span class="foto-capture-text">
                            <strong>Tirar foto / enviar imagem</strong>
                            <small>Toque para abrir a camera ou escolher do celular</small>
                        </span>
                    </label>
                    <input type="file" id="fotos_recepcao" name="fotos_recepcao[]"
                           accept="image/*" capture="environment" multiple
                           style="position:absolute;left:-9999px;opacity:0;">

                    <div id="fotos_preview" class="fotos-grid" <?= empty($fotosExistentes) ? 'style="display:none;"' : '' ?>>
                        <?php foreach ($fotosExistentes as $url): ?>
                            <div class="foto-thumb existing">
                                <img src="<?= View::e($url) ?>" alt="Foto recepcao">
                                <small>ja salva</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p id="fotos_hint" class="small text-body-secondary mt-3 mb-0"
                       <?= empty($fotosExistentes) ? '' : 'style="display:none;"' ?>>
                        Nenhuma foto adicionada ainda.
                    </p>
                </div>
            </div>
        </div>

        <!-- Acoes -->
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
            <a href="/os" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" id="btnSubmitOs" class="btn btn-primary btn-lg">
                <i class="ph ph-<?= $edit ? 'floppy-disk' : 'check-circle' ?> me-1"></i>
                <?= $edit ? 'Salvar OS' : 'Emitir Ordem de Servico' ?>
            </button>
        </div>
    </form>
</div>

<!-- Template para clonar novos equipamentos -->
<template id="equip-template">
    <div class="equip-card position-relative border rounded-3 p-3 bg-body-tertiary" data-index="{IDX}">
        <button type="button" class="btn-remover-equip btn btn-sm btn-outline-danger position-absolute top-0 end-0 mt-2 me-2" title="Remover equipamento">
            <i class="ph ph-x"></i>
        </button>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Equipamento / Aparelho <span class="required">*</span></label>
                <input type="text" name="equipamentos[{IDX}][nome]" placeholder="Ex.: Lavadora Brastemp 11kg" required class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Fabricante / Marca</label>
                <input type="text" name="equipamentos[{IDX}][fabricante]" placeholder="Ex.: MAKITA, BOSCH, DEWALT" list="fabricantes-hints" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Modelo</label>
                <input type="text" name="equipamentos[{IDX}][modelo]" placeholder="Ex.: HR5210.C, GWS 22-230" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">Defeito relatado</label>
                <input type="text" name="equipamentos[{IDX}][defeito]" placeholder="O que esta acontecendo?" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">No Serie</label>
                <input type="text" name="equipamentos[{IDX}][serie]" class="form-control text-mono">
            </div>
            <div class="col-md-6">
                <label class="form-label">Voltagem</label>
                <select name="equipamentos[{IDX}][voltagem]" class="form-select">
                    <option value="">Selecione...</option>
                    <option value="110V">110V / 127V</option>
                    <option value="220V">220V</option>
                    <option value="Bivolt">Bivolt</option>
                    <option value="Bateria">Bateria</option>
                    <option value="Monofásico">Monofásico</option>
                    <option value="Trifásico">Trifásico</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Caixa / Local</label>
                <input type="text" name="equipamentos[{IDX}][cx]" placeholder="No da prateleira ou caixa" class="form-control">
            </div>
            <div class="col-12 pt-2 border-top d-flex flex-wrap align-items-center gap-3">
                <div class="form-check">
                    <input type="checkbox" name="equipamentos[{IDX}][em_garantia]" value="1" class="form-check-input chk-garantia">
                    Em garantia?
                </div>
                <div class="tipo-garantia-wrap d-flex flex-wrap align-items-center gap-3" style="display:none;">
                    <div class="form-check">
                        <input type="radio" name="equipamentos[{IDX}][tipo_garantia]" value="loja" class="form-check-input"> Nossa loja
                    </div>
                    <div class="form-check">
                        <input type="radio" name="equipamentos[{IDX}][tipo_garantia]" value="fabricante" class="form-check-input"> Fabricante
                    </div>
                    <div>
                        <input type="text" name="equipamentos[{IDX}][garantia_autorizacao]"
                               placeholder="Autorização / RMA do fabricante"
                               class="form-control form-control-sm" style="min-width:200px;" maxlength="50">
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<!-- Autocomplete de fabricantes já usados -->
<datalist id="fabricantes-hints">
    <?php foreach ($fabricantesHints ?? [] as $hint): ?>
    <option value="<?= View::e((string) $hint) ?>">
    <?php endforeach; ?>
</datalist>

<script src="/assets/js/os-form.js?v=<?= time() ?>"></script>

<style>
/* Estilos que o os-form.js depende (autocomplete, fotos) */
.autocomplete-dropdown {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--color-bg-surface, #fff); border: 1px solid var(--color-border, #e5e7eb); border-radius: 8px;
    z-index: 50; max-height: 260px; overflow-y: auto;
    box-shadow: var(--shadow-3); margin-top: 4px;
}
.ac-item {
    padding: 10px 14px; cursor: pointer;
    border-bottom: 1px solid var(--color-border, #f1f5f9);
    display: flex; flex-direction: column; gap: 2px;
}
.ac-item:last-child { border-bottom: 0; }
.ac-item:hover, .ac-item.active { background-color: var(--color-brand-soft, #eef2ff); }
.ac-item strong { color: var(--color-brand, #4f46e5); font-size: .9rem; }
.ac-item .ac-meta { font-size: .76rem; color: var(--color-text-muted, #64748b); }
.ac-empty { padding: 12px 14px; color: var(--color-text-muted, #94a3b8); font-size: .85rem; }

#cliente_status .alert { margin: 0; }
#cliente_status .alert button {
    background: none; border: 0; color: inherit; text-decoration: underline;
    cursor: pointer; font-size: .85rem; padding: 0;
}

.foto-capture-btn {
    display: flex; align-items: center; gap: .85rem;
    padding: .9rem 1rem; border-radius: 10px;
    background: var(--bs-info, #0ea5e9); color: #fff;
    cursor: pointer; user-select: none; min-height: 64px;
    transition: background .15s, transform .15s;
    box-shadow: 0 4px 10px rgba(14,165,233,.25);
}
.foto-capture-btn:hover { background: #0284c7; transform: translateY(-1px); }
.foto-capture-icon {
    width: 44px; height: 44px; border-radius: 10px;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.foto-capture-text { display: flex; flex-direction: column; gap: 2px; }
.foto-capture-text strong { font-size: .95rem; font-weight: 600; }
.foto-capture-text small { font-size: .76rem; opacity: .92; }

.fotos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: .5rem; margin-top: .85rem;
}
.foto-thumb {
    position: relative; aspect-ratio: 1 / 1; border-radius: 8px;
    overflow: hidden; background: var(--color-bg-muted, #f1f5f9);
    border: 1px solid var(--color-border, #cbd5e1);
}
.foto-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.foto-thumb.existing { border-color: var(--bs-success, #16a34a); }
.foto-thumb small {
    position: absolute; bottom: 0; left: 0; right: 0;
    background: rgba(0,0,0,.55); color: #fff;
    font-size: .65rem; text-align: center; padding: 2px 0;
}
.foto-thumb .foto-remove {
    position: absolute; top: 4px; right: 4px;
    background: rgba(220,38,38,.92); color: #fff;
    border: 0; border-radius: 50%; width: 26px; height: 26px;
    cursor: pointer; font-size: .85rem; line-height: 1;
    display: flex; align-items: center; justify-content: center;
}
.foto-thumb .foto-remove:hover { background: #dc2626; }
</style>
