<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use Illuminate\Support\Collection;

class RoleAssignmentPolicy
{
    public const SOURCES = ['ROTATION', 'LEAST_RECENTLY_USED', 'SEEDED_RANDOM', 'SCENARIO_FIXED', 'GAMEMASTER_OVERRIDE', 'LATE_JOIN_POLICY'];

    public function __construct(private RoleFairnessService $fairness) {}

    /** @return Collection<int, GameSessionPlayer> */
    public function players(TeamScenarioInstance $instance, TeamNodeInstance $node, array $roles): Collection
    {
        $source = $node->node->config['assignment_policy'] ?? 'LEAST_RECENTLY_USED';
        if (! in_array($source, self::SOURCES, true)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The role assignment policy is unavailable.', [], 409);
        }

        $eligible = $instance->registration->players()->whereIn('participation_status', ['READY', 'ACTIVE', 'DISCONNECTED'])->get();
        if ($source === 'SCENARIO_FIXED') {
            $ids = $node->node->config['player_ids'] ?? [];
            $fixed = $eligible->whereIn('public_id', $ids)->values();
            if ($fixed->count() < count($roles) || $fixed->pluck('public_id')->unique()->count() !== $fixed->count()) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The fixed role assignment is not eligible.', [], 409);
            }

            return $fixed;
        }

        return $this->fairness->order($instance, $node, $eligible, $source === 'SEEDED_RANDOM');
    }
}
