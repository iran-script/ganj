<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use Tests\TestCase;

class ApiExceptionTest extends TestCase
{
    public function test_api_exception_is_available(): void
    {
        $this->assertSame('TEST', (new ApiException('TEST', 'message'))->errorCode);
    }
}
