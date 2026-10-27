<?php

namespace App\Providers;

use App\Enums\UserStatus;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\Scenario;
use App\Models\Team;
use App\Models\User;
use App\Policies\GamePolicy;
use App\Policies\GameSessionPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ScenarioPolicy;
use App\Policies\TeamPolicy;
use App\Services\Games\BillingGate;
use App\Services\Games\CancellationResolution;
use App\Services\Games\PricingSnapshotProvider;
use App\Services\Games\ProductionSessionDependencies;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Idempotency\DatabaseIdempotencyStore;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Locations\RuntimeLocationVerification;
use App\Services\Organizations\OrganizationScope;
use App\Services\Runtime\ARVerification;
use App\Services\Runtime\HintAction;
use App\Services\Runtime\LiveChallengeTrigger;
use App\Services\Runtime\LocationVerification;
use App\Services\Runtime\QRVerification;
use App\Services\Runtime\QrVerificationAdapter;
use App\Services\Runtime\RuntimeInitializer;
use App\Services\Runtime\ScoreAction;
use App\Services\Runtime\UnavailableRuntimeDomains;
use App\Services\Scoring\ScoringService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->bind(RuntimeBootstrap::class, RuntimeInitializer::class);
        $this->app->bind(LocationVerification::class, RuntimeLocationVerification::class);
        $this->app->bind(QRVerification::class, QrVerificationAdapter::class);
        $this->app->bind(ScoreAction::class, ScoringService::class);
        foreach ([ARVerification::class, HintAction::class, LiveChallengeTrigger::class] as $port) {
            $this->app->bind($port, UnavailableRuntimeDomains::class);
        }
        foreach ([BillingGate::class, ScenarioAssignmentReadiness::class, PricingSnapshotProvider::class, CancellationResolution::class] as $port) {
            $this->app->bind($port, ProductionSessionDependencies::class);
        }
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(Scenario::class, ScenarioPolicy::class);
        Gate::policy(Game::class, GamePolicy::class);
        Gate::policy(GameSession::class, GameSessionPolicy::class);
        Gate::define('roles.manageGlobal', fn (User $user): bool => app(OrganizationScope::class)->isSuperAdmin($user));
        Gate::define('admin.users.view', fn (User $user): bool => app(OrganizationScope::class)->isSuperAdmin($user));
        Gate::define('admin.users.manage', fn (User $user): bool => app(OrganizationScope::class)->isSuperAdmin($user));
        Gate::define('admin.users.status', fn (User $user): bool => app(OrganizationScope::class)->isSuperAdmin($user));
        Gate::define('admin.roles.view', fn (User $user): bool => app(OrganizationScope::class)->isSuperAdmin($user));
        Gate::define('admin.players.view', function (User $user): bool {
            if (app(OrganizationScope::class)->isSuperAdmin($user)) {
                return true;
            }
            if (app(OrganizationScope::class)->organizations($user, true)->exists()) {
                return true;
            }

            return RoleAssignment::query()->where('user_id', $user->id)->where('role_code', 'GAMEMASTER')->whereNull('revoked_at')->exists();
        });
        foreach (['user.read_self', 'user.update_self', 'device.list_self', 'device.revoke_self'] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->status === UserStatus::Active->value);
        }
        foreach (['register' => 5, 'login' => 10] as $action => $maximum) {
            RateLimiter::for('identity-'.$action, fn (Request $request) => Limit::perMinute($maximum)->by($request->ip()));
        }
        RateLimiter::for('location-submit', fn (Request $request) => Limit::perMinute(config('location.rate_per_minute'))->by((string) $request->user()?->id));
        RateLimiter::for('runtime-submit', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->id));
        RateLimiter::for('qr-scan', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->id.':'.($request->header('X-Device-Id') ?? 'missing')));
        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));
        RateLimiter::for('booking-create', fn (Request $request) => Limit::perMinute(8)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('payment-actions', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('team-invites', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip())));
        Event::listen(JobProcessed::class, function (): void {
            try {
                Redis::connection()->setex('phase0:queue-worker-heartbeat', 120, (string) now()->timestamp);
            } catch (\Throwable) {
            }
        });
    }
}
