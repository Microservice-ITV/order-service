<?php

namespace App\Exceptions;

use Exception;

class OrderNotFoundException extends Exception
{
    public function __construct(string $orderId = '')
    {
        parent::__construct("Order '{$orderId}' not found.");
    }
}
