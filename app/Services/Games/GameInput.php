<?php

namespace App\Services\Games;

use App\Services\Teams\TeamInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GameInput
{
    public static function validate(array $input, string $action): array
    {
        $reason = ['sometimes', 'nullable', 'string', 'max:1000'];
        $game = ['name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'city' => ['sometimes', 'nullable', 'string', 'max:120'], 'default_timezone' => ['required', 'timezone'], 'status' => ['sometimes', 'in:DRAFT,ACTIVE']];
        $game += ['pricing' => ['sometimes', 'array:mode,amount,currency,booking_available,payment_deadline_minutes,refund_policy,cancellation_policy,max_active_reservations,effective_at'], 'scenario_version_id' => ['sometimes', 'uuid']];
        $session = ['timezone' => ['sometimes', 'timezone'], 'registration_opens_at' => ['sometimes', 'nullable', 'date'], 'registration_closes_at' => ['sometimes', 'nullable', 'date'], 'scheduled_start_at' => ['sometimes', 'nullable', 'date']];
        if ($action === 'index') {
            return TeamInput::validate($input, 'index');
        }
        if ($action === 'timeline') {
            $values = Validator::make($input, TeamInput::rules('index'))->validate();
            if (array_diff(array_keys($input), ['limit', 'cursor'])) {
                throw ValidationException::withMessages(['input' => 'Unknown pagination field.']);
            }
            if (isset($values['cursor'])) {
                $cursor = json_decode(base64_decode(strtr($values['cursor'], '-_', '+/'), true) ?: '', true);
                if (! is_array($cursor) || count($cursor) !== 3 || ! is_bool($cursor['_pointsToNextItems'] ?? null) || ! Str::isUuid($cursor['public_id'] ?? '') || ! is_string($cursor['occurred_at'] ?? null) || strtotime($cursor['occurred_at']) === false) {
                    throw ValidationException::withMessages(['cursor' => 'The timeline cursor is invalid.']);
                }
            }

            return $values;
        }
        if ($action === 'gameUpdate') {
            foreach ($game as &$rule) {
                if ($rule[0] === 'required') {
                    $rule[0] = 'sometimes';
                }
            }
            unset($rule);
        }
        $rules = match ($action) {
            'gameCreate' => ['organization_id' => ['required', 'uuid']] + $game + ['reason' => $reason],
            'gameUpdate' => $game + ['reason' => $reason],
            'sessionCreate' => ['game_id' => ['required', 'uuid']] + $session + ['reason' => $reason],
            'sessionUpdate' => $session + ['reason' => $reason],
            'registerTeam' => ['team_id' => ['required', 'uuid'], 'reason' => $reason],
            'bindDevice','rebindDevice' => ['device_id' => ['required', 'uuid'], 'user_id' => ['sometimes', 'uuid'], 'reason' => $reason],
            'ready' => ['ready' => ['required', 'boolean']],
            'cancel' => ['reason' => ['required', 'string', 'max:1000'], 'emergency' => ['sometimes', 'boolean']],
            'show','state' => [],
            default => ['reason' => $reason],
        };
        $validator = Validator::make($input, $rules);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($input, $rules): void {
            foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
                $v->errors()->add($field, 'This field is not permitted.');
            }
            foreach (['name', 'reason'] as $field) {
                if (isset($input[$field]) && is_string($input[$field]) && trim($input[$field]) === '') {
                    $v->errors()->add($field, 'This field cannot be blank.');
                }
            }
        });

        return $validator->validate();
    }

    public static function reason(array $input): string
    {
        if (! is_string($input['reason'] ?? null) || trim($input['reason']) === '') {
            throw ValidationException::withMessages(['reason' => 'An operational reason is required.']);
        }

        return $input['reason'];
    }
}
