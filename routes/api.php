<?php

use App\Http\Controllers\Api\CheckpointController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\GameMasterController;
use App\Http\Controllers\Api\GameSessionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\QrController;
use App\Http\Controllers\Api\RuntimeController;
use App\Http\Controllers\Api\ScenarioController;
use App\Http\Controllers\Api\ScoringController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Middleware\AuthenticateIdentity;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [HealthController::class, 'check']);
    Route::get('/live', [HealthController::class, 'live']);
    Route::get('/ready', [HealthController::class, 'ready']);
    Route::post('/auth/register', [IdentityController::class, 'register'])->middleware('throttle:identity-register')->name('identity.register');
    Route::post('/auth/login', [IdentityController::class, 'login'])->middleware('throttle:identity-login')->name('identity.login');
    Route::middleware(AuthenticateIdentity::class)->group(function (): void {
        Route::post('/locations', [LocationController::class, 'submit'])->middleware('throttle:location-submit')->name('locations.submit');
        Route::post('/locations/batch', [LocationController::class, 'submit'])->middleware('throttle:location-submit')->name('locations.batch');
        Route::get('/game-sessions/{session_id}/players/me/location-policy', [LocationController::class, 'policy'])->name('locations.policy');
        Route::get('/checkpoints', [CheckpointController::class, 'index'])->name('checkpoints.index');
        Route::post('/checkpoints', [CheckpointController::class, 'save'])->name('checkpoints.create');
        Route::get('/checkpoints/{checkpoint_id}', [CheckpointController::class, 'show'])->name('checkpoints.show');
        Route::patch('/checkpoints/{checkpoint_id}', [CheckpointController::class, 'save'])->name('checkpoints.update');
        Route::post('/qr/scan', [QrController::class, 'scan'])->middleware('throttle:qr-scan')->name('qr.scan');
        Route::get('/qr/triggers', [QrController::class, 'index'])->name('qr.triggers.index');
        Route::post('/qr/triggers', [QrController::class, 'create'])->name('qr.triggers.create');
        Route::get('/qr/triggers/{trigger_id}', [QrController::class, 'show'])->name('qr.triggers.show');
        foreach (['activate', 'deactivate', 'revoke'] as $action) {
            Route::post('/qr/triggers/{trigger_id}/'.$action, [QrController::class, 'lifecycle'])->defaults('action', $action)->name('qr.triggers.'.$action);
        }
        Route::get('/game-sessions/{session_id}/runtime', [RuntimeController::class, 'read'])->name('runtime.session');
        Route::get('/game-sessions/{session_id}/teams/{team_id}/runtime', [RuntimeController::class, 'read'])->name('runtime.team');
        Route::get('/game-sessions/{session_id}/players/me/runtime', [RuntimeController::class, 'read'])->name('runtime.self');
        Route::get('/game-sessions/{session_id}/leaderboard', [ScoringController::class, 'leaderboard'])->name('scores.leaderboard');
        Route::get('/game-sessions/{session_id}/teams/{team_id}/score', [ScoringController::class, 'team'])->name('scores.team');
        Route::get('/game-sessions/{session_id}/teams/{team_id}/score-events', [ScoringController::class, 'events'])->name('scores.events');
        Route::post('/game-sessions/{session_id}/teams/{team_id}/score-adjustments', [ScoringController::class, 'adjust'])->name('scores.adjust');
        Route::get('/game-sessions/{session_id}/teams/{team_id}/hints', [ScoringController::class, 'hints'])->name('hints.index');
        Route::post('/runtime/nodes/{node_instance_id}/hints/consume', [ScoringController::class, 'consumeHint'])->middleware('throttle:runtime-submit')->name('hints.consume');
        foreach (['start' => 'start_node', 'submit' => 'submit_action', 'complete' => 'complete_node'] as $path => $action) {
            Route::post('/runtime/nodes/{node_instance_id}/'.$path, [RuntimeController::class, 'mutate'])->middleware('throttle:runtime-submit')->name('runtime.'.$action);
        }
        foreach (['pause' => 'pause_team', 'resume' => 'resume_team'] as $path => $action) {
            Route::post('/game-sessions/{session_id}/teams/{team_id}/'.$path, [RuntimeController::class, 'team'])->name('runtime.'.$action);
        }
        Route::get('/scenarios', [ScenarioController::class, 'index'])->name('scenarios.index');
        Route::post('/scenarios', [ScenarioController::class, 'mutate'])->name('scenarios.create');
        Route::get('/scenarios/{scenario_id}', [ScenarioController::class, 'show'])->name('scenarios.show');
        Route::patch('/scenarios/{scenario_id}', [ScenarioController::class, 'mutate'])->name('scenarios.update');
        Route::get('/scenarios/{scenario_id}/versions', [ScenarioController::class, 'index'])->name('scenarios.versions');
        Route::post('/scenarios/{scenario_id}/versions', [ScenarioController::class, 'mutate'])->name('scenarios.createVersion');
        Route::get('/scenario-versions/{version_id}', [ScenarioController::class, 'show'])->name('scenarios.version');
        Route::patch('/scenario-versions/{version_id}', [ScenarioController::class, 'mutate'])->name('scenarios.updateVersion');
        Route::get('/scenario-versions/{version_id}/graph', [ScenarioController::class, 'show'])->name('scenarios.graph');
        Route::put('/scenario-versions/{version_id}/graph', [ScenarioController::class, 'mutate'])->name('scenarios.saveGraph');
        foreach (['validate', 'publish', 'duplicate', 'simulate'] as $action) {
            Route::post('/scenario-versions/{version_id}/'.$action, [ScenarioController::class, 'mutate'])->name('scenarios.'.$action);
        }
        Route::get('/games', [GameController::class, 'index'])->name('games.index');
        Route::post('/games', [GameController::class, 'create'])->name('games.create');
        Route::get('/games/{game_id}', [GameController::class, 'show'])->name('games.show');
        Route::patch('/games/{game_id}', [GameController::class, 'update'])->name('games.update');
        Route::post('/games/{game_id}/archive', [GameController::class, 'archive'])->name('games.archive');
        Route::get('/game-sessions', [GameSessionController::class, 'index'])->name('sessions.index');
        Route::post('/game-sessions', [GameSessionController::class, 'create'])->name('sessions.create');
        Route::get('/game-sessions/{session_id}', [GameSessionController::class, 'show'])->name('sessions.show');
        Route::patch('/game-sessions/{session_id}', [GameSessionController::class, 'update'])->name('sessions.update');
        Route::delete('/game-sessions/{session_id}/teams/{team_id}', [GameSessionController::class, 'removeTeam'])->name('sessions.removeTeam');
        foreach (['open-registration' => 'openRegistration', 'close-registration' => 'closeRegistration', 'register-team' => 'registerTeam', 'bind-device' => 'bindDevice', 'rebind-device' => 'rebindDevice', 'ready' => 'ready', 'start' => 'start', 'pause' => 'pause', 'resume' => 'resume', 'finish' => 'finish', 'cancel' => 'cancel'] as $path => $action) {
            Route::post('/game-sessions/{session_id}/'.$path, [GameSessionController::class, $action])->name('sessions.'.$action);
        }
        Route::get('/game-sessions/{session_id}/state', [GameSessionController::class, 'state'])->name('sessions.state');
        Route::get('/game-sessions/{session_id}/timeline', [GameSessionController::class, 'timeline'])->name('sessions.timeline');
        Route::get('/game-sessions/{session_id}/control-room', [GameMasterController::class, 'controlRoom'])->name('gamemaster.control-room');
        Route::post('/game-sessions/{session_id}/live-challenges', [GameMasterController::class, 'createChallenge'])->name('live-challenges.create');
        Route::get('/game-sessions/{session_id}/live-challenges', [GameMasterController::class, 'listChallenges'])->name('live-challenges.index');
        Route::get('/live-challenges/{challenge_id}', [GameMasterController::class, 'showChallenge'])->name('live-challenges.show');
        foreach (['ack', 'start', 'submit', 'cancel'] as $action) {
            Route::post('/live-challenges/{challenge_id}/'.$action, [GameMasterController::class, 'transition'])->defaults('action', $action)->name('live-challenges.'.$action);
        }
        Route::post('/game-sessions/{session_id}/messages', [GameMasterController::class, 'message'])->name('gamemaster.messages');
        Route::post('/game-sessions/{session_id}/players/{player_id}/rebind-device', [GameMasterController::class, 'rebindDevice'])->name('gamemaster.rebind-device');
        Route::post('/game-sessions/{session_id}/players/{player_id}/disqualify', [GameMasterController::class, 'disqualify'])->name('gamemaster.disqualify');
        Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [TeamController::class, 'create'])->name('teams.create');
        Route::post('/teams/join', [TeamController::class, 'join'])->name('teams.join');
        Route::get('/teams/{team_id}', [TeamController::class, 'show'])->name('teams.show');
        Route::patch('/teams/{team_id}', [TeamController::class, 'update'])->name('teams.update');
        Route::get('/teams/{team_id}/invites', [TeamController::class, 'invites'])->name('teams.invites');
        Route::post('/teams/{team_id}/invites', [TeamController::class, 'createInvite'])->name('teams.createInvite');
        Route::delete('/teams/{team_id}/invites/{invite_id}', [TeamController::class, 'revokeInvite'])->name('teams.revokeInvite');
        Route::post('/teams/{team_id}/leave', [TeamController::class, 'leave'])->name('teams.leave');
        Route::delete('/teams/{team_id}/members/{user_id}', [TeamController::class, 'remove'])->name('teams.remove');
        Route::post('/teams/{team_id}/transfer-captaincy', [TeamController::class, 'transfer'])->name('teams.transfer');
        Route::post('/teams/{team_id}/archive', [TeamController::class, 'archive'])->name('teams.archive');
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'create'])->name('organizations.create');
        Route::get('/organizations/{organization_id}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::patch('/organizations/{organization_id}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::get('/organizations/{organization_id}/members', [OrganizationController::class, 'members'])->name('organizations.members');
        Route::post('/organizations/{organization_id}/members', [OrganizationController::class, 'addMember'])->name('organizations.addMember');
        Route::delete('/organizations/{organization_id}/members/{user_id}', [OrganizationController::class, 'removeMember'])->name('organizations.removeMember');
        Route::post('/auth/logout', [IdentityController::class, 'logout'])->name('identity.logout');
        Route::get('/me', [IdentityController::class, 'me'])->name('identity.me');
        Route::patch('/me', [IdentityController::class, 'update'])->name('identity.update');
        Route::get('/devices', [IdentityController::class, 'devices'])->name('identity.devices');
        Route::delete('/devices/{device_id}', [IdentityController::class, 'revoke'])->name('identity.revoke');
    });
});
