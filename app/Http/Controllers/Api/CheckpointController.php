<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checkpoint;
use App\Services\Locations\CheckpointService;
use App\Services\Locations\LocationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckpointController extends Controller
{
    public function __construct(private CheckpointService $checkpoints, private LocationInput $input) {}

    public function index(Request $request): JsonResponse
    {
        $this->input->validate($request->all(), ['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|nullable|string|max:1000']);
        $page = $this->checkpoints->query($request->user())->with('organization')->selectRaw('checkpoints.*, ST_AsGeoJSON(geom) as geojson')
            ->orderBy('public_id')->cursorPaginate($request->integer('limit', 25));

        return response()->json(['data' => $page->getCollection()->map(fn (Checkpoint $checkpoint): array => $this->checkpoints->project($checkpoint)),
            'meta' => ['pagination' => ['cursor' => $request->input('cursor'), 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkpoints->project($this->checkpoints->resolve($request->user(), $request->route('checkpoint_id')))])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkpoints->save($request->user(), $request->all(), $request->route('checkpoint_id'))],
            $request->route('checkpoint_id') ? 200 : 201)->header('Cache-Control', 'no-store');
    }
}
