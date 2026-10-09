<?php

namespace App\Services\Teams;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamInput
{
    /** @return array<string, array> */
    public static function rules(string $action): array
    {
        $reason = ['sometimes', 'nullable', 'string', 'max:1000'];

        return match ($action) {
            'create' => ['name' => ['required', 'string', 'max:255'], 'avatar_media_id' => ['sometimes', 'prohibited']],
            'update' => ['name' => ['sometimes', 'required', 'string', 'max:255'], 'avatar_media_id' => ['sometimes', 'prohibited'], 'reason' => $reason],
            'createInvite' => ['expires_at' => ['sometimes', 'nullable', 'date', 'after:now'], 'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'], 'reason' => $reason],
            'join' => ['invite_code' => ['required', 'string', 'max:256']],
            'transfer' => ['user_id' => ['required', 'uuid'], 'reason' => $reason],
            'archive', 'remove', 'revokeInvite' => ['reason' => $reason],
            'index', 'invites' => ['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:512']],
            default => [],
        };
    }

    /** @param array<string, mixed> $input */
    public static function validate(array $input, string $action): array
    {
        $rules = self::rules($action);
        $validator = Validator::make($input, $rules);
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($input, $rules, $action): void {
            foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
            if (array_key_exists('name', $input) && is_string($input['name']) && trim($input['name']) === '') {
                $validator->errors()->add('name', 'The name field is required.');
            }
            if ($action === 'update' && ! array_key_exists('name', $input)) {
                $validator->errors()->add('name', 'At least one editable team field is required.');
            }
            if (array_key_exists('avatar_media_id', $input) && $input['avatar_media_id'] !== null) {
                $validator->errors()->add('avatar_media_id', 'Media references are not available in this phase.');
            }
        });
        $validated = $validator->validate();
        if (isset($validated['cursor'])) {
            $decoded = json_decode(base64_decode(strtr($validated['cursor'], '-_', '+/'), true) ?: '', true);
            if (! is_array($decoded) || count($decoded) !== 2 || ! is_bool($decoded['_pointsToNextItems'] ?? null) || ! is_string($decoded['public_id'] ?? null) || ! Str::isUuid($decoded['public_id'])) {
                throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
            }
        }

        return $validated;
    }
}
