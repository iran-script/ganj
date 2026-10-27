<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

class OrganizationInput
{
    /** @return array<string, array> */
    public static function rules(string $action): array
    {
        $reason = ['sometimes', 'nullable', 'string', 'max:1000'];

        return match ($action) {
            'create' => ['name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'timezone_default' => ['sometimes', 'required', 'timezone', 'max:64'], 'reason' => $reason],
            'update' => ['name' => ['sometimes', 'required', 'string', 'max:255'], 'slug' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'timezone_default' => ['sometimes', 'required', 'timezone', 'max:64'], 'reason' => $reason],
            'addMember' => ['user_id' => ['required', 'uuid'], 'role' => ['required', Rule::enum(OrganizationRole::class)], 'reason' => $reason],
            'removeMember' => ['reason' => $reason],
            'changeRole' => ['role' => ['required', Rule::enum(OrganizationRole::class)], 'reason' => ['required', 'string', 'max:1000']],
            'setMemberStatus' => ['status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])], 'reason' => ['required', 'string', 'min:3', 'max:1000']],
            'archive' => ['reason' => ['required', 'string', 'max:1000']],
            'index' => ['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:512']],
            'members' => ['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:512'], 'q' => ['sometimes', 'nullable', 'string', 'max:255'], 'role' => ['sometimes', 'string', 'regex:/^(ORGANIZATION_ADMIN|GAMEMASTER|STAFF)(,(ORGANIZATION_ADMIN|GAMEMASTER|STAFF))*$/'], 'status' => ['sometimes', 'string', 'regex:/^(ACTIVE|SUSPENDED|LEFT|REMOVED)(,(ACTIVE|SUSPENDED|LEFT|REMOVED))*$/']],
            default => [],
        };
    }

    /** @param array<string, mixed> $input */
    public static function validate(array $input, string $action): array
    {
        $rules = self::rules($action);
        $validator = Validator::make($input, $rules);
        $validator->after(function (ValidationValidator $validator) use ($input, $rules, $action): void {
            foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
            if ($action === 'update' && ! array_intersect(['name', 'slug', 'timezone_default'], array_keys($input))) {
                $validator->errors()->add('name', 'At least one organization field is required.');
            }
        });
        $validated = $validator->validate();
        if (isset($validated['cursor'])) {
            $column = $action === 'members' ? 'cursor_user_id' : 'public_id';
            $decoded = json_decode(base64_decode(strtr($validated['cursor'], '-_', '+/'), true) ?: '', true);
            if (! is_array($decoded) || count($decoded) !== 2 || ! is_bool($decoded['_pointsToNextItems'] ?? null) || ! is_string($decoded[$column] ?? null) || ! Str::isUuid($decoded[$column]) || ! Cursor::fromEncoded($validated['cursor'])) {
                throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
            }
        }

        return $validated;
    }
}
