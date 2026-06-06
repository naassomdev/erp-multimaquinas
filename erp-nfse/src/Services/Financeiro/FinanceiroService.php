<?php
declare(strict_types=1);
namespace App\Services\Financeiro;

use InvalidArgumentException;
use PDO;
use Throwable;

final class FinanceiroService
{
    private const TABELAS_VALIDAS = ['lancamentos_receber', 'lancamentos_pagar'];

    public function __construct(private readonly PDO $pdo) {}

    // ── Fluxo de caixa ────────────────────────────────────────────────────────

    /**
     * Retorna entradas, saídas e saldo de um período.
     * Considera apenas lançamentos com status = 'pago'.
     */
    public function fluxoCaixa(string $dataInicio, string $dataFim): array
    {
        $stRec = $this->pdo->prepare(
            "SELECT COALESCE(SUM(valor_pago), 0)
             FROM lancamentos_receber
             WHERE status = 'pago' AND data_pagamento BETWEEN ? AND ?
             LIMIT 1"
        );
        $stRec->execute([$dataInicio, $dataFim]);
        $entradas = (float)$stRec->fetchColumn();

        $stPag = $this->pdo->prepare(
            "SELECT COALESCE(SUM(valor_pago), 0)
             FROM lancamentos_pagar
             WHERE status = 'pago' AND data_pagamento BETWEEN ? AND ?
             LIMIT 1"
        );
        $stPag->execute([$dataInicio, $dataFim]);
        $saidas = (float)$stPag->fetchColumn();

        return [
            'entradas' => round($entradas, 2),
            'saidas'   => round($saidas,   2),
            'saldo'    => round($entradas - $saidas, 2),
            'periodo'  => [$dataInicio, $dataFim],
        ];
    }

    // ── Pagamento ─────────────────────────────────────────────────────────────

    /**
     * Registra o pagamento de um lançamento (receber ou pagar).
     * $valor pode diferir do valor original (ex: desconto ou juros).
     */
    public function registrarPagamento(
        string $tabela,
        int    $id,
        float  $valor,
        string $dataPagamento
    ): void {
        if (!in_array($tabela, self::TABELAS_VALIDAS, true)) {
            throw new InvalidArgumentException(
                "Tabela inválida: {$tabela}. Use: " . implode(' | ', self::TABELAS_VALIDAS)
            );
        }

        $this->pdo->prepare(
            "UPDATE {$tabela}
             SET status = 'pago', valor_pago = ?, data_pagamento = ?
             WHERE id = ? AND status = 'aberto'
             LIMIT 1"
        )->execute([$valor, $dataPagamento, $id]);
    }

    // ── Inadimplência ─────────────────────────────────────────────────────────

    /**
     * Lista contas vencidas há mais de $diasAtraso dias (receber + pagar).
     */
    public function contasVencidas(int $diasAtraso = 0): array
    {
        $st = $this->pdo->prepare(
            "SELECT 'receber'                         AS tipo,
                    id, descricao, valor, vencimento,
                    DATEDIFF(CURDATE(), vencimento)   AS dias_atraso,
                    NULL                              AS chave_nfe
             FROM lancamentos_receber
             WHERE status = 'aberto'
               AND vencimento < DATE_SUB(CURDATE(), INTERVAL ? DAY)

             UNION ALL

             SELECT 'pagar'                           AS tipo,
                    id, descricao, valor, vencimento,
                    DATEDIFF(CURDATE(), vencimento)   AS dias_atraso,
                    chave_nfe
             FROM lancamentos_pagar
             WHERE status = 'aberto'
               AND vencimento < DATE_SUB(CURDATE(), INTERVAL ? DAY)

             ORDER BY dias_atraso DESC
             LIMIT 200"
        );
        $st->execute([$diasAtraso, $diasAtraso]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Contas a vencer (próximos 30 dias) ───────────────────────────────────

    public function contasAVencer(int $dias = 30): array
    {
        $st = $this->pdo->prepare(
            "SELECT 'receber' AS tipo, id, descricao, valor, vencimento
             FROM lancamentos_receber
             WHERE status = 'aberto'
               AND vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)

             UNION ALL

             SELECT 'pagar' AS tipo, id, descricao, valor, vencimento
             FROM lancamentos_pagar
             WHERE status = 'aberto'
               AND vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)

             ORDER BY vencimento ASC
             LIMIT 200"
        );
        $st->execute([$dias, $dias]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
