<?php
declare(strict_types=1);

use App\Core\View;

/**
 * @var array<string, string> $cfg        Configurações atuais (email_* + smtp_*)
 * @var string                $csrf_token Token CSRF
 */

$temSenha       = isset($cfg['smtp_password']) && $cfg['smtp_password'] !== '';
$encAtual       = $cfg['smtp_encryption'] ?? 'tls';
$smtpAtivo      = ($cfg['smtp_enabled'] ?? '0') === '1';

$v = static fn(string $chave, string $padrao = ''): string =>
    View::e($cfg[$chave] ?? $padrao);
?>

<div class="container-fluid px-4 py-4" style="max-width:760px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div>
            <h1 class="fs-4 fw-semibold mb-0">
                <i class="ph ph-envelope me-2 text-primary"></i> Configuração de E-mail
            </h1>
            <p class="text-body-secondary small mt-1 mb-0">
                Defina o servidor SMTP para envio de e-mails pelo sistema.
            </p>
        </div>
    </div>

    <form method="POST" action="/admin/email" novalidate>
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <!-- ── Remetente ──────────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-user me-1"></i> Remetente
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="email_from_name">
                            Nome do remetente <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="email_from_name"
                               name="email_from_name"
                               value="<?= $v('email_from_name', 'Multimáquinas Assistência') ?>"
                               maxlength="100" required
                               placeholder="Multimáquinas Assistência">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email_from_address">
                            E-mail do remetente <span class="text-danger">*</span>
                        </label>
                        <input type="email" class="form-control" id="email_from_address"
                               name="email_from_address"
                               value="<?= $v('email_from_address') ?>"
                               maxlength="100" required
                               placeholder="naoresponda@empresa.com.br">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SMTP ──────────────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
                <span><i class="ph ph-cloud me-1"></i> Servidor SMTP</span>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="smtp_enabled" name="smtp_enabled" value="1"
                           <?= $smtpAtivo ? 'checked' : '' ?>>
                    <label class="form-check-label" for="smtp_enabled">Habilitado</label>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="smtp_host">Host SMTP</label>
                        <input type="text" class="form-control" id="smtp_host" name="smtp_host"
                               value="<?= $v('smtp_host') ?>"
                               maxlength="200"
                               placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="smtp_port">Porta</label>
                        <input type="number" class="form-control" id="smtp_port" name="smtp_port"
                               value="<?= $v('smtp_port', '587') ?>"
                               min="1" max="65535"
                               placeholder="587">
                        <div class="form-text">587 (TLS) · 465 (SSL) · 25 (sem cripto)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="smtp_encryption">Criptografia</label>
                        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                            <option value="tls" <?= $encAtual === 'tls' ? 'selected' : '' ?>>TLS (recomendado, porta 587)</option>
                            <option value="ssl" <?= $encAtual === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
                            <option value="none" <?= $encAtual === 'none' ? 'selected' : '' ?>>Nenhuma (porta 25)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="smtp_username">Usuário SMTP</label>
                        <input type="text" class="form-control" id="smtp_username" name="smtp_username"
                               value="<?= $v('smtp_username') ?>"
                               maxlength="200" autocomplete="off"
                               placeholder="usuario@empresa.com.br">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="smtp_password">Senha SMTP</label>
                        <input type="password" class="form-control" id="smtp_password" name="smtp_password"
                               autocomplete="new-password" maxlength="500"
                               placeholder="<?= $temSenha ? '••••••••  (senha salva — deixe em branco para manter)' : 'Senha do SMTP' ?>">
                        <?php if ($temSenha): ?>
                        <div class="form-text">
                            <i class="ph ph-check-circle text-success me-1"></i>
                            Senha já configurada. Preencha apenas para alterar.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Ações ──────────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-4">
            <button type="submit" class="btn btn-primary">
                <i class="ph ph-floppy-disk me-1"></i> Salvar configurações
            </button>
        </div>
    </form>

    <!-- ── Teste de envio ─────────────────────────────────────────────────── -->
    <div class="card mb-5">
        <div class="card-header fw-semibold">
            <i class="ph ph-paper-plane-tilt me-1"></i> Teste de envio
        </div>
        <div class="card-body">
            <?php if (!$smtpAtivo): ?>
            <div class="alert alert-warning py-2 small mb-3">
                <i class="ph ph-warning me-1"></i>
                Habilite o SMTP e salve as configurações antes de testar.
            </div>
            <?php endif; ?>
            <form method="POST" action="/admin/email/testar" novalidate>
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label" for="email_destino_teste">
                            Enviar e-mail de teste para
                        </label>
                        <input type="email" class="form-control" id="email_destino_teste"
                               name="email_destino_teste"
                               placeholder="admin@exemplo.com"
                               required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary w-100"
                                <?= !$smtpAtivo ? 'disabled' : '' ?>>
                            <i class="ph ph-paper-plane-tilt me-1"></i> Enviar teste
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

</div>
