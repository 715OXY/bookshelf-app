<?php

namespace App\Exceptions;

use RuntimeException;

class IsbnLookupException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
