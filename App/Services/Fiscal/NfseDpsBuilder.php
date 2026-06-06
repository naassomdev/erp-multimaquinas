<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class NfseDpsBuilder
{
    public function buildDraftXml(array $dadosConferidos): string
    {
        throw new RuntimeException('Geração de DPS/XML oficial ainda não habilitada nesta etapa.');
    }
}
