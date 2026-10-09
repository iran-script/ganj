<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [], public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
