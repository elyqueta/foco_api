<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Services\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Task extends Model
{
    use HasUuids;

    protected $table = 'tasks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'description',
        'category',
        'urgency',
        'status',
        'can_postpone',
        'due_date',
        'due_time',
        'next_step',
        'estimate_minutes',
        'tags',
        'tracked_seconds',
        'first_started_at',
        'completed_at',
        'expired_at',
        'postponed_count',
    ];

    protected $casts = [
        'urgency' => Urgency::class,
        'status' => TaskStatus::class,
        'can_postpone' => 'boolean',
        'due_date' => 'date:Y-m-d',
        'tags' => 'array',
        'estimate_minutes' => 'integer',
        'tracked_seconds' => 'integer',
        'postponed_count' => 'integer',
        'first_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if ($model->tags === null) {
                $model->tags = [];
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(ActivityEntry::class, 'subject')->latest('at');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TaskTimeEntry::class)->orderBy('started_at');
    }

    public function activeTimeEntry()
    {
        return $this->hasOne(TaskTimeEntry::class)->whereNull('ended_at');
    }

    /**
     * Combined due date+time in the user's timezone.
     */
    public function dueAtLocal(): Attribute
    {
        return Attribute::get(function (): ?CarbonImmutable {
            if ($this->due_date === null) {
                return null;
            }

            $date = $this->due_date;
            $time = $this->due_time;

            $tz = $this->user?->timezone ?? 'UTC';
            $tz = is_string($tz) ? $tz : 'UTC';

            if ($time === null || $time === '') {
                return CarbonImmutable::parse($date->format('Y-m-d'), $tz)->startOfDay();
            }

            [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', (string) $time)), 3, 0);

            return CarbonImmutable::create($date->year, $date->month, $date->day, $hour, $minute, $second, $tz);
        });
    }

    /**
     * Contract serialization for dueDate (with time → YYYY-MM-DDTHH:mm, without → YYYY-MM-DD).
     */
    public function dueDateString(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->due_date === null) {
                return null;
            }

            if ($this->due_time !== null && $this->due_time !== '') {
                return $this->due_date->format('Y-m-d').'T'.substr((string) $this->due_time, 0, 5);
            }

            return $this->due_date->format('Y-m-d');
        });
    }

    /**
     * Whether the task is overdue right now (calculated, not stored).
     * Expiration is by day: due_date < today(user tz). Time only matters for isOverdue flag.
     */
    public function isOverdue(): bool
    {
        if ($this->status === TaskStatus::Done || $this->status === TaskStatus::Expired) {
            return false;
        }
        if ($this->due_date === null) {
            return false;
        }

        $clock = app(UserClock::class);
        $today = $clock->today($this->user)->format('Y-m-d');
        $dueDateStr = $this->due_date->format('Y-m-d');

        if ($dueDateStr < $today) {
            return true;
        }

        if ($dueDateStr === $today && $this->due_time !== null && $this->due_time !== '') {
            $now = $clock->now($this->user);
            $dueTime = CarbonImmutable::parse($this->due_time, $now->timezone);

            return $dueTime->lt($now);
        }

        return false;
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['todo', 'in_progress', 'postponed']);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['done', 'expired']);
    }

    public function scopeToday($query, CarbonImmutable $today)
    {
        return $query->where(function ($q) use ($today) {
            $q->whereNull('due_date')
                ->orWhere('due_date', '>=', $today->format('Y-m-d'));
        });
    }

    public function scopeUrgent($query)
    {
        return $query->whereIn('urgency', ['critical', 'high']);
    }
}
