<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class DomainRuleException extends Exception
{
    public function __construct(
        public readonly string $code,
        string $message,
        public readonly int $status = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
