#!/usr/bin/env php
<?php
declare(strict_types=1);

use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Dotenv::createImmutable(BASE_PATH)->safeLoad();

function usage(): void
{
    $script = basename(__FILE__);
    echo <<<TXT
Uso:
  php scripts/{$script} --input=/abs/path/unmatched.csv [--apply]

Comportamento:
  - Sem --apply: dry-run (nao escreve no banco).
  - Com --apply:
    - insere itens com kind=new;
    - aplica kind=description_match apenas quando o produto sugerido for unico (1:1);
    - gera CSV com pendencias restantes.

TXT;
}

function db(): PDO
{
    $cfg = require BASE_PATH . '/config/database.php';
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset']
        ),
        $cfg['username'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function toFloat(string $v): float
{
    $v = trim($v);
    if ($v === '') return 0.0;
    return (float) str_replace(',', '.', $v);
}

function pricing(float $cost): array
{
    $margin = $cost < 50.0 ? 150.0 : 40.0;
    $sale = round($cost * (1 + $margin / 100), 2);
    return [$margin, $sale];
}

/** @return array<int, array<string, string>> */
function readCsv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("CSV inacessivel: {$path}");
    }

    $rows = [];
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("Falha ao abrir {$path}");
    }

    $header = fgetcsv($fp, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSV vazio: {$path}");
    }

    while (($line = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        if (count($line) !== count($header)) continue;
        $rows[] = array_combine($header, $line);
    }
    fclose($fp);
    return $rows;
}

function writeCsv(string $path, array $rows): void
{
    $fp = fopen($path, 'wb');
    if ($fp === false) throw new RuntimeException("Falha ao criar {$path}");
    if (empty($rows)) {
        fputcsv($fp, ['row', 'codigo', 'descricao', 'estoque_qty', 'preco_custo', 'ativo', 'kind', 'suggested_produto_id', 'suggested_codigo', 'reason'], ',', '"', '\\');
        fclose($fp);
        return;
    }
    $header = array_keys($rows[0]);
    fputcsv($fp, $header, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fp, $row, ',', '"', '\\');
    }
    fclose($fp);
}

$opts = getopt('', ['input:', 'apply', 'help']);
if (isset($opts['help']) || !isset($opts['input'])) {
    usage();
    exit(isset($opts['help']) ? 0 : 1);
}

$input = (string) $opts['input'];
$apply = array_key_exists('apply', $opts);

$rows = readCsv($input);
$byCode = [];
foreach ($rows as $r) {
    $code = trim((string) ($r['codigo'] ?? ''));
    if ($code !== '') {
        $byCode[$code] = true;
    }
}

$descMatches = array_values(array_filter($rows, static fn(array $r): bool => ($r['kind'] ?? '') === 'description_match'));
$descByTarget = [];
foreach ($descMatches as $r) {
    $pid = (string) ($r['suggested_produto_id'] ?? '');
    if ($pid === '') continue;
    $descByTarget[$pid] = ($descByTarget[$pid] ?? 0) + 1;
}

$pdo = db();

$inserted = 0;
$updatedByDesc = 0;
$pending = [];

if ($apply) {
    $pdo->beginTransaction();
    try {
        $checkCode = $pdo->prepare('SELECT id FROM produtos WHERE codigo = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO produtos
              (codigo, ean, categoria, marca, ncm, descricao, valor, valor_oferta, estoque_qty, estoque_min, unidade, ativo, sob_encomenda, preco_custo, margem_lucro, valor_venda_calculado)
             VALUES
              (:codigo, "", "", "", "", :descricao, :valor, 0, :estoque_qty, 0, "un", :ativo, 0, :preco_custo, :margem_lucro, :valor_venda)'
        );
        $update = $pdo->prepare(
            'UPDATE produtos
                SET estoque_qty = :estoque_qty,
                    preco_custo = :preco_custo,
                    margem_lucro = :margem_lucro,
                    valor_venda_calculado = :valor_venda_calc,
                    valor = :valor_venda
              WHERE id = :id
              LIMIT 1'
        );

        foreach ($rows as $r) {
            $kind = trim((string) ($r['kind'] ?? ''));
            $codigo = trim((string) ($r['codigo'] ?? ''));
            $descricao = trim((string) ($r['descricao'] ?? ''));
            $qty = toFloat((string) ($r['estoque_qty'] ?? '0'));
            $cost = toFloat((string) ($r['preco_custo'] ?? '0'));
            $ativo = (int) toFloat((string) ($r['ativo'] ?? '1'));
            [$margin, $sale] = pricing($cost);

            if ($kind === 'new') {
                $checkCode->execute([$codigo]);
                if ($checkCode->fetchColumn() !== false) {
                    $pending[] = $r + ['reason' => 'codigo_ja_existe_no_banco'];
                    continue;
                }

                $insert->execute([
                    ':codigo' => $codigo,
                    ':descricao' => $descricao,
                    ':valor' => $sale,
                    ':estoque_qty' => $qty,
                    ':ativo' => $ativo > 0 ? 1 : 0,
                    ':preco_custo' => $cost,
                    ':margem_lucro' => $margin,
                    ':valor_venda' => $sale,
                ]);
                $inserted++;
                continue;
            }

            if ($kind === 'description_match') {
                $pid = trim((string) ($r['suggested_produto_id'] ?? ''));
                if ($pid === '') {
                    $pending[] = $r + ['reason' => 'sem_produto_sugerido'];
                    continue;
                }
                if (($descByTarget[$pid] ?? 0) !== 1) {
                    $pending[] = $r + ['reason' => 'conflito_mesmo_produto_para_multiplas_linhas'];
                    continue;
                }

                $update->execute([
                    ':id' => (int) $pid,
                    ':estoque_qty' => $qty,
                    ':preco_custo' => $cost,
                    ':margem_lucro' => $margin,
                    ':valor_venda_calc' => $sale,
                    ':valor_venda' => $sale,
                ]);
                $updatedByDesc++;
                continue;
            }

            $pending[] = $r + ['reason' => $kind === '' ? 'kind_vazio' : 'revisao_manual'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} else {
    foreach ($rows as $r) {
        $kind = trim((string) ($r['kind'] ?? ''));
        if ($kind === 'new') {
            $inserted++;
            continue;
        }
        if ($kind === 'description_match') {
            $pid = trim((string) ($r['suggested_produto_id'] ?? ''));
            if ($pid !== '' && ($descByTarget[$pid] ?? 0) === 1) {
                $updatedByDesc++;
            } else {
                $pending[] = $r + ['reason' => 'conflito_mesmo_produto_para_multiplas_linhas'];
            }
            continue;
        }
        $pending[] = $r + ['reason' => 'revisao_manual'];
    }
}

$pendingPath = BASE_PATH . '/storage/logs/legacy_unmatched_pending_' . date('Ymd_His') . '.csv';
writeCsv($pendingPath, $pending);

echo json_encode([
    'ok' => true,
    'mode' => $apply ? 'apply' : 'dry-run',
    'input_rows' => count($rows),
    'would_insert_or_inserted_new' => $inserted,
    'would_update_or_updated_description_match_unique' => $updatedByDesc,
    'pending_manual' => count($pending),
    'pending_csv' => $pendingPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
