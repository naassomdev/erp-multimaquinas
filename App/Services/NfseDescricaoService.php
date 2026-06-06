<?php
declare(strict_types=1);

namespace App\Services;

final class NfseDescricaoService
{
    /**
     * @param array<string,mixed> $dados
     */
    public function consolidada(array $dados): string
    {
        $linhas = [
            'Servico de assistencia tecnica, manutencao e reparo em equipamento.',
            '',
        ];

        $this->addLinha($linhas, 'OS', $dados['os_id'] ?? null);
        $this->addLinha($linhas, 'Equipamento', $dados['equip_nome'] ?? null);

        $marcaModelo = trim(implode(' / ', array_filter([
            trim((string)($dados['fabricante'] ?? '')),
            trim((string)($dados['modelo'] ?? '')),
        ], static fn (string $v): bool => $v !== '')));
        $this->addLinha($linhas, 'Marca/Modelo', $marcaModelo);
        $this->addLinha($linhas, 'Numero de serie', $dados['serie'] ?? null);

        $linhas[] = '';
        $linhas[] = 'Servicos executados: diagnostico tecnico, manutencao, reparo, substituicoes necessarias, testes e validacao de funcionamento.';

        if (isset($dados['valor_total']) && (float)$dados['valor_total'] > 0) {
            $linhas[] = '';
            $linhas[] = 'Valor total do servico: R$ ' . number_format((float)$dados['valor_total'], 2, ',', '.');
        }

        return trim(implode("\n", $linhas));
    }

    /**
     * @param list<string> $linhas
     */
    private function addLinha(array &$linhas, string $label, mixed $value): void
    {
        $value = trim((string)($value ?? ''));
        if ($value !== '') {
            $linhas[] = "{$label}: {$value}";
        }
    }
}
