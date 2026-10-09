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

    /**
     * Padrão da API para atualizações: se o pedido não muda nenhum valor,
     * não atualiza nem responde com sucesso — devolve 422 NO_CHANGES.
     */
    public static function noChanges(): self
    {
        return new self('NO_CHANGES', 'Nenhuma alteração detetada.', 422);
    }
}
