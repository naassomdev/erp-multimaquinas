<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

final class NfseXmlValidator
{
    public function validateDraft(string $xml): array
    {
        return [
            'ok' => false,
            'errors' => ['Validação XML oficial ainda não habilitada nesta etapa.'],
        ];
    }
}
