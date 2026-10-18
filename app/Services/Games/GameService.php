<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GameService
{
    public function __construct(private GameScope $scope, private OrganizationScope $organizations, private GameEvents $events, private IdempotencyStore $idempotency, private ProductionSessionDependencies $configuration) {}

    public function execute(User $actor, string $action, array $input, ?string $id = null, ?string $key = null): array
    {
        return DB::transaction(function () use ($actor, $action, $input, $id, $key): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $input = GameInput::validate($input, $action === 'create' ? 'gameCreate' : ($action === 'update' ? 'gameUpdate' : 'archive'));
            if ($action === 'create') {
                $context = $this->organizations->resolve($actor, $input['organization_id'], true);
                $context->authorize('update');
                $game = new Game;
                $game->forceFill(['organization_id' => $context->organization->id]);
                $game->setRelation('organization', $context->organization);
            } else {
                $game = $this->scope->resolve($actor, $id ?? '', false, true);
                Gate::forUser($actor)->authorize('manage', $game);
            }
            if ($this->organizations->isSuperAdmin($actor)) {
                GameInput::reason($input);
            }
            if ($game->organization->status !== 'ACTIVE') {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The organization is archived.', [], 409);
            }
            $fingerprint = '';
            if ($key !== null) {
                Validator::make(['key' => $key], ['key' => 'required|string|min:8|max:255'])->validate();
                $sorted = $input;
                ksort($sorted);
                $fingerprint = hash('sha256', json_encode([$action, $id, $sorted], JSON_THROW_ON_ERROR));
                $key = 'games:'.$actor->public_id.':'.hash('sha256', $key);
                $saved = $this->idempotency->reserve($key, $fingerprint);
                if ($saved['status'] === 'completed') {
                    return $saved['response']['data'];
                }
                if ($saved['status'] !== 'reserved') {
                    throw new ApiException('CONFLICT', 'Operation pending.', [], 409);
                }
            }
            if ($game->status === 'ARCHIVED' && $action !== 'archive') {
                throw new ApiException('STATE_TRANSITION_INVALID', 'Archived games cannot be edited.', [], 409);
            }
            $oldStatus = $game->status;
            if ($action === 'archive') {
                if (DB::table('game_sessions')->where('game_id', $game->id)->whereIn('status', ['REGISTRATION', 'READY', 'RUNNING', 'PAUSED'])->exists()) {
                    throw new ApiException('DEPENDENCY_ACTIVE', 'این بازی اجرای فعال دارد و فعلاً قابل بایگانی نیست.', [], 409);
                }
                if ($game->status !== 'ARCHIVED') {
                    $game->forceFill(['status' => 'ARCHIVED', 'archived_at' => now()])->save();
                    $this->events->record('game.archived.v1', $actor, $game, reason: $input['reason'] ?? null);
                }
            } else {
                if ($oldStatus === 'ACTIVE' && ($input['status'] ?? 'ACTIVE') === 'DRAFT') {
                    throw new ApiException('STATE_TRANSITION_INVALID', 'Active games cannot return to draft.', [], 409);
                }
                $values = array_intersect_key($input, array_flip(['name', 'slug', 'description', 'city', 'status', 'default_timezone']));
                if (isset($values['slug']) && Game::where('organization_id', $game->organization_id)->where('slug', $values['slug'])->when($game->exists, fn (Builder $q): Builder => $q->whereKeyNot($game->id))->exists()) {
                    throw ValidationException::withMessages(['slug' => 'This slug is already used in the organization.']);
                }
                if (array_key_exists('pricing', $input)) {
                    GameInput::reason($input);
                    $values['pricing'] = $this->configuration->validatePricing($input['pricing']);
                }
                if (isset($input['scenario_version_id'])) {
                    $version = ScenarioVersion::where('public_id', $input['scenario_version_id'])->first();
                    $values['scenario_version_id'] = $this->configuration->publishedVersion((int) $version?->id, $game->organization_id)->id;
                }
                $game->forceFill($values)->save();
                $this->events->record($action === 'create' ? 'game.created.v1' : 'game.updated.v1', $actor, $game, reason: $input['reason'] ?? null);
                if ($game->status === 'ACTIVE' && $oldStatus !== 'ACTIVE') {
                    $this->events->record('game.activated.v1', $actor, $game);
                }
            }
            $result = $game->publicProfile();
            if ($key !== null) {
                $this->idempotency->complete($key, $fingerprint, $action === 'create' ? 201 : 200, ['data' => $result]);
            }

            return $result;
        }, 3);
    }
}
