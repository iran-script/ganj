<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateIdentity;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ScenarioContractTest extends TestCase
{
    public function test_scenario_routes_are_canonical_authenticated_and_do_not_add_later_domains(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $all = collect(Route::getRoutes()->getRoutes());
        $routes = $all->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/scenarios') || str_starts_with($route->uri(), 'api/v1/scenario-versions'));
        $this->assertCount(15, $routes);
        foreach ($routes as $route) {
            $path = substr($route->uri(), 6);
            $method = strtolower($route->methods()[0]);
            $this->assertArrayHasKey($path, $spec['paths']);
            $this->assertArrayHasKey($method, $spec['paths'][$path]);
            $operation = $spec['paths'][$path][$method];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $this->assertContains(AuthenticateIdentity::class, $route->gatherMiddleware());
            foreach ($operation['parameters'] ?? [] as $parameter) {
                if ($parameter['in'] === 'path') {
                    $this->assertSame('uuid', $parameter['schema']['format']);
                }
            }
        }
        foreach (['scenario-assignments', 'billing', 'payments', 'store', 'gps', 'ar'] as $later) {
            $this->assertFalse($all->contains(fn ($route): bool => in_array($later, explode('/', $route->uri()), true)));
        }
        foreach (['ScenarioCreate', 'ScenarioUpdate', 'ScenarioVersionUpdate', 'ScenarioGraphSave', 'ScenarioPublish'] as $name) {
            $this->assertFalse($spec['components']['schemas'][$name]['additionalProperties']);
        }
        $this->assertArrayNotHasKey('status', $spec['components']['schemas']['ScenarioVersionUpdate']['properties']);
        $this->assertContains('revision_token', $spec['components']['schemas']['ScenarioGraphSave']['required']);
    }
}
