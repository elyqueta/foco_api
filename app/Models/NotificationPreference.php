<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    protected $keyType = 'int';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'email_enabled',
        'in_app_enabled',
        'digest_hour',
        'due_soon_hours',
        'due_imminent_minutes',
        'timer_long_hours',
        'types',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'digest_hour' => 'integer',
        'due_soon_hours' => 'integer',
        'due_imminent_minutes' => 'integer',
        'timer_long_hours' => 'integer',
        'types' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typesWithDefaults(): array
    {
        $defaults = [
            'daily_digest' => ['email' => true, 'inApp' => true],
            'task_due_soon' => ['email' => true, 'inApp' => true],
            'task_due_imminent' => ['email' => true, 'inApp' => true],
            'task_expired' => ['email' => false, 'inApp' => true],
            'task_completed' => ['email' => false, 'inApp' => true],
            'task_estimate_exceeded' => ['email' => false, 'inApp' => true],
            'timer_running_long' => ['email' => false, 'inApp' => true],
            'project_due_soon' => ['email' => true, 'inApp' => true],
            'project_overdue' => ['email' => true, 'inApp' => true],
            'project_ready_to_complete' => ['email' => false, 'inApp' => true],
            'task_overdue_postponed' => ['email' => false, 'inApp' => true],
        ];

        $stored = $this->types ?? [];

        return array_replace_recursive($defaults, $stored);
    }

    public function channelEnabled(string $type, string $channel): bool
    {
        if ($channel === 'email' && ! $this->email_enabled) {
            return false;
        }
        if ($channel === 'in_app' && ! $this->in_app_enabled) {
            return false;
        }

        $types = $this->typesWithDefaults();

        return (bool) ($types[$type][$channel] ?? false);
    }
}
