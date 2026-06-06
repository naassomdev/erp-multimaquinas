<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Pdv\PdvDocumentType;
use PDO;

final class VendaDocumentoRepository
{
    public function __construct(private readonly ?PDO $pdo = null) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criar(array $dados): int
    {
        $tipo = trim((string)($dados['tipo_documento'] ?? ''));
        PdvDocumentType::assertValid($tipo);
        $categoria = trim((string)($dados['categoria'] ?? PdvDocumentType::categoria($tipo)));
        $payload = $dados['payload_json'] ?? null;

        $stmt = $this->pdo()->prepare(
            "INSERT INTO venda_documentos (
                venda_id, os_id, orcamento_id, categoria, tipo_documento, modelo,
                status, numero, serie, chave_acesso, protocolo, valor, link_consulta,
                emitido_externamente, observacoes, xml_path, pdf_path, payload_json,
                mensagem_retorno, data_emissao, data_cancelamento, created_by, updated_by
            ) VALUES (
                :venda_id, :os_id, :orcamento_id, :categoria, :tipo_documento, :modelo,
                :status, :numero, :serie, :chave_acesso, :protocolo, :valor, :link_consulta,
                :emitido_externamente, :observacoes, :xml_path, :pdf_path, :payload_json,
                :mensagem_retorno, :data_emissao, :data_cancelamento, :created_by, :updated_by
            )"
        );

        $stmt->execute([
            ':venda_id' => (int)($dados['venda_id'] ?? 0),
            ':os_id' => $this->nullableString($dados['os_id'] ?? null),
            ':orcamento_id' => $this->nullableInt($dados['orcamento_id'] ?? null),
            ':categoria' => $categoria,
            ':tipo_documento' => $tipo,
            ':modelo' => $this->nullableString($dados['modelo'] ?? null),
            ':status' => trim((string)($dados['status'] ?? 'pendente')),
            ':numero' => $this->nullableString($dados['numero'] ?? null),
            ':serie' => $this->nullableString($dados['serie'] ?? null),
            ':chave_acesso' => $this->nullableString($dados['chave_acesso'] ?? null),
            ':protocolo' => $this->nullableString($dados['protocolo'] ?? null),
            ':valor' => $this->nullableDecimal($dados['valor'] ?? null),
            ':link_consulta' => $this->nullableString($dados['link_consulta'] ?? null),
            ':emitido_externamente' => isset($dados['emitido_externamente']) ? (int)(bool)$dados['emitido_externamente'] : 1,
            ':observacoes' => $this->nullableString($dados['observacoes'] ?? null),
            ':xml_path' => $this->nullableString($dados['xml_path'] ?? null),
            ':pdf_path' => $this->nullableString($dados['pdf_path'] ?? null),
            ':payload_json' => is_array($payload)
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $payload,
            ':mensagem_retorno' => $this->nullableString($dados['mensagem_retorno'] ?? null),
            ':data_emissao' => $this->nullableString($dados['data_emissao'] ?? null),
            ':data_cancelamento' => $this->nullableString($dados['data_cancelamento'] ?? null),
            ':created_by' => $this->nullableInt($dados['created_by'] ?? null),
            ':updated_by' => $this->nullableInt($dados['updated_by'] ?? null),
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarReciboNaoFiscal(int $vendaId, array $dados = []): int
    {
        $dados['venda_id'] = $vendaId;
        $dados['categoria'] = PdvDocumentType::CATEGORIA_COMERCIAL;
        $dados['tipo_documento'] = PdvDocumentType::RECIBO_NAO_FISCAL;
        $dados['status'] = $dados['status'] ?? 'preparado';

        return $this->criar($dados);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarPorVenda(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM venda_documentos WHERE venda_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$vendaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM venda_documentos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarFiscalPorChave(string $chaveAcesso): ?array
    {
        $chave = trim($chaveAcesso);
        if ($chave === '') {
            return null;
        }

        $stmt = $this->pdo()->prepare(
            "SELECT *
               FROM venda_documentos
              WHERE categoria = :categoria
                AND chave_acesso = :chave_acesso
              LIMIT 1"
        );
        $stmt->execute([
            ':categoria' => PdvDocumentType::CATEGORIA_FISCAL,
            ':chave_acesso' => $chave,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarFiscaisPorVenda(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT *
               FROM venda_documentos
              WHERE venda_id = ?
                AND categoria = ?
              ORDER BY id ASC"
        );
        $stmt->execute([$vendaId, PdvDocumentType::CATEGORIA_FISCAL]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contarPorVenda(int $vendaId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM venda_documentos WHERE venda_id = ?'
        );
        $stmt->execute([$vendaId]);
        return (int)$stmt->fetchColumn();
    }

    public function contarFiscaisAtivosPorVenda(int $vendaId): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*)
               FROM venda_documentos
              WHERE venda_id = :venda_id
                AND categoria = :categoria
                AND status NOT IN ('cancelado', 'inativo', 'removido')"
        );
        $stmt->execute([
            ':venda_id' => $vendaId,
            ':categoria' => PdvDocumentType::CATEGORIA_FISCAL,
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function buscarFiscalPorVendaDocumento(int $vendaId, int $documentoId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT *
               FROM venda_documentos
              WHERE id = :id
                AND venda_id = :venda_id
                AND categoria = :categoria
              LIMIT 1"
        );
        $stmt->execute([
            ':id' => $documentoId,
            ':venda_id' => $vendaId,
            ':categoria' => PdvDocumentType::CATEGORIA_FISCAL,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $extras
     */
    public function alterarStatus(int $id, string $status, array $extras = []): void
    {
        $sets = ['status = :status'];
        $params = [
            ':id' => $id,
            ':status' => $status,
        ];

        $map = [
            'numero' => 'numero',
            'serie' => 'serie',
            'modelo' => 'modelo',
            'chave_acesso' => 'chave_acesso',
            'protocolo' => 'protocolo',
            'valor' => 'valor',
            'link_consulta' => 'link_consulta',
            'os_id' => 'os_id',
            'orcamento_id' => 'orcamento_id',
            'emitido_externamente' => 'emitido_externamente',
            'observacoes' => 'observacoes',
            'xml_path' => 'xml_path',
            'pdf_path' => 'pdf_path',
            'mensagem_retorno' => 'mensagem_retorno',
            'data_emissao' => 'data_emissao',
            'data_cancelamento' => 'data_cancelamento',
            'updated_by' => 'updated_by',
        ];

        foreach ($map as $input => $column) {
            if (!array_key_exists($input, $extras)) {
                continue;
            }
            $sets[] = "{$column} = :{$input}";
            $params[":{$input}"] = $extras[$input];
        }

        if (array_key_exists('payload_json', $extras)) {
            $sets[] = 'payload_json = :payload_json';
            $params[':payload_json'] = is_array($extras['payload_json'])
                ? json_encode($extras['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $extras['payload_json'];
        }

        $sql = 'UPDATE venda_documentos SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return number_format((float)$value, 2, '.', '');
    }
}
