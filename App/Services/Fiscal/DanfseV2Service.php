<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class DanfseV2Service
{
    public function __construct(
        private readonly DanfseValidationService $validation = new DanfseValidationService(),
        private readonly DanfseXmlParser $parser = new DanfseXmlParser(),
        private readonly DanfseV2Renderer $renderer = new DanfseV2Renderer(),
    ) {}

    public function gerar(array $nota): string
    {
        $check = $this->validation->canRender($nota);
        if (empty($check['ok'])) {
            throw new RuntimeException((string)$check['message']);
        }

        throw new RuntimeException('DANFSe Nacional proprio sera implementado em etapa futura, a partir do XML autorizado.');
    }
}
