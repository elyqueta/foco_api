<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Exceptions\DomainRuleException;
use Carbon\CarbonImmutable;

/**
 * Normalização da data de prazo de um projeto.
 *
 * Projetos não têm hora (T-02): `YYYY-MM-DD` e ISO com hora são ambos
 * aceites e reduzidos à data. O `ProjectFormRequest` já valida o formato
 * — esta classe é a rede de segurança para chamadas internas (seeders,
 * ações, comandos) e garante que uma data inválida nunca chega a uma
 * coluna `date` (no PostgreSQL daria erro 500/503).
 */
final class ProjectDueDate
{
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('Y-m-d');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->format('Y-m-d');
        } catch (\Exception) {
            throw new DomainRuleException(
                'INVALID_DUE_DATE',
                'A data de prazo não é válida.',
                422,
            );
        }
    }
}
