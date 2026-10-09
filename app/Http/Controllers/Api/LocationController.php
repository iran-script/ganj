<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Locations\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(private LocationService $locations) {}

    public function submit(Request $request): JsonResponse
    {
        $result = $request->route()->getName() === 'locations.batch'
            ? $this->locations->batch($request->user(), $request->header('X-Device-Id'), $request->all())
            : $this->locations->ingest($request->user(), $request->header('X-Device-Id'), $request->all());

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function policy(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->locations->policy($request->user(), $request->route('session_id'), $request->header('X-Device-Id'))])->header('Cache-Control', 'no-store');
    }
}
