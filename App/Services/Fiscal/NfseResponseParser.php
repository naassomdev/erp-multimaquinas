<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

final class NfseResponseParser
{
    public function parse(array|string $response): array
    {
        return [
            'status' => 'nao_processado',
            'message' => 'Parser de retorno oficial reservado para etapa futura.',
        ];
    }
}
