<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'captain_user_id' => User::factory(), 'status' => 'ACTIVE'];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Team $team): void {
            TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $team->captain_user_id]);
        });
    }

    /** Captain and initial membership must commit together to satisfy the deferred constraint. */
    public function create(mixed $attributes = [], ?Model $parent = null): Model|Collection
    {
        return DB::transaction(fn (): Model|Collection => parent::create($attributes, $parent));
    }
}
