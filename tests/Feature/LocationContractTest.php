<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateIdentity;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class LocationContractTest extends TestCase
{
    public function test_seven_canonical_operations_have_strict_observation_and_checkpoint_contracts(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'locations.') || str_starts_with($route->getName() ?? '', 'checkpoints.'));
        $this->assertCount(7, $routes);
        foreach ($routes as $route) {
            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            $operation = $spec['paths'][$path][strtolower($route->methods()[0])];
            $this->assertNotEmpty($operation['security']);
            $this->assertContains(AuthenticateIdentity::class, $route->gatherMiddleware());
            if (isset($operation['requestBody'])) {
                $schema = $spec['components']['schemas'][basename($operation['requestBody']['content']['application/json']['schema']['$ref'])];
                $this->assertFalse($schema['additionalProperties']);
            }
        }
        $this->assertSame(100, $spec['components']['schemas']['LocationBatch']['properties']['samples']['maxItems']);
        $this->assertArrayNotHasKey('inside', $spec['components']['schemas']['LocationSample']['properties']);
    }
}
