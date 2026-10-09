<?php

declare(strict_types=1);

namespace App\Actions\Categories;

use App\Exceptions\DomainRuleException;
use App\Models\Category;
use App\Models\User;

class ResolveCategory
{
    /**
     * Resolve o valor recebido (nome ou variação de maiúsculas/espaços)
     * contra as categorias do utilizador. Devolve a categoria canónica
     * ou null quando não existe. Fonte única de resolução, usada pela
     * regra ValidCategory, pelos requests e pelas actions de criação.
     */
    public function handle(User $user, string $value): ?Category
    {
        $key = Category::nameKey($value);

        if ($key === '') {
            return null;
        }

        return $user->categories()->where('name_key', $key)->first();
    }

    /**
     * Nome canónico para gravação: vazio usa a categoria padrão do
     * utilizador; texto que não existe é rejeitado (CATEGORY_NOT_FOUND).
     */
    public function canonicalName(User $user, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $this->fallback($user)->name;
        }

        $category = $this->handle($user, $value);

        if (! $category instanceof Category) {
            throw new DomainRuleException(
                'CATEGORY_NOT_FOUND',
                'A categoria não existe.',
                422,
            );
        }

        return $category->name;
    }

    /**
     * Categoria que recebe reatribuições e serve de default. Como os
     * utilizadores auto-registados têm as categorias padrão elimináveis,
     * o fallback nunca pode falhar: tenta a padrão, depois a mais antiga
     * restante e, em último caso, (re)cria a padrão sem protecção.
     *
     * @param  Category|null  $exclude  categoria a remover (nunca é fallback)
     */
    public function fallback(User $user, ?Category $exclude = null): Category
    {
        $remaining = fn () => $user->categories()
            ->when($exclude instanceof Category, fn ($query) => $query->whereKeyNot($exclude->getKey()));

        $fallback = $remaining()->where('name_key', Category::DEFAULT_KEY)->first()
            ?? $remaining()->orderBy('id')->first();

        if ($fallback instanceof Category) {
            return $fallback;
        }

        return $user->categories()->create([
            'name' => Category::DEFAULT_KEY,
            'name_key' => Category::DEFAULT_KEY,
            'is_default' => false,
        ]);
    }
}
