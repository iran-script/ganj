<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateIdentity;
use App\Http\Middleware\RequireDevice;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Identity\DeviceResolver;
use App\Services\Identity\IdentityService;
use App\Services\Outbox\OutboxService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $changes = []): array
    {
        return array_replace(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'Strong-password-123!', 'device_uuid' => (string) Str::uuid(), 'device_name' => 'Phone', 'platform' => 'android', 'app_version' => '1.0'], $changes);
    }

    private function register(array $input = []): array
    {
        return $this->postJson('/api/v1/auth/register', $input ?: $this->input())->assertCreated()->json('data');
    }

    public function test_registration_hashes_password_and_correlates_private_events(): void
    {
        $input = $this->input();
        $this->withHeader('X-Request-Id', 'identity-test');
        $response = $this->postJson('/api/v1/auth/register', $input)->assertCreated()->assertHeader('X-Request-Id', 'identity-test');
        $response->assertJsonMissingPath('data.user.password_hash')->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.device.user_id');
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check($input['password'], $user->password_hash));
        $this->assertSame($user->public_id, $response->json('data.user.id'));
        $this->assertTrue(Str::isUuid($response->json('data.device.id')));
        $this->assertSame('ACTIVE', $user->status);
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.registered.v1', 'request_id' => 'identity-test']);
        $this->assertNotSame($input['password'], $user->password_hash);
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $tokenHash = DB::table('personal_access_tokens')->value('token');
        $this->assertSame(hash('sha256', $response->json('data.token')), $tokenHash);
        foreach (['audit_events', 'outbox_events'] as $table) {
            $stored = json_encode(DB::table($table)->get());
            foreach ([$input['password'], $response->json('data.token'), $user->password_hash, $tokenHash] as $secret) {
                $this->assertStringNotContainsString($secret, $stored);
            }
        }
        foreach (DB::table('outbox_events')->get() as $event) {
            $envelope = json_decode($event->payload, true);
            $this->assertSame($event->event_id, $envelope['event_id']);
            $this->assertSame($event->topic, $envelope['event_type']);
            $this->assertSame($user->public_id, $envelope['actor']['id']);
            $this->assertSame('identity-test', $envelope['request_id']);
            $this->assertSame(1, $envelope['payload_version']);
        }
    }

    public static function identifiers(): array
    {
        return ['mobile' => [['email' => null, 'mobile' => '+989121234567']], 'email' => [['email' => 'PLAYER@EXAMPLE.COM']]];
    }

    #[DataProvider('identifiers')]
    public function test_register_and_login_by_identifier_reuse_device(array $changes): void
    {
        $input = $this->input($changes);
        $first = $this->register($input);
        unset($input['name']);
        $input['device_name'] = 'Renamed';
        $second = $this->postJson('/api/v1/auth/login', $input)->assertOk()->assertJsonPath('data.device.device_name', 'Renamed')->json('data');
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame($first['device']['id'], $second['device']['id']);
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertNotNull(User::first()->last_login_at);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'user.logged_in.v1']);
    }

    public function test_missing_duplicate_and_weak_identifiers_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->input(['email' => null]))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->postJson('/api/v1/auth/register', $this->input(['password' => 'short']))->assertUnprocessable();
        $this->register($this->input(['mobile' => '+989121234567']));
        $this->postJson('/api/v1/auth/register', $this->input(['email' => 'PLAYER@EXAMPLE.COM']))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', $this->input(['email' => 'other@example.com', 'mobile' => '+989121234567']))->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_wrong_password_and_unknown_account_have_same_error(): void
    {
        $input = $this->input();
        $this->register($input);
        unset($input['name']);
        $this->postJson('/api/v1/auth/login', array_replace($input, ['password' => 'wrong']))->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->postJson('/api/v1/auth/login', array_replace($input, ['email' => 'missing@example.com']))->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public static function statuses(): array
    {
        return [['BLOCKED'], ['SUSPENDED']];
    }

    #[DataProvider('statuses')]
    public function test_status_denies_login_and_stale_tokens(string $status): void
    {
        $input = $this->input();
        $first = $this->register($input);
        DB::table('users')->update(['status' => $status]);
        unset($input['name']);
        $this->postJson('/api/v1/auth/login', $input)->assertForbidden()->assertJsonPath('error.code', 'AUTHORIZATION_DENIED')->assertJsonPath('error.details.status', $status);
        $this->withToken($first['token'])->getJson('/api/v1/me')->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_me_profile_identifier_invariants_and_logout(): void
    {
        $this->withHeader('X-Request-Id', 'unauth-test')->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('X-Request-Id', 'unauth-test')->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $first = $this->register();
        $other = User::factory()->create(['email' => 'other@example.com']);
        $this->withToken($first['token'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'Player');
        $this->patchJson('/api/v1/me', ['name' => 'New Name', 'mobile' => '+989121234568'])->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->patchJson('/api/v1/me', ['email' => 'OTHER@EXAMPLE.COM'])->assertUnprocessable();
        $this->patchJson('/api/v1/me', ['email' => null, 'mobile' => null])->assertUnprocessable();
        $this->patchJson('/api/v1/me', ['email' => null])->assertOk();
        foreach (['status', 'role', 'roles', 'user_id', 'password_hash'] as $field) {
            $this->patchJson('/api/v1/me', [$field => 'forbidden'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('users', ['status' => 'ACTIVE', 'name' => 'New Name']);
        $this->assertSame('other@example.com', $other->fresh()->email);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.identifiers_updated']);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'user.logged_out.v1']);
    }

    public function test_device_ownership_revocation_and_context(): void
    {
        $input = $this->input();
        $first = $this->register($input);
        $otherInput = $this->input(['email' => 'other@example.com']);
        $other = $this->register($otherInput);
        $this->withToken($first['token'])->getJson('/api/v1/devices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first['device']['id']);
        $this->deleteJson('/api/v1/devices/'.$other['device']['id'])->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->deleteJson('/api/v1/devices/1')->assertNotFound();
        $this->withHeader('X-Device-Id', $input['device_uuid'])->getJson('/api/v1/me')->assertOk();
        $this->withHeader('X-Device-Id', $otherInput['device_uuid'])->getJson('/api/v1/me')->assertForbidden();
        $this->withHeader('X-Device-Id', $input['device_uuid'])->deleteJson('/api/v1/devices/'.$first['device']['id'])->assertNoContent();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        unset($input['name']);
        $this->postJson('/api/v1/auth/login', $input)->assertForbidden();
        $this->assertDatabaseHas('outbox_events', ['topic' => 'device.revoked.v1']);
        $this->assertDatabaseCount('user_devices', 2);
    }

    public function test_revoke_is_idempotent_from_another_active_device(): void
    {
        $input = $this->input();
        $first = $this->register($input);
        unset($input['name']);
        $input['device_uuid'] = (string) Str::uuid();
        $second = $this->postJson('/api/v1/auth/login', $input)->assertOk()->json('data');
        $this->withToken($second['token'])->deleteJson('/api/v1/devices/'.$first['device']['id'])->assertNoContent();
        $this->deleteJson('/api/v1/devices/'.$first['device']['id'])->assertNoContent();
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'device.revoked.v1')->count());
        $this->getJson('/api/v1/me')->assertOk();
        $this->withToken($first['token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_sensitive_device_guard_requires_owned_non_revoked_device(): void
    {
        Route::middleware([AuthenticateIdentity::class, RequireDevice::class])->get('/api/v1/test-device', fn (Request $r) => ['device' => $r->attributes->get('identity_device')->public_id]);
        $input = $this->input();
        $first = $this->register($input);
        $this->withToken($first['token'])->getJson('/api/v1/test-device')->assertForbidden();
        $this->withHeader('X-Device-Id', $input['device_uuid'])->getJson('/api/v1/test-device')->assertOk()->assertJsonPath('device', $first['device']['id']);
        $user = User::firstOrFail();
        $device = UserDevice::firstOrFail();
        $this->assertTrue(app(DeviceResolver::class)->resolve($user, $input['device_uuid'])->is($device));
        $device->revoked_at = now();
        $device->save();
        $this->expectException(ApiException::class);
        app(DeviceResolver::class)->resolve($user, $input['device_uuid']);
    }

    public function test_session_cookie_cannot_replace_bearer_authentication(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_registration_rolls_back_when_outbox_fails(): void
    {
        $this->mock(OutboxService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Outbox failure'));
        $this->postJson('/api/v1/auth/register', $this->input())->assertStatus(500);
        foreach (['users', 'user_devices', 'personal_access_tokens', 'audit_events', 'outbox_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_expired_tokens_and_client_actor_overrides_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->input(['user_id' => 1]))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', $this->input(['role' => 'SUPER_ADMIN']))->assertUnprocessable();
        $input = $this->input();
        $first = $this->register($input);
        unset($input['name']);
        $this->postJson('/api/v1/auth/login', $input + ['user_id' => 1])->assertUnprocessable();
        DB::table('personal_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->withToken($first['token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_credentials_and_token_material_are_absent_from_request_logs(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            $records[] = [$event->message, $event->context];
        });
        $input = $this->input();
        $first = $this->register($input);
        $tokenHash = DB::table('personal_access_tokens')->value('token');
        $this->withToken($first['token'])->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $logs = json_encode($records);
        $this->assertNotEmpty($records);
        foreach ([$input['password'], $first['token'], $tokenHash, User::first()->password_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function test_database_failures_do_not_log_credential_bindings(): void
    {
        $secret = 'private-token-hash-sentinel';
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            $records[] = [$event->message, $event->context];
        });
        $exception = new QueryException('pgsql', 'insert into personal_access_tokens (token) values (?)', [$secret], new \PDOException('database rejected '.$secret));
        $this->mock(IdentityService::class)->shouldReceive('register')->once()->andThrow($exception);
        $response = $this->postJson('/api/v1/auth/register', $this->input())->assertStatus(500);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode($records));
        $this->assertStringContainsString('identity.persistence_failed', json_encode($records));
    }

    public function test_register_and_login_have_dedicated_rate_limits(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/register', []);
        }
        $response->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertGreaterThan(0, $response->json('error.details.retry_after_seconds'));
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/v1/auth/login', []);
        }
        $response->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
