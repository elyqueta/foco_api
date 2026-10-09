<?php

declare(strict_types=1);

namespace App\Rules;

use App\Actions\Categories\ResolveCategory;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * Regra reutilizável (Fases 6, 7 e 11): o valor tem de existir nas categorias
 * do utilizador autenticado. A comparação é por `name_key` (minúsculas e
 * espaços colapsados); quem guarda o nome canónico é a action de criação,
 * nunca o texto recebido.
 */
class ValidCategory implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if (app(ResolveCategory::class)->handle($user, (string) $value) === null) {
            $fail('A categoria indicada não existe.');
        }
    }
}
