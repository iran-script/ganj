<?php

namespace App\Services\Scoring;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\HintConsumption;
use App\Models\HintDefinition;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Games\GameScope;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Runtime\RuntimeRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HintService
{
    public function __construct(private GameScope $scope, private IdempotencyStore $idempotency, private ScoringService $scoring, private ScoreEvents $events) {}

    public function consume(User $actor, ?UserDevice $device, string $nodeId, array $input, string $idempotencyKey): array
    {
        Validator::make(['key' => $idempotencyKey], ['key' => 'required|string|min:8|max:255'])->validate();
        Validator::make($input, [])->validate();
        $fingerprint = hash('sha256', json_encode(['actor' => $actor->public_id, 'device' => $device?->public_id, 'node' => $nodeId], JSON_THROW_ON_ERROR));
        $key = 'hint:'.hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($actor, $device, $nodeId, $key, $fingerprint): array {
            $node = TeamNodeInstance::where('public_id', $nodeId)->with(['instance.session', 'node'])->first();
            if (! $node) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Node was not found.', [], 404);
            }
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail()->ensureActive();
            $session = $this->scope->resolve($actor, $node->instance->session->public_id, true, true);
            TeamScenarioInstance::whereKey($node->team_scenario_instance_id)->lockForUpdate()->firstOrFail();
            $node = TeamNodeInstance::whereKey($node->id)->lockForUpdate()->firstOrFail();
            $player = GameSessionPlayer::where('game_session_id', $session->id)->where('user_id', $actor->id)->where('game_session_team_id', $node->instance->game_session_team_id)->lockForUpdate()->first();
            if (! $player || in_array($player->participation_status, ['REMOVED', 'DISQUALIFIED'], true)) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'The Session participation was not found.', [], 404);
            }
            $bound = UserDevice::whereKey($device?->id)->where('user_id', $actor->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if (! $bound || $player->active_device_id !== $bound->id || $player->roster_frozen_at === null) {
                throw new ApiException('DEVICE_NOT_BOUND', 'The Device is not bound to this Player.', [], 403);
            }
            $reserved = $this->idempotency->reserve($key, $fingerprint);
            if ($reserved['status'] === 'completed') {
                return $reserved['response']['data'];
            }
            app(RuntimeRoles::class)->assertSubgroupEligible($node, $player);
            if ($session->status !== 'RUNNING' || $node->instance->status !== 'ACTIVE' || $node->status !== 'ACTIVE') {
                throw new ApiException('ACTION_NOT_ALLOWED', 'Hints require an active Runtime Node.', [], 409);
            }
            $used = HintConsumption::where('node_instance_id', $node->id)->where('source', 'DEFAULT')->count();
            $definitions = $node->node->config['hints'] ?? [];
            if ($used >= 3 || ! is_array($definitions) || ! isset($definitions[$used])) {
                throw new ApiException('HINT_LIMIT_REACHED', 'No built-in hint is available.', [], 409);
            }
            $hintNo = $used + 1;
            HintDefinition::query()->insertOrIgnore(['scenario_node_id' => $node->scenario_node_id, 'hint_no' => $hintNo, 'content' => (string) $definitions[$used], 'created_at' => now()]);
            $hint = HintDefinition::where(['scenario_node_id' => $node->scenario_node_id, 'hint_no' => $hintNo])->sole();
            $consumption = new HintConsumption;
            $consumption->forceFill(['consumption_id' => (string) Str::uuid(), 'game_session_id' => $session->id, 'team_id' => $player->game_session_team_id,
                'player_id' => $player->id, 'node_instance_id' => $node->id, 'hint_definition_id' => $hint->id, 'source' => 'DEFAULT',
                'penalty_score' => (int) ($hint->penalty_score ?? 0), 'penalty_seconds' => (int) ($hint->penalty_seconds ?? 0),
                'idempotency_key' => 'node:'.$node->public_id.':hint:'.$hintNo, 'consumed_at' => now()])->save();
            $node->forceFill(['state_json' => array_replace($node->state_json ?? [], ['hints_used' => $hintNo])])->save();
            $instance = $node->instance;
            $this->scoring->apply($instance, $player, $node, -abs($consumption->penalty_score), 'HINT_PENALTY', 'Hint consumed', 'hint-penalty:'.$consumption->consumption_id);
            $score = $this->scoring->scoreFor($instance->session->id, $instance->registration->id);
            $score->forceFill(['hints_used' => $score->hints_used + 1, 'updated_at' => now()])->save();
            $this->events->record('hint.default_consumed.v1', ['session_id' => $session->public_id, 'team_id' => $instance->registration->team->public_id, 'player_id' => $player->public_id, 'node_instance_id' => $node->public_id, 'hint_no' => $hintNo, 'penalty_score' => $consumption->penalty_score, 'penalty_seconds' => $consumption->penalty_seconds], $instance->registration->team->public_id, $actor->id, $session->organization_id);

            $result = ['hint_no' => $hintNo, 'content' => $hint->content, 'penalty_score' => $consumption->penalty_score, 'penalty_seconds' => $consumption->penalty_seconds];
            $this->idempotency->complete($key, $fingerprint, 200, ['data' => $result]);

            return $result;
        });
    }

    public function list(User $actor, string $sessionId, string $teamId): array
    {
        $session = $this->scope->resolve($actor, $sessionId, true);
        $registration = $session->teams()->whereHas('team', fn ($query) => $query->where('public_id', $teamId))->first();
        if (! $registration) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }
        $player = $session->players()->where('user_id', $actor->id)->where('game_session_team_id', $registration->id)->first();
        if (! $player && ! $this->scope->operator($actor, $session)) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }

        return HintConsumption::where('game_session_id', $session->id)->where('team_id', $registration->id)->get()->map(fn (HintConsumption $consumption): array => ['node_instance_id' => TeamNodeInstance::find($consumption->node_instance_id)?->public_id, 'hint_no' => HintDefinition::find($consumption->hint_definition_id)?->hint_no, 'source' => $consumption->source, 'consumed_at' => $consumption->consumed_at?->toISOString()])->all();
    }
}
