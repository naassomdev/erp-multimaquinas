<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class NfseTransmissionService
{
    public function transmit(array $payload): array
    {
        throw new RuntimeException('Transmissão real da NFS-e bloqueada nesta etapa.');
    }
}
