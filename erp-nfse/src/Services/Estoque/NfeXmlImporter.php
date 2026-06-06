<?php
declare(strict_types=1);
namespace App\Services\Estoque;

use DOMDocument;
use DOMXPath;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Importa uma NF-e de compra (XML SEFAZ) e alimenta estoque + contas a pagar.
 * Toda a operação acontece em uma única transação — se qualquer coisa falhar,
 * nada é persistido.
 */
final class NfeXmlImporter
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    public function __construct(private readonly PDO $pdo) {}

    public function importar(string $xmlPath): array
    {
        if (!is_readable($xmlPath)) {
            throw new RuntimeException("Arquivo XML inacessível: {$xmlPath}");
        }

        $dom = new DOMDocument();
        if (!$dom->load($xmlPath)) {
            throw new RuntimeException("XML inválido ou malformado: {$xmlPath}");
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('nfe', self::NS);

        // ── Dados do cabeçalho da NF ──────────────────────────────────────────
        $chave      = ltrim((string)$xp->evaluate('string(//nfe:infNFe/@Id)'), 'NFe');
        $cnpjEmit   = (string)$xp->evaluate('string(//nfe:emit/nfe:CNPJ)');
        $nomeEmit   = (string)$xp->evaluate('string(//nfe:emit/nfe:xNome)');
        $valorTotal = (float) $xp->evaluate('string(//nfe:ICMSTot/nfe:vNF)');
        $dtEmissao  = substr((string)$xp->evaluate('string(//nfe:ide/nfe:dhEmi)'), 0, 10);

        if ($chave === '') {
            throw new RuntimeException('Chave de acesso não encontrada no XML.');
        }

        $resultado = [
            'chave_nfe'  => $chave,
            'emitente'   => $nomeEmit,
            'cnpj'       => $cnpjEmit,
            'valor_total'=> $valorTotal,
            'inseridos'  => 0,
            'atualizados'=> 0,
            'itens'      => [],
        ];

        $this->pdo->beginTransaction();
        try {
            // Idempotência: não importa a mesma NF duas vezes
            $stDup = $this->pdo->prepare(
                'SELECT id FROM lancamentos_pagar WHERE chave_nfe = ? LIMIT 1'
            );
            $stDup->execute([$chave]);
            if ($stDup->fetchColumn()) {
                $this->pdo->rollBack();
                $resultado['aviso'] = 'NF-e já importada anteriormente.';
                return $resultado;
            }

            // ── Fornecedor (upsert) ───────────────────────────────────────────
            $fornecedorId = $this->upsertFornecedor(
                preg_replace('/\D/', '', $cnpjEmit) ?? '',
                $nomeEmit
            );

            // ── Itens / Produtos ──────────────────────────────────────────────
            $itens = $xp->query('//nfe:det');
            foreach ($itens as $det) {
                $xpDet = new DOMXPath($dom);
                $xpDet->registerNamespace('nfe', self::NS);

                $codigo    = (string)$xpDet->evaluate('string(nfe:prod/nfe:cProd)',   $det);
                $descricao = (string)$xpDet->evaluate('string(nfe:prod/nfe:xProd)',   $det);
                $ncm       = (string)$xpDet->evaluate('string(nfe:prod/nfe:NCM)',     $det);
                $ean       = (string)$xpDet->evaluate('string(nfe:prod/nfe:cEAN)',    $det);
                $qty       = (float) $xpDet->evaluate('string(nfe:prod/nfe:qCom)',    $det);
                $vlrUnit   = (float) $xpDet->evaluate('string(nfe:prod/nfe:vUnCom)',  $det);
                $unidade   = (string)$xpDet->evaluate('string(nfe:prod/nfe:uCom)',    $det);

                $this->upsertProduto(
                    $codigo, $descricao, $ncm,
                    ($ean !== '' && $ean !== 'SEM GTIN') ? $ean : null,
                    $qty, $vlrUnit, $unidade,
                    $resultado
                );
            }

            // ── Conta a pagar (vencimento D+30 padrão) ───────────────────────
            $vencimento = date('Y-m-d', strtotime($dtEmissao . ' +30 days'));
            $this->pdo->prepare(
                "INSERT INTO lancamentos_pagar
                 (fornecedor_id, chave_nfe, valor, vencimento, status, descricao, criado_em)
                 VALUES (?, ?, ?, ?, 'aberto', ?, NOW())"
            )->execute([
                $fornecedorId,
                $chave,
                $valorTotal,
                $vencimento,
                "NF-e {$chave} — {$nomeEmit}",
            ]);

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return $resultado;
    }

    private function upsertFornecedor(string $cnpj, string $nome): int
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM fornecedores WHERE cnpj = ? LIMIT 1'
        );
        $st->execute([$cnpj]);
        $id = $st->fetchColumn();

        if ($id !== false) {
            return (int)$id;
        }

        $this->pdo->prepare(
            'INSERT INTO fornecedores (cnpj, nome, criado_em) VALUES (?, ?, NOW())'
        )->execute([$cnpj, $nome]);

        return (int)$this->pdo->lastInsertId();
    }

    private function upsertProduto(
        string  $codigo,
        string  $descricao,
        string  $ncm,
        ?string $ean,
        float   $qty,
        float   $vlrUnit,
        string  $unidade,
        array   &$resultado
    ): void {
        $st = $this->pdo->prepare(
            'SELECT id FROM produtos WHERE codigo = ? LIMIT 1'
        );
        $st->execute([$codigo]);
        $prodId = $st->fetchColumn();

        if ($prodId !== false) {
            $this->pdo->prepare(
                'UPDATE produtos
                 SET estoque_qty = estoque_qty + ?, preco_custo = ?, ncm = ?, atualizado_em = NOW()
                 WHERE id = ?
                 LIMIT 1'
            )->execute([$qty, $vlrUnit, $ncm, (int)$prodId]);
            $resultado['atualizados']++;
        } else {
            $this->pdo->prepare(
                'INSERT INTO produtos
                 (codigo, descricao, ncm, ean, estoque_qty, preco_custo, unidade, ativo, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
            )->execute([$codigo, $descricao, $ncm, $ean, $qty, $vlrUnit, $unidade]);
            $resultado['inseridos']++;
        }

        $resultado['itens'][] = [
            'codigo'    => $codigo,
            'descricao' => $descricao,
            'qty'       => $qty,
            'vlr_unit'  => $vlrUnit,
        ];
    }
}
