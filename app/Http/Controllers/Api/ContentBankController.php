<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checkpoint;
use App\Models\QrTrigger;
use App\Services\Audit\AuditService;
use App\Services\Locations\CheckpointService;
use App\Services\Locations\LocationInput;
use App\Services\Qr\QrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ContentBankController extends Controller
{
    public function __construct(private CheckpointService $locations, private QrService $qr, private LocationInput $input, private AuditService $audit) {}

    public function locations(Request $request): JsonResponse
    {
        $query = $this->locations->query($request->user())->where('type', 'POINT');
        if ($request->boolean('active_only')) {
            $query->where('status', 'ACTIVE');
        }
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->string('search')->limit(100).'%');
        }

        return response()->json(['data' => $query->orderBy('name')->limit(500)->get()->map(fn (Checkpoint $item): array => $this->location($item))])->header('Cache-Control', 'no-store');
    }

    public function showLocation(Request $request): JsonResponse
    {
        $item = $this->locations->resolve($request->user(), $request->route('location_id'));
        abort_unless($item->type === 'POINT', 404);

        return response()->json(['data' => $this->location($item)])->header('Cache-Control', 'no-store');
    }

    public function locationStages(Request $request): JsonResponse
    {
        $location = $this->locations->resolve($request->user(), $request->route('location_id'));
        abort_unless($location->type === 'POINT', 404);
        $stages = DB::table('scenario_nodes as nodes')->join('scenario_versions as versions', 'versions.id', '=', 'nodes.scenario_version_id')
            ->join('scenarios', 'scenarios.id', '=', 'versions.scenario_id')
            ->where('scenarios.organization_id', $location->organization_id)
            ->where('nodes.config->checkpoint_id', $location->public_id)
            ->orderBy('scenarios.title')->orderBy('versions.version_no')->orderBy('nodes.node_key')
            ->get(['scenarios.public_id as scenario_id', 'scenarios.title as scenario_title', 'versions.public_id as version_id',
                'versions.version_no', 'versions.status as version_status', 'nodes.node_key', 'nodes.node_type', 'nodes.title as stage_title'])
            ->map(fn (object $stage): array => (array) $stage)->all();

        return response()->json(['data' => $stages])->header('Cache-Control', 'no-store');
    }

    public function saveLocation(Request $request): JsonResponse
    {
        $id = $request->route('location_id');
        $rules = ['organization_id' => $id ? 'prohibited' : 'required|uuid', 'name' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'default_radius_m' => 'required|numeric|min:0.01|max:100000', 'accuracy_m' => 'sometimes|nullable|numeric|min:1|max:1000',
            'dwell_seconds' => 'sometimes|nullable|integer|min:0|max:86400', 'category' => 'sometimes|string|max:100',
            'description' => 'sometimes|string|max:2000', 'status' => 'required|in:ACTIVE,INACTIVE,ARCHIVED'];
        $this->input->validate($request->all(), $rules);
        $input = $request->all();
        $payload = ['name' => $input['name'], 'type' => 'POINT', 'geom' => ['type' => 'Point', 'coordinates' => [(float) $input['longitude'], (float) $input['latitude']]],
            'radius_meters' => $input['default_radius_m'], 'config' => array_filter(['min_accuracy' => $input['accuracy_m'] ?? null, 'dwell_time' => $input['dwell_seconds'] ?? null], fn ($value) => $value !== null),
            'metadata' => ['category' => $input['category'] ?? '', 'description' => $input['description'] ?? ''], 'status' => $input['status']];
        if (! $id) {
            $payload['organization_id'] = $input['organization_id'];
        } else {
            abort_unless($this->locations->resolve($request->user(), $id)->type === 'POINT', 404);
        }
        $saved = $this->locations->save($request->user(), $payload, $id);

        return response()->json(['data' => $this->location($this->locations->resolve($request->user(), $saved['id']))], $id ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    private function location(Checkpoint $item): array
    {
        $value = $this->locations->project($item);

        return ['id' => $value['id'], 'organization_id' => $value['organization_id'], 'name' => $value['name'],
            'latitude' => $value['geom']['coordinates'][1], 'longitude' => $value['geom']['coordinates'][0], 'default_radius_m' => $value['radius_meters'],
            'accuracy_m' => $value['config']['min_accuracy'] ?? null, 'dwell_seconds' => $value['config']['dwell_time'] ?? null,
            'category' => $item->metadata['category'] ?? '', 'description' => $item->metadata['description'] ?? '', 'status' => $item->status,
            'created_at' => $item->created_at->toISOString(), 'updated_at' => $item->updated_at->toISOString()];
    }

    public function qrIndex(Request $request): JsonResponse
    {
        $items = collect($this->qr->list($request->user()))->map(fn (array $item): array => $this->qrItem($item))
            ->filter(fn (array $item): bool => (! $request->boolean('active_only') || $item['status'] === 'ACTIVE')
                && (! $request->filled('search') || str_contains(mb_strtolower($item['name'].' '.$item['label']), mb_strtolower($request->string('search')->toString()))))->values();

        return response()->json(['data' => $items])->header('Cache-Control', 'no-store');
    }

    public function qrShow(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->qrItem($this->qr->resolve($request->user(), $request->route('qr_id')))])->header('Cache-Control', 'no-store');
    }

    public function qrSave(Request $request): JsonResponse
    {
        $id = $request->route('qr_id');
        $this->input->validate($request->all(), ['organization_id' => $id ? 'prohibited' : 'required|uuid', 'name' => 'required|string|max:255',
            'label' => 'required|string|max:100', 'location_description' => 'sometimes|nullable|string|max:2000', 'notes' => 'sometimes|nullable|string|max:2000', 'status' => 'required|in:ACTIVE,INACTIVE']);
        if (! $id) {
            $item = $this->qr->provision($request->user(), ['organization_id' => $request->input('organization_id'),
                'status' => $request->input('status'), 'metadata' => $request->only(['name', 'label', 'location_description', 'notes'])]);

            return response()->json(['data' => $this->qrItem($item)], 201)->header('Cache-Control', 'no-store');
        }
        $result = DB::transaction(function () use ($request, $id): array {
            $metadata = $request->only(['name', 'label', 'location_description', 'notes']);
            $this->qr->resolve($request->user(), $id);
            $this->qr->lifecycle($request->user(), $id, $request->input('status'));
            $item = QrTrigger::where('public_id', $id)->lockForUpdate()->sole();
            $item->forceFill(['metadata' => array_replace($item->metadata ?? [], $metadata)])->save();
            $this->audit->record('qr.bank.saved', 'qr_trigger', $id, actorId: $request->user()->id, organizationId: $item->organization_id);

            return $this->qrItem($this->qr->resolve($request->user(), $id));
        });

        return response()->json(['data' => $result], $id ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    private function qrItem(array $item): array
    {
        return array_diff_key($item, ['metadata' => true]) + ['name' => $item['metadata']['name'] ?? $item['metadata']['label'] ?? 'QR',
            'label' => $item['metadata']['label'] ?? '', 'location_description' => $item['metadata']['location_description'] ?? '', 'notes' => $item['metadata']['notes'] ?? '',
            'printable_available' => $item['status'] === 'ACTIVE' && QrTrigger::where('public_id', $item['id'])->whereNotNull('printable_identity')->exists(),
            'usage_count' => DB::table('scenario_nodes')->where('node_type', 'QR')->where('config->trigger_id', $item['id'])->count()];
    }

    public function qrPrintable(Request $request): Response
    {
        $id = $request->route('qr_id');

        return response($this->qr->printable($request->user(), $id), 200, [
            'Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'attachment; filename="qr-'.$id.'.svg"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
