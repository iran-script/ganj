<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\RuntimeVariable;
use App\Models\ScenarioVariable;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Scenarios\GraphValidator;
use Illuminate\Support\Facades\DB;

class RuntimeVariables
{
    public function __construct(private GraphValidator $validator, private RuntimeEvents $events) {}

    public function initialize(TeamScenarioInstance $instance): void
    {
        foreach (ScenarioVariable::where('scenario_version_id', $instance->scenario_version_id)->get() as $definition) {
            $contexts = match ($definition->scope) {
                'PLAYER' => $instance->registration->players()->pluck('id')->map(fn (int $id): array => ['player_id' => $id])->all(),
                'NODE' => TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->pluck('id')->map(fn (int $id): array => ['node_instance_id' => $id])->all(),
                default => [[]],
            };
            foreach ($contexts as $context) {
                (new RuntimeVariable)->forceFill($context + ['team_scenario_instance_id' => $instance->id, 'key' => $definition->key,
                    'value' => $definition->default_value, 'value_type' => $definition->type, 'updated_at' => now()])->save();
            }
        }
    }

    /** Trusted handler/system API only; callers own the runtime command and aggregate lock. */
    public function write(TeamScenarioInstance $instance, string $key, mixed $value, ?GameSessionPlayer $player = null, ?TeamNodeInstance $node = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Variable writes require the command transaction.');
        }
        $instance = TeamScenarioInstance::whereKey($instance->id)->lockForUpdate()->sole();
        $definition = ScenarioVariable::where('scenario_version_id', $instance->scenario_version_id)->where('key', $key)->first();
        if (! $definition || ! $definition->writable || ! $this->validator->matches($definition->type, $value)
            || ($definition->scope === 'PLAYER') !== ($player !== null) || ($definition->scope === 'NODE') !== ($node !== null)
            || ($player && $player->game_session_team_id !== $instance->game_session_team_id)
            || ($node && $node->team_scenario_instance_id !== $instance->id)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Invalid variable type, scope or write permission.', [], 409);
        }
        $variable = RuntimeVariable::where('team_scenario_instance_id', $instance->id)->where('key', $key)
            ->where('player_id', $player?->id)->where('node_instance_id', $node?->id)->lockForUpdate()->sole();
        if ($variable->value !== $value) {
            $variable->forceFill(['value' => $value, 'updated_at' => now()])->save();
            $this->events->record('variable_changed', $instance, $node, data: ['key' => $key, 'scope' => $definition->scope]);
        }
    }

    public function read(TeamScenarioInstance $instance, string $key, ?GameSessionPlayer $player, ?TeamNodeInstance $node): mixed
    {
        $definition = ScenarioVariable::where('scenario_version_id', $instance->scenario_version_id)->where('key', $key)->first();
        if (! $definition) {
            return null;
        }

        return RuntimeVariable::where('team_scenario_instance_id', $instance->id)->where('key', $key)
            ->where('player_id', $definition->scope === 'PLAYER' ? $player?->id : null)
            ->where('node_instance_id', $definition->scope === 'NODE' ? $node?->id : null)->first()?->value;
    }
}
