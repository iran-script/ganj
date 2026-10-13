<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaService
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf', 'audio/wav', 'audio/x-wav', 'audio/mpeg'];

    public function __construct(private OrganizationScope $scope, private AuditService $audit) {}

    public function library(User $actor): array
    {
        return MediaAsset::whereIn('organization_id', $this->scope->organizations($actor, true)->select('id'))
            ->where('purpose', 'LIBRARY')->latest()->limit(500)->get()->map(fn (MediaAsset $asset): array => $this->project($asset))->all();
    }

    public function create(User $actor, array $input, UploadedFile $file): array
    {
        Validator::make($input, ['organization_id' => 'required|uuid', 'name' => 'required|string|max:255', 'kind' => 'required|in:IMAGE,AUDIO,FILE'])->validate();
        $context = $this->scope->resolve($actor, $input['organization_id']);
        $context->authorize('update');
        if ($context->organization->status !== 'ACTIVE') {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Organization is inactive.', [], 409);
        }
        $asset = null;
        try {
            return DB::transaction(function () use ($actor, $input, $file, $context, &$asset): array {
                $asset = $this->store($actor, $context->organization->id, $file, $input['name'], $input['kind'], 'LIBRARY');
                $this->audit->record('media.uploaded', 'media_asset', $asset->public_id, actorId: $actor->id, organizationId: $asset->organization_id);

                return $this->project($asset);
            });
        } catch (\Throwable $error) {
            if ($asset) {
                Storage::disk($asset->disk)->delete($asset->object_key);
            }
            throw $error;
        }
    }

    /** Only trusted services choose ownership and purpose. Binary objects never change. */
    public function store(User $actor, int $organizationId, UploadedFile $file, string $name, string $kind, string $purpose, int $maxMb = 25, ?array $allowed = null, ?int $maxDuration = null): MediaAsset
    {
        Validator::make(['file' => $file], ['file' => 'required|file|max:'.($maxMb * 1024)])->validate();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $types = $allowed ?? match ($kind) {
            'IMAGE' => ['image/jpeg', 'image/png'], 'AUDIO' => ['audio/wav', 'audio/x-wav', 'audio/mpeg'], default => self::MIME_TYPES
        };
        if (! in_array($mime, array_intersect($types, self::MIME_TYPES), true) || $file->getSize() < 1) {
            throw ValidationException::withMessages(['file' => 'The file content type is not permitted.']);
        }
        if (str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 40000000) {
                throw ValidationException::withMessages(['file' => 'The image dimensions are invalid or too large.']);
            }
        }
        $duration = null;
        if ($maxDuration !== null) {
            $duration = $this->wavDuration($file);
            if ($duration > $maxDuration) {
                throw ValidationException::withMessages(['file' => 'The recording exceeds the duration limit.']);
            }
        }
        $disk = config('filesystems.media_disk', 'local');
        $path = 'nexo-media/'.$organizationId.'/'.Str::uuid();
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('Media storage failed.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        try {
            $asset = (new MediaAsset)->forceFill(['organization_id' => $organizationId, 'created_by' => $actor->id, 'name' => $name,
                'kind' => $kind, 'purpose' => $purpose, 'disk' => $disk, 'object_key' => $path, 'mime_type' => $mime,
                'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'duration_seconds' => $duration]);
            $asset->save();

            return $asset;
        } catch (\Throwable $error) {
            Storage::disk($disk)->delete($path);
            throw $error;
        }
    }

    private function wavDuration(UploadedFile $file): int
    {
        $bytes = file_get_contents($file->getRealPath());
        $rate = 0;
        $dataSize = 0;
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE') {
            for ($offset = 12; $offset + 8 <= strlen($bytes);) {
                $length = unpack('V', substr($bytes, $offset + 4, 4))[1];
                if ($offset + 8 + $length > strlen($bytes)) {
                    throw ValidationException::withMessages(['file' => 'The recording is truncated.']);
                }
                $chunk = substr($bytes, $offset, 4);
                if ($chunk === 'fmt ' && $length >= 16 && unpack('v', substr($bytes, $offset + 8, 2))[1] === 1) {
                    $rate = unpack('V', substr($bytes, $offset + 16, 4))[1];
                    $format = unpack('vchannels/Vsamples/Vrate/valign/vbits', substr($bytes, $offset + 10, 14));
                    if (! in_array($format['channels'], [1, 2], true) || ! in_array($format['bits'], [8, 16, 24, 32], true)
                        || $format['samples'] < 8000 || $format['samples'] > 192000
                        || $format['align'] !== (int) ($format['channels'] * $format['bits'] / 8)
                        || $rate !== $format['samples'] * $format['align']) {
                        throw ValidationException::withMessages(['file' => 'The PCM recording format is invalid.']);
                    }
                }
                if ($chunk === 'data') {
                    $dataSize += $length;
                }
                $offset += 8 + $length + ($length % 2);
            }
        }
        if ($rate < 1 || $dataSize < 1) {
            throw ValidationException::withMessages(['file' => 'A valid PCM WAV recording is required.']);
        }

        return (int) ceil($dataSize / $rate);
    }

    public function resolveLibrary(User $actor, string $id): MediaAsset
    {
        $asset = Str::isUuid($id) ? MediaAsset::where('public_id', $id)->where('purpose', 'LIBRARY')
            ->whereIn('organization_id', $this->scope->organizations($actor, true)->select('id'))->first() : null;
        if (! $asset) {
            throw $this->scope->notFound();
        }

        return $asset;
    }

    public function project(MediaAsset $asset): array
    {
        return ['id' => $asset->public_id, 'organization_id' => $asset->organization->public_id, 'name' => $asset->name,
            'kind' => $asset->kind, 'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes,
            'duration_seconds' => $asset->duration_seconds, 'created_at' => $asset->created_at?->toISOString()];
    }

    public function download(MediaAsset $asset): StreamedResponse
    {
        $extension = match ($asset->mime_type) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf', 'audio/mpeg' => 'mp3', default => 'wav'
        };

        return Storage::disk($asset->disk)->download($asset->object_key, $asset->public_id.'.'.$extension,
            ['Content-Type' => $asset->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
