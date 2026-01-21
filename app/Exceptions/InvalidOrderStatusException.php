<?php

namespace App\Exceptions;

use Exception;

class InvalidOrderStatusException extends Exception
{
    public function __construct(string $currentStatus = '', string $attemptedAction = '')
    {
        parent::__construct("Cannot {$attemptedAction} order with status '{$currentStatus}'.");
    }
}
