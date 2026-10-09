<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\Urgency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Project extends Model
{
    use HasUuids;

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

    public function progress(): Attribute
    {
        return Attribute::get(function () {
            $total = $this->tasks()->count();
            $done = $this->tasks()->where('status', 'done')->count();
            $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;

            return [
                'total' => $total,
                'done' => $done,
                'percent' => $percent,
            ];
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
