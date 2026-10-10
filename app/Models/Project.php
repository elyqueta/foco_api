<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Project extends Model
{
    /**
     * Cor padrão do projeto (paleta sugerida do front, doc 06).
     */
    public const DEFAULT_COLOR = '#6C5CE7';

    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids;

    protected $table = 'projects';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'category',
        'urgency',
        'status',
        'can_postpone',
        'due_date',
        'next_step',
        'color',
    ];

    protected $casts = [
        'urgency' => Urgency::class,
        'status' => ProjectStatus::class,
        'can_postpone' => 'boolean',
        'due_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(ActivityEntry::class, 'subject')->latest('at');
    }

    /**
     * Contagens que o `progress` reutiliza quando estão carregadas — usar
     * com `withCount()` (listas) ou `loadCount()` (detalhe). Fonte única
     * dos nomes dos alias: `Project::progress()` lê estes atributos.
     *
     * @return array<int, string|\Closure>
     */
    public static function progressCounts(): array
    {
        return [
            'tasks',
            'tasks as done_tasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done->value),
        ];
    }

    /**
     * Progresso do projeto (doc 06): total, concluídas e percentagem.
     * `percent = total == 0 ? 0 : round(done/total*100)`; tarefas `expired`
     * contam no total. Usa `progressCounts()` quando as contagens já foram
     * carregadas; sem elas faz as duas consultas.
     */
    public function progress(): Attribute
    {
        return Attribute::get(function (): array {
            $total = (int) ($this->tasks_count ?? $this->tasks()->count());
            $done = (int) ($this->done_tasks_count ?? $this->tasks()
                ->where('status', TaskStatus::Done->value)
                ->count());

            return [
                'total' => $total,
                'done' => $done,
                'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            ];
        });
    }

    /**
     * Pesquisa case-insensitive em nome e descrição (`LOWER(...) LIKE`
     * funciona em PostgreSQL e SQLite, ao contrário do `ilike`).
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.mb_strtolower($term).'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
        });
    }

    public function isOverdue(CarbonImmutable $now): bool
    {
        if ($this->status === ProjectStatus::Done) {
            return false;
        }
        if ($this->due_date === null) {
            return false;
        }

        return $this->due_date->lt($now->startOfDay());
    }
}
