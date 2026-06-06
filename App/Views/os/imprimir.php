<?php
use App\Core\View;
/**
 * @var array  $os
 * @var array  $equipamentos
 * @var ?array $aceite       Dados do aceite digital
 * @var string $termoTexto   Texto completo do termo de responsabilidade
 * @var array  $empresa      Dados da empresa (nome, cnpj, endereco, telefone…)
 * @var bool   $logoExiste   Indica se public/img/logo.png existe
 */

// ── Formatadores inline ────────────────────────────────────────────────────────
$fmtCnpj = static function (string $v): string {
    $v = preg_replace('/\D/', '', $v) ?? '';
    if (strlen($v) === 14) {
        return substr($v, 0, 2) . '.' . substr($v, 2, 3) . '.'
             . substr($v, 5, 3) . '/' . substr($v, 8, 4) . '-' . substr($v, 12, 2);
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

$empNome     = (string) ($empresa['nome']     ?? 'Multimáquinas Assistência Técnica');
$empCnpj     = (string) ($empresa['cnpj']     ?? '');
$empEndereco = (string) ($empresa['endereco'] ?? '');
$empBairro   = (string) ($empresa['bairro']   ?? '');
$empCidade   = (string) ($empresa['cidade']   ?? '');
$empUf       = (string) ($empresa['uf']       ?? '');
$empTelefone = (string) ($empresa['telefone'] ?? '');

// Linha de endereço completa para o cabeçalho
$empEndLinha = trim($empEndereco
    . ($empBairro  !== '' ? ($empEndereco !== '' ? ', ' : '') . $empBairro : '')
    . ($empCidade  !== '' ? ($empEndereco !== '' || $empBairro !== '' ? ' — ' : '') . $empCidade : '')
    . ($empUf      !== '' ? ($empCidade   !== '' ? '/' : ' — ') . $empUf : ''));

$logoExiste = $logoExiste ?? false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Imprimir OS #<?= View::e($os['id']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; font-size: 12px; color: #000; }
        .print-wrap { max-width: 100%; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
        .logo-box { width: 50%; }
        .logo-box h1 { margin: 0; font-size: 20px; }
        .logo-box p { margin: 2px 0; }
        .os-box { text-align: right; width: 50%; }
        .os-box h2 { margin: 0; font-size: 24px; color: #333; }
        
        .section { margin-bottom: 15px; border: 1px solid #000; padding: 10px; border-radius: 4px; }
        .section-title { font-weight: bold; background: #eee; padding: 5px; margin: -10px -10px 10px -10px; border-bottom: 1px solid #000; border-radius: 4px 4px 0 0; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .kv dt { font-weight: bold; display: inline; }
        .kv dd { display: inline; margin: 0 15px 0 5px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; }
        th { background: #eee; }
        
        .termo { font-size: 9px; text-align: justify; margin-top: 20px; white-space: pre-wrap; word-wrap: break-word; line-height: 1.5; }
        .termo-title { font-weight: bold; font-size: 10px; margin-bottom: 5px; }
        .assinaturas { display: flex; justify-content: space-between; margin-top: 40px; }
        .assinatura-linha { border-top: 1px solid #000; width: 45%; text-align: center; padding-top: 5px; }
        
        .aceite-info { font-size: 9px; color: #555; margin-top: 10px; padding: 5px; background: #f5f5f5; border-radius: 3px; }
        
        @media print {
            @page { size: A5 landscape; margin: 10mm; }
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="no-print" style="text-align:center;margin-bottom:20px;">
    <button onclick="window.print()" style="padding:10px 20px;font-size:16px;cursor:pointer;">🖨️ Imprimir OS</button>
    <button onclick="window.close()" style="padding:10px 20px;font-size:16px;cursor:pointer;">Fechar</button>
</div>

<div class="print-wrap">
    
    <div class="header">
        <div class="logo-box">
            <?php if ($logoExiste): ?>
            <img src="/img/logo.png" alt="<?= View::e($empNome) ?>"
                 style="max-height:50px;max-width:160px;object-fit:contain;margin-bottom:4px;display:block;">
            <?php endif; ?>
            <h1><?= View::e($empNome) ?></h1>
            <?php if ($empEndLinha !== ''): ?>
            <p><?= View::e($empEndLinha) ?></p>
            <?php endif; ?>
            <p>
                <?php if ($empTelefone !== ''): ?>
                    Tel: <?= View::e($fmtFone($empTelefone)) ?>
                <?php endif; ?>
                <?php if ($empCnpj !== ''): ?>
                    <?= $empTelefone !== '' ? ' | ' : '' ?>CNPJ: <?= View::e($fmtCnpj($empCnpj)) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="os-box">
            <h2>ORDEM DE SERVIÇO</h2>
            <p style="font-size:18px; font-weight:bold; margin-top:5px;">Nº <?= View::e($os['id']) ?></p>
            <p>Entrada: <?= date('d/m/Y H:i', strtotime($os['data_entrada'])) ?></p>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Dados do Cliente</div>
        <div class="grid-2">
            <div>
                <dl class="kv">
                    <dt>Nome:</dt> <dd><?= View::e($os['nome_cliente']) ?></dd><br>
                    <dt>Telefone:</dt> <dd><?= View::e($os['telefone'] ?: 'Não informado') ?></dd>
                    <?php if (!empty($os['contato_nome']) || !empty($os['contato_telefone'])): ?>
                    <br><dt>Contato:</dt> <dd>
                        <?= View::e((string)($os['contato_nome'] ?? '')) ?>
                        <?php if (!empty($os['contato_nome']) && !empty($os['contato_telefone'])): ?> &mdash; <?php endif; ?>
                        <?= View::e((string)($os['contato_telefone'] ?? '')) ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
            <div>
                <dl class="kv">
                    <dt>CPF/CNPJ:</dt> <dd><?= View::e($os['doc_cliente'] ?: 'Não informado') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Equipamentos e Defeitos Relatados</div>
        <table>
            <thead>
                <tr>
                    <th width="35%">Equipamento / Marca</th>
                    <th width="15%">Série</th>
                    <th width="10%">Tensão</th>
                    <th width="40%">Defeito Relatado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipamentos as $eq): ?>
                <tr>
                    <td>
                        <?= View::e($eq['nome']) ?>
                        <?php if ($eq['em_garantia']): ?>
                            <strong>(Em Garantia)</strong>
                        <?php endif; ?>
                    </td>
                    <td><?= View::e($eq['serie'] ?: '-') ?></td>
                    <td><?= View::e($eq['voltagem'] ?: '-') ?></td>
                    <td><?= View::e($eq['defeito'] ?: 'Não informado') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="termo">
        <div class="termo-title">TERMO DE RESPONSABILIDADE E CONDIÇÕES DE PRESTAÇÃO DE SERVIÇO:</div>
        <?= nl2br(View::e($termoTexto ?? '')) ?>
    </div>

    <?php if (!empty($aceite) && !empty($aceite['aceito_em'])): ?>
    <div class="aceite-info">
        ✅ <strong>Aceite digital registrado</strong> em <?= date('d/m/Y \à\s H:i', strtotime($aceite['aceito_em'])) ?>
        (IP: <?= View::e($aceite['ip_cliente'] ?? '—') ?>)
    </div>
    <?php endif; ?>

    <p style="font-size:10px;margin-top:15px;">
        Declaro que li, compreendi e concordo plenamente com as regras de orçamento, garantia, prazos de retirada e abandono descritas neste Termo de Responsabilidade.
    </p>

    <div class="assinaturas">
        <div class="assinatura-linha">
            Assinatura do Cliente<br>
            <small><?= View::e($os['nome_cliente']) ?></small>
        </div>
        <div class="assinatura-linha">
            Multimáquinas Assistência<br>
            <small>Recebido por: <?= View::e($os['usuario_recebeu']) ?></small>
        </div>
    </div>

</div>

</body>
</html>
