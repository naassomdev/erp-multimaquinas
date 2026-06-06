<?php
use App\Core\View;
/**
 * @var array<string, string> $settings
 * @var string $csrf_token
 */
$v = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
$apiKeyConfigured = trim($v('cpfhub_api_key')) !== '';
$limit = (int) ($v('monthly_plan_limit', '50') ?: '50');
?>

<div class="d-flex flex-column gap-4">
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Clientes - Configuração CPF/CNPJ</h1>
            <p class="page-header__subtitle">CPF via CPFHub e CNPJ preservado via BrasilAPI.</p>
        </div>
        <div class="page-header__actions">
            <a href="/clientes" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
            <a href="/clientes/novo" class="btn btn-primary">
                <i class="ph ph-user-plus me-1"></i> Novo Cliente
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="ph ph-sliders me-1"></i> Integração
                </div>
                <div class="card-body">
                    <form method="POST" action="/clientes/configuracao-documentos" class="d-flex flex-column gap-4" autocomplete="off">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">API Key CPFHub</label>
                                <input type="password" name="cpfhub_api_key" value="<?= View::e($v('cpfhub_api_key')) ?>" class="form-control text-mono" placeholder="Cole aqui a sua chave da CPFHub">
                                <div class="form-text">Usada na consulta de CPF do formulário de clientes.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">URL base CPFHub</label>
                                <input type="url" name="cpfhub_base_url" value="<?= View::e($v('cpfhub_base_url', 'https://api.cpfhub.io')) ?>" class="form-control text-mono">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">URL MCP CPFHub</label>
                                <input type="url" name="cpfhub_mcp_url" value="<?= View::e($v('cpfhub_mcp_url', 'https://api.cpfhub.io/mcp')) ?>" class="form-control text-mono">
                                <div class="form-text">Referência para o tool oficial `get_quota_info` do MCP.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">URL base CNPJ</label>
                                <input type="url" name="cnpj_base_url" value="<?= View::e($v('cnpj_base_url', 'https://brasilapi.com.br/api/cnpj/v1')) ?>" class="form-control text-mono">
                                <div class="form-text">Mantém a consulta de CNPJ separada para não quebrar o fluxo atual.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Plano</label>
                                <input type="text" name="plan_name" value="<?= View::e($v('plan_name', 'Grátis')) ?>" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Limite mensal</label>
                                <input type="number" min="0" step="1" name="monthly_plan_limit" value="<?= View::e($v('monthly_plan_limit', '50')) ?>" class="form-control text-mono">
                                <div class="form-text">No site público da CPFHub, o plano grátis informa 50 consultas/mês.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">WhatsApp suporte</label>
                                <input type="text" name="support_whatsapp_number" value="<?= View::e($v('support_whatsapp_number', '551132300861')) ?>" class="form-control text-mono">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Link de contato</label>
                                <input type="url" name="support_whatsapp_url" value="<?= View::e($v('support_whatsapp_url')) ?>" class="form-control text-mono">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="ph ph-floppy-disk me-1"></i> Salvar configuração
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <i class="ph ph-info me-1"></i> Referências oficiais
                </div>
                <div class="card-body d-flex flex-column gap-3">
                    <div class="alert <?= $apiKeyConfigured ? 'alert-success' : 'alert-warning' ?> mb-0">
                        <?= $apiKeyConfigured ? 'API Key CPFHub configurada.' : 'API Key CPFHub pendente de configuração.' ?>
                    </div>

                    <dl class="row mb-0">
                        <dt class="col-sm-5">Plano</dt>
                        <dd class="col-sm-7"><?= View::e($v('plan_name', 'Grátis')) ?></dd>

                        <dt class="col-sm-5">Limite</dt>
                        <dd class="col-sm-7"><?= number_format($limit, 0, ',', '.') ?> consultas/mês</dd>

                        <dt class="col-sm-5">Ritmo</dt>
                        <dd class="col-sm-7"><?= View::e($v('rate_limit_hint')) ?></dd>

                        <dt class="col-sm-5">Contato</dt>
                        <dd class="col-sm-7 text-mono small"><?= View::e($v('support_whatsapp_number')) ?></dd>
                    </dl>

                    <div class="d-grid gap-2">
                        <a href="<?= View::e($v('support_whatsapp_url')) ?>" class="btn btn-success" target="_blank" rel="noreferrer">
                            <i class="ph ph-whatsapp-logo me-1"></i> Falar com a CPFHub
                        </a>
                        <a href="<?= View::e($v('quickstart_url')) ?>" class="btn btn-outline-secondary" target="_blank" rel="noreferrer">Quickstart PHP</a>
                        <a href="<?= View::e($v('mcp_doc_url')) ?>" class="btn btn-outline-secondary" target="_blank" rel="noreferrer">Documentação MCP</a>
                        <a href="<?= View::e($v('api_reference_url')) ?>" class="btn btn-outline-secondary" target="_blank" rel="noreferrer">API Reference CPF</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <i class="ph ph-shield-check me-1"></i> Observações de operação
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="alert alert-info mb-0">
                        A consulta de CPF agora usa o payload oficial da CPFHub (`success` + `data.name` + `data.birthDate`).
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="alert alert-secondary mb-0">
                        O CNPJ continua funcional via URL separada, com padrão BrasilAPI, para evitar regressão no cadastro.
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="alert alert-warning mb-0">
                        Para confirmar sua cota contratada além do plano grátis, use o contato oficial acima ou o MCP da CPFHub.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
