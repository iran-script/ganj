<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeamRequest;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Services\Teams\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Symfony\Component\HttpFoundation\Response;

class TeamController extends Controller
{
    public function __construct(private TeamService $teams) {}

    public function index(TeamRequest $request): JsonResponse
    {
        $page = $this->teams->listing($request->user(), $request->validated());

        return $this->collection($page, $request, $page->getCollection()->map(fn (Team $team): array => $team->publicProfile())->all());
    }

    public function show(TeamRequest $request, string $team_id): JsonResponse
    {
        return response()->json(['data' => $this->teams->show($request->user(), $team_id)])->header('Cache-Control', 'no-store');
    }

    public function invites(TeamRequest $request, string $team_id): JsonResponse
    {
        $page = $this->teams->invites($request->user(), $team_id, $request->validated());

        return $this->collection($page, $request, $page->getCollection()->map(fn (TeamInvite $invite): array => $invite->publicProfile())->all());
    }

    public function create(TeamRequest $request): Response
    {
        return $this->mutation($request, 'create', status: 201);
    }

    public function join(TeamRequest $request): Response
    {
        return $this->mutation($request, 'join');
    }

    public function update(TeamRequest $request, string $team_id): Response
    {
        return $this->mutation($request, 'update', $team_id);
    }

    public function createInvite(TeamRequest $request, string $team_id): Response
    {
        return $this->mutation($request, 'createInvite', $team_id, status: 201);
    }

    public function revokeInvite(TeamRequest $request, string $team_id, string $invite_id): Response
    {
        return $this->mutation($request, 'revokeInvite', $team_id, $invite_id, 204);
    }

    public function leave(TeamRequest $request, string $team_id): Response
    {
        return $this->mutation($request, 'leave', $team_id, status: 204);
    }

    public function remove(TeamRequest $request, string $team_id, string $user_id): Response
    {
        return $this->mutation($request, 'remove', $team_id, $user_id, 204);
    }

    public function transfer(TeamRequest $request, string $team_id): Response
    {
        return $this->mutation($request, 'transfer', $team_id);
    }

    public function archive(TeamRequest $request, string $team_id): Response
    {
        return $this->mutation($request, 'archive', $team_id);
    }

    private function mutation(TeamRequest $request, string $action, ?string $teamId = null, ?string $targetId = null, int $status = 200): Response
    {
        $data = $this->teams->execute($request->user(), $action, $request->validated(), $teamId, $targetId, $request->header('Idempotency-Key'));

        return ($status === 204 ? response()->noContent() : response()->json(['data' => $data], $status))->header('Cache-Control', 'no-store');
    }

    private function collection(CursorPaginator $page, TeamRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['pagination' => ['cursor' => $request->input('cursor'), 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }
}
