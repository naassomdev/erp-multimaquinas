<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use InvalidArgumentException;

final class PdvDocumentType
{
    public const RECIBO_NAO_FISCAL = 'recibo_nao_fiscal';
    public const NFE = 'nfe';
    public const NFCE = 'nfce';
    public const NFSE = 'nfse';
    public const CUPOM_FISCAL = 'cupom_fiscal';
    public const SAT = 'sat';
    public const MFE = 'mfe';
    public const ECF = 'ecf';

    public const CATEGORIA_COMERCIAL = 'comercial';
    public const CATEGORIA_FISCAL = 'fiscal';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::RECIBO_NAO_FISCAL,
            self::NFE,
            self::NFCE,
            self::NFSE,
            self::CUPOM_FISCAL,
            self::SAT,
            self::MFE,
            self::ECF,
        ];
    }

    public static function assertValid(string $type): void
    {
        if (!in_array($type, self::all(), true)) {
            throw new InvalidArgumentException("Tipo de documento PDV inválido: {$type}");
        }
    }

    public static function categoria(string $type): string
    {
        self::assertValid($type);

        return $type === self::RECIBO_NAO_FISCAL
            ? self::CATEGORIA_COMERCIAL
            : self::CATEGORIA_FISCAL;
    }
}
