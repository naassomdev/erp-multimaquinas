<?php
declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Regra de negócio do módulo Financeiro: fluxo de caixa, registro de pagamentos
 * e consultas de inadimplência. Nada aqui faz CRUD bruto — para isso há o
 * FinanceiroRepository.
 */
final class FinanceiroService
{
    public const TABELAS_VALIDAS = ['lancamentos_receber', 'lancamentos_pagar'];

    public function __construct(
        private readonly ?PDO $pdo = null
    ) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * Soma entradas (recebido) e saídas (pago) num período. Considera apenas
     * lançamentos com status = 'pago'.
     */
    public function fluxoCaixa(string $dataInicio, string $dataFim): array
    {
        $stRec = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(valor_pago), 0)
             FROM lancamentos_receber
             WHERE status = 'pago' AND data_pagamento BETWEEN ? AND ?"
        );
        $stRec->execute([$dataInicio, $dataFim]);
        $entradas = (float)$stRec->fetchColumn();

        $stPag = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(valor_pago), 0)
             FROM lancamentos_pagar
             WHERE status = 'pago' AND data_pagamento BETWEEN ? AND ?"
        );
        $stPag->execute([$dataInicio, $dataFim]);
        $saidas = (float)$stPag->fetchColumn();

        return [
            'entradas' => round($entradas, 2),
            'saidas'   => round($saidas, 2),
            'saldo'    => round($entradas - $saidas, 2),
            'periodo'  => [$dataInicio, $dataFim],
        ];
    }

    /**
     * Resumo do mês corrente: realizado (status pago) + previsto (em aberto).
     */
    public function resumoMes(?int $ano = null, ?int $mes = null): array
    {
        $ano = $ano ?? (int)date('Y');
        $mes = $mes ?? (int)date('n');
        $inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $fim    = date('Y-m-t', strtotime($inicio));

        $realizado = $this->fluxoCaixa($inicio, $fim);

        $stRecPrev = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(valor), 0)
             FROM lancamentos_receber
             WHERE status = 'aberto' AND vencimento BETWEEN ? AND ?"
        );
        $stRecPrev->execute([$inicio, $fim]);
        $previstoEntradas = (float)$stRecPrev->fetchColumn();

        $stPagPrev = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(valor), 0)
             FROM lancamentos_pagar
             WHERE status = 'aberto' AND vencimento BETWEEN ? AND ?"
        );
        $stPagPrev->execute([$inicio, $fim]);
        $previstoSaidas = (float)$stPagPrev->fetchColumn();

        return [
            'mes_referencia' => sprintf('%04d-%02d', $ano, $mes),
            'periodo'        => [$inicio, $fim],
            'realizado'      => $realizado,
            'previsto'       => [
                'entradas' => round($previstoEntradas, 2),
                'saidas'   => round($previstoSaidas, 2),
                'saldo'    => round($previstoEntradas - $previstoSaidas, 2),
            ],
        ];
    }

    /**
     * Registra o pagamento (parcial ou total) de um lançamento.
     * $valor pode diferir do valor original (desconto, juros, etc.).
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
        if ($valor <= 0) {
            throw new InvalidArgumentException('Valor pago deve ser maior que zero.');
        }

        $st = $this->pdo()->prepare(
            "UPDATE {$tabela}
             SET status = 'pago', valor_pago = ?, data_pagamento = ?
             WHERE id = ? AND status = 'aberto'
             LIMIT 1"
        );
        $st->execute([$valor, $dataPagamento, $id]);

        if ($st->rowCount() === 0) {
            throw new InvalidArgumentException(
                "Lançamento #{$id} não encontrado ou já foi quitado/cancelado."
            );
        }
    }

    /**
     * Lista contas vencidas há mais de $diasAtraso dias (receber + pagar).
     */
    public function contasVencidas(int $diasAtraso = 0): array
    {
        $st = $this->pdo()->prepare(
            "SELECT 'receber' AS tipo,
                    r.id, r.descricao, r.valor, r.vencimento,
                    DATEDIFF(CURDATE(), r.vencimento) AS dias_atraso,
                    NULL AS chave_nfe,
                    c.nome AS contraparte
             FROM lancamentos_receber r
             LEFT JOIN clientes c ON c.id = r.cliente_id
             WHERE r.status = 'aberto'
               AND r.vencimento < DATE_SUB(CURDATE(), INTERVAL ? DAY)

             UNION ALL

             SELECT 'pagar' AS tipo,
                    p.id, p.descricao, p.valor, p.vencimento,
                    DATEDIFF(CURDATE(), p.vencimento) AS dias_atraso,
                    p.chave_nfe,
                    f.nome AS contraparte
             FROM lancamentos_pagar p
             LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
             WHERE p.status = 'aberto'
               AND p.vencimento < DATE_SUB(CURDATE(), INTERVAL ? DAY)

             ORDER BY dias_atraso DESC
             LIMIT 200"
        );
        $st->execute([$diasAtraso, $diasAtraso]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Contas a vencer nos próximos N dias (receber + pagar).
     */
    public function contasAVencer(int $dias = 30): array
    {
        $st = $this->pdo()->prepare(
            "SELECT 'receber' AS tipo,
                    r.id, r.descricao, r.valor, r.vencimento,
                    c.nome AS contraparte
             FROM lancamentos_receber r
             LEFT JOIN clientes c ON c.id = r.cliente_id
             WHERE r.status = 'aberto'
               AND r.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)

             UNION ALL

             SELECT 'pagar' AS tipo,
                    p.id, p.descricao, p.valor, p.vencimento,
                    f.nome AS contraparte
             FROM lancamentos_pagar p
             LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
             WHERE p.status = 'aberto'
               AND p.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)

             ORDER BY vencimento ASC
             LIMIT 200"
        );
        $st->execute([$dias, $dias]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
