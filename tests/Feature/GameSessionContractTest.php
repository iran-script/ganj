<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class GameSessionContractTest extends TestCase
{
    public function test_game_and_session_routes_match_the_canonical_contract_without_cross_domain_routes(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $expected = [];
        $routes = collect(Route::getRoutes()->getRoutes());
        $actual = $routes->filter(fn ($r): bool => str_starts_with($r->getName() ?? '', 'games.') || str_starts_with($r->getName() ?? '', 'sessions.') || str_starts_with($r->getName() ?? '', 'rooms.'));
        foreach ($actual as $route) {
            $path = substr($route->uri(), 6);
            $method = strtolower($route->methods()[0]);
            $this->assertArrayHasKey($path, $spec['paths']);
            $this->assertArrayHasKey($method, $spec['paths'][$path]);
            $operation = $spec['paths'][$path][$method];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            foreach ($operation['parameters'] ?? [] as $parameter) {
                if ($parameter['in'] === 'path') {
                    $this->assertSame('uuid', $parameter['schema']['format']);
                }
            }
            $expected[] = $path;
        }
        $this->assertCount(27, $expected);
        foreach (['/billing', '/payments', '/store', '/gps', '/ar'] as $later) {
            $this->assertFalse($actual->contains(fn ($r): bool => in_array(ltrim($later, '/'), explode('/', $r->uri()), true)));
        }
        foreach (['GameCreate', 'GameUpdate', 'SessionCreate', 'SessionUpdate', 'SessionReady', 'SessionDevice'] as $schema) {
            $this->assertFalse($spec['components']['schemas'][$schema]['additionalProperties']);
        }
        $this->assertArrayNotHasKey('status', $spec['components']['schemas']['SessionUpdate']['properties']);
        $this->assertArrayNotHasKey('organization_id', $spec['components']['schemas']['SessionCreate']['properties']);
        $this->assertArrayNotHasKey('organization_id', $spec['components']['schemas']['GameUpdate']['properties']);
        $this->assertSame(['ready'], $spec['components']['schemas']['SessionReady']['required']);
    }
}
