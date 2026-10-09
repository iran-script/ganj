<?php

namespace App\Services\Idempotency;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function reserve(string $key, string $fingerprint, int $ttlSeconds = 86400): array
    {
        $now = now();
        $expires = $now->copy()->addSeconds($ttlSeconds);
        $inserted = DB::table('idempotency_keys')->insertOrIgnore(['key' => $key, 'fingerprint' => $fingerprint, 'status' => 'reserved', 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now]);
        if ($inserted === 1) {
            return ['status' => 'reserved'];
        }
        $row = DB::table('idempotency_keys')->where('key', $key)->first();
        if (! $row || $row->expires_at < $now) {
            DB::table('idempotency_keys')->where('key', $key)->delete();

            return $this->reserve($key, $fingerprint, $ttlSeconds);
        }
        if (! hash_equals($row->fingerprint, $fingerprint)) {
            throw new ApiException('IDEMPOTENCY_CONFLICT', 'The idempotency key was used for a different request.', [], 409);
        }

        return ['status' => $row->status, 'response' => $row->response_payload ? json_decode($row->response_payload, true) : null, 'http_status' => $row->response_status];
    }

    public function complete(string $key, string $fingerprint, int $status, array $response): void
    {
        DB::table('idempotency_keys')->where('key', $key)->where('fingerprint', $fingerprint)->update(['status' => 'completed', 'response_status' => $status, 'response_payload' => json_encode($response), 'updated_at' => now()]);
    }
}
