<?php

namespace App\Exceptions;

use RuntimeException;

class RenderFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly bool $retryable,
        public readonly string $userMessage,
        string $logMessage = '',
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $userMessage);
    }
}
