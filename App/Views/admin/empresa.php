<?php
declare(strict_types=1);

use App\Core\View;

/**
 * @var array  $cfg        Chaves empresa_* existentes em configuracoes
 * @var array  $nfse       Chaves nfse_prestador_* existentes em configuracoes
 * @var bool   $logoExiste Indica se public/img/logo.png existe
 * @var string $csrf_token Token CSRF
 */

// ── Helpers ───────────────────────────────────────────────────────────────────
$val = static fn(string $chave) => htmlspecialchars((string) ($cfg[$chave] ?? ''), ENT_QUOTES);

// Detectar se campos empresa_* essenciais estão vazios mas nfse tem dados
$empresaIncompleta = ($cfg['empresa_cnpj'] ?? '') === ''
    && ($nfse['nfse_prestador_cnpj'] ?? '') !== '';

// Dados NFS-e para botão "Copiar" (passados como JSON para o JS)
$nfseParaCopiar = [
    'empresa_razao_social' => $nfse['nfse_prestador_razao_social'] ?? '',
    'empresa_cnpj'         => preg_replace('/\D/', '', $nfse['nfse_prestador_cnpj'] ?? '') ?? '',
    'empresa_endereco'     => $nfse['nfse_prestador_logradouro'] ?? '',
    'empresa_numero'       => $nfse['nfse_prestador_numero'] ?? '',
    'empresa_complemento'  => $nfse['nfse_prestador_complemento'] ?? '',
    'empresa_bairro'       => $nfse['nfse_prestador_bairro'] ?? '',
    'empresa_cep'          => preg_replace('/\D/', '', $nfse['nfse_prestador_cep'] ?? '') ?? '',
    'empresa_telefone'     => $nfse['nfse_prestador_telefone'] ?? '',
    'empresa_email'        => $nfse['nfse_prestador_email'] ?? '',
];
?>

<div class="container-fluid px-4 py-4" style="max-width:860px;">

    <div class="page-header mb-4">
        <h1 class="page-header__title fs-4 fw-semibold mb-0">
            <i class="ph ph-buildings me-2 text-primary"></i> Dados da Empresa
        </h1>
        <p class="text-body-secondary small mt-1 mb-0">
            Configure nome, endereço, CNPJ, contato e logo usados em PDFs, impressões e relatórios.
        </p>
    </div>

    <?php if ($empresaIncompleta): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
        <i class="ph ph-warning fs-5 mt-1 flex-shrink-0"></i>
        <div>
            <strong>Dados incompletos.</strong> Alguns campos estão vazios, mas os dados do cadastro NFS-e estão disponíveis como substituto.
            Preencha os dados da empresa para padronizar relatórios e PDFs.
            <br>
            <button type="button" class="btn btn-sm btn-outline-warning mt-2" id="btn-copiar-nfse">
                <i class="ph ph-copy me-1"></i> Copiar dados da NFS-e para os campos
            </button>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" action="/admin/empresa" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

        <!-- ── Identificação ──────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-identification-card me-1"></i> Identificação
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="empresa_nome">
                            Nome fantasia <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="empresa_nome" name="empresa_nome"
                               value="<?= $val('empresa_nome') ?>" maxlength="150" required
                               placeholder="Ex.: Multimáquinas Assistência Técnica">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="empresa_razao_social">Razão social</label>
                        <input type="text" class="form-control" id="empresa_razao_social" name="empresa_razao_social"
                               value="<?= $val('empresa_razao_social') ?>" maxlength="200"
                               placeholder="Ex.: MULTIMAQUINAS COMERCIO E ASSISTENCIA TECNICA LTDA">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="empresa_cnpj">
                            CNPJ <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="empresa_cnpj" name="empresa_cnpj"
                               value="<?= $val('empresa_cnpj') ?>" maxlength="18"
                               placeholder="00.000.000/0001-00"
                               inputmode="numeric">
                        <div class="form-text">Somente os dígitos são salvos.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Endereço ───────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-map-pin me-1"></i> Endereço
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label" for="empresa_endereco">Logradouro</label>
                        <input type="text" class="form-control" id="empresa_endereco" name="empresa_endereco"
                               value="<?= $val('empresa_endereco') ?>" maxlength="200"
                               placeholder="Ex.: Avenida Brigadeiro Jose Vicente Faria Lima">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="empresa_numero">Número</label>
                        <input type="text" class="form-control" id="empresa_numero" name="empresa_numero"
                               value="<?= $val('empresa_numero') ?>" maxlength="20"
                               placeholder="1477">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="empresa_complemento">Complemento</label>
                        <input type="text" class="form-control" id="empresa_complemento" name="empresa_complemento"
                               value="<?= $val('empresa_complemento') ?>" maxlength="100"
                               placeholder="Sala 2">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="empresa_bairro">Bairro</label>
                        <input type="text" class="form-control" id="empresa_bairro" name="empresa_bairro"
                               value="<?= $val('empresa_bairro') ?>" maxlength="100"
                               placeholder="Ex.: Atibaia Jardim">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="empresa_cidade">Cidade</label>
                        <input type="text" class="form-control" id="empresa_cidade" name="empresa_cidade"
                               value="<?= $val('empresa_cidade') ?>" maxlength="100"
                               placeholder="Ex.: Atibaia">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="empresa_uf">UF</label>
                        <input type="text" class="form-control text-uppercase" id="empresa_uf" name="empresa_uf"
                               value="<?= $val('empresa_uf') ?>" maxlength="2"
                               placeholder="SP" style="text-transform:uppercase">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="empresa_cep">CEP</label>
                        <input type="text" class="form-control" id="empresa_cep" name="empresa_cep"
                               value="<?= $val('empresa_cep') ?>" maxlength="9"
                               placeholder="12942-655" inputmode="numeric">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Contato ────────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-phone me-1"></i> Contato
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="empresa_telefone">
                            Telefone / WhatsApp <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="empresa_telefone" name="empresa_telefone"
                               value="<?= $val('empresa_telefone') ?>" maxlength="20"
                               placeholder="(11) 99999-9999" inputmode="tel">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="empresa_email">
                            E-mail <span class="text-danger">*</span>
                        </label>
                        <input type="email" class="form-control" id="empresa_email" name="empresa_email"
                               value="<?= $val('empresa_email') ?>" maxlength="150"
                               placeholder="contato@empresa.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="empresa_site">Site (opcional)</label>
                        <input type="text" class="form-control" id="empresa_site" name="empresa_site"
                               value="<?= $val('empresa_site') ?>" maxlength="200"
                               placeholder="www.empresa.com.br">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Logo ──────────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-image me-1"></i> Logo da Empresa
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <?php if ($logoExiste): ?>
                    <div class="col-auto">
                        <p class="form-label mb-1">Logo atual</p>
                        <img src="/img/logo.png?v=<?= time() ?>"
                             alt="Logo atual da empresa"
                             style="max-height:70px;max-width:200px;object-fit:contain;border:1px solid #dee2e6;border-radius:6px;padding:6px;background:#fff;">
                        <div class="form-text mt-1">
                            Salva em <code>public/img/logo.png</code>
                            <?php if (!empty($cfg['empresa_logo_atualizado_em'])): ?>
                                · Atualizada em <?= htmlspecialchars(
                                    (new \DateTime($cfg['empresa_logo_atualizado_em']))->format('d/m/Y \à\s H:i'),
                                    ENT_QUOTES
                                ) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col">
                        <label class="form-label" for="logo">
                            <?= $logoExiste ? 'Substituir logo' : 'Enviar logo' ?>
                        </label>
                        <input type="file" class="form-control" id="logo" name="logo"
                               accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                        <div class="form-text">
                            PNG, JPG ou WEBP · Máximo 1 MB · Recomendado: fundo transparente, proporção 4:1.
                            A logo anterior será salva como backup automaticamente.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Ações ──────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-5">
            <a href="/dashboard" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="ph ph-floppy-disk me-1"></i> Salvar dados da empresa
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    // Dados NFS-e disponíveis para cópia
    const nfse = <?= json_encode($nfseParaCopiar, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

    const btnCopiar = document.getElementById('btn-copiar-nfse');
    if (!btnCopiar) return;

    btnCopiar.addEventListener('click', function () {
        let copiados = 0;
        Object.entries(nfse).forEach(([campo, valor]) => {
            if (!valor) return;
            const el = document.getElementById(campo);
            if (el && el.value.trim() === '') {
                el.value = valor;
                el.classList.add('is-valid');
                copiados++;
            }
        });

        if (copiados > 0) {
            btnCopiar.textContent = `✓ ${copiados} campo(s) copiado(s). Revise e salve.`;
            btnCopiar.classList.replace('btn-outline-warning', 'btn-success');
            btnCopiar.disabled = true;
        } else {
            btnCopiar.textContent = 'Campos já estão preenchidos.';
            btnCopiar.disabled = true;
        }
    });
})();
</script>
