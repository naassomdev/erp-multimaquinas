<?php
/**
 * Pagina publica de Aceite Digital do Termo de Responsabilidade.
 * Mobile-first, standalone (sem layout do ERP).
 *
 * @var ?array  $aceite        Dados do aceite + OS (null se slug invalido)
 * @var array   $equipamentos  Lista de equipamentos da OS
 * @var ?string $erro          Mensagem de erro (null se ok)
 */
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($titulo ?? 'Termo de Responsabilidade') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2/regular/style.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
            line-height: 1.6;
            min-height: 100vh;
        }

        .page-wrap {
            max-width: 680px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* -- Header -- */
        .termo-header {
            text-align: center;
            padding: 2rem 1rem 1.5rem;
        }
        .termo-header__icon {
            font-size: 2.5rem;
            margin-bottom: 0.25rem;
            color: var(--bs-primary);
        }
        .termo-header h1 {
            font-size: 1.35rem;
            font-weight: 700;
        }
        .termo-header p {
            font-size: 0.85rem;
            color: var(--bs-secondary-color);
            margin-top: 0.25rem;
        }

        /* -- OS Info -- */
        .os-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem 1rem;
        }
        .os-info dt {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--bs-secondary-color);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .os-info dd {
            font-size: 0.92rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        /* -- Equipamentos -- */
        .equip-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.65rem 0;
            border-bottom: 1px solid var(--bs-border-color);
        }
        .equip-item:last-child { border-bottom: 0; }
        .equip-num {
            background: var(--bs-primary);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            width: 24px; height: 24px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .equip-name { font-weight: 600; font-size: 0.92rem; }
        .equip-detail { font-size: 0.8rem; color: var(--bs-secondary-color); }

        /* -- Termo container -- */
        .termo-container {
            background: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 8px;
            padding: 1.25rem;
            max-height: 400px;
            overflow-y: auto;
            font-size: 0.82rem;
            line-height: 1.7;
            color: var(--bs-body-color);
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .termo-container::-webkit-scrollbar { width: 6px; }
        .termo-container::-webkit-scrollbar-thumb { background: var(--bs-border-color); border-radius: 3px; }

        /* -- Accept section -- */
        .accept-section {
            text-align: center;
            padding: 1.5rem 1rem;
        }
        .accept-label {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            cursor: pointer;
            margin-bottom: 1.25rem;
            text-align: left;
            font-size: 0.88rem;
            font-weight: 500;
        }
        .accept-label input[type="checkbox"] {
            width: 22px; height: 22px;
            accent-color: var(--bs-primary);
            cursor: pointer;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .btn-aceitar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--bs-primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.9rem 2.5rem;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(var(--bs-primary-rgb), 0.3);
            width: 100%;
            max-width: 400px;
        }
        .btn-aceitar:hover:not(:disabled) {
            filter: brightness(0.9);
            transform: translateY(-1px);
        }
        .btn-aceitar:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .btn-aceitar:active:not(:disabled) { transform: translateY(0); }

        /* -- Aceite confirmado -- */
        .aceite-confirmado {
            background: var(--bs-success-bg-subtle);
            border: 2px solid var(--bs-success-border-subtle);
            border-radius: var(--bs-border-radius-lg);
            padding: 1.5rem;
            text-align: center;
        }
        .aceite-confirmado__icon {
            font-size: 3rem;
            display: block;
            margin-bottom: 0.5rem;
            color: var(--bs-success);
        }
        .aceite-confirmado h2 {
            color: var(--bs-success);
            font-size: 1.15rem;
            margin-bottom: 0.5rem;
        }
        .aceite-confirmado p {
            color: var(--bs-secondary-color);
            font-size: 0.85rem;
        }

        /* -- Erro -- */
        .erro-wrap {
            text-align: center;
            padding: 3rem 1rem;
        }
        .erro-wrap__icon { font-size: 3rem; display: block; margin-bottom: 0.75rem; color: var(--bs-danger); }
        .erro-wrap h2 { color: var(--bs-danger); font-size: 1.15rem; margin-bottom: 0.5rem; }
        .erro-wrap p { color: var(--bs-secondary-color); font-size: 0.88rem; }

        /* -- Footer -- */
        .termo-footer {
            text-align: center;
            padding: 1.5rem 1rem;
            font-size: 0.75rem;
            color: var(--bs-secondary-color);
        }

        /* -- Spinner -- */
        .spinner-anim {
            display: inline-block;
            width: 18px; height: 18px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="page-wrap">

    <div class="termo-header">
        <div class="termo-header__icon"><i class="ph ph-gear"></i></div>
        <h1>Multimaquinas Assistencia Tecnica</h1>
        <p>Termo de Responsabilidade e Condicoes de Servico</p>
    </div>

    <?php if ($erro): ?>
        <!-- -- Estado: Erro / Link invalido -- -->
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="erro-wrap">
                    <span class="erro-wrap__icon"><i class="ph ph-x-circle"></i></span>
                    <h2>Link invalido</h2>
                    <p><?= View::e($erro) ?></p>
                </div>
            </div>
        </div>

    <?php elseif ($aceite['aceito_em'] !== null): ?>
        <!-- -- Estado: Ja aceitou -- -->
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h6 class="card-title fw-bold d-flex align-items-center gap-2">
                    <i class="ph ph-clipboard-text"></i> Ordem de Servico #<?= View::e($aceite['os_id']) ?>
                </h6>
                <dl class="os-info">
                    <dt>Cliente</dt>
                    <dd><?= View::e($aceite['nome_cliente']) ?></dd>
                    <dt>Data de Entrada</dt>
                    <dd><?= date('d/m/Y', strtotime($aceite['data_entrada'])) ?></dd>
                </dl>
            </div>
        </div>

        <div class="aceite-confirmado mb-3">
            <span class="aceite-confirmado__icon"><i class="ph ph-check-circle"></i></span>
            <h2>Termo aceito com sucesso!</h2>
            <p>Aceite registrado em <strong><?= date('d/m/Y \a\s H:i', strtotime($aceite['aceito_em'])) ?></strong></p>
            <p class="mt-2">Obrigado pela confianca. Entraremos em contato quando o orcamento estiver pronto.</p>
        </div>

    <?php else: ?>
        <!-- -- Estado: Pendente de aceite -- -->

        <!-- Info da OS -->
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h6 class="card-title fw-bold d-flex align-items-center gap-2">
                    <i class="ph ph-clipboard-text"></i> Dados da Ordem de Servico
                </h6>
                <dl class="os-info">
                    <dt>No da OS</dt>
                    <dd class="fw-bold text-primary">#<?= View::e($aceite['os_id']) ?></dd>
                    <dt>Data de Entrada</dt>
                    <dd><?= date('d/m/Y', strtotime($aceite['data_entrada'])) ?></dd>
                    <dt>Cliente</dt>
                    <dd><?= View::e($aceite['nome_cliente']) ?></dd>
                    <?php if (!empty($aceite['doc_cliente'])): ?>
                    <dt>CPF/CNPJ</dt>
                    <dd><?= View::e($aceite['doc_cliente']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Equipamentos -->
        <?php if (!empty($equipamentos)): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h6 class="card-title fw-bold d-flex align-items-center gap-2">
                    <i class="ph ph-wrench"></i> Equipamento(s) deixado(s)
                </h6>
                <?php foreach ($equipamentos as $i => $eq): ?>
                <div class="equip-item">
                    <span class="equip-num"><?= $i + 1 ?></span>
                    <div>
                        <div class="equip-name"><?= View::e($eq['nome']) ?></div>
                        <?php if (!empty($eq['defeito'])): ?>
                            <div class="equip-detail">Defeito: <?= View::e($eq['defeito']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($eq['serie'])): ?>
                            <div class="equip-detail">Serie: <?= View::e($eq['serie']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Termo completo -->
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h6 class="card-title fw-bold d-flex align-items-center gap-2">
                    <i class="ph ph-file-text"></i> Termo de Responsabilidade
                </h6>
                <div class="termo-container" id="termoTexto"><?= View::e($aceite['versao_termo']) ?></div>
            </div>
        </div>

        <!-- Secao de aceite -->
        <div class="card shadow-sm mb-3">
            <div class="card-body accept-section">
                <label class="accept-label" id="labelAceite">
                    <input type="checkbox" id="chkAceite">
                    <span>Declaro que li, compreendi e concordo plenamente com as regras de orcamento, garantia, prazos de retirada e abandono descritas neste Termo de Responsabilidade.</span>
                </label>
                <button type="button" class="btn-aceitar" id="btnAceitar" disabled>
                    <i class="ph ph-check-circle"></i> Li e concordo com os termos
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="termo-footer">
        <p>&copy; <?= date('Y') ?> Multimaquinas Assistencia Tecnica</p>
        <p>Todos os direitos reservados</p>
    </div>
</div>

<!-- Confirmacao modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content text-center">
            <div class="modal-body p-4">
                <h5 class="mb-2"><i class="ph ph-warning"></i> Confirmar Aceite</h5>
                <p class="text-body-secondary small mb-4">Ao confirmar, voce declara que leu e concorda com <strong>todas as clausulas</strong> do Termo de Responsabilidade.</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light flex-fill" id="btnCancelar" data-bs-dismiss="modal">Voltar</button>
                    <button type="button" class="btn btn-success flex-fill" id="btnConfirmar">Confirmar Aceite</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($aceite && $aceite['aceito_em'] === null): ?>
<script>
(function() {
    const chk   = document.getElementById('chkAceite');
    const btn   = document.getElementById('btnAceitar');
    const confirmModalEl = document.getElementById('confirmModal');
    const confirmModal = new bootstrap.Modal(confirmModalEl);
    const btnConfirm = document.getElementById('btnConfirmar');
    const slug  = '<?= View::e($aceite['slug']) ?>';

    chk.addEventListener('change', () => {
        btn.disabled = !chk.checked;
    });

    btn.addEventListener('click', () => {
        if (!chk.checked) return;
        confirmModal.show();
    });

    btnConfirm.addEventListener('click', async () => {
        btnConfirm.disabled = true;
        btnConfirm.innerHTML = '<span class="spinner-anim"></span> Registrando...';

        try {
            const res = await fetch('/termo/' + slug + '/aceitar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            const json = await res.json();

            if (json.ok) {
                window.location.reload();
            } else {
                alert('Erro: ' + (json.error || 'Nao foi possivel registrar o aceite.'));
                btnConfirm.disabled = false;
                btnConfirm.textContent = 'Confirmar Aceite';
            }
        } catch (err) {
            console.error(err);
            alert('Erro de conexao. Tente novamente.');
            btnConfirm.disabled = false;
            btnConfirm.textContent = 'Confirmar Aceite';
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>
