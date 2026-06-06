<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class DanfseV2Renderer
{
    public function renderPdf(array $xmlData): string
    {
        throw new RuntimeException('Renderização DANFSe V2 reservada para etapa futura.');
    }
}
