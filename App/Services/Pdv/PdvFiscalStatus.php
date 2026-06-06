<?php
declare(strict_types=1);

namespace App\Services\Pdv;

use InvalidArgumentException;

final class PdvFiscalStatus
{
    public const NAO_APLICAVEL = 'nao_aplicavel';
    public const PENDENTE = 'pendente';
    public const PROCESSANDO = 'processando';
    public const AUTORIZADO = 'autorizado';
    public const REGISTRADO_MANUAL = 'registrado_manual';
    public const REJEITADO = 'rejeitado';
    public const CANCELADO = 'cancelado';
    public const DENEGADO = 'denegado';
    public const CONTINGENCIA = 'contingencia';
    public const ERRO = 'erro';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::NAO_APLICAVEL,
            self::PENDENTE,
            self::PROCESSANDO,
            self::AUTORIZADO,
            self::REGISTRADO_MANUAL,
            self::REJEITADO,
            self::CANCELADO,
            self::DENEGADO,
            self::CONTINGENCIA,
            self::ERRO,
        ];
    }

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::all(), true)) {
            throw new InvalidArgumentException("Status fiscal PDV inválido: {$status}");
        }
    }
}
