<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use App\Services\AuditoriaService;
use Throwable;

final class PdvAuditService
{
    public const EVENT_VENDA_CRIAR = 'PDV_VENDA_CRIAR';
    public const EVENT_VENDA_CANCELAR = 'PDV_VENDA_CANCELAR';
    public const EVENT_ITEM_ADICIONAR = 'PDV_ITEM_ADICIONAR';
    public const EVENT_ITEM_REMOVER = 'PDV_ITEM_REMOVER';
    public const EVENT_TOTAIS_ATUALIZAR = 'PDV_TOTAIS_ATUALIZAR';
    public const EVENT_PAGAMENTO_REGISTRAR = 'PDV_PAGAMENTO_REGISTRAR';
    public const EVENT_DOCUMENTO_PREPARAR = 'PDV_DOCUMENTO_PREPARAR';
    public const EVENT_DOCUMENTO_FISCAL_VINCULAR = 'PDV_DOCUMENTO_FISCAL_VINCULAR';
    public const EVENT_DOCUMENTO_FISCAL_CANCELAR_VINCULO = 'PDV_DOCUMENTO_FISCAL_CANCELAR_VINCULO';
    public const EVENT_VENDA_FINALIZAR = 'PDV_VENDA_FINALIZAR';
    public const EVENT_ESTOQUE_SAIDA = 'PDV_ESTOQUE_SAIDA';
    public const EVENT_ESTOQUE_ESTORNAR = 'PDV_ESTOQUE_ESTORNAR';
    public const EVENT_FINANCEIRO_CRIAR = 'PDV_FINANCEIRO_CRIAR';
    public const EVENT_FINANCEIRO_CANCELAR = 'PDV_FINANCEIRO_CANCELAR';
    public const EVENT_PAGAMENTO_CANCELAR = 'PDV_PAGAMENTO_CANCELAR';
    public const EVENT_VENDA_ESTORNAR = 'PDV_VENDA_ESTORNAR';
    public const EVENT_ERRO = 'PDV_ERRO';

    public function __construct(
        private readonly AuditoriaService $audit = new AuditoriaService(),
    ) {}

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarVendaCriada(int $vendaId, array $dados): void
    {
        $this->audit->registrar('vendas_balcao', (string)$vendaId, self::EVENT_VENDA_CRIAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarVendaCancelada(int $vendaId, array $dados): void
    {
        $this->audit->registrar('vendas_balcao', (string)$vendaId, self::EVENT_VENDA_CANCELAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarItemAdicionado(int $itemId, array $dados): void
    {
        $this->audit->registrar('vendas_itens', (string)$itemId, self::EVENT_ITEM_ADICIONAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarItemRemovido(int $itemId, array $dados): void
    {
        $this->audit->registrar('vendas_itens', (string)$itemId, self::EVENT_ITEM_REMOVER, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarTotaisAtualizados(int $vendaId, array $dados): void
    {
        $this->audit->registrar('vendas_balcao', (string)$vendaId, self::EVENT_TOTAIS_ATUALIZAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarPagamento(int $pagamentoId, array $dados): void
    {
        $this->audit->registrar('venda_pagamentos', (string)$pagamentoId, self::EVENT_PAGAMENTO_REGISTRAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarDocumento(int $documentoId, array $dados): void
    {
        $this->audit->registrar('venda_documentos', (string)$documentoId, self::EVENT_DOCUMENTO_PREPARAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarDocumentoFiscalVinculado(int $documentoId, array $dados): void
    {
        $this->audit->registrar('venda_documentos', (string)$documentoId, self::EVENT_DOCUMENTO_FISCAL_VINCULAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarDocumentoFiscalVinculoCancelado(int $documentoId, array $dados): void
    {
        $this->audit->registrar('venda_documentos', (string)$documentoId, self::EVENT_DOCUMENTO_FISCAL_CANCELAR_VINCULO, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarVendaFinalizada(int $vendaId, array $dados): void
    {
        $this->audit->registrar('vendas_balcao', (string)$vendaId, self::EVENT_VENDA_FINALIZAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarEstoqueSaida(int $movimentacaoId, array $dados): void
    {
        $this->audit->registrar('estoque_movimentacoes', (string)$movimentacaoId, self::EVENT_ESTOQUE_SAIDA, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarEstoqueEstornado(int $movimentacaoId, array $dados): void
    {
        $this->audit->registrar('estoque_movimentacoes', (string)$movimentacaoId, self::EVENT_ESTOQUE_ESTORNAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarFinanceiroCriado(int $lancamentoId, array $dados): void
    {
        $this->audit->registrar('lancamentos_receber', (string)$lancamentoId, self::EVENT_FINANCEIRO_CRIAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarFinanceiroCancelado(int $lancamentoId, array $dados): void
    {
        $this->audit->registrar('lancamentos_receber', (string)$lancamentoId, self::EVENT_FINANCEIRO_CANCELAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarPagamentoCancelado(int $pagamentoId, array $dados): void
    {
        $this->audit->registrar('venda_pagamentos', (string)$pagamentoId, self::EVENT_PAGAMENTO_CANCELAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function registrarVendaEstornada(int $vendaId, array $dados): void
    {
        $this->audit->registrar('vendas_balcao', (string)$vendaId, self::EVENT_VENDA_ESTORNAR, $this->sanitize($dados));
    }

    /**
     * @param array<string, mixed> $contexto
     */
    public function registrarErro(?int $vendaId, Throwable|string $erro, array $contexto = []): void
    {
        $payload = $this->sanitize($contexto);
        $payload['mensagem'] = $erro instanceof Throwable ? $erro->getMessage() : (string)$erro;

        $this->audit->registrar(
            'vendas_balcao',
            (string)($vendaId ?? 0),
            self::EVENT_ERRO,
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    private function sanitize(array $dados): array
    {
        $clean = [];
        foreach ($dados as $key => $value) {
            $clean[$key] = $this->sanitizeValue((string)$key, $value);
        }
        return $clean;
    }

    private function sanitizeValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            $nested = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $nested[(string)$nestedKey] = $this->sanitizeValue((string)$nestedKey, $nestedValue);
            }
            return $nested;
        }

        $normalizedKey = strtolower($key);
        if (in_array($normalizedKey, [
            'tipo_documento',
            'modelo',
            'status',
            'venda_id',
            'documento_id',
        ], true)) {
            return $value;
        }

        if (str_contains($normalizedKey, 'xml')) {
            return '[xml_omitido]';
        }
        if ($normalizedKey === 'payload_json' || $normalizedKey === 'payload') {
            return '[payload_omitido]';
        }
        if (
            str_contains($normalizedKey, 'referencia_externa') ||
            str_contains($normalizedKey, 'chave_acesso') ||
            str_contains($normalizedKey, 'protocolo') ||
            str_contains($normalizedKey, 'txid') ||
            str_contains($normalizedKey, 'nsu') ||
            str_contains($normalizedKey, 'linha_digitavel')
        ) {
            return $this->maskToken((string)$value, 4);
        }
        if (str_contains($normalizedKey, 'email')) {
            return $this->maskEmail((string)$value);
        }
        if (str_contains($normalizedKey, 'telefone') || str_contains($normalizedKey, 'celular')) {
            return $this->maskDigits((string)$value, 4);
        }
        if (
            str_contains($normalizedKey, 'cpf') ||
            str_contains($normalizedKey, 'cnpj') ||
            str_contains($normalizedKey, 'documento')
        ) {
            return $this->maskDigits((string)$value, 4);
        }

        return $value;
    }

    private function maskDigits(string $value, int $visibleTail = 4): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        $tail = substr($digits, -$visibleTail);
        return str_repeat('*', max(0, strlen($digits) - $visibleTail)) . $tail;
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, 1);
        return $visible . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
    }

    private function maskToken(string $value, int $visibleTail = 4): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $tail = substr($value, -$visibleTail);
        return str_repeat('*', max(0, strlen($value) - $visibleTail)) . $tail;
    }
}
