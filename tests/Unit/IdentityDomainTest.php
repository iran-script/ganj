<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Identity\IdentityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentityDomainTest extends TestCase
{
    public static function deniedStatuses(): array
    {
        return [['BLOCKED'], ['SUSPENDED']];
    }

    #[DataProvider('deniedStatuses')]
    public function test_inactive_status_denies_access(string $status): void
    {
        $user = new User;
        $user->status = $status;
        $this->expectException(ApiException::class);
        $user->ensureActive();
    }

    public function test_active_status_allows_access_and_secrets_are_hidden(): void
    {
        $user = new User;
        $user->status = 'ACTIVE';
        $user->password_hash = 'Strong-password-123!';
        $user->ensureActive();
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $this->assertTrue(Hash::check('Strong-password-123!', $user->password_hash));
    }

    public function test_profile_cannot_remove_both_identifiers(): void
    {
        $this->expectException(ValidationException::class);
        app(IdentityService::class)->validateIdentifiers(['email' => null, 'mobile' => null]);
    }
}
