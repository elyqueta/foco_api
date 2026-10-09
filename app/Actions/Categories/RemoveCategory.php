<?php

declare(strict_types=1);

namespace App\Actions\Categories;

use App\Enums\ActivityType;
use App\Exceptions\DomainRuleException;
use App\Models\ActivityEntry;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemoveCategory
{
    /**
     * Remove uma categoria personalizada do utilizador: numa transação,
     * todas as tarefas e projetos com essa categoria passam a `professional`
     * (com atividade `edited` em cada um) e só depois a categoria é apagada.
     * Categorias padrão são protegidas (CATEGORY_PROTECTED).
     *
     * A reatribuição é feita em conjunto (UPDATE + INSERT multi-row) para
     * não prender locks em transações longas com muitos itens.
     */
    public function handle(User $user, Category $category): void
    {
        if ($category->is_default) {
            throw new DomainRuleException(
                'CATEGORY_PROTECTED',
                'As categorias padrão não podem ser removidas.',
                422,
            );
        }

        DB::transaction(function () use ($user, $category): void {
            $fallback = app(ResolveCategory::class)->fallback($user);

            $this->reassignTasks($user, $category, $fallback);
            $this->reassignProjects($user, $category, $fallback);

            $category->delete();
        });
    }

    private function reassignTasks(User $user, Category $category, Category $fallback): void
    {
        $ids = $user->tasks()->where('category', $category->name)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $user->tasks()->whereKey($ids)->update([
            'category' => $fallback->name,
            'updated_at' => now(),
        ]);

        $this->logReassignment($user, 'task', $ids->all(), $fallback);
    }

    private function reassignProjects(User $user, Category $category, Category $fallback): void
    {
        $ids = $user->projects()->where('category', $category->name)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $user->projects()->whereKey($ids)->update([
            'category' => $fallback->name,
            'updated_at' => now(),
        ]);

        $this->logReassignment($user, 'project', $ids->all(), $fallback);
    }

    /**
     * Uma entrada de atividade `edited` por item reatribuído.
     *
     * @param  list<string>  $ids
     */
    private function logReassignment(User $user, string $subject, array $ids, Category $fallback): void
    {
        $now = now();

        $rows = array_map(fn (string $id): array => [
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'subject_type' => $subject,
            'subject_id' => $id,
            'type' => ActivityType::Edited->value,
            'message' => 'Categoria alterada para '.$fallback->name,
            'at' => $now,
            'created_at' => $now,
        ], $ids);

        ActivityEntry::insert($rows);
    }
}
