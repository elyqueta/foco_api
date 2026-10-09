<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ActivityEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'task' => Task::class,
            'project' => Project::class,
            'activity' => ActivityEntry::class,
        ]);

        User::observe(UserObserver::class);

        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
