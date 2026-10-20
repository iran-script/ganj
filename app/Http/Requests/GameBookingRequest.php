<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GameBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return $this->route()->getActionMethod() === 'create'
            ? ['team_id' => ['required', 'uuid'], 'room_name' => ['required', 'string', 'min:1', 'max:120']]
            : [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
            if (isset($this->all()['room_name']) && is_string($this->input('room_name')) && trim($this->input('room_name')) === '') {
                $validator->errors()->add('room_name', 'The room name cannot be blank.');
            }
        }];
    }
}
