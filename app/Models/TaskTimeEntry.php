<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskTimeEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTimeEntry extends Model
{
    /** @use HasFactory<TaskTimeEntryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'task_time_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'task_id',
        'started_at',
        'ended_at',
        'ended_reason',
        'created_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }

    public function seconds(): int
    {
        if ($this->ended_at === null) {
            return (int) $this->started_at->diffInSeconds(now());
        }

        return (int) $this->started_at->diffInSeconds($this->ended_at);
    }
}
