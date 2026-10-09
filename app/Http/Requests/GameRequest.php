<?php

namespace App\Http\Requests;

use App\Services\Games\GameInput;
use Illuminate\Foundation\Http\FormRequest;

class GameRequest extends FormRequest
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
        if (in_array($action, ['create', 'update'], true)) {
            $action = (str_starts_with($this->route()->getName(), 'games.') ? 'game' : 'session').ucfirst($action);
        }
        $values = GameInput::validate($this->all(), $action);

        return $key === null ? $values : data_get($values, $key, $default);
    }
}
