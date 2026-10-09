<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use GameSessionFixtures,RefreshDatabase;

    public function test_game_create_activate_update_archive_and_historical_read_use_public_ids(): void
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $org->id, 'user_id' => $actor->id]);
        $this->authenticateGame($actor);
        $data = ['organization_id' => $org->public_id, 'name' => 'NEXO walk', 'slug' => 'nexo-walk', 'default_timezone' => 'Asia/Tehran'];
        $result = $this->postJson('/api/v1/games', $data)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.organization_id', $org->public_id);
        $id = $result->json('data.id');
        $this->assertNotSame((string) Game::sole()->id, $id);
        $this->patchJson('/api/v1/games/'.$id, ['status' => 'ACTIVE', 'description' => 'An urban adventure'])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->patchJson('/api/v1/games/'.$id, ['status' => 'DRAFT'])->assertConflict();
        $this->postJson('/api/v1/games/'.$id.'/archive')->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->postJson('/api/v1/games/'.$id.'/archive')->assertOk();
        $this->getJson('/api/v1/games/'.$id)->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->patchJson('/api/v1/games/'.$id, ['name' => 'No'])->assertConflict();
        $this->assertDatabaseCount('games', 1);
        foreach (['game.created.v1', 'game.activated.v1', 'game.updated.v1', 'game.archived.v1'] as $type) {
            $this->assertDatabaseHas('outbox_events', ['topic' => $type]);
        }
    }

    public function test_tenant_ownership_injection_and_cross_tenant_reads_are_denied(): void
    {
        [$actor,$own] = $this->sessionFixture(false);
        $other = Game::factory()->create();
        $this->authenticateGame($actor);
        $this->getJson('/api/v1/games/'.$other->public_id)->assertNotFound();
        $this->getJson('/api/v1/games/'.$other->id)->assertNotFound();
        $this->patchJson('/api/v1/games/'.$own->public_id, ['organization_id' => $other->organization->public_id])->assertUnprocessable();
        $this->postJson('/api/v1/games', ['organization_id' => $other->organization->public_id, 'name' => 'Stolen', 'slug' => 'stolen', 'default_timezone' => 'UTC'])->assertNotFound();
        $this->getJson('/api/v1/games')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->public_id);
    }

    public function test_player_can_read_active_game_but_cannot_manage_or_create_games(): void
    {
        $game = Game::factory()->create();
        $this->authenticateGame();
        $this->getJson('/api/v1/games/'.$game->public_id)->assertOk();
        $this->patchJson('/api/v1/games/'.$game->public_id, ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/games/'.$game->public_id.'/archive')->assertForbidden();
        $this->postJson('/api/v1/games', ['organization_id' => $game->organization->public_id, 'name' => 'Denied', 'slug' => 'denied', 'default_timezone' => 'UTC'])->assertNotFound();
    }

    public static function invalidGameInputs(): array
    {
        return [['name', ''], ['slug', 'Bad Slug'], ['default_timezone', 'Moon/Base'], ['captain_user_id', '123'], ['status', 'RUNNING']];
    }

    #[DataProvider('invalidGameInputs')]
    public function test_game_validation_rejects_invalid_or_unsafe_fields(string $field, mixed $value): void
    {
        [$actor,$game] = $this->sessionFixture(false);
        $this->authenticateGame($actor);
        $this->patchJson('/api/v1/games/'.$game->public_id, [$field => $value])->assertUnprocessable();
    }
}
