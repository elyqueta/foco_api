<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityEntry extends Model
{
    use HasUuids;

    protected $table = 'activity_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'type',
        'message',
        'at',
        'created_at',
    ];

    protected $casts = [
        'type' => ActivityType::class,
        'at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
