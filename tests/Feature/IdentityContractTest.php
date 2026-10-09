<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class IdentityContractTest extends TestCase
{
    public function test_openapi_identity_paths_and_references_are_valid(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('201', $spec['paths']['/auth/register']['post']['responses']);
        $this->assertArrayHasKey('204', $spec['paths']['/auth/logout']['post']['responses']);
        $this->assertArrayHasKey('204', $spec['paths']['/devices/{device_id}']['delete']['responses']);
        $this->assertFalse($spec['components']['schemas']['IdentityRegistration']['additionalProperties']);
        $this->assertFalse($spec['paths']['/me']['patch']['requestBody']['content']['application/json']['schema']['additionalProperties']);
        array_walk_recursive($spec, function ($value, $key) use ($spec): void {
            if ($key === '$ref' && str_starts_with($value, '#/')) {
                $target = $spec;
                foreach (explode('/', substr($value, 2)) as $part) {
                    $this->assertArrayHasKey($part, $target, 'Unresolved reference: '.$value);
                    $target = $target[$part];
                }
            }
        });
    }
}
