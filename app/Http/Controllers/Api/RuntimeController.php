<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\DeviceResolver;
use App\Services\Runtime\RuntimeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RuntimeController extends Controller
{
    public function __construct(private RuntimeEngine $engine, private DeviceResolver $devices) {}

    public function read(Request $request): JsonResponse
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['input' => 'This read does not accept query parameters.']);
        }
        $self = $request->route()->getName() === 'runtime.self';
        $result = $this->engine->read($request->user(), $request->route('session_id'), $request->route('team_id'), $self, $self ? $this->devices->fromRequest($request) : null);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function mutate(Request $request): JsonResponse
    {
        $action = substr($request->route()->getName(), strlen('runtime.'));
        $result = $this->engine->execute($request->user(), $action, $request->route('node_instance_id'), $request->all(),
            $request->header('Idempotency-Key', ''), $this->devices->fromRequest($request));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function team(Request $request): JsonResponse
    {
        $visible = $this->engine->read($request->user(), $request->route('session_id'), $request->route('team_id'));
        $action = substr($request->route()->getName(), strlen('runtime.'));
        $result = $this->engine->execute($request->user(), $action, $visible['id'], $request->all(), $request->header('Idempotency-Key', ''));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
