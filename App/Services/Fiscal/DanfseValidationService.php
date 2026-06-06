<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

final class DanfseValidationService
{
    public function canRender(array $nota): array
    {
        $temXmlAutorizado = !empty($nota['xml_autorizado_path']) || !empty($nota['xml_retorno']);

        return [
            'ok' => $temXmlAutorizado && ($nota['status'] ?? '') === 'autorizada',
            'message' => $temXmlAutorizado
                ? 'XML autorizado localizado.'
                : 'DANFSe final exige XML autorizado da NFS-e.',
        ];
    }
}
