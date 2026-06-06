<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\View;

/**
 * @var array|null $editando   null = criar | array = dados do usuário sendo editado (sem senha)
 * @var array      $niveis     Níveis válidos do sistema
 * @var string     $csrf_token Token CSRF
 * @var array      $old        Valores antigos do formulário (para repopular após erro na criação)
 */

$criando     = $editando === null;
$acaoUrl     = $criando ? '/admin/usuarios' : "/admin/usuarios/{$editando['id']}";
$usuarioAuth = Auth::user();

$nivelLabel = [
    'admin'    => 'Administrador — acesso total',
    'recepcao' => 'Recepção — OS, orçamentos, clientes, alertas',
    'oficina'  => 'Oficina / Técnico — painel técnico',
];

/**
 * Retorna o valor do campo: prioriza $old (erros de criação),
 * depois $editando (modo edição) e por fim ''.
 */
$campo = static function (string $chave) use ($old, $editando): string {
    if (isset($old[$chave]) && $old[$chave] !== '') {
        return htmlspecialchars($old[$chave], ENT_QUOTES);
    }
    if ($editando !== null && isset($editando[$chave])) {
        return htmlspecialchars((string) $editando[$chave], ENT_QUOTES);
    }
    return '';
};

$statusAtual = (int) ($old['status'] ?? $editando['status'] ?? 1);
$nivelAtual  = $old['nivel_acesso'] ?? $editando['nivel_acesso'] ?? '';

// Impedir que o admin logado inative a si mesmo via UI
$ehProprioUsuario = !$criando && (int) ($editando['id'] ?? 0) === (int) ($usuarioAuth['id'] ?? 0);
?>

<div class="container-fluid px-4 py-4" style="max-width:700px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/admin/usuarios" class="btn btn-sm btn-outline-secondary">
            <i class="ph ph-arrow-left"></i>
        </a>
        <div>
            <h1 class="fs-4 fw-semibold mb-0">
                <?= $criando ? 'Novo Usuário' : 'Editar Usuário' ?>
            </h1>
            <?php if (!$criando): ?>
            <p class="text-body-secondary small mb-0">
                ID #<?= (int) $editando['id'] ?>
                — Criado em <?php
                    try {
                        echo (new \DateTime((string) $editando['criado_em']))->format('d/m/Y');
                    } catch (\Throwable) {
                        echo View::e((string) $editando['criado_em']);
                    }
                ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="<?= htmlspecialchars($acaoUrl, ENT_QUOTES) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

        <!-- ── Dados pessoais ──────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-user me-1"></i> Dados do usuário
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="nome">
                            Nome <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               value="<?= $campo('nome') ?>"
                               maxlength="100" required
                               placeholder="Nome completo">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="email">
                            E-mail <span class="text-danger">*</span>
                        </label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= $campo('email') ?>"
                               maxlength="100" required
                               placeholder="usuario@exemplo.com">
                        <div class="form-text">Usado para login. Deve ser único.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Nível de acesso ─────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-shield-check me-1"></i> Nível de acesso
            </div>
            <div class="card-body">
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($niveis as $n): ?>
                    <label class="d-flex align-items-start gap-2 p-3 border rounded cursor-pointer
                        <?= $nivelAtual === $n ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                        style="cursor:pointer">
                        <input type="radio" name="nivel_acesso" value="<?= View::e($n) ?>"
                               class="form-check-input mt-1 flex-shrink-0"
                               <?= $nivelAtual === $n ? 'checked' : '' ?> required>
                        <div>
                            <strong><?= View::e(ucfirst($n)) ?></strong>
                            <div class="text-body-secondary small">
                                <?= View::e($nivelLabel[$n] ?? '') ?>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Status ─────────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-toggle-right me-1"></i> Status
            </div>
            <div class="card-body">
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="status"
                               id="status_ativo" value="1"
                               <?= $statusAtual === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="status_ativo">
                            <span class="badge bg-success">Ativo</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="status"
                               id="status_inativo" value="0"
                               <?= $statusAtual === 0 ? 'checked' : '' ?>
                               <?= $ehProprioUsuario ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="status_inativo">
                            <span class="badge bg-secondary">Inativo</span>
                        </label>
                    </div>
                </div>
                <?php if ($ehProprioUsuario): ?>
                <div class="form-text text-warning mt-2">
                    <i class="ph ph-warning me-1"></i>
                    Você não pode inativar seu próprio usuário.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Senha ──────────────────────────────────────────────────────── -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="ph ph-lock me-1"></i> Senha
                <?php if (!$criando): ?>
                <span class="badge bg-secondary ms-2 fw-normal">opcional na edição</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$criando): ?>
                <div class="alert alert-info py-2 small mb-3">
                    <i class="ph ph-info me-1"></i>
                    Deixe em branco para manter a senha atual. Preencha apenas para alterar.
                </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="senha">
                            <?= $criando ? 'Senha <span class="text-danger">*</span>' : 'Nova senha' ?>
                        </label>
                        <input type="password" class="form-control" id="senha" name="senha"
                               autocomplete="new-password"
                               minlength="8"
                               <?= $criando ? 'required' : '' ?>
                               placeholder="Mínimo 8 caracteres">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="senha_confirmar">
                            <?= $criando ? 'Confirmar senha <span class="text-danger">*</span>' : 'Confirmar nova senha' ?>
                        </label>
                        <input type="password" class="form-control" id="senha_confirmar" name="senha_confirmar"
                               autocomplete="new-password"
                               placeholder="Repita a senha">
                        <div id="senha-feedback" class="form-text" style="display:none;color:var(--bs-danger)">
                            As senhas não conferem.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Ações ──────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-5">
            <a href="/admin/usuarios" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="ph ph-floppy-disk me-1"></i>
                <?= $criando ? 'Criar usuário' : 'Salvar alterações' ?>
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const senhaEl   = document.getElementById('senha');
    const confEl    = document.getElementById('senha_confirmar');
    const feedback  = document.getElementById('senha-feedback');
    const form      = senhaEl?.closest('form');

    function validarSenhas() {
        if (!confEl || confEl.value === '') { feedback.style.display = 'none'; return; }
        const ok = senhaEl.value === confEl.value;
        feedback.style.display = ok ? 'none' : 'block';
        confEl.setCustomValidity(ok ? '' : 'As senhas não conferem.');
    }

    senhaEl?.addEventListener('input', validarSenhas);
    confEl?.addEventListener('input', validarSenhas);
    form?.addEventListener('submit', validarSenhas);
})();
</script>
