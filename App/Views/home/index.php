<?php
use App\Core\View;
/** @var string $titulo */
/** @var string $mensagem */
?>

<div class="card shadow-sm mx-auto" style="max-width:720px">
    <div class="card-body p-4">
        <h1 class="fs-4 fw-bold"><?= View::e($titulo) ?></h1>
        <p class="text-body-secondary"><?= View::e($mensagem) ?></p>

        <h2 class="fs-5 fw-semibold mt-4">Rotas de teste</h2>
        <ul>
            <li><a href="/">/ (esta pagina)</a></li>
            <li><a href="/ola">/ola (JSON)</a></li>
            <li><a href="/ping">/ping (JSON)</a></li>
        </ul>

        <div class="alert alert-info mt-4">
            Estrutura nova rodando. Proximo passo: tela de login.
        </div>
    </div>
</div>
