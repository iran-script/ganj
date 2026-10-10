<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\DeviceResolver;
use App\Services\Qr\QrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QrController extends Controller
{
    public function __construct(private QrService $qr, private DeviceResolver $devices) {}

    public function scan(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->qr->scan($request->user(), $this->devices->fromRequest($request), $request->all(), $request->header('Idempotency-Key', ''))])->header('Cache-Control', 'no-store');
    }

    public function create(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->qr->create($request->user(), $request->all())], 201)->header('Cache-Control', 'no-store');
    }

    public function lifecycle(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->qr->lifecycle($request->user(), $request->route('trigger_id'), $request->route('action') === 'revoke' ? 'REVOKED' : ($request->route('action') === 'activate' ? 'ACTIVE' : 'INACTIVE'))])->header('Cache-Control', 'no-store');
    }
}
