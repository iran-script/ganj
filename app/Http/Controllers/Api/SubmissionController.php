<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\DeviceResolver;
use App\Services\Media\MediaService;
use App\Services\Media\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function __construct(private SubmissionService $submissions, private DeviceResolver $devices, private MediaService $media) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['session_id' => 'required|uuid']);

        return response()->json(['data' => $this->submissions->index($request->user(), $request->string('session_id')->toString())])->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:25600']);

        return response()->json(['data' => $this->submissions->upload($request->user(), $this->devices->fromRequest($request),
            $request->route('node_instance_id'), $request->file('file'), $request->only(['captured_at', 'capture_source']), $request->header('Idempotency-Key', ''))], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->submissions->project($this->submissions->visible($request->user(), $request->route('submission_id')))])->header('Cache-Control', 'no-store');
    }

    public function review(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->submissions->review($request->user(), $request->route('submission_id'), $request->only(['status', 'message']))])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request): StreamedResponse
    {
        return $this->media->download($this->submissions->visible($request->user(), $request->route('submission_id'))->asset);
    }
}
