<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Serviço de lógica de negócio para Estoque/Produtos.
 * Cálculos de preço: Custo + Margem = Preço de Venda.
 */
final class EstoqueService
{
    /**
     * Calcula o preço de venda com base no custo e margem.
     * Fórmula: venda = custo * (1 + margem/100)
     */
    public function calcularPrecoVenda(float $custo, float $margem): float
    {
        if ($custo <= 0) return 0.0;
        return round($custo * (1 + $margem / 100), 2);
    }

    /**
     * Calcula a margem com base no custo e preço de venda.
     * Fórmula: margem = ((venda - custo) / custo) * 100
     */
    public function calcularMargem(float $custo, float $venda): float
    {
        if ($custo <= 0) return 0.0;
        return round((($venda - $custo) / $custo) * 100, 2);
    }

    /**
     * Prepara os dados do produto recalculando preços.
     * Se preco_custo e margem_lucro estão preenchidos, calcula valor_venda_calculado.
     */
    public function recalcularPrecos(array &$dados): void
    {
        $custo  = (float) ($dados['preco_custo'] ?? 0);
        $margem = (float) ($dados['margem_lucro'] ?? 0);

        if ($custo > 0 && $margem > 0) {
            $dados['valor_venda_calculado'] = $this->calcularPrecoVenda($custo, $margem);
        }

        // Se valor de venda foi preenchido manualmente e custo existe, calcula margem
        $vendaCalc = (float) ($dados['valor_venda_calculado'] ?? 0);
        if ($custo > 0 && $vendaCalc > 0 && $margem <= 0) {
            $dados['margem_lucro'] = $this->calcularMargem($custo, $vendaCalc);
        }
    }
}
