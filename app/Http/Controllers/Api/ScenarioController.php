<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\AuthoringNodeCatalog;
use App\Services\Scenarios\NodeTypeRegistry;
use App\Services\Scenarios\ScenarioGraph;
use App\Services\Scenarios\ScenarioInput;
use App\Services\Scenarios\ScenarioScope;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScenarioController extends Controller
{
    public function __construct(private ScenarioScope $scope, private ScenarioService $service, private ScenarioGraph $graphs, private NodeTypeRegistry $registry) {}

    public function index(Request $request): JsonResponse
    {
        $input = ScenarioInput::validate($request->all(), 'index');
        if ($request->route('scenario_id')) {
            $scenario = $this->scope->resolve($request->user(), $request->route('scenario_id'));
            Gate::forUser($request->user())->authorize('view', $scenario);
            $query = $scenario->versions();
        } else {
            $query = $this->scope->scenarios($request->user());
        }
        $page = $query->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);

        return response()->json(['data' => $page->getCollection()->map(fn (Scenario|ScenarioVersion $model): array => $model->publicProfile())->all(),
            'meta' => ['pagination' => ['cursor' => $input['cursor'] ?? null, 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => app(AuthoringNodeCatalog::class)->describe()])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        ScenarioInput::validate($request->all(), 'show');
        $version = $request->route('version_id') ? $this->scope->version($request->user(), $request->route('version_id')) : null;
        $scenario = $version?->scenario ?? $this->scope->resolve($request->user(), $request->route('scenario_id'));
        Gate::forUser($request->user())->authorize('view', $scenario);
        $result = $version?->publicProfile() ?? $scenario->publicProfile();
        if ($request->route()->getName() === 'scenarios.graph') {
            $graph = $this->graphs->read($version);
            $result = ['revision_token' => $version->revision_token, 'graph' => $graph, 'randomization' => $this->registry->randomizationContract($graph['nodes'])];
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function mutate(Request $request): JsonResponse
    {
        $action = substr($request->route()->getName(), 10);
        $result = $this->service->execute($request->user(), $action, $request->all(), $request->route('version_id') ?? $request->route('scenario_id'), $request->header('Idempotency-Key'));

        return response()->json(['data' => $result], in_array($action, ['create', 'createVersion', 'duplicate'], true) ? 201 : 200)->header('Cache-Control', 'no-store');
    }
}
