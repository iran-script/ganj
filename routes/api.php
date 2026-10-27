<?php

use App\Http\Controllers\Api\AdminManagementController;
use App\Http\Controllers\Api\CheckpointController;
use App\Http\Controllers\Api\ContentBankController;
use App\Http\Controllers\Api\GameBookingController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\GameMasterController;
use App\Http\Controllers\Api\GameRoomController;
use App\Http\Controllers\Api\GameSessionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PlayerManagementController;
use App\Http\Controllers\Api\QrController;
use App\Http\Controllers\Api\RuntimeController;
use App\Http\Controllers\Api\ScenarioController;
use App\Http\Controllers\Api\ScoringController;
use App\Http\Controllers\Api\SubmissionController;
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
        Route::get('/me/capabilities', [AdminManagementController::class, 'capabilities'])->name('identity.capabilities');
        Route::middleware('throttle:admin-sensitive')->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/users', [AdminManagementController::class, 'users'])->name('users.index');
            Route::post('/users', [AdminManagementController::class, 'createUser'])->name('users.create');
            Route::get('/users/{user_id}', [AdminManagementController::class, 'user'])->whereUuid('user_id')->name('users.show');
            Route::patch('/users/{user_id}', [AdminManagementController::class, 'updateUser'])->whereUuid('user_id')->name('users.update');
            Route::patch('/users/{user_id}/status', [AdminManagementController::class, 'setStatus'])->whereUuid('user_id')->name('users.status');
            Route::post('/users/{user_id}/password', [AdminManagementController::class, 'resetPassword'])->whereUuid('user_id')->name('users.password');
            Route::delete('/users/{user_id}', [AdminManagementController::class, 'deleteUser'])->whereUuid('user_id')->name('users.delete');
            Route::post('/users/{user_id}/restore', [AdminManagementController::class, 'restoreUser'])->whereUuid('user_id')->name('users.restore');
            Route::get('/admins', [AdminManagementController::class, 'administrators'])->name('admins.index');
            Route::post('/admins/{user_id}', [AdminManagementController::class, 'grantAdministrator'])->whereUuid('user_id')->name('admins.grant');
            Route::delete('/admins/{user_id}', [AdminManagementController::class, 'revokeAdministrator'])->whereUuid('user_id')->name('admins.revoke');
            Route::get('/roles', [AdminManagementController::class, 'roles'])->name('roles.index');
            Route::get('/players', [PlayerManagementController::class, 'index'])->name('players.index');
            Route::get('/players/{user_id}', [PlayerManagementController::class, 'show'])->whereUuid('user_id')->name('players.show');
            Route::patch('/organizations/{organization_id}/members/{user_id}/role', [OrganizationController::class, 'changeMemberRole'])->whereUuid('organization_id')->whereUuid('user_id')->name('organizations.members.role');
            Route::post('/organizations/{organization_id}/members', [OrganizationController::class, 'adminAddMember'])->whereUuid('organization_id')->name('organizations.members.add');
            Route::delete('/organizations/{organization_id}/members/{user_id}', [OrganizationController::class, 'adminRemoveMember'])->whereUuid('organization_id')->whereUuid('user_id')->name('organizations.members.remove');
            Route::patch('/organizations/{organization_id}/members/{user_id}/status', [OrganizationController::class, 'adminSetMemberStatus'])->whereUuid('organization_id')->whereUuid('user_id')->name('organizations.members.status');
        });
        Route::get('/content/locations', [ContentBankController::class, 'locations']);
        Route::post('/content/locations', [ContentBankController::class, 'saveLocation']);
        Route::get('/content/locations/{location_id}', [ContentBankController::class, 'showLocation']);
        Route::patch('/content/locations/{location_id}', [ContentBankController::class, 'saveLocation']);
        Route::get('/content/qr-bank', [ContentBankController::class, 'qrIndex']);
        Route::post('/content/qr-bank', [ContentBankController::class, 'qrSave']);
        Route::get('/content/qr-bank/{qr_id}', [ContentBankController::class, 'qrShow']);
        Route::patch('/content/qr-bank/{qr_id}', [ContentBankController::class, 'qrSave']);
        Route::get('/content/qr-bank/{qr_id}/printable', [ContentBankController::class, 'qrPrintable'])->whereUuid('qr_id');
        Route::get('/content/media', [MediaController::class, 'index']);
        Route::post('/content/media', [MediaController::class, 'create']);
        Route::get('/content/media/{asset_id}/download', [MediaController::class, 'download']);
        Route::get('/runtime/nodes/{node_instance_id}/media', [MediaController::class, 'runtime']);
        Route::post('/runtime/nodes/{node_instance_id}/evidence', [SubmissionController::class, 'upload'])->middleware('throttle:runtime-submit');
        Route::get('/submissions', [SubmissionController::class, 'index']);
        Route::get('/submissions/{submission_id}', [SubmissionController::class, 'show']);
        Route::post('/submissions/{submission_id}/review', [SubmissionController::class, 'review']);
        Route::get('/submissions/{submission_id}/download', [SubmissionController::class, 'download']);
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
        Route::get('/scenario-node-catalog', [ScenarioController::class, 'catalog'])->name('scenarios.catalog');
        Route::post('/scenarios', [ScenarioController::class, 'mutate'])->name('scenarios.create');
        Route::get('/scenarios/{scenario_id}', [ScenarioController::class, 'show'])->name('scenarios.show');
        Route::patch('/scenarios/{scenario_id}', [ScenarioController::class, 'mutate'])->name('scenarios.update');
        Route::post('/scenarios/{scenario_id}/archive', [ScenarioController::class, 'mutate'])->name('scenarios.archive');
        Route::get('/scenarios/{scenario_id}/versions', [ScenarioController::class, 'index'])->name('scenarios.versions');
        Route::post('/scenarios/{scenario_id}/versions', [ScenarioController::class, 'mutate'])->name('scenarios.createVersion');
        Route::post('/scenarios/{scenario_id}/draft', [ScenarioController::class, 'mutate'])->name('scenarios.ensureDraft');
        Route::get('/scenario-versions/{version_id}', [ScenarioController::class, 'show'])->name('scenarios.version');
        Route::patch('/scenario-versions/{version_id}', [ScenarioController::class, 'mutate'])->name('scenarios.updateVersion');
        Route::get('/scenario-versions/{version_id}/graph', [ScenarioController::class, 'show'])->name('scenarios.graph');
        Route::put('/scenario-versions/{version_id}/graph', [ScenarioController::class, 'mutate'])->name('scenarios.saveGraph');
        foreach (['validate', 'publish', 'duplicate', 'simulate'] as $action) {
            Route::post('/scenario-versions/{version_id}/'.$action, [ScenarioController::class, 'mutate'])->name('scenarios.'.$action);
        }
        Route::get('/games', [GameController::class, 'index'])->name('games.index');
        Route::get('/me/bookings', [GameBookingController::class, 'index'])->name('bookings.index');
        Route::get('/me/payments', [GameBookingController::class, 'payments'])->name('payments.index');
        Route::post('/games/{game_id}/bookings', [GameBookingController::class, 'create'])->middleware('throttle:booking-create')->whereUuid('game_id')->name('bookings.create');
        Route::get('/bookings/{booking_id}', [GameBookingController::class, 'show'])->whereUuid('booking_id')->name('bookings.show');
        Route::post('/payment-orders/{order_id}/checkout', [GameBookingController::class, 'checkout'])->middleware('throttle:payment-actions')->whereUuid('order_id')->name('payments.checkout');
        Route::post('/payment-orders/{order_id}/test-confirm', [GameBookingController::class, 'confirmTestPayment'])->middleware('throttle:payment-actions')->whereUuid('order_id')->name('payments.test-confirm');
        Route::get('/games/{game_id}/rooms', [GameRoomController::class, 'index'])->name('rooms.index');
        Route::post('/games/{game_id}/rooms', [GameRoomController::class, 'create'])->name('rooms.create');
        Route::get('/rooms/{room_id}', [GameRoomController::class, 'show'])->name('rooms.show');
        Route::post('/rooms/{room_id}/teams', [GameRoomController::class, 'addTeam'])->name('rooms.teams.add');
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
        Route::post('/teams/join', [TeamController::class, 'join'])->middleware('throttle:team-invites')->name('teams.join');
        Route::get('/team-invites/{invite_code}', [TeamController::class, 'previewInvite'])->where('invite_code', '[A-Za-z0-9_-]{16,64}')->middleware('throttle:team-invites')->name('teamInvites.preview');
        Route::get('/teams/{team_id}/join-requests', [TeamController::class, 'joinRequests'])->whereUuid('team_id')->name('teams.joinRequests');
        Route::post('/teams/{team_id}/join-requests/{request_id}/accept', [TeamController::class, 'acceptJoinRequest'])->whereUuid('team_id')->whereUuid('request_id')->name('teams.joinRequests.accept');
        Route::post('/teams/{team_id}/join-requests/{request_id}/decline', [TeamController::class, 'declineJoinRequest'])->whereUuid('team_id')->whereUuid('request_id')->name('teams.joinRequests.decline');
        Route::get('/players/{player_id}', [TeamController::class, 'searchPlayer'])->whereUuid('player_id')->middleware('throttle:team-invites')->name('players.show');
        Route::get('/me/team-invitations', [TeamController::class, 'incomingInvitations'])->name('teams.invitations.incoming');
        Route::post('/me/team-invitations/{invite_id}/accept', [TeamController::class, 'acceptInvitation'])->whereUuid('invite_id')->name('teams.invitations.accept');
        Route::post('/me/team-invitations/{invite_id}/decline', [TeamController::class, 'declineInvitation'])->whereUuid('invite_id')->name('teams.invitations.decline');
        Route::get('/teams/{team_id}', [TeamController::class, 'show'])->name('teams.show');
        Route::patch('/teams/{team_id}', [TeamController::class, 'update'])->name('teams.update');
        Route::get('/teams/{team_id}/invites', [TeamController::class, 'invites'])->name('teams.invites');
        Route::post('/teams/{team_id}/invites', [TeamController::class, 'createInvite'])->name('teams.createInvite');
        Route::post('/teams/{team_id}/player-invitations', [TeamController::class, 'invitePlayer'])->whereUuid('team_id')->middleware('throttle:team-invites')->name('teams.invitePlayer');
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
