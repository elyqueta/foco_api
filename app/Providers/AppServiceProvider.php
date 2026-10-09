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
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'task' => Task::class,
            'project' => Project::class,
            'activity' => ActivityEntry::class,
        ]);

        User::observe(UserObserver::class);

        JsonResource::withoutWrapping();

        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)->by(
                Str::lower((string) $request->input('email')).'|'.$request->ip()
            );
        });

        // Registo público: abuso Bloqueado por IP (o utilizador ainda não existe).
        RateLimiter::for('register', function ($request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
