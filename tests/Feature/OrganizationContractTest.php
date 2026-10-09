<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OrganizationContractTest extends TestCase
{
    public function test_organization_routes_and_schemas_match_the_canonical_openapi(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/organizations'));
        $operations = [];
        foreach ($spec['paths'] as $path => $methods) {
            if (str_starts_with($path, '/organizations')) {
                foreach ($methods as $method => $operation) {
                    $operations[] = strtoupper($method).' api/v1'.$path;
                    $this->assertSame([['bearerAuth' => []]], $operation['security']);
                }
            }
        }
        $this->assertCount(7, $operations);
        $this->assertEqualsCanonicalizing($operations, $routes->map(fn ($route) => $route->methods()[0].' '.$route->uri())->values()->all());
        $this->assertArrayHasKey('201', $spec['paths']['/organizations']['post']['responses']);
        $this->assertArrayHasKey('201', $spec['paths']['/organizations/{organization_id}/members']['post']['responses']);
        $this->assertArrayHasKey('204', $spec['paths']['/organizations/{organization_id}/members/{user_id}']['delete']['responses']);
        $this->assertSame(['ORGANIZATION_ADMIN', 'GAMEMASTER', 'STAFF'], $spec['components']['schemas']['OrganizationMemberCreate']['properties']['role']['enum']);
        $this->assertFalse($spec['components']['schemas']['OrganizationUpdate']['additionalProperties']);
        $this->assertArrayNotHasKey('status', $spec['components']['schemas']['OrganizationUpdate']['properties']);
        $this->assertArrayNotHasKey('/organizations/{organization_id}/archive', $spec['paths']);
        $this->assertArrayNotHasKey('patch', $spec['paths']['/organizations/{organization_id}/members/{user_id}']);
    }
}
