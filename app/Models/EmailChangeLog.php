<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailChangeLog extends Model
{
    protected $table = 'email_change_logs';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'old_email',
        'new_email',
        'ip_address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
