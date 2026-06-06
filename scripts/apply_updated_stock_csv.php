#!/usr/bin/env php
<?php
declare(strict_types=1);

use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Dotenv::createImmutable(BASE_PATH)->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

final class UpdatedStockCsvApplier
{
    private const CSV_HEADERS = [
        'ID',
        'Código',
        'EAN',
        'Descrição',
        'Categoria',
        'Marca',
        'NCM',
        'Unidade',
        'Preço de Custo (R$)',
        'Margem de Lucro (%)',
        'Valor de Venda (R$)',
        'Preço de Oferta (R$)',
        'Estoque Atual',
        'Estoque Mínimo',
        'Controla Estoque',
        'Status',
        'Data de Cadastro',
    ];

    /** @var array<string, int> */
    private array $stats = [
        'csv_rows' => 0,
        'matched_rows' => 0,
        'changed_products' => 0,
        'stock_movements' => 0,
        'text_fixes' => 0,
        'brand_inferred' => 0,
        'brand_normalized' => 0,
        'category_inferred' => 0,
        'margin_capped' => 0,
        'zero_cost_remaining' => 0,
        'missing_ncm_remaining' => 0,
        'missing_brand_remaining' => 0,
        'missing_category_remaining' => 0,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $csvPath,
        private readonly bool $apply,
        private readonly bool $deleteCsv,
        private readonly ?int $userId,
    ) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (!is_file($this->csvPath) || !is_readable($this->csvPath)) {
            throw new RuntimeException("CSV inacessivel: {$this->csvPath}");
        }

        $rows = $this->readCsv();
        $dbRows = $this->loadProductsById();
        $updates = $this->buildUpdates($rows, $dbRows);

        $timestamp = date('Ymd_His');
        $backupPath = null;
        if ($this->apply) {
            $backupPath = $this->saveJson(
                BASE_PATH . "/storage/logs/stock_update_backup_{$timestamp}.json",
                array_values($dbRows)
            );
            $this->applyUpdates($updates);
            if ($this->deleteCsv) {
                unlink($this->csvPath);
            }
        }

        $report = [
            'generated_at' => date(DATE_ATOM),
            'csv_path' => $this->csvPath,
            'applied' => $this->apply,
            'csv_deleted' => $this->apply && $this->deleteCsv && !is_file($this->csvPath),
            'backup_path' => $backupPath,
            'stats' => $this->stats,
            'warnings' => $this->warnings,
        ];

        $report['report_path'] = $this->saveJson(
            BASE_PATH . "/storage/logs/stock_update_estoque_atualizado_{$timestamp}.json",
            $report
        );

        return $report;
    }

    /** @return array<int, array<string, mixed>> */
    private function readCsv(): array
    {
        $fp = fopen($this->csvPath, 'r');
        if ($fp === false) {
            throw new RuntimeException('Falha ao abrir CSV.');
        }

        $headers = fgetcsv($fp, 0, ';', '"', '\\');
        if ($headers === false) {
            throw new RuntimeException('CSV vazio.');
        }

        if ($headers !== self::CSV_HEADERS) {
            throw new RuntimeException('Cabecalho do CSV nao corresponde ao formato esperado.');
        }

        $idx = array_flip($headers);
        $rows = [];
        $ids = [];
        $codes = [];

        while (($raw = fgetcsv($fp, 0, ';', '"', '\\')) !== false) {
            if (count($raw) === 1 && trim((string) $raw[0]) === '') {
                continue;
            }

            $id = (int) trim((string) ($raw[$idx['ID']] ?? '0'));
            $codigo = trim((string) ($raw[$idx['Código']] ?? ''));
            if ($id <= 0 || $codigo === '') {
                throw new RuntimeException('Linha com ID ou codigo vazio no CSV.');
            }
            if (isset($ids[$id])) {
                throw new RuntimeException("ID duplicado no CSV: {$id}");
            }
            if (isset($codes[$codigo])) {
                throw new RuntimeException("Codigo duplicado no CSV: {$codigo}");
            }

            $ids[$id] = true;
            $codes[$codigo] = true;

            $rows[] = [
                'id' => $id,
                'codigo' => $codigo,
                'ean' => trim((string) ($raw[$idx['EAN']] ?? '')),
                'descricao' => trim((string) ($raw[$idx['Descrição']] ?? '')),
                'categoria' => trim((string) ($raw[$idx['Categoria']] ?? '')),
                'marca' => trim((string) ($raw[$idx['Marca']] ?? '')),
                'ncm' => trim((string) ($raw[$idx['NCM']] ?? '')),
                'unidade' => trim((string) ($raw[$idx['Unidade']] ?? 'un')) ?: 'un',
                'preco_custo' => $this->toFloat($raw[$idx['Preço de Custo (R$)']] ?? ''),
                'valor_venda' => $this->toFloat($raw[$idx['Valor de Venda (R$)']] ?? ''),
                'valor_oferta' => $this->toFloat($raw[$idx['Preço de Oferta (R$)']] ?? ''),
                'estoque_qty' => $this->toFloat($raw[$idx['Estoque Atual']] ?? ''),
                'estoque_min' => $this->toFloat($raw[$idx['Estoque Mínimo']] ?? ''),
                'controla_estoque' => $this->toBoolSimNao($raw[$idx['Controla Estoque']] ?? 'Sim'),
                'ativo' => trim((string) ($raw[$idx['Status']] ?? 'Ativo')) === 'Ativo' ? 1 : 0,
            ];
        }

        fclose($fp);
        $this->stats['csv_rows'] = count($rows);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadProductsById(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, codigo, ean, descricao, categoria, marca, ncm, unidade,
                    valor, valor_oferta, preco_custo, margem_lucro, valor_venda_calculado,
                    estoque_qty, estoque_min, ativo, controla_estoque, created_at, updated_at
               FROM produtos'
        );

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $csvRows
     * @param array<int, array<string, mixed>> $dbRows
     * @return array<int, array{before: array<string, mixed>, after: array<string, mixed>}>
     */
    private function buildUpdates(array $csvRows, array $dbRows): array
    {
        $updates = [];

        foreach ($csvRows as $row) {
            $id = (int) $row['id'];
            if (!isset($dbRows[$id])) {
                throw new RuntimeException("Produto ID {$id} existe no CSV, mas nao existe no banco.");
            }

            $before = $dbRows[$id];
            if ((string) $before['codigo'] !== (string) $row['codigo']) {
                throw new RuntimeException("Codigo divergente para ID {$id}: banco={$before['codigo']} csv={$row['codigo']}");
            }

            $after = $this->correctRow($row);
            $this->countRemainingGaps($after);

            $diff = false;
            foreach ($after as $field => $value) {
                if ($this->fieldChanged($before[$field] ?? null, $value)) {
                    $diff = true;
                    break;
                }
            }

            if ($diff) {
                $updates[] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
            $this->stats['matched_rows']++;
        }

        $this->stats['changed_products'] = count($updates);

        return $updates;
    }

    /** @param array<string, mixed> $row */
    private function correctRow(array $row): array
    {
        foreach (['ean', 'descricao', 'categoria', 'marca', 'ncm', 'unidade'] as $field) {
            $fixed = $this->fixText((string) $row[$field]);
            if ($fixed !== $row[$field]) {
                $this->stats['text_fixes']++;
            }
            $row[$field] = $fixed;
        }

        $brand = $this->normalizeBrand((string) $row['marca']);
        if ($brand !== $row['marca']) {
            $this->stats['brand_normalized']++;
            $row['marca'] = $brand;
        }

        if ($row['marca'] === '') {
            $inferred = $this->inferBrand((string) $row['descricao']);
            if ($inferred !== '') {
                $row['marca'] = $inferred;
                $this->stats['brand_inferred']++;
            }
        }

        if ($row['categoria'] === '') {
            $inferred = $this->inferCategory((string) $row['descricao']);
            $row['categoria'] = $inferred;
            $this->stats['category_inferred']++;
        }

        $margin = 0.0;
        if ((float) $row['preco_custo'] > 0.0 && (float) $row['valor_venda'] > 0.0) {
            $margin = (((float) $row['valor_venda'] / (float) $row['preco_custo']) - 1.0) * 100.0;
            if ($margin > 999.99) {
                $margin = 999.99;
                $this->stats['margin_capped']++;
            }
        }

        return [
            'codigo' => $row['codigo'],
            'ean' => $row['ean'],
            'descricao' => $row['descricao'],
            'categoria' => $row['categoria'],
            'marca' => $row['marca'],
            'ncm' => preg_replace('/\D+/', '', (string) $row['ncm']) ?? '',
            'unidade' => $row['unidade'] ?: 'un',
            'valor' => round((float) $row['valor_venda'], 2),
            'valor_oferta' => round((float) $row['valor_oferta'], 2),
            'preco_custo' => round((float) $row['preco_custo'], 2),
            'margem_lucro' => round($margin, 2),
            'valor_venda_calculado' => round((float) $row['valor_venda'], 2),
            'estoque_qty' => round((float) $row['estoque_qty'], 3),
            'estoque_min' => round((float) $row['estoque_min'], 3),
            'ativo' => (int) $row['ativo'],
            'controla_estoque' => (int) $row['controla_estoque'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function countRemainingGaps(array $row): void
    {
        if ((float) $row['preco_custo'] <= 0.0) {
            $this->stats['zero_cost_remaining']++;
        }
        if (trim((string) $row['ncm']) === '') {
            $this->stats['missing_ncm_remaining']++;
        }
        if (trim((string) $row['marca']) === '') {
            $this->stats['missing_brand_remaining']++;
        }
        if (trim((string) $row['categoria']) === '') {
            $this->stats['missing_category_remaining']++;
        }
    }

    /**
     * @param array<int, array{before: array<string, mixed>, after: array<string, mixed>}> $updates
     */
    private function applyUpdates(array $updates): void
    {
        $sql = 'UPDATE produtos
                   SET codigo = :codigo,
                       ean = :ean,
                       descricao = :descricao,
                       categoria = :categoria,
                       marca = :marca,
                       ncm = :ncm,
                       unidade = :unidade,
                       valor = :valor,
                       valor_oferta = :valor_oferta,
                       preco_custo = :preco_custo,
                       margem_lucro = :margem_lucro,
                       valor_venda_calculado = :valor_venda_calculado,
                       estoque_qty = :estoque_qty,
                       estoque_min = :estoque_min,
                       ativo = :ativo,
                       controla_estoque = :controla_estoque,
                       updated_at = NOW()
                 WHERE id = :id
                 LIMIT 1';
        $updateStmt = $this->pdo->prepare($sql);
        $moveStmt = $this->pdo->prepare(
            "INSERT INTO estoque_movimentacoes
               (produto_id, tipo, qtd, saldo_ant, saldo_pos, descricao, usuario_id, criado_em, origem_tipo, origem_id)
             VALUES
               (:produto_id, :tipo, :qtd, :saldo_ant, :saldo_pos, :descricao, :usuario_id, NOW(), :origem_tipo, :origem_id)"
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($updates as $item) {
                $before = $item['before'];
                $after = $item['after'];
                $id = (int) $before['id'];

                $params = [':id' => $id];
                foreach ($after as $field => $value) {
                    $params[":{$field}"] = $value;
                }
                $updateStmt->execute($params);

                $oldQty = (float) $before['estoque_qty'];
                $newQty = (float) $after['estoque_qty'];
                if (abs($oldQty - $newQty) >= 0.0001) {
                    $moveStmt->execute([
                        ':produto_id' => $id,
                        ':tipo' => $newQty > $oldQty ? 'entrada' : 'saida',
                        ':qtd' => abs($newQty - $oldQty),
                        ':saldo_ant' => $oldQty,
                        ':saldo_pos' => $newQty,
                        ':descricao' => 'Atualizacao via estoque_atualizado.csv em 2026-05-29',
                        ':usuario_id' => $this->userId,
                        ':origem_tipo' => 'csv_estoque_atualizado',
                        ':origem_id' => basename($this->csvPath),
                    ]);
                    $this->stats['stock_movements']++;
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function fixText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $replacements = [
            'Ì' => 'ÇÃO',
            'o' => 'ção',
            'O' => 'ÇÃO',
            'CÌO' => 'ÇÃO',
            'cÌo' => 'ção',
            'PÌS' => 'PÇS',
            'ESPAÌADOR' => 'ESPAÇADOR',
            '' => 'Ç',
            '' => 'É',
            '' => 'é',
            '' => 'ã',
            '' => 'ç',
            '' => 'â',
            '' => 'í',
            'å' => 'Ã',
            'è' => 'Ó',
            'ï' => 'Ô',
            'î' => 'Ó',
            '¯' => 'Ø',
            '¡' => 'º',
            '¼' => 'º',
            '' => 'á',
            'æ' => 'Ê',
            'Ì' => 'Ã',
        ];

        $value = strtr($value, $replacements);
        $value = str_replace('MECÃNICO', 'MECÂNICO', $value);
        $value = str_replace('MECANICO', 'MECÂNICO', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function normalizeBrand(string $brand): string
    {
        $brand = trim($this->fixText($brand));
        $upper = strtoupper($brand);

        return match ($upper) {
            'STANELY' => 'STANLEY',
            'ELETROLUX' => 'ELECTROLUX',
            'THORQ3' => 'THORQ3',
            default => $brand,
        };
    }

    private function inferBrand(string $description): string
    {
        $brands = [
            'BLACK & DECKER',
            'IPC BRASIL',
            'MILWAUKEE',
            'SCHNEIDER',
            'ELECTROLUX',
            'SODRAMAR',
            'NAUTILUS',
            'HITACHI',
            'DEWALT',
            'MAKITA',
            'BOSCH',
            'VONDER',
            'STANLEY',
            'SYLLENT',
            'PHILIPS',
            'SKIL',
            'WEG',
        ];

        $upper = strtoupper($description);
        foreach ($brands as $brand) {
            $pattern = '/(?<![A-Z0-9])' . preg_quote($brand, '/') . '(?![A-Z0-9])/i';
            if (preg_match($pattern, $upper) === 1) {
                return $brand;
            }
        }

        return '';
    }

    private function inferCategory(string $description): string
    {
        $upper = strtoupper($description);
        $rules = [
            'SERVIÇO' => ['MAO DE OBRA', 'MÃO DE OBRA', 'CONSERTO', 'LIMPEZA DE CARBURADOR', 'AFIAÇÃO'],
            'LAVADORA ALTA PRESSÃO' => ['LAVADORA', 'ALTA PRESSÃO'],
            'BOMBA' => ['BOMBA', 'MOTOBOMBA', 'PRESSURIZADOR'],
            'PARAFUSADEIRA' => ['PARAFUSADEIRA'],
            'FURADEIRA' => ['FURADEIRA'],
            'MARTELETE' => ['MARTELETE', 'MARTELO', 'ROMPEDOR'],
            'ESMERILHADEIRA' => ['ESMERILHADEIRA', 'ESM.'],
            'LIXADEIRA' => ['LIXADEIRA'],
            'SERRA CIRCULAR' => ['SERRA CIRCULAR'],
            'SERRA TICO TICO' => ['SERRA TICO', 'TICO-TICO'],
            'SERRA ESQUADRIA' => ['SERRA DE ESQUADRIA', 'ESQUADRIA'],
            'SERRA MARMORE' => ['SERRA MÁRMORE', 'SERRA MARMORE'],
            'BATERIA' => ['BATERIA'],
            'CARVÃO/ESCOVA' => ['CARVÃO', 'CARVAO', 'ESCOVA'],
            'INDUZIDO' => ['INDUZIDO'],
            'INTERRUPTOR' => ['INTERRUPTOR'],
            'ROLAMENTO' => ['ROLAMENTO'],
            'MOLA' => ['MOLA'],
            'PARAFUSO' => ['PARAFUSO'],
            'ENGRENAGEM' => ['ENGRENAGEM', 'COROA', 'PINHAO', 'PINHÃO'],
            'VEDAÇÃO' => ['VEDADOR', 'RETENTOR', 'O-RING', 'ANEL ORING', 'VEDAÇÃO'],
            'MOTOR' => ['MOTOR'],
        ];

        foreach ($rules as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($upper, $needle)) {
                    return $category;
                }
            }
        }

        return 'DIVERSOS';
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $raw) === 1) {
            return (float) $raw;
        }

        if (preg_match('/^-?\d{1,3}(?:\.\d{3})*,\d+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
            return (float) $raw;
        }

        if (preg_match('/^-?\d+(?:,\d+)?$/', $raw) === 1) {
            return (float) str_replace(',', '.', $raw);
        }

        $raw = str_replace(',', '', $raw);
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    private function toBoolSimNao(mixed $value): int
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['SIM', 'S', '1', 'TRUE'], true) ? 1 : 0;
    }

    private function fieldChanged(mixed $before, mixed $after): bool
    {
        if (is_float($after) || is_int($after)) {
            return abs((float) $before - (float) $after) >= 0.0001;
        }

        return (string) $before !== (string) $after;
    }

    private function saveJson(string $path, mixed $payload): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Nao foi possivel criar {$dir}");
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $path;
    }
}

function buildPdo(): PDO
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

$options = getopt('', ['file:', 'apply', 'delete-csv', 'user-id::', 'help']);
if (isset($options['help'])) {
    echo "Uso: php scripts/apply_updated_stock_csv.php --file=database/migrations/estoque_atualizado.csv [--apply] [--delete-csv] [--user-id=1]\n";
    exit(0);
}

$file = (string) ($options['file'] ?? BASE_PATH . '/database/migrations/estoque_atualizado.csv');
if (!str_starts_with($file, '/')) {
    $file = BASE_PATH . '/' . ltrim($file, '/');
}

$applier = new UpdatedStockCsvApplier(
    buildPdo(),
    $file,
    isset($options['apply']),
    isset($options['delete-csv']),
    isset($options['user-id']) ? (int) $options['user-id'] : null
);

$report = $applier->run();

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
