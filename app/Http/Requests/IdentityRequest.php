<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('device_uuid'))) {
            $this->merge(['device_uuid' => strtolower($this->input('device_uuid'))]);
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('patch')) {
            return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'], 'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/']];
        }
        $rules = ['email' => ['nullable', 'required_without:mobile', 'email:rfc', 'max:255'], 'mobile' => ['nullable', 'required_without:email', 'string', 'regex:/^\+?[0-9]{7,15}$/'], 'password' => ['required', 'string', 'max:72'], 'device_uuid' => ['required', 'uuid'], 'device_name' => ['required', 'string', 'max:255'], 'platform' => ['required', 'string', 'max:32'], 'app_version' => ['required', 'string', 'max:64']];
        if ($this->routeIs('identity.register')) {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['password'][] = 'min:12';
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
            if (is_string($this->input('password')) && strlen($this->input('password')) > 72) {
                $validator->errors()->add('password', 'The password must not exceed 72 bytes.');
            }
        }];
    }
}
