<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class TeamContractTest extends TestCase
{
    public function test_team_routes_match_the_canonical_openapi(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $objectSpec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'), Yaml::PARSE_OBJECT_FOR_MAP);
        $operations = [];
        foreach ($spec['paths'] as $path => $methods) {
            if (str_starts_with($path, '/teams')) {
                foreach ($methods as $method => $operation) {
                    $operations[] = strtoupper($method).' api/v1'.$path;
                    $this->assertSame([['bearerAuth' => []]], $operation['security']);
                    $this->assertIsArray($objectSpec->paths->{$path}->{$method}->security[0]->bearerAuth);
                }
            }
        }
        $routes = collect(Route::getRoutes()->getRoutes());
        $teamRoutes = $routes->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/teams'));
        $this->assertCount(12, $operations);
        $this->assertEqualsCanonicalizing($operations, $teamRoutes->map(fn ($route): string => $route->methods()[0].' '.$route->uri())->values()->all());
        $this->assertArrayHasKey('201', $spec['paths']['/teams']['post']['responses']);
        $this->assertArrayHasKey('201', $spec['paths']['/teams/{team_id}/invites']['post']['responses']);
        $this->assertArrayHasKey('204', $spec['paths']['/teams/{team_id}/members/{user_id}']['delete']['responses']);
        $this->assertFalse($spec['components']['schemas']['TeamUpdate']['additionalProperties']);
        $this->assertSame('uuid', $spec['components']['schemas']['Team']['properties']['id']['format']);
        $this->assertArrayNotHasKey('organization_id', $spec['components']['schemas']['TeamCreate']['properties']);
        $this->assertArrayNotHasKey('captain_user_id', $spec['components']['schemas']['TeamUpdate']['properties']);
        $this->assertArrayNotHasKey('code_hash', $spec['components']['schemas']['TeamInvite']['properties']);
        $this->assertSame(8, $spec['components']['schemas']['Team']['properties']['member_count']['maximum']);
        $this->checkReferences($spec, $spec);
    }

    private function checkReferences(array $node, array $spec): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
                $resolved = $spec;
                foreach (explode('/', substr($value, 2)) as $part) {
                    $this->assertArrayHasKey($part, $resolved, $value);
                    $resolved = $resolved[$part];
                }
            } elseif (is_array($value)) {
                $this->checkReferences($value, $spec);
            }
        }
    }
}
