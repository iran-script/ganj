<?php

namespace App\Services\Scenarios;

use App\Services\Teams\TeamInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScenarioInput
{
    public static function validate(array $input, string $action): array
    {
        if ($action === 'index') {
            return TeamInput::validate($input, 'index');
        }
        $reason = ['reason' => 'sometimes|nullable|string|max:1000'];
        $metadata = ['difficulty' => 'sometimes|nullable|string|max:100', 'estimated_duration_seconds' => 'sometimes|nullable|integer|min:1|max:2147483647',
            'estimated_distance_meters' => 'sometimes|nullable|numeric|min:0|max:10000000', 'physical_effort' => 'sometimes|nullable|string|max:100',
            'challenge_mix' => 'sometimes|array', 'challenge_mix.*' => 'numeric|min:0'];
        $revision = ['revision_token' => 'required|uuid'];
        $rules = match ($action) {
            'create' => ['organization_id' => 'required|uuid', 'title' => 'required|string|max:255', 'description' => 'sometimes|nullable|string|max:10000', 'theme' => 'sometimes|nullable|string|max:255'] + $reason,
            'update' => ['title' => 'sometimes|required|string|max:255', 'description' => 'sometimes|nullable|string|max:10000', 'theme' => 'sometimes|nullable|string|max:255'] + $reason,
            'createVersion' => $metadata + $reason,
            'updateVersion' => $metadata + $revision + $reason,
            'saveGraph' => $revision + ['nodes' => 'present|array|list|max:1000', 'edges' => 'present|array|list|max:5000', 'variables' => 'sometimes|array|list|max:500', 'conditions' => 'sometimes|array|list|max:2000', 'subflows' => 'sometimes|array|list|max:1000'] + $reason,
            'publish' => $revision + $reason,
            'validate','simulate' => ['revision_token' => 'sometimes|uuid'] + $reason,
            'duplicate','archive','archiveVersion' => $reason,
            'show','graph' => [],
            default => throw new \InvalidArgumentException('Unknown scenario action.'),
        };
        self::fields($input, $rules);
        if (isset($input['title']) && trim($input['title']) === '') {
            throw ValidationException::withMessages(['title' => 'The title field is required.']);
        }
        if (in_array($action, ['update', 'updateVersion'], true) && ! array_intersect(array_keys($input), array_diff(array_keys($rules), ['revision_token', 'reason']))) {
            throw ValidationException::withMessages(['input' => 'At least one editable field is required.']);
        }

        return Validator::make($input, $rules)->validate();
    }

    public static function fields(array $input, array $rules): void
    {
        foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
            throw ValidationException::withMessages([(string) $field => 'This field is not permitted.']);
        }
    }
}
