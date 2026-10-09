<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'category', 'name_key');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'category', 'name_key');
    }
}
