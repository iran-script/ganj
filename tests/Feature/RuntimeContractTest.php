<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateIdentity;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class RuntimeContractTest extends TestCase
{
    public function test_runtime_operations_match_frozen_paths_and_strict_evidence_contract(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'runtime.'));
        $this->assertCount(8, $routes);
        foreach ($routes as $route) {
            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            $method = strtolower($route->methods()[0]);
            $operation = $spec['paths'][$path][$method];
            $this->assertNotEmpty($operation['operationId']);
            $this->assertNotEmpty($operation['security']);
            $this->assertContains(AuthenticateIdentity::class, $route->gatherMiddleware());
            if ($method === 'post') {
                $key = collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key');
                $this->assertTrue($key['required']);
                $requestRef = $operation['requestBody']['content']['application/json']['schema']['$ref'];
                $schema = $spec['components']['schemas'][basename($requestRef)];
                $this->assertFalse($schema['additionalProperties']);
            }
        }
        $this->assertArrayNotHasKey('completed', $spec['components']['schemas']['RuntimeEvidence']['properties']);
        $this->assertArrayNotHasKey('score', $spec['components']['schemas']['RuntimeEvidence']['properties']);
    }
}
