<?php
declare(strict_types=1);

use App\Core\View;

/**
 * @var array  $dados       Resultado de OrcamentoRepository::buscarParaDocumento()
 * @var array  $itens       Resultado de OrcamentoRepository::listarItens()
 * @var array  $empresa     Dados da empresa montados no controller
 * @var bool   $auto_print  Se true, dispara window.print() ao carregar
 */

// ── Modo de renderização ──────────────────────────────────────────────────────
$isPdfDownload = $isPdfDownload ?? false;
$logoDataUri   = $logoDataUri   ?? null;

// ── Formatadores ─────────────────────────────────────────────────────────────
$fmtBrl = static fn(float $v): string =>
    'R$ ' . number_format($v, 2, ',', '.');

$fmtCnpj = static function (string $v): string {
    $v = preg_replace('/\D/', '', $v) ?? '';
    if (strlen($v) === 14) {
        return substr($v, 0, 2) . '.' . substr($v, 2, 3) . '.' .
               substr($v, 5, 3) . '/' . substr($v, 8, 4) . '-' . substr($v, 12, 2);
    }
    return $v;
};

$fmtFone = static function (string $v): string {
    $v = preg_replace('/\D/', '', $v) ?? '';
    $n = strlen($v);
    if ($n === 11) return '(' . substr($v, 0, 2) . ') ' . substr($v, 2, 5) . '-' . substr($v, 7);
    if ($n === 10) return '(' . substr($v, 0, 2) . ') ' . substr($v, 2, 4) . '-' . substr($v, 6);
    return $v;
};

$fmtCep = static function (string $v): string {
    $v = preg_replace('/\D/', '', $v) ?? '';
    return strlen($v) === 8 ? substr($v, 0, 5) . '-' . substr($v, 5) : $v;
};

$fmtData = static function (string $v): string {
    if ($v === '' || $v === '0000-00-00') return '';
    try {
        return (new \DateTime(substr($v, 0, 10)))->format('d/m/Y');
    } catch (\Throwable) {
        return $v;
    }
};

// ── Datas ─────────────────────────────────────────────────────────────────────
$dataOrcStr = (string) ($dados['data_orcamento'] ?? '');
if ($dataOrcStr === '' || $dataOrcStr === '0000-00-00') {
    $dataOrcStr = substr((string) ($dados['created_at'] ?? ''), 0, 10);
}
try {
    $dtOrc      = new \DateTime(substr($dataOrcStr, 0, 10));
    $dataEmissao = $dtOrc->format('d/m/Y');
    $dataValidade = (clone $dtOrc)->modify('+15 days')->format('d/m/Y');
} catch (\Throwable) {
    $dataEmissao  = date('d/m/Y');
    $dataValidade = date('d/m/Y', strtotime('+15 days'));
}

$dataEntrada = $fmtData((string) ($dados['data_entrada'] ?? ''));

// ── Dados do cliente ──────────────────────────────────────────────────────────
$cliNome     = (string) ($dados['cli_nome']     ?: ($dados['nome_cliente']  ?? ''));
$cliFantasia = (string) ($dados['cli_fantasia'] ?? '');
// 10F-3: contato responsável da OS (opcional)
$contatoNome = (string) ($dados['contato_nome']     ?? '');
$contatoTel  = (string) ($dados['contato_telefone'] ?? '');
$cliCpfCnpj  = (string) ($dados['cli_cpf_cnpj'] ?? '');
$cliEmail    = (string) ($dados['cli_email']    ?? '');
$cliTelefone = (string) ($dados['cli_telefone'] ?: ($dados['os_telefone'] ?? ''));
$cliCelular  = (string) ($dados['cli_celular']  ?? '');
$cliEndereco = (string) ($dados['cli_endereco'] ?? '');
$cliNumero   = (string) ($dados['cli_numero']   ?? '');
$cliCompl    = (string) ($dados['cli_complemento'] ?? '');
$cliBairro   = (string) ($dados['cli_bairro']   ?? '');
$cliCidade   = (string) ($dados['cli_cidade']   ?? '');
$cliUf       = (string) ($dados['cli_uf']       ?? '');
$cliCep      = (string) ($dados['cli_cep']      ?? '');

$cliEndFull = trim(
    $cliEndereco .
    ($cliNumero !== '' ? ', ' . $cliNumero : '') .
    ($cliCompl  !== '' ? ' — ' . $cliCompl : '')
);

$cliLocalidade = trim(
    $cliBairro .
    ($cliBairro !== '' && ($cliCidade !== '' || $cliUf !== '') ? ', ' : '') .
    $cliCidade .
    ($cliUf  !== '' ? ' - ' . $cliUf : '') .
    ($cliCep !== '' ? ' · CEP ' . $fmtCep($cliCep) : '')
);

// ── Dados do equipamento ──────────────────────────────────────────────────────
$equipNome   = (string) ($dados['equip_nome']  ?? '');
$fabricante  = (string) ($dados['fabricante']  ?? '');
$modelo      = (string) ($dados['modelo']      ?? '');
$serie       = (string) ($dados['serie']       ?? '');
$voltagem    = (string) ($dados['voltagem']    ?? '');
$defeito     = (string) ($dados['defeito']     ?? '');

// Diagnóstico: obs_cli preferida, fallback obs_int, omite seção se ambas vazias.
$obsCli      = (string) ($dados['obs_cli'] ?? '');
$obsInt      = (string) ($dados['obs_int'] ?? '');
$diagnostico = $obsCli !== '' ? $obsCli : $obsInt;
$mostrarDiag = $diagnostico !== '';

// Garantia
$emGarantiaFab = (int) ($dados['em_garantia'] ?? 0) === 1
    && (string) ($dados['tipo_garantia'] ?? '') === 'fabricante';
$garantiaAuth  = (string) ($dados['garantia_autorizacao'] ?? '');

// ── Dados do orçamento ────────────────────────────────────────────────────────
$orcId       = (int)   ($dados['id']       ?? 0);
$osId        = (string)($dados['os_id']    ?? '');
$statusOrc   = (string)($dados['status']   ?? 'rascunho');
$geradoPor   = (string)($dados['gerado_por'] ?? '');
$total       = (float) ($dados['total']    ?? 0);
$moValor     = (float) ($dados['mo_valor'] ?? 0);
$motivoGrat  = (string)($dados['motivo_gratuidade'] ?? '');
$mostrarGrat = $total <= 0.0 && $motivoGrat !== '';

$subtotalPecas = 0.0;
foreach ($itens as $it) {
    $subtotalPecas += (float) ($it['valor_total'] ?? 0);
}

$statusLabel = [
    'rascunho'  => 'Rascunho',
    'enviado'   => 'Enviado ao cliente',
    'aprovado'  => 'Aprovado',
    'cancelado' => 'Cancelado',
][$statusOrc] ?? ucfirst($statusOrc);

// ── Empresa ───────────────────────────────────────────────────────────────────
$empNome     = (string) ($empresa['nome']     ?? 'Multimáquinas Assistência Técnica');
$empCnpj     = (string) ($empresa['cnpj']     ?? '');
$empEndereco = (string) ($empresa['endereco'] ?? '');
$empBairro   = (string) ($empresa['bairro']   ?? '');
$empCidade   = (string) ($empresa['cidade']   ?? '');
$empCep      = (string) ($empresa['cep']      ?? '');
$empTelefone = (string) ($empresa['telefone'] ?? '');
$empEmail    = (string) ($empresa['email']    ?? '');

$empEndFull = trim(
    $empEndereco .
    ($empBairro  !== '' ? ($empEndereco !== '' ? ', ' : '') . $empBairro : '') .
    ($empCep     !== '' ? ' · CEP ' . $fmtCep($empCep) : '')
);

// Logo
$logoExiste = is_file(BASE_PATH . '/public/img/logo.png');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Orçamento #<?= $orcId ?> — <?= View::e($equipNome) ?></title>
<style>
/* ── Reset mínimo ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.5;
    color: #1a1a1a;
    margin: 0;
    padding: 20px;
    background: #fff;
}

/* ── Wrapper ──────────────────────────────────────────────── */
.doc-wrap { max-width: 210mm; margin: 0 auto; }

/* ── Cabeçalho ────────────────────────────────────────────── */
.doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    border-bottom: 2px solid #1a3c5e;
    padding-bottom: 14px;
    margin-bottom: 14px;
}
.doc-header__brand {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    flex: 1;
}
.doc-header__brand img {
    max-height: 56px;
    max-width: 90px;
    object-fit: contain;
    flex-shrink: 0;
}
.doc-header__brand-info { }
.doc-brand-name {
    font-size: 15px;
    font-weight: bold;
    color: #1a3c5e;
    margin: 0 0 2px;
}
.doc-brand-sub {
    font-size: 9.5px;
    color: #555;
    margin: 1px 0 0;
}

.doc-header__meta { text-align: right; flex-shrink: 0; }
.doc-meta-badge {
    font-size: 16px;
    font-weight: bold;
    color: #1a3c5e;
    border: 2px solid #1a3c5e;
    display: inline-block;
    padding: 3px 10px;
    border-radius: 4px;
    margin-bottom: 8px;
    letter-spacing: .03em;
}
.doc-meta-table { border-collapse: collapse; font-size: 9.5px; margin-left: auto; }
.doc-meta-table th {
    text-align: right;
    padding: 1px 6px 1px 0;
    color: #666;
    font-weight: normal;
    white-space: nowrap;
}
.doc-meta-table td {
    text-align: left;
    font-weight: 600;
    padding: 1px 0;
    white-space: nowrap;
}

/* ── Seções ───────────────────────────────────────────────── */
.doc-section { margin-bottom: 10px; }
.doc-section-title {
    font-size: 9px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #1a3c5e;
    background: #eef2f7;
    padding: 4px 8px;
    border-left: 3px solid #1a3c5e;
    margin-bottom: 6px;
}

/* ── Grade de dados ───────────────────────────────────────── */
.doc-grid { display: grid; gap: 2px 18px; padding: 2px 4px; }
.doc-grid--2 { grid-template-columns: 1fr 1fr; }
.doc-grid--3 { grid-template-columns: 1fr 1fr 1fr; }
.doc-kv { margin-bottom: 3px; }
.doc-kv dt { font-size: 9px; color: #777; text-transform: uppercase; letter-spacing: .04em; margin: 0; }
.doc-kv dd { font-size: 10.5px; font-weight: 500; margin: 0; }
.doc-kv dd.empty { color: #bbb; font-style: italic; }

/* ── Tabela de itens ──────────────────────────────────────── */
.doc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    margin-bottom: 6px;
    color: #000;
}
.doc-table th {
    background: #eef2f7;
    border: 1px solid #888;
    padding: 4px 6px;
    text-align: left;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #000;
    font-weight: bold;
}
.doc-table td {
    border: 1px solid #888;
    padding: 4px 6px;
    vertical-align: top;
    font-weight: 600;
}
.doc-table tr:nth-child(even) td { background: #fafbfc; }
.doc-table .r { text-align: right; }
.doc-table .c { text-align: center; }
.doc-table .mono { font-family: 'Courier New', Courier, monospace; }

/* Linha M.O. */
.doc-table tr.mo-row td { background: #f5f7fa; font-style: italic; }

/* ── Totais ───────────────────────────────────────────────── */
.doc-totals-wrap { display: flex; justify-content: flex-end; margin-bottom: 10px; }
.doc-totals { border-collapse: collapse; font-size: 11px; min-width: 200px; }
.doc-totals td { padding: 3px 10px; }
.doc-totals td:first-child { color: #555; text-align: right; }
.doc-totals td:last-child  { font-weight: 600; text-align: right; font-family: 'Courier New', Courier, monospace; }
.doc-totals tr.total-row td {
    font-size: 13px;
    font-weight: bold;
    border-top: 2px solid #1a3c5e;
    padding-top: 5px;
}
.doc-totals tr.total-row td:first-child { color: #1a3c5e; }

/* ── Gratuidade ───────────────────────────────────────────── */
.doc-gratuidade {
    border: 2px solid #1a3c5e;
    border-radius: 4px;
    padding: 10px 14px;
    text-align: center;
    margin: 8px 0;
    background: #f0f5fc;
}
.doc-gratuidade p { margin: 0; font-weight: 600; font-size: 11px; color: #1a3c5e; }

/* ── Diagnóstico ──────────────────────────────────────────── */
.doc-diag-box {
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 8px 10px;
    font-size: 10.5px;
    color: #333;
    white-space: pre-wrap;
    word-break: break-word;
    background: #fafbfc;
}

/* ── Condições ────────────────────────────────────────────── */
.doc-conditions {
    font-size: 9px;
    color: #666;
    padding: 2px 4px;
}
.doc-conditions ul { margin: 2px 0; padding-left: 14px; }
.doc-conditions li { margin-bottom: 2px; }

/* ── Aprovação ────────────────────────────────────────────── */
.doc-approval {
    display: flex;
    gap: 20px;
    margin-top: 18px;
    padding-top: 6px;
}
.doc-approval__field {
    flex: 1;
    border-top: 1px solid #aaa;
    padding-top: 4px;
    font-size: 9.5px;
    color: #666;
}

/* ── Rodapé ───────────────────────────────────────────────── */
.doc-footer {
    border-top: 1px solid #ccc;
    margin-top: 14px;
    padding-top: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 8.5px;
    color: #888;
}

/* ── Status badge ─────────────────────────────────────────── */
.status-pill {
    display: inline-block;
    font-size: 8px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.status-pill--rascunho  { background: #e9ecef; color: #495057; }
.status-pill--enviado   { background: #dbeafe; color: #1d4ed8; }
.status-pill--aprovado  { background: #dcfce7; color: #166534; }
.status-pill--cancelado { background: #fee2e2; color: #991b1b; }

/* ── Barra de ações (tela) ────────────────────────────────── */
.no-print {
    display: flex;
    gap: 12px;
    justify-content: center;
    align-items: center;
    padding: 12px 20px;
    background: #f0f4f8;
    border-bottom: 1px solid #ccd;
    margin-bottom: 20px;
}
.no-print button {
    padding: 8px 22px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}
.btn-print    { background: #1a3c5e; color: #fff; }
.btn-print:hover { background: #153251; }
.btn-download { background: #166534; color: #fff; }
.btn-download:hover { background: #14532d; }
.btn-back  { background: #e2e8f0; color: #333; }
.btn-back:hover  { background: #cbd5e1; }

/* ── Impressão ────────────────────────────────────────────── */
@page { size: A4; margin: 10mm; }
@media print {
    .no-print { display: none !important; }
    body { padding: 0; background: #fff; }
    .doc-wrap { max-width: 100%; }
    .doc-section { break-inside: avoid; }
}
</style>
<?php if ($isPdfDownload): ?>
<style>
/* ── dompdf compat: substituir flex/grid por float ────────── */
.no-print { display: none !important; }
.doc-header { overflow: hidden; width: 100%; }
.doc-header__brand { float: left; width: 65%; }
.doc-header__brand img { float: left; margin-right: 10px; }
.doc-header__meta { float: right; width: 34%; text-align: right; }
.doc-grid { overflow: hidden; }
.doc-grid--2 .doc-kv { float: left; width: 48%; margin-right: 2%; }
.doc-grid--3 .doc-kv { float: left; width: 31%; margin-right: 2%; }
.doc-totals-wrap { display: block; text-align: right; }
.doc-totals { margin-left: auto; }
.doc-approval { overflow: hidden; }
.doc-approval__field { float: left; width: 31%; margin-right: 2%; }
.doc-footer { overflow: hidden; }
.doc-footer span:first-child { float: left; }
.doc-footer span:last-child { float: right; }
</style>
<?php endif; ?>
</head>
<body<?= $auto_print ? ' onload="window.print()"' : '' ?>>

<!-- ── Barra de ações (oculta ao imprimir) ───────────────────────────────── -->
<?php if (!$isPdfDownload): ?>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    <button class="btn-download" onclick="window.location.href=window.location.pathname+'?download=1'">⬇️ Baixar PDF</button>
    <button class="btn-back"  onclick="history.back()">← Voltar</button>
</div>
<?php endif; ?>

<div class="doc-wrap">

    <!-- ── Cabeçalho ──────────────────────────────────────────────────────── -->
    <div class="doc-header">
        <div class="doc-header__brand">
            <?php if ($isPdfDownload && $logoDataUri !== null): ?>
            <img src="<?= $logoDataUri ?>" alt="Logo <?= View::e($empNome) ?>">
            <?php elseif (!$isPdfDownload && $logoExiste): ?>
            <img src="/img/logo.png" alt="Logo <?= View::e($empNome) ?>">
            <?php endif; ?>
            <div class="doc-header__brand-info">
                <p class="doc-brand-name"><?= View::e($empNome) ?></p>
                <?php if ($empCnpj !== ''): ?>
                <p class="doc-brand-sub">CNPJ: <?= View::e($fmtCnpj($empCnpj)) ?></p>
                <?php endif; ?>
                <?php if ($empEndFull !== ''): ?>
                <p class="doc-brand-sub"><?= View::e($empEndFull) ?></p>
                <?php endif; ?>
                <?php if ($empTelefone !== '' || $empEmail !== ''): ?>
                <p class="doc-brand-sub">
                    <?php if ($empTelefone !== ''): ?>
                        Tel: <?= View::e($fmtFone($empTelefone)) ?>
                    <?php endif; ?>
                    <?php if ($empEmail !== ''): ?>
                        <?= $empTelefone !== '' ? ' &nbsp;·&nbsp; ' : '' ?><?= View::e($empEmail) ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="doc-header__meta">
            <table class="doc-meta-table">
                <tr><th>Nº:</th>      <td><?= $orcId ?></td></tr>
                <tr><th>OS:</th>      <td>#<?= View::e($osId) ?></td></tr>
                <tr><th>Emissão:</th> <td><?= View::e($dataEmissao) ?></td></tr>
                <tr><th>Válido até:</th> <td><?= View::e($dataValidade) ?></td></tr>
                <?php if ($geradoPor !== ''): ?>
                <tr><th>Atendente:</th> <td><?= View::e($geradoPor) ?></td></tr>
                <?php endif; ?>
                <tr><th>Status:</th>
                    <td><span class="status-pill status-pill--<?= View::e($statusOrc) ?>"><?= View::e($statusLabel) ?></span></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ── Cliente ────────────────────────────────────────────────────────── -->
    <div class="doc-section">
        <div class="doc-section-title">Cliente</div>
        <div class="doc-grid doc-grid--3">
            <dl class="doc-kv">
                <dt>Nome</dt>
                <dd><?= View::e($cliNome) ?: '<span class="empty">—</span>' ?></dd>
            </dl>
            <?php if ($cliFantasia !== ''): ?>
            <dl class="doc-kv">
                <dt>Nome fantasia</dt>
                <dd><?= View::e($cliFantasia) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($cliCpfCnpj !== ''): ?>
            <dl class="doc-kv">
                <dt>CPF/CNPJ</dt>
                <dd><?= View::e($cliCpfCnpj) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($cliEmail !== ''): ?>
            <dl class="doc-kv">
                <dt>E-mail</dt>
                <dd><?= View::e($cliEmail) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($cliTelefone !== ''): ?>
            <dl class="doc-kv">
                <dt>Telefone</dt>
                <dd><?= View::e($fmtFone($cliTelefone)) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($cliCelular !== '' && $cliCelular !== $cliTelefone): ?>
            <dl class="doc-kv">
                <dt>Celular</dt>
                <dd><?= View::e($fmtFone($cliCelular)) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($contatoNome !== '' || $contatoTel !== ''): ?>
            <dl class="doc-kv">
                <dt>Contato responsável</dt>
                <dd>
                    <?= View::e($contatoNome) ?>
                    <?php if ($contatoNome !== '' && $contatoTel !== ''): ?> &mdash; <?php endif; ?>
                    <?php if ($contatoTel !== ''): ?><?= View::e($fmtFone($contatoTel)) ?><?php endif; ?>
                </dd>
            </dl>
            <?php endif; ?>
        </div>
        <?php if ($cliEndFull !== '' || $cliLocalidade !== ''): ?>
        <div style="padding:2px 4px;margin-top:2px;">
            <dl class="doc-kv">
                <dt>Endereço</dt>
                <dd>
                    <?php if ($cliEndFull !== ''): ?><?= View::e($cliEndFull) ?><?php endif; ?>
                    <?php if ($cliLocalidade !== ''): ?>
                        <?= $cliEndFull !== '' ? ' — ' : '' ?><?= View::e($cliLocalidade) ?>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Equipamento ────────────────────────────────────────────────────── -->
    <div class="doc-section">
        <div class="doc-section-title">Equipamento — OS #<?= View::e($osId) ?></div>
        <div class="doc-grid doc-grid--3">
            <dl class="doc-kv">
                <dt>Equipamento</dt>
                <dd><?= View::e($equipNome) ?: '<span class="empty">—</span>' ?></dd>
            </dl>
            <?php if ($fabricante !== ''): ?>
            <dl class="doc-kv">
                <dt>Fabricante</dt>
                <dd><?= View::e($fabricante) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($modelo !== ''): ?>
            <dl class="doc-kv">
                <dt>Modelo</dt>
                <dd><?= View::e($modelo) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($serie !== '' && $serie !== 'N/I'): ?>
            <dl class="doc-kv">
                <dt>Nº de série</dt>
                <dd><?= View::e($serie) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($voltagem !== ''): ?>
            <dl class="doc-kv">
                <dt>Voltagem</dt>
                <dd><?= View::e($voltagem) ?></dd>
            </dl>
            <?php endif; ?>
            <?php if ($dataEntrada !== ''): ?>
            <dl class="doc-kv">
                <dt>Entrada na OS</dt>
                <dd><?= View::e($dataEntrada) ?></dd>
            </dl>
            <?php endif; ?>
        </div>
        <?php if ($defeito !== ''): ?>
        <div style="padding:4px 4px 0;">
            <dl class="doc-kv">
                <dt>Defeito informado pelo cliente</dt>
                <dd><?= nl2br(View::e($defeito)) ?></dd>
            </dl>
        </div>
        <?php endif; ?>
        <?php if ($emGarantiaFab && $garantiaAuth !== ''): ?>
        <div style="padding:4px 4px 0;">
            <dl class="doc-kv">
                <dt>Autorização / RMA de fabricante</dt>
                <dd><?= View::e($garantiaAuth) ?></dd>
            </dl>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Diagnóstico / Observação ao cliente ────────────────────────────── -->
    <?php if ($mostrarDiag): ?>
    <div class="doc-section">
        <div class="doc-section-title">Diagnóstico / Observação ao cliente</div>
        <div class="doc-diag-box"><?= nl2br(View::e($diagnostico)) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Itens do orçamento ─────────────────────────────────────────────── -->
    <div class="doc-section">
        <div class="doc-section-title">Peças e serviços</div>
        <?php if (empty($itens)): ?>
        <p style="padding:4px;color:#999;font-style:italic;font-size:10px;">Nenhum item registrado neste orçamento.</p>
        <?php else: ?>
        <table class="doc-table">
            <thead>
                <tr>
                    <th style="width:80px">Cód./Ref.</th>
                    <th>Descrição</th>
                    <th class="c" style="width:50px">Qtd</th>
                    <th class="c" style="width:40px">Un</th>
                    <th class="r" style="width:90px">Valor unit.</th>
                    <th class="r" style="width:90px">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $it): ?>
                <tr>
                    <td class="mono"><?= View::e((string) ($it['codigo'] ?? '')) ?: '<span style="color:#bbb">—</span>' ?></td>
                    <td><?= View::e((string) ($it['descricao'] ?? '')) ?></td>
                    <td class="c mono"><?= number_format((float) ($it['qtd'] ?? 0), ($it['qtd'] == floor((float)$it['qtd']) ? 0 : 3), ',', '') ?></td>
                    <td class="c"><?= View::e((string) ($it['unidade'] ?? 'un')) ?></td>
                    <td class="r mono"><?= $fmtBrl((float) ($it['valor_unit'] ?? 0)) ?></td>
                    <td class="r mono"><?= $fmtBrl((float) ($it['valor_total'] ?? 0)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- ── Totais ─────────────────────────────────────────────────────────── -->
    <div class="doc-section">
        <div class="doc-totals-wrap">
            <table class="doc-totals">
                <tr>
                    <td>Subtotal peças / serviços:</td>
                    <td><?= $fmtBrl($subtotalPecas) ?></td>
                </tr>
                <tr>
                    <td>Mão de obra:</td>
                    <td><?= $fmtBrl($moValor) ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total do orçamento:</td>
                    <td><?= $fmtBrl($total) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ── Gratuidade ─────────────────────────────────────────────────────── -->
    <?php if ($mostrarGrat): ?>
    <div class="doc-gratuidade">
        <?php if ($motivoGrat === 'garantia_fabricante'): ?>
        <p>✔ Atendimento registrado como <strong>garantia de fabricante</strong> — sem cobrança ao cliente.</p>
        <?php elseif ($motivoGrat === 'cortesia'): ?>
        <p>✔ Atendimento registrado como <strong>cortesia</strong> — sem cobrança ao cliente.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Condições gerais ───────────────────────────────────────────────── -->
    <div class="doc-section doc-conditions">
        <div class="doc-section-title">Condições gerais</div>
        <ul>
            <li>Orçamento válido por <strong>15 dias</strong> a partir da data de emissão.</li>
            <li>A aprovação deste orçamento autoriza o início do serviço/conserto.</li>
            <li>Peças estão sujeitas à disponibilidade no momento da execução.</li>
            <li>Caso sejam identificados novos danos durante o conserto, o cliente será consultado antes de qualquer intervenção adicional.</li>
            <li>Para <strong>APROVAR</strong> o orçamento, responda este documento por WhatsApp ou e-mail com a palavra <strong>APROVADO</strong>.</li>
            <li>Para <strong>RECUSAR</strong>, responda com a palavra <strong>CANCELADO</strong>.</li>
        </ul>
    </div>

    <!-- ── Aprovação / Assinatura ─────────────────────────────────────────── -->
    <div class="doc-section" style="margin-top:16px;">
        <div class="doc-approval">
            <div class="doc-approval__field">
                Assinatura do cliente
            </div>
            <div class="doc-approval__field">
                Nome legível
            </div>
            <div class="doc-approval__field">
                Data: &nbsp; _____ / _____ / __________
            </div>
        </div>
    </div>

    <!-- ── Rodapé ─────────────────────────────────────────────────────────── -->
    <div class="doc-footer">
        <span>
            <?php if ($empTelefone !== ''): ?>
                Tel: <?= View::e($fmtFone($empTelefone)) ?>
            <?php endif; ?>
            <?php if ($empEmail !== ''): ?>
                <?= $empTelefone !== '' ? ' · ' : '' ?><?= View::e($empEmail) ?>
            <?php endif; ?>
        </span>
        <span>Documento gerado pelo sistema Multimáquinas · <?= date('d/m/Y H:i') ?></span>
    </div>

</div><!-- /.doc-wrap -->

</body>
</html>
