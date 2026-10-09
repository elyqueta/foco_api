<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class DomainRuleException extends Exception
{
    /**
     * `$code` da classe base (Exception) é int e não pode ser redeclarado
     * como readonly string: o código de domínio vive em `$errorCode`.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
