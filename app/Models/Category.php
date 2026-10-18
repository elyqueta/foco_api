<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * Chave da categoria padrão: fallback de reatribuição e default de
     * criação (fonte única — ver App\Actions\Categories\ResolveCategory).
     */
    public const DEFAULT_KEY = 'professional';

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $table = 'categories';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'name',
        'name_key',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Chave de comparação do nome: minúsculas, sem espaços extra
     * (norma do doc 05-categorias §Regras).
     */
    public static function nameKey(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $name);

        return mb_strtolower(trim((string) $collapsed));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tarefas do utilizador dono da categoria. O `category` da tarefa guarda
     * o nome canónico. O `whereColumn` (em vez de `$this->user_id`) mantém o
     * scoping por utilizador tanto em acesso directo como em `withCount`,
     * onde a relação é construída a partir de um modelo protótipo.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'category', 'name')
            ->whereColumn('tasks.user_id', 'categories.user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'category', 'name')
            ->whereColumn('projects.user_id', 'categories.user_id');
    }
}
