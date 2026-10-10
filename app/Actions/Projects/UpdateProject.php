<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Categories\ResolveCategory;
use App\Enums\ActivityType;
use App\Enums\ProjectStatus;
use App\Enums\Urgency;
use App\Exceptions\DomainRuleException;
use App\Models\Project;
use App\Models\User;
use App\Services\RecordActivity;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    /**
     * Atualização parcial: só os campos enviados são considerados. O diff
     * contra os valores atuais decide o que escreve e que atividade registar
     * — nenhum valor diferente significa `422 NO_CHANGES` (padrão da API).
     *
     * Atividade por tipo de alteração (doc 06):
     *  - `status` diferente → `status_changed`;
     *  - `next_step` diferente → `next_step_changed`;
     *  - qualquer outro campo diferente → **uma** entrada `edited` por pedido.
     */
    public function handle(User $user, Project $project, array $data): Project
    {
        $attributes = $this->resolveAttributes($user, $data);

        $changes = [];

        foreach ($attributes as $key => $value) {
            if (! $this->isSame($key, $project->{$key}, $value)) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            throw DomainRuleException::noChanges();
        }

        return DB::transaction(function () use ($user, $project, $changes): Project {
            $project->update($changes);

            $this->recordActivity($user, $project, $changes);

            return $project
                ->loadCount(Project::progressCounts())
                ->load('activity');
        });
    }

    /**
     * Campos efetivamente enviados (chaves ausentes não entram), já
     * normalizados para a base de dados.
     *
     * @return array<string, mixed>
     */
    private function resolveAttributes(User $user, array $data): array
    {
        $attributes = [];

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $attributes['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = trim((string) $data['description']);
        }

        if (array_key_exists('category', $data) && trim((string) $data['category']) !== '') {
            $attributes['category'] = app(ResolveCategory::class)->canonicalName($user, (string) $data['category']);
        }

        if (array_key_exists('urgency', $data) && trim((string) $data['urgency']) !== '') {
            $urgency = Urgency::tryFrom((string) $data['urgency']);

            if ($urgency instanceof Urgency) {
                $attributes['urgency'] = $urgency;
            }
        }

        if (array_key_exists('status', $data) && trim((string) $data['status']) !== '') {
            $status = ProjectStatus::tryFrom((string) $data['status']);

            if ($status instanceof ProjectStatus) {
                $attributes['status'] = $status;
            }
        }

        if (array_key_exists('canPostpone', $data)) {
            $attributes['can_postpone'] = (bool) $data['canPostpone'];
        }

        if (array_key_exists('dueDate', $data)) {
            // Texto vazio ou `null` limpam o prazo (regra transversal); o
            // resto é normalizado para `Y-m-d` (projetos não têm hora).
            $attributes['due_date'] = ProjectDueDate::normalize($data['dueDate']);
        }

        if (array_key_exists('nextStep', $data)) {
            $attributes['next_step'] = trim((string) $data['nextStep']);
        }

        if (array_key_exists('color', $data) && trim((string) $data['color']) !== '') {
            $attributes['color'] = trim((string) $data['color']);
        }

        return $attributes;
    }

    /**
     * Comparação explícita (sem depender de `getDirty`, cujo comportamento
     * varia com o cast `date:Y-m-d`): datas comparam-se só pela parte da
     * data, booleanos por verdadeiro/falso e enums pelo valor.
     */
    private function isSame(string $key, mixed $current, mixed $new): bool
    {
        if ($key === 'due_date') {
            return $this->dateValue($current) === $this->dateValue($new);
        }

        if ($key === 'can_postpone') {
            return (bool) $current === (bool) $new;
        }

        // Enum instância ou string crua: compara-se sempre pelo valor.
        if ($current instanceof \BackedEnum) {
            $current = $current->value;
        }

        if ($new instanceof \BackedEnum) {
            $new = $new->value;
        }

        if (is_string($current) && is_string($new)) {
            return trim($current) === trim($new);
        }

        return $current === $new;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr(trim((string) $value), 0, 10);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordActivity(User $user, Project $project, array $changes): void
    {
        $record = app(RecordActivity::class);

        if (array_key_exists('status', $changes)) {
            $record->record(
                $user,
                $project,
                ActivityType::StatusChanged,
                'Estado alterado para '.$project->status->label(),
            );
        }

        if (array_key_exists('next_step', $changes)) {
            $record->record(
                $user,
                $project,
                ActivityType::NextStepChanged,
                'Próximo passo atualizado',
            );
        }

        $otherFields = array_diff(array_keys($changes), ['status', 'next_step']);

        if ($otherFields !== []) {
            $record->record(
                $user,
                $project,
                ActivityType::Edited,
                'Projeto editado',
            );
        }
    }
}
