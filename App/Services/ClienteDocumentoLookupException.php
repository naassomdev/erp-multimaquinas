<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ClienteDocumentoLookupException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 502)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
