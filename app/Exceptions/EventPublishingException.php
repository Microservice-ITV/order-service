<?php

namespace App\Exceptions;

use Exception;

class EventPublishingException extends Exception
{
    public function __construct(string $message = 'Failed to publish event', ?Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
