<?php

namespace App\Exceptions;

use RuntimeException;

class PayOSReconciliationException extends RuntimeException
{
    public function __construct(string $message, int $status = 502, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->getCode() ?: 502;
    }
}
