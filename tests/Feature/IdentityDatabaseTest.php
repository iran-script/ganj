<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentityDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public static function constraints(): array
    {
        return ['email' => ['email', '23505'], 'mobile' => ['mobile', '23505'], 'device' => ['device', '23505'], 'identifier' => ['identifier', '23514'], 'status' => ['status', '23514']];
    }

    #[DataProvider('constraints')]
    public function test_postgresql_enforces_identity_constraints(string $constraint, string $sqlState): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $user = User::factory()->create(['email' => 'player@example.com', 'mobile' => '+989121234567']);
        $other = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id]);
        try {
            DB::transaction(function () use ($constraint, $user, $other, $device): void {
                match ($constraint) {
                    'email' => DB::table('users')->where('id', $other->id)->update(['email' => 'PLAYER@EXAMPLE.COM']),
                    'mobile' => DB::table('users')->where('id', $other->id)->update(['mobile' => $user->mobile]),
                    'device' => UserDevice::factory()->create(['user_id' => $user->id, 'device_uuid' => $device->device_uuid]),
                    'identifier' => DB::table('users')->where('id', $user->id)->update(['email' => null, 'mobile' => null]),
                    'status' => DB::table('users')->where('id', $user->id)->update(['status' => 'ADMIN']),
                };
            });
            $this->fail('The database must reject invalid identity data.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }
}
