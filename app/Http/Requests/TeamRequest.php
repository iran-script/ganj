<?php

namespace App\Http\Requests;

use App\Services\Teams\TeamInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'invitePlayer' => ['user_id' => ['required', 'uuid'], 'expires_at' => ['sometimes', 'nullable', 'date', 'after:now']],
            default => TeamInput::rules($this->route()->getActionMethod()),
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                TeamInput::validate($this->all(), $this->route()->getActionMethod());
            }
        }];
    }
}
