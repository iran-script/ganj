<?php

namespace App\Http\Requests;

use App\Services\Teams\TeamInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GameRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    public function validated($key = null, $default = null): mixed
    {
        $action = $this->route()->getActionMethod();
        $rules = match ($action) {
            'index' => TeamInput::rules('index'),
            'create' => ['name' => ['required', 'string', 'min:2', 'max:120'], 'team_id' => ['required', 'uuid']],
            'addTeam' => ['team_id' => ['required', 'uuid']],
            default => [],
        };
        $values = $action === 'index' ? TeamInput::validate($this->all(), 'index') : Validator::make($this->all(), $rules)->validate();
        if (array_diff(array_keys($this->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['input' => 'Unknown room field.']);
        }
        if (isset($values['name']) && trim($values['name']) === '') {
            throw ValidationException::withMessages(['name' => 'Room name cannot be blank.']);
        }

        return $key === null ? $values : data_get($values, $key, $default);
    }
}
