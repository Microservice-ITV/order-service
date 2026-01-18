<?php

namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    public function __construct(
        string $message,
        private int $status = 422
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
