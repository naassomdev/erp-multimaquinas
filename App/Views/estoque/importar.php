<?php
use App\Core\View;
/**
 * @var string $csrf_token
 */
?>

<div class="d-flex flex-column gap-4">

    <!-- Cabecalho -->
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Importar NF-e de Compra</h1>
            <p class="page-header__subtitle">Envie o XML da nota fiscal eletronica para alimentar o estoque e gerar a conta a pagar.</p>
        </div>
        <div class="page-header__actions">
            <a href="/estoque" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <form method="POST" action="/estoque/importar/preview" enctype="multipart/form-data" class="d-flex flex-column gap-4">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <div class="card shadow-sm">
            <div class="card-header"><i class="ph ph-file-arrow-up"></i> Arquivo XML</div>
            <div class="card-body">
                <p class="text-body-secondary small mb-3">
                    Envie o XML autorizado (modelo 55 — NF-e). Notas ja importadas anteriormente
                    serao ignoradas (verificacao por chave de acesso).
                </p>
                <div class="mb-3">
                    <label class="form-label">Arquivo XML da NF-e <span class="required">*</span></label>
                    <input type="file" name="xml_nfe" accept=".xml,application/xml,text/xml" required class="form-control">
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-dashed">
            <div class="card-body">
                <h6 class="fw-semibold mb-2"><i class="ph ph-info me-1"></i> Fluxo de importacao em 2 passos</h6>
                <ol class="small text-body-secondary mb-0 ps-3" style="line-height:1.8">
                    <li><strong>Pre-visualizacao</strong> — ao enviar o XML, abrimos uma tela de conferencia onde voce reve cada item, ajusta a margem (padrao: 150%) e confirma a vinculacao com pecas ja cadastradas. <em>Nada e salvo nessa etapa.</em></li>
                    <li><strong>Confirmacao</strong> — so ao clicar em <em>"Confirmar e salvar"</em> e que atualizamos estoque, registramos as movimentacoes, criamos o lancamento em contas a pagar e damos baixa nas pendencias de necessidades de compra.</li>
                </ol>
            </div>
        </div>

        <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2">
            <a href="/estoque" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ph ph-clipboard-text me-1"></i> Pre-visualizar XML
            </button>
        </div>
    </form>
</div>
