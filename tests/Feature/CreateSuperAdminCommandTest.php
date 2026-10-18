<?php

namespace Tests\Feature;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_hashed_idempotent_super_admin(): void
    {
        $this->artisan('nexo:super-admin:create', ['--name' => 'NEXO Test Admin', '--email' => 'test-admin@nexo.local', '--reason' => 'Authorized control center verification'])
            ->expectsQuestion('Password (minimum 12 characters)', 'a-secure-test-password')
            ->expectsOutput('Super Admin account created.')
            ->assertExitCode(0);

        $user = User::where('email', 'test-admin@nexo.local')->sole();
        $this->assertTrue(Hash::check('a-secure-test-password', $user->password_hash));
        $this->assertDatabaseHas('role_assignments', ['user_id' => $user->id, 'role_code' => RoleAssignment::SUPER_ADMIN, 'revoked_at' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'role_assignment.granted', 'resource_id' => $user->public_id]);

        $this->artisan('nexo:super-admin:create', ['--name' => 'Changed Name', '--email' => 'test-admin@nexo.local', '--reason' => 'Repeat verification'])
            ->expectsQuestion('Password (minimum 12 characters)', 'another-password')
            ->expectsOutput('Super Admin account already exists; no changes were made.')
            ->assertExitCode(0);

        $this->assertSame('NEXO Test Admin', $user->fresh()->name);
        $this->assertDatabaseCount('role_assignments', 1);
    }

    public function test_command_does_not_modify_an_existing_non_admin_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@nexo.local', 'name' => 'Existing User']);

        $this->artisan('nexo:super-admin:create', ['--name' => 'Attempted Change', '--email' => $user->email, '--reason' => 'Authorized verification'])
            ->expectsQuestion('Password (minimum 12 characters)', 'a-secure-test-password')
            ->expectsOutput('An account with this identifier already exists and was not changed.')
            ->assertExitCode(1);

        $this->assertSame('Existing User', $user->fresh()->name);
        $this->assertDatabaseCount('role_assignments', 0);
    }
}
