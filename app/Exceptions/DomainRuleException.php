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
        /**
         * Erros por campo (contrato `422 { message, errors, code }`), usados
         * quando a regra de negócio está ligada a um campo concreto (ex.
         * `dueDate` com o código `PAST_DUE_DATE`). Vazio por omissão — a
         * maioria das regras só devolve `{ message, code }`.
         *
         * @var array<string, list<string>>
         */
        public readonly array $errors = [],
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

    /**
     * Regra de domínio ligada a um campo (adiciona `errors` ao corpo PT).
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(string $errorCode, string $message, array $errors, int $status = 422): self
    {
        return new self($errorCode, $message, $status, null, $errors);
    }
}
