<?php

namespace App\Http\Requests;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class AdminManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'createUser' => [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
                'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
                'password' => ['required', 'string', 'min:12', 'max:72'],
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
            ],
            'updateUser' => [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
                'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
            ],
            'resetPassword' => ['password' => ['required', 'string', 'min:12', 'max:72'], 'reason' => ['required', 'string', 'min:3', 'max:1000']],
            'deleteUser' => ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            'restoreUser' => ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            'setStatus' => ['status' => ['required', Rule::enum(UserStatus::class)], 'reason' => ['required', 'string', 'min:3', 'max:1000']],
            'grantAdministrator', 'revokeAdministrator' => ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            'users' => $this->paginationRules() + [
                'q' => ['sometimes', 'nullable', 'string', 'max:255'],
                'status' => ['sometimes', Rule::enum(UserStatus::class)],
                'role' => ['sometimes', 'string', 'max:32'],
                'organization_id' => ['sometimes', 'uuid'],
            ],
            'administrators' => $this->paginationRules() + ['q' => ['sometimes', 'nullable', 'string', 'max:255']],
            'auditEvents' => [
                'q' => ['sometimes', 'nullable', 'string', 'max:255'],
                'action' => ['sometimes', 'string', 'max:128'],
                'resource_type' => ['sometimes', 'string', 'max:128'],
                'actor_id' => ['sometimes', 'uuid'],
                'organization_id' => ['sometimes', 'uuid'],
                'from' => ['sometimes', 'date'],
                'to' => ['sometimes', 'date', 'after_or_equal:from'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ] + ['limit' => ['sometimes', 'integer', 'min:1', 'max:100']],
            'index' => $this->paginationRules() + ['q' => ['sometimes', 'nullable', 'string', 'max:255'], 'status' => ['sometimes', Rule::enum(UserStatus::class)]],
            default => [],
        };
    }

    private function paginationRules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
            if ($validator->errors()->isEmpty() && is_string($this->input('cursor'))) {
                $decoded = json_decode(base64_decode(strtr($this->input('cursor'), '-_', '+/'), true) ?: '', true);
                if (! is_array($decoded) || count($decoded) !== 2 || ! is_bool($decoded['_pointsToNextItems'] ?? null)
                    || ! is_string($decoded['public_id'] ?? null) || ! Str::isUuid($decoded['public_id'])) {
                    throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
                }
            }
        }];
    }
}
