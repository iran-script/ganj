<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Models\GameSession;
use App\Services\Games\GameScope;
use App\Services\Games\SessionService;
use App\Services\Identity\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GameSessionController extends Controller
{
    public function __construct(private GameScope $scope, private SessionService $service) {}

    public function index(GameRequest $request): JsonResponse
    {
        $input = $request->validated();
        $actor = $request->user();
        $page = $this->scope->sessions($actor)->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);

        return response()->json(['data' => $page->getCollection()->map(fn (GameSession $s): array => $s->publicProfile($actor, $this->scope->operator($actor, $s)))->all(), 'meta' => ['pagination' => ['cursor' => $input['cursor'] ?? null, 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function show(GameRequest $request, string $session_id): JsonResponse
    {
        $request->validated();
        $session = $this->scope->resolve($request->user(), $session_id, true);

        return response()->json(['data' => $session->publicProfile($request->user(), $this->scope->operator($request->user(), $session))])->header('Cache-Control', 'no-store');
    }

    public function state(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->show($request, $session_id);
    }

    public function timeline(GameRequest $request, string $session_id): JsonResponse
    {
        $input = $request->validated();
        $session = $this->scope->resolve($request->user(), $session_id, true);
        $operational = $this->scope->operator($request->user(), $session);
        $query = DB::table('audit_events')->select('action', 'reason', 'occurred_at', 'request_id')->selectRaw("context->>'event_id' as public_id")
            ->where('resource_type', 'game_session')->where('resource_id', $session->public_id)->where('organization_id', $session->organization_id)->whereNotNull('context->event_id');
        if (! $operational) {
            $query->whereIn('action', ['game_session.created.v1', 'game_session.registration_opened.v1', 'game_session.registration_closed.v1', 'game_session.started.v1', 'game_session.paused.v1', 'game_session.resumed.v1', 'game_session.finished.v1', 'game_session.cancelled.v1']);
        }
        $page = DB::query()->fromSub($query, 'session_history')->orderBy('occurred_at')->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
        $data = collect($page->items())->map(fn ($e): array => ['event_id' => $e->public_id, 'event_type' => $e->action, 'occurred_at' => Carbon::parse($e->occurred_at)->utc()->toISOString()] + ($operational ? ['reason' => $e->reason, 'request_id' => $e->request_id] : []))->all();

        return response()->json(['data' => $data, 'meta' => ['pagination' => ['cursor' => $input['cursor'] ?? null, 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function create(GameRequest $request): JsonResponse
    {
        return $this->mutate($request, 'create', null, status: 201);
    }

    public function update(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'update', $session_id);
    }

    public function openRegistration(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'openRegistration', $session_id);
    }

    public function closeRegistration(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'closeRegistration', $session_id);
    }

    public function registerTeam(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'registerTeam', $session_id);
    }

    public function removeTeam(GameRequest $request, string $session_id, string $team_id): JsonResponse
    {
        return $this->mutate($request, 'removeTeam', $session_id, $team_id);
    }

    public function bindDevice(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'bindDevice', $session_id);
    }

    public function rebindDevice(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'rebindDevice', $session_id);
    }

    public function ready(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'ready', $session_id);
    }

    public function start(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'start', $session_id);
    }

    public function pause(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'pause', $session_id);
    }

    public function resume(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'resume', $session_id);
    }

    public function finish(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'finish', $session_id);
    }

    public function cancel(GameRequest $request, string $session_id): JsonResponse
    {
        return $this->mutate($request, 'cancel', $session_id);
    }

    private function mutate(GameRequest $request, string $action, ?string $id, ?string $target = null, int $status = 200): JsonResponse
    {
        $device = null;
        if ($action === 'ready') {
            $device = app(DeviceResolver::class)->fromRequest($request);
            if ($device->id !== $request->user()->currentAccessToken()->user_device_id) {
                throw new ApiException('DEVICE_NOT_BOUND', 'Use the bound Device token.', [], 409);
            }
        }

        return response()->json(['data' => $this->service->execute($request->user(), $action, $request->validated(), $id, $target, $request->header('Idempotency-Key'), $device)], $status)->header('Cache-Control', 'no-store');
    }
}
