<?php
use App\Core\Csrf;
use App\Core\View;
/** @var ?string $erro */
/** @var ?string $sucesso */
/** @var string  $email_old */
?>

<div class="card shadow-sm" style="max-width:400px;width:100%">
    <div class="card-body p-4">
        <h1 class="fs-4 fw-bold mb-1">Entrar</h1>
        <p class="text-body-secondary mb-4">ERP Multimaquinas</p>

        <?php if (!empty($erro)): ?>
            <div class="alert alert-danger small"><?= View::e($erro) ?></div>
        <?php endif; ?>

        <?php if (!empty($sucesso)): ?>
            <div class="alert alert-success small"><?= View::e($sucesso) ?></div>
        <?php endif; ?>

        <form method="POST" action="/login" autocomplete="on" novalidate>
            <?= Csrf::field() ?>

            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= View::e($email_old) ?>" required autofocus autocomplete="username">
            </div>

            <div class="mb-3">
                <label for="senha" class="form-label">Senha</label>
                <input type="password" id="senha" name="senha" class="form-control"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary w-100">Entrar</button>
        </form>
    </div>
</div>
