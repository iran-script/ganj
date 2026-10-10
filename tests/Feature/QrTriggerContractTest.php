<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateIdentity;
use App\Services\Runtime\QRVerification;
use App\Services\Runtime\QrVerificationAdapter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class QrTriggerContractTest extends TestCase
{
    public function test_qr_scan_is_device_bound_idempotent_online_and_strict(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $operation = $spec['paths']['/qr/scan']['post'];
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route): bool => $route->getName() === 'qr.scan');
        $this->assertNotNull($route);
        $this->assertContains(AuthenticateIdentity::class, $route->gatherMiddleware());
        $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'X-Device-Id')['required']);
        $this->assertTrue(collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
        $request = $spec['components']['schemas']['QrScanRequest'];
        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['session_id', 'qr_identifier'], $request['required']);
        $this->assertSame(true, $spec['components']['schemas']['QrScanResponse']['properties']['online_required']['const']);
        $this->assertArrayNotHasKey('answer', $spec['components']['schemas']['QrScanResponse']['properties']);
        $this->assertArrayNotHasKey('identifier_hash', $spec['components']['schemas']['QrScanResponse']['properties']);
    }

    public function test_qr_verification_is_wired_without_opening_future_runtime_ports(): void
    {
        $this->assertInstanceOf(QrVerificationAdapter::class, app(QRVerification::class));
        $this->assertStringContainsString("'qr_token'", file_get_contents(base_path('app/Services/Runtime/RuntimeEngine.php')));
        $this->assertStringContainsString('hash(\'sha256\', $raw)', file_get_contents(base_path('app/Services/Qr/QrService.php')));
        $this->assertStringContainsString('hash(\'sha256\', $token)', file_get_contents(base_path('app/Services/Qr/QrService.php')));
        $this->assertStringNotContainsString("'answer'", file_get_contents(base_path('app/Services/Qr/QrService.php')));
    }
}
