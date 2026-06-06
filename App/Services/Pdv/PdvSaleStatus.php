<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use InvalidArgumentException;

final class PdvSaleStatus
{
    public const RASCUNHO = 'rascunho';
    public const PENDENTE_PAGAMENTO = 'pendente_pagamento';
    public const PARCIALMENTE_PAGO = 'parcialmente_pago';
    public const PAGO = 'pago';
    public const FINALIZADO = 'finalizado';
    public const CANCELADO = 'cancelado';
    public const ESTORNADO = 'estornado';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::RASCUNHO,
            self::PENDENTE_PAGAMENTO,
            self::PARCIALMENTE_PAGO,
            self::PAGO,
            self::FINALIZADO,
            self::CANCELADO,
            self::ESTORNADO,
        ];
    }

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::all(), true)) {
            throw new InvalidArgumentException("Status de venda PDV inválido: {$status}");
        }
    }
}
