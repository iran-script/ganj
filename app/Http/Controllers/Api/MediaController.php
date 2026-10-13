<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\Identity\DeviceResolver;
use App\Services\Media\MediaService;
use App\Services\Media\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private MediaService $media, private SubmissionService $submissions, private DeviceResolver $devices) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->media->library($request->user())])->header('Cache-Control', 'no-store');
    }

    public function create(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:25600']);

        return response()->json(['data' => $this->media->create($request->user(), $request->only(['organization_id', 'name', 'kind']), $request->file('file'))], 201)->header('Cache-Control', 'no-store');
    }

    public function download(Request $request): StreamedResponse
    {
        return $this->media->download($this->media->resolveLibrary($request->user(), $request->route('asset_id')));
    }

    public function runtime(Request $request): StreamedResponse
    {
        [$node] = $this->submissions->playerNode($request->user(), $this->devices->fromRequest($request), $request->route('node_instance_id'));
        abort_unless($node->node->node_type === 'MediaReveal', 404);
        $asset = MediaAsset::where('public_id', $node->node->config['media_asset_id'])->where('purpose', 'LIBRARY')
            ->where('organization_id', $node->instance->session->organization_id)->firstOrFail();

        return $this->media->download($asset);
    }
}
