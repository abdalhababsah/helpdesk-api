<?php

namespace App\Providers;

use App\Authorization\PermissionRegistry;
use App\Models\Category;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the grant matrix is read once per process rather than
        // per authorization check.
        $this->app->singleton(PermissionRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Action log subjects are stored under these keys instead of class
        // names, so moving or renaming a model does not orphan its history.
        // enforceMorphMap also rejects an unmapped model outright, which turns
        // a forgotten entry into an immediate error rather than a bad row.
        // Two buckets, not one. Per address alone lets a single origin spray
        // many accounts; per address alone lets a distributed attacker grind
        // one account. Both must hold.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by('login:email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinutes(15, 20)->by('login:ip:'.$request->ip()),
        ]);

        // A well-behaved client refreshes about once every ten minutes.
        // Tight per address and per account: each request sends an email.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by('reset:email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinutes(15, 10)->by('reset:ip:'.$request->ip()),
        ]);

        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinutes(15, 30)->by('refresh:ip:'.$request->ip()));

        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'category' => Category::class,
            'ticket' => Ticket::class,
            'ticket_comment' => TicketComment::class,
        ]);
    }
}
