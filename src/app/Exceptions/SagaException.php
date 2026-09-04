<?php

namespace App\Exceptions;

use Exception;

/**
 * Saga Exception
 * 
 * Custom exception for saga-related errors.
 */
class SagaException extends Exception
{
    protected string $sagaId;
    protected string $stepName;
    protected array $context;

    public function __construct(
        string $message,
        string $sagaId = '',
        string $stepName = '',
        array $context = [],
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->sagaId = $sagaId;
        $this->stepName = $stepName;
        $this->context = $context;
    }

    public function getSagaId(): string
    {
        return $this->sagaId;
    }

    public function getStepName(): string
    {
        return $this->stepName;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }
}