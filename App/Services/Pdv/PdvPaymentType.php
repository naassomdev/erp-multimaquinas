<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use InvalidArgumentException;

final class PdvPaymentType
{
    public const DINHEIRO = 'dinheiro';
    public const CARTAO = 'cartao';
    public const PIX = 'pix';
    public const BOLETO = 'boleto';
    public const FATURADO = 'faturado';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::DINHEIRO,
            self::CARTAO,
            self::PIX,
            self::BOLETO,
            self::FATURADO,
        ];
    }

    public static function assertValid(string $type): void
    {
        if (!in_array($type, self::all(), true)) {
            throw new InvalidArgumentException("Forma de pagamento PDV inválida: {$type}");
        }
    }
}
