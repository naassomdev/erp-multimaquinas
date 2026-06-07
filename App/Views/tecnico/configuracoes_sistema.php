<?php
use App\Core\View;

/**
 * @var array<string, string> $configs
 * @var string $csrf_token
 */
$diasAlerta = (int) ($configs['alerta_dias_os_sem_diagnostico'] ?? 20);
?>

<div class="d-flex flex-column gap-4">
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Configurações do Sistema</h1>
            <p class="page-header__subtitle">Parâmetros operacionais disponíveis apenas para administradores.</p>
        </div>
        <div class="page-header__actions">
            <a href="/tecnico" class="btn btn-outline-secondary">
                <i class="ph ph-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <form method="POST" action="/tecnico/configuracoes-sistema" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <div class="card shadow-sm">
            <div class="card-header">
                <i class="ph ph-bell-ringing me-1"></i> Alertas de diagnóstico
            </div>
            <div class="card-body">
                <label class="form-label" for="alerta_dias">
                    Prazo para alerta de OS sem diagnóstico
                    <span class="text-muted fw-normal">(dias corridos)</span>
                </label>
                <div class="input-group" style="max-width:200px">
                    <input type="number"
                           id="alerta_dias"
                           name="alerta_dias_os_sem_diagnostico"
                           class="form-control"
                           value="<?= View::e((string) $diasAlerta) ?>"
                           min="1"
                           max="365"
                           required>
                    <span class="input-group-text">dias</span>
                </div>
                <div class="form-text">
                    O sistema notifica os técnicos diariamente sobre equipamentos com OS
                    <strong>aberta ou em andamento</strong> sem diagnóstico concluído acima deste prazo.
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="ph ph-floppy-disk me-1"></i> Salvar configurações
            </button>
            <a href="/tecnico" class="btn btn-link">Voltar</a>
        </div>
    </form>
</div>
