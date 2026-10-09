<?php

namespace Tests;

use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use App\Services\Games\BillingGate;
use App\Services\Games\CancellationResolution;
use App\Services\Games\PricingSnapshotProvider;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Games\SessionService;
use Illuminate\Support\Str;

trait GameSessionFixtures
{
    protected function adapters(): SessionTestDependencies
    {
        $fake = new SessionTestDependencies;
        foreach ([BillingGate::class, ScenarioAssignmentReadiness::class, PricingSnapshotProvider::class, RuntimeBootstrap::class, CancellationResolution::class] as $port) {
            $this->app->instance($port, $fake);
        }

        return $fake;
    }

    /** @return array{0:User,1:Game,2:GameSession} */
    protected function sessionFixture(bool $open = true): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id, 'user_id' => $admin->id]);
        $game = Game::factory()->create(['organization_id' => $organization->id]);
        $result = app(SessionService::class)->execute($admin, 'create', ['game_id' => $game->public_id]);
        $session = GameSession::where('public_id', $result['id'])->sole();
        if ($open) {
            app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id);
        }

        return [$admin, $game, $session->fresh()];
    }

    protected function teamFixture(int $count = 4): Team
    {
        $team = Team::factory()->create();
        if ($count > 1) {
            TeamMember::factory()->count($count - 1)->create(['team_id' => $team->id]);
        }

        return $team;
    }

    protected function registerFixture(GameSession $session, int $count = 4): Team
    {
        $team = $this->teamFixture($count);
        app(SessionService::class)->execute($team->captain, 'registerTeam', ['team_id' => $team->public_id], $session->public_id);

        return $team;
    }

    protected function readyFixture(GameSession $session, int $ready = 4): void
    {
        foreach ($session->teams()->whereNotIn('status', ['REMOVED', 'DISQUALIFIED'])->get() as $team) {
            foreach ($team->players()->orderBy('id')->limit($ready)->get() as $player) {
                $device = UserDevice::factory()->create(['user_id' => $player->user_id]);
                app(SessionService::class)->execute($player->user, 'bindDevice', ['device_id' => $device->public_id], $session->public_id);
                app(SessionService::class)->execute($player->user, 'ready', ['ready' => true], $session->public_id, requestDevice: $device);
            }
        }
    }

    protected function preparedFixture(int $count = 4, int $ready = 4): array
    {
        [$admin,$game,$session] = $this->sessionFixture();
        $team = $this->registerFixture($session, $count);
        $this->readyFixture($session, $ready);
        app(SessionService::class)->execute($admin, 'closeRegistration', [], $session->public_id);

        return [$admin, $session->fresh(), $team];
    }

    protected function authenticateGame(?User $user = null, ?UserDevice $device = null): UserDevice
    {
        $user ??= User::factory()->create();
        $device ??= UserDevice::factory()->create(['user_id' => $user->id]);
        $token = Str::random(64);
        $access = $user->tokens()->make(['name' => 'game-test', 'token' => hash('sha256', $token), 'abilities' => ['*']]);
        $access->forceFill(['user_device_id' => $device->id])->save();
        $this->withToken($token)->withHeader('X-Device-Id', $device->device_uuid);

        return $device;
    }

    protected function assertDomainError(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (ApiException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}

/** Deterministic test-only ports. Runtime audit writes deliberately share the caller transaction. */
class SessionTestDependencies implements BillingGate, CancellationResolution, PricingSnapshotProvider, RuntimeBootstrap, ScenarioAssignmentReadiness
{
    public string $billing = 'PASS';

    public bool $pricing = true;

    public bool $participationReferences = true;

    public bool $scenario = true;

    public bool $runtimeFailure = false;

    public string $cancellation = 'RESOLVED';

    public int $bootstrapCalls = 0;

    public array $commands = [];

    public function evaluate(GameSession $session, array $roster, string $stage, ?GameSessionPlayer $player = null): array
    {
        $references = [];
        if ($this->participationReferences) {
            foreach ($roster as $team) {
                foreach ($team['players'] as $participant) {
                    $references[$participant['user_id']] = 'test-billable-'.$participant['player_id'];
                }
            }
        }

        return ['status' => $this->billing, 'reference' => 'test-billing-reference', 'participation_references' => $references];
    }

    public function registrationReady(GameSession $session): bool
    {
        return $this->pricing;
    }

    public function freeze(GameSession $session, array $roster): array
    {
        return $this->pricing ? ['test-pricing-reference'] : [];
    }

    public function assignments(GameSession $session, array $roster): array
    {
        return $this->scenario ? array_fill_keys(array_column($roster, 'team_id'), 'test-scenario-version') : [];
    }

    public function bootstrap(GameSession $session, array $frozenRosters, string $commandId): array
    {
        $this->bootstrapCalls++;
        $this->commands[] = $commandId;
        app(AuditService::class)->record('test.runtime.bootstrap', 'game_session', $session->public_id);
        if ($this->runtimeFailure) {
            throw new ApiException('SESSION_NOT_READY', 'Test bootstrap failed.', [], 409);
        }

        return array_fill_keys(array_column($frozenRosters, 'team_id'), 'test-runtime-reference');
    }

    public function resolveCancellation(GameSession $session, string $reason, string $commandId): array
    {
        return ['status' => $this->cancellation];
    }
}
