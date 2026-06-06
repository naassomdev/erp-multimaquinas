<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class DanfseQrCodeService
{
    public function consultaPublicaPayload(array $xmlData): string
    {
        throw new RuntimeException('QR Code do DANFSe reservado para etapa futura.');
    }
}
