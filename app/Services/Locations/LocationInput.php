<?php

namespace App\Services\Locations;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LocationInput
{
    public function sample(array $input): array
    {
        $rules = ['session_id' => 'required|uuid', 'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|between:0,100000', 'altitude' => 'sometimes|nullable|numeric|between:-12000,100000',
            'speed' => 'sometimes|nullable|numeric|between:0,10000', 'heading' => 'sometimes|nullable|numeric|min:0|lt:360',
            'client_timestamp' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'sequence' => 'required|integer|min:0|max:9007199254740991'];
        $this->validate($input, $rules);
        $timestamp = CarbonImmutable::parse($input['client_timestamp'])->utc();
        if ($timestamp->greaterThan(now()->addSeconds(config('location.future_tolerance_seconds')))) {
            throw new ApiException('LOCATION_INVALID', 'The observation timestamp is too far in the future.', [], 422);
        }
        foreach (['lat', 'lng', 'accuracy', 'altitude', 'speed', 'heading'] as $key) {
            $input[$key] = isset($input[$key]) ? (float) $input[$key] : null;
        }
        $input['sequence'] = (int) $input['sequence'];
        $input['client_timestamp'] = $timestamp->format('Y-m-d\TH:i:s.u\Z');
        $input['session_id'] = strtolower($input['session_id']);

        return $input;
    }

    public function policyRules(): array
    {
        return ['min_accuracy' => 'sometimes|numeric|min:1|max:1000', 'dwell_time' => 'sometimes|integer|min:0|max:86400',
            'required_player_count' => 'sometimes|integer|min:1|max:8',
            'required_roles' => 'sometimes|array|list|max:4', 'required_roles.*' => 'string|distinct|in:SCANNER,NAVIGATOR,DECODER,AR_OPERATOR',
            'required_capabilities' => 'sometimes|array|list|max:4', 'required_capabilities.*' => 'string|distinct|in:SCAN_QR,NAVIGATE,DECODE,OPERATE_AR'];
    }

    public function validate(array $input, array $rules): void
    {
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['payload' => ['Unsupported fields are not accepted.']]);
        }
        Validator::make($input, $rules)->validate();
    }
}
