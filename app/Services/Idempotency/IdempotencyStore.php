<?php

namespace App\Services\Idempotency;

interface IdempotencyStore
{
    public function reserve(string $key, string $fingerprint, int $ttlSeconds = 86400): array;

    public function complete(string $key, string $fingerprint, int $status, array $response): void;
}
