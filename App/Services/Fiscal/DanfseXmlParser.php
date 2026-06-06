<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class DanfseXmlParser
{
    public function parseAuthorizedXml(string $xml): array
    {
        throw new RuntimeException('DANFSe deve ser gerado apenas a partir de XML autorizado em etapa futura.');
    }
}
