#!/usr/bin/env php
<?php
declare(strict_types=1);

use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Dotenv::createImmutable(BASE_PATH)->safeLoad();

final class LegacyStockWorkbook
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var array<int, string>|null */
    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path)
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new RuntimeException("Planilha inacessivel: {$this->path}");
        }
    }

    /** @return string[] */
    public function listSheets(): array
    {
        return array_keys($this->resolveSheetTargets());
    }

    /** @return array<int, array<string, mixed>> */
    public function readSheet(string $sheetName): array
    {
        $targets = $this->resolveSheetTargets();
        if (!isset($targets[$sheetName])) {
            throw new RuntimeException("Aba '{$sheetName}' nao encontrada. Disponiveis: " . implode(', ', array_keys($targets)));
        }

        $xml = $this->readZipEntry($targets[$sheetName]);
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new RuntimeException("Falha ao ler XML da aba '{$sheetName}'.");
        }

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', self::NS_MAIN);

        $rows = [];
        $headers = null;

        foreach ($xp->query('/s:worksheet/s:sheetData/s:row') as $rowNode) {
            $rowNumber = (int) ($rowNode->attributes?->getNamedItem('r')?->nodeValue ?? 0);
            $rowValues = [];

            foreach ($xp->query('s:c', $rowNode) as $cellNode) {
                $ref = (string) ($cellNode->attributes?->getNamedItem('r')?->nodeValue ?? '');
                $col = preg_replace('/\d+/', '', $ref) ?: '';
                $rowValues[$col] = $this->cellValue($cellNode);
            }

            if ($headers === null) {
                $headers = $this->buildHeaders($rowValues);
                continue;
            }

            $assoc = ['_row' => $rowNumber];
            foreach ($headers as $col => $headerName) {
                $assoc[$headerName] = $rowValues[$col] ?? '';
            }
            $rows[] = $assoc;
        }

        if ($headers === null) {
            throw new RuntimeException("Aba '{$sheetName}' vazia.");
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function resolveSheetTargets(): array
    {
        $workbook = new DOMDocument();
        if (!@$workbook->loadXML($this->readZipEntry('xl/workbook.xml'))) {
            throw new RuntimeException('Falha ao ler workbook.xml.');
        }

        $rels = new DOMDocument();
        if (!@$rels->loadXML($this->readZipEntry('xl/_rels/workbook.xml.rels'))) {
            throw new RuntimeException('Falha ao ler workbook.xml.rels.');
        }

        $xpWorkbook = new DOMXPath($workbook);
        $xpWorkbook->registerNamespace('s', self::NS_MAIN);
        $xpWorkbook->registerNamespace('r', self::NS_REL);

        $xpRels = new DOMXPath($rels);
        $xpRels->registerNamespace('r', self::NS_PKG);

        $relMap = [];
        foreach ($xpRels->query('/r:Relationships/r:Relationship') as $relNode) {
            $id = (string) ($relNode->attributes?->getNamedItem('Id')?->nodeValue ?? '');
            $target = (string) ($relNode->attributes?->getNamedItem('Target')?->nodeValue ?? '');
            if ($id !== '' && $target !== '') {
                $relMap[$id] = 'xl/' . ltrim($target, '/');
            }
        }

        $targets = [];
        foreach ($xpWorkbook->query('/s:workbook/s:sheets/s:sheet') as $sheetNode) {
            $name = (string) ($sheetNode->attributes?->getNamedItem('name')?->nodeValue ?? '');
            $relId = (string) ($sheetNode->attributes?->getNamedItemNS(self::NS_REL, 'id')?->nodeValue ?? '');
            if ($name !== '' && isset($relMap[$relId])) {
                $targets[$name] = $relMap[$relId];
            }
        }

        return $targets;
    }

    /** @param array<string, string> $rowValues
     *  @return array<string, string>
     */
    private function buildHeaders(array $rowValues): array
    {
        $headers = [];
        foreach ($rowValues as $col => $value) {
            $header = trim((string) $value);
            if ($header === '') {
                continue;
            }
            $headers[$col] = $header;
        }
        return $headers;
    }

    private function cellValue(DOMNode $cellNode): string
    {
        $type = (string) ($cellNode->attributes?->getNamedItem('t')?->nodeValue ?? '');
        $valueNode = null;
        foreach ($cellNode->childNodes as $child) {
            if ($child->nodeName === 'v') {
                $valueNode = $child;
                break;
            }
        }

        if ($type === 'inlineStr') {
            return trim($cellNode->textContent);
        }

        $raw = $valueNode?->textContent ?? '';
        if ($type === 's') {
            $shared = $this->loadSharedStrings();
            $idx = (int) $raw;
            return $shared[$idx] ?? '';
        }

        return trim((string) $raw);
    }

    /** @return array<int, string> */
    private function loadSharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $entries = $this->listZipEntries();
        if (!in_array('xl/sharedStrings.xml', $entries, true)) {
            $this->sharedStrings = [];
            return $this->sharedStrings;
        }

        $doc = new DOMDocument();
        if (!@$doc->loadXML($this->readZipEntry('xl/sharedStrings.xml'))) {
            throw new RuntimeException('Falha ao ler sharedStrings.xml.');
        }

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', self::NS_MAIN);

        $strings = [];
        foreach ($xp->query('/s:sst/s:si') as $siNode) {
            $text = '';
            foreach ($xp->query('.//s:t', $siNode) as $textNode) {
                $text .= $textNode->textContent;
            }
            $strings[] = $text;
        }

        $this->sharedStrings = $strings;
        return $strings;
    }

    /** @return string[] */
    private function listZipEntries(): array
    {
        $cmd = sprintf('unzip -Z1 %s 2>/dev/null', escapeshellarg($this->path));
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new RuntimeException('Falha ao listar conteudo da planilha via unzip.');
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    private function readZipEntry(string $entry): string
    {
        $cmd = sprintf('unzip -p %s %s 2>/dev/null', escapeshellarg($this->path), escapeshellarg($entry));
        $output = shell_exec($cmd);
        if ($output === null || $output === '') {
            throw new RuntimeException("Falha ao extrair '{$entry}' da planilha.");
        }

        return $output;
    }
}

final class LegacyStockImporter
{
    private const HEADER_MAP = [
        'referencia'         => 'codigo',
        'codigo'             => 'codigo',
        'ref'                => 'codigo',
        'nomeproduto'        => 'descricao',
        'descricao'          => 'descricao',
        'quantidadeestoque'  => 'estoque_qty',
        'quantidade'         => 'estoque_qty',
        'estoque'            => 'estoque_qty',
        'valorcusto'         => 'preco_custo',
        'custo'              => 'preco_custo',
        'ativo'              => 'ativo',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @param array<int, array<string, mixed>> $sheetRows */
    public function analyze(array $sheetRows, bool $updateCost): array
    {
        $rows = $this->normalizeRows($sheetRows);
        $dbIndex = $this->loadProductsByCode();
        $dbProducts = $dbIndex['products'];
        $dbDuplicates = $dbIndex['duplicates'];

        $matched = [];
        $unmatched = [];
        $duplicatesInSheet = [];
        $changes = [];
        $unchanged = 0;

        foreach ($rows as $row) {
            $codigo = $row['codigo'];

            if (isset($duplicatesInSheet[$codigo])) {
                $duplicatesInSheet[$codigo]['rows'][] = $row['_row'];
                continue;
            }

            if (($row['_duplicate'] ?? false) === true) {
                $duplicatesInSheet[$codigo] = [
                    'codigo' => $codigo,
                    'rows' => [$row['_first_row'], $row['_row']],
                ];
                continue;
            }

            if (!isset($dbProducts[$codigo])) {
                $unmatched[] = [
                    'row' => $row['_row'],
                    'codigo' => $codigo,
                    'descricao' => $row['descricao'],
                    'estoque_qty_planilha' => $row['estoque_qty'],
                    'preco_custo_planilha' => $row['preco_custo'],
                ];
                continue;
            }

            $produto = $dbProducts[$codigo];
            $targetQty = $row['estoque_qty'];
            $targetCost = $row['preco_custo'];
            $currentQty = (float) $produto['estoque_qty'];
            $currentCost = (float) $produto['preco_custo'];
            $qtyDiff = round($targetQty - $currentQty, 6);
            $costChanged = $updateCost && !$this->floatEquals($targetCost, $currentCost);

            $matched[] = $codigo;

            if ($this->floatEquals($targetQty, $currentQty) && !$costChanged) {
                $unchanged++;
                continue;
            }

            $changes[] = [
                'row' => $row['_row'],
                'produto_id' => (int) $produto['id'],
                'codigo' => $codigo,
                'descricao_planilha' => $row['descricao'],
                'descricao_banco' => (string) $produto['descricao'],
                'estoque_qty_atual' => $currentQty,
                'estoque_qty_novo' => $targetQty,
                'estoque_delta' => $qtyDiff,
                'preco_custo_atual' => $currentCost,
                'preco_custo_novo' => $targetCost,
                'ativo_banco' => (int) $produto['ativo'],
                'ativo_planilha' => $row['ativo'],
                'alterar_custo' => $costChanged,
            ];
        }

        return [
            'summary' => [
                'sheet_rows' => count($rows),
                'db_products' => count($dbProducts),
                'db_duplicate_codes' => count($dbDuplicates),
                'matched_codes' => count($matched),
                'unmatched_codes' => count($unmatched),
                'sheet_duplicates' => count($duplicatesInSheet),
                'changes' => count($changes),
                'unchanged' => $unchanged,
                'match_rate' => count($rows) > 0 ? round(count($matched) / count($rows), 4) : 0.0,
                'update_cost' => $updateCost,
            ],
            'changes' => $changes,
            'unmatched' => $unmatched,
            'sheet_duplicates' => array_values($duplicatesInSheet),
            'db_duplicates' => $dbDuplicates,
        ];
    }

    /** @param array<string, mixed> $analysis */
    public function apply(array $analysis, bool $updateCost, ?int $userId, bool $allowUnmatched): array
    {
        $summary = $analysis['summary'] ?? [];
        $unmatched = $analysis['unmatched'] ?? [];
        $sheetDuplicates = $analysis['sheet_duplicates'] ?? [];
        $dbDuplicates = $analysis['db_duplicates'] ?? [];
        $changes = $analysis['changes'] ?? [];

        if (!empty($sheetDuplicates)) {
            throw new RuntimeException('Existem referencias duplicadas na planilha. Corrija antes de aplicar.');
        }
        if (!empty($dbDuplicates)) {
            throw new RuntimeException('Existem codigos duplicados no banco. Corrija antes de aplicar.');
        }
        if (!$allowUnmatched && !empty($unmatched)) {
            throw new RuntimeException('Existem referencias da planilha sem correspondencia exata no banco. Revise o dry-run antes de aplicar.');
        }

        $applied = 0;
        $movementCount = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($changes as $change) {
                $produtoId = (int) $change['produto_id'];
                $currentQty = (float) $change['estoque_qty_atual'];
                $newQty = (float) $change['estoque_qty_novo'];
                $currentCost = (float) $change['preco_custo_atual'];
                $newCost = (float) $change['preco_custo_novo'];

                $sql = 'UPDATE produtos SET estoque_qty = :estoque_qty';
                $params = [
                    ':estoque_qty' => $newQty,
                    ':id' => $produtoId,
                ];

                if ($updateCost && !$this->floatEquals($currentCost, $newCost)) {
                    $sql .= ', preco_custo = :preco_custo';
                    $params[':preco_custo'] = $newCost;
                }

                $sql .= ' WHERE id = :id LIMIT 1';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);

                if (!$this->floatEquals($currentQty, $newQty)) {
                    $delta = abs($newQty - $currentQty);
                    $tipo = $newQty > $currentQty ? 'entrada' : 'saida';
                    $mov = $this->pdo->prepare(
                        "INSERT INTO estoque_movimentacoes
                           (produto_id, tipo, qtd, saldo_ant, saldo_pos, descricao, usuario_id, criado_em)
                         VALUES (:produto_id, :tipo, :qtd, :saldo_ant, :saldo_pos, :descricao, :usuario_id, NOW())"
                    );
                    $mov->execute([
                        ':produto_id' => $produtoId,
                        ':tipo' => $tipo,
                        ':qtd' => $delta,
                        ':saldo_ant' => $currentQty,
                        ':saldo_pos' => $newQty,
                        ':descricao' => 'Ajuste de estoque por migracao de planilha legada',
                        ':usuario_id' => $userId,
                    ]);
                    $movementCount++;
                }

                $applied++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $summary['applied'] = $applied;
        $summary['movements_created'] = $movementCount;
        return $summary;
    }

    /** @param array<int, array<string, mixed>> $sheetRows
     *  @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $sheetRows): array
    {
        $rows = [];
        $seenCodes = [];

        foreach ($sheetRows as $row) {
            $mapped = [
                '_row' => (int) ($row['_row'] ?? 0),
                'codigo' => '',
                'descricao' => '',
                'estoque_qty' => 0.0,
                'preco_custo' => 0.0,
                'ativo' => null,
            ];

            foreach ($row as $key => $value) {
                if ($key === '_row') {
                    continue;
                }
                $normalized = $this->normalizeHeader((string) $key);
                if (!isset(self::HEADER_MAP[$normalized])) {
                    continue;
                }
                $field = self::HEADER_MAP[$normalized];
                $mapped[$field] = match ($field) {
                    'codigo', 'descricao' => trim((string) $value),
                    'estoque_qty', 'preco_custo' => $this->toFloat($value),
                    'ativo' => $value === '' ? null : (int) $this->toFloat($value),
                    default => $value,
                };
            }

            if ($mapped['codigo'] === '') {
                continue;
            }

            if (isset($seenCodes[$mapped['codigo']])) {
                $mapped['_duplicate'] = true;
                $mapped['_first_row'] = $seenCodes[$mapped['codigo']];
            } else {
                $seenCodes[$mapped['codigo']] = $mapped['_row'];
            }

            $rows[] = $mapped;
        }

        return $rows;
    }

    /** @return array{products: array<string, array<string, mixed>>, duplicates: array<int, array<string, mixed>>} */
    private function loadProductsByCode(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, codigo, descricao, estoque_qty, preco_custo, ativo
               FROM produtos
              WHERE codigo IS NOT NULL AND codigo <> ""'
        );

        $products = [];
        $duplicates = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $codigo = trim((string) ($row['codigo'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            if (isset($products[$codigo])) {
                if (!isset($duplicates[$codigo])) {
                    $duplicates[$codigo] = [[
                        'id' => (int) $products[$codigo]['id'],
                        'descricao' => (string) $products[$codigo]['descricao'],
                    ]];
                }
                $duplicates[$codigo][] = [
                    'id' => (int) $row['id'],
                    'descricao' => (string) $row['descricao'],
                ];
                continue;
            }
            $products[$codigo] = $row;
        }

        return [
            'products' => $products,
            'duplicates' => array_map(
                static fn(string $codigo, array $rows): array => [
                    'codigo' => $codigo,
                    'rows' => $rows,
                ],
                array_keys($duplicates),
                array_values($duplicates)
            ),
        ];
    }

    private function normalizeHeader(string $header): string
    {
        $header = trim($header);
        $header = preg_replace('/[^a-zA-Z0-9]+/', '', $header) ?? '';
        return strtolower($header);
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
            $raw = str_replace(',', '.', $raw);
            return (float) $raw;
        }

        $raw = str_replace(',', '', $raw);
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    private function floatEquals(float $a, float $b, float $epsilon = 0.0001): bool
    {
        return abs($a - $b) < $epsilon;
    }
}

function usage(): void
{
    $script = basename(__FILE__);
    echo <<<TXT
Uso:
  php scripts/{$script} --file=/caminho/arquivo.xlsx [--sheet=MASTER] [--update-cost] [--apply] [--allow-unmatched] [--user-id=1]

Padrao:
  - executa em dry-run;
  - sincroniza apenas estoque_qty;
  - so aplica no banco quando --apply for informado;
  - aborta a aplicacao se houver codigos duplicados no banco ou na planilha;
  - aborta se houver referencias sem match exato, a menos que voce use --allow-unmatched.

TXT;
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

function saveReport(array $report): string
{
    $dir = BASE_PATH . '/storage/logs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Nao foi possivel criar {$dir}");
    }

    $path = $dir . '/legacy_stock_import_' . date('Ymd_His') . '.json';
    file_put_contents(
        $path,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return $path;
}

$options = getopt('', [
    'file:',
    'sheet::',
    'update-cost',
    'apply',
    'allow-unmatched',
    'user-id::',
    'help',
]);

if (isset($options['help']) || !isset($options['file'])) {
    usage();
    exit(isset($options['help']) ? 0 : 1);
}

$file = (string) $options['file'];
$sheet = isset($options['sheet']) && $options['sheet'] !== false ? (string) $options['sheet'] : 'MASTER';
$updateCost = array_key_exists('update-cost', $options);
$apply = array_key_exists('apply', $options);
$allowUnmatched = array_key_exists('allow-unmatched', $options);
$userId = isset($options['user-id']) && $options['user-id'] !== false ? (int) $options['user-id'] : null;

try {
    $workbook = new LegacyStockWorkbook($file);
    $rows = $workbook->readSheet($sheet);
    $pdo = buildPdo();
    $importer = new LegacyStockImporter($pdo);
    $analysis = $importer->analyze($rows, $updateCost);

    $report = [
        'file' => $file,
        'sheet' => $sheet,
        'mode' => $apply ? 'apply' : 'dry-run',
        'generated_at' => date(DATE_ATOM),
        'summary' => $analysis['summary'],
        'sheet_duplicates' => array_slice($analysis['sheet_duplicates'], 0, 100),
        'db_duplicates' => array_slice($analysis['db_duplicates'], 0, 100),
        'unmatched_sample' => array_slice($analysis['unmatched'], 0, 100),
        'changes_sample' => array_slice($analysis['changes'], 0, 100),
    ];

    if ($apply) {
        $report['apply_summary'] = $importer->apply($analysis, $updateCost, $userId, $allowUnmatched);
    }

    $reportPath = saveReport($report);

    echo json_encode([
        'ok' => true,
        'report' => $reportPath,
        'summary' => $report['summary'],
        'mode' => $report['mode'],
        'apply_summary' => $report['apply_summary'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, '[legacy-stock-import] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
