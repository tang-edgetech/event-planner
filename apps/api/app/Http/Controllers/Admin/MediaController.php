<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image uploads (banners, section images, logos). Files live on the "public" disk under media/;
 * the public site and the backoffice serve them at /media/… .
 */
class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $media = Media::query()
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where('original_name', 'like', "%{$t}%"))
            ->orderByDesc('id')->paginate(48);

        return response()->json(['data' => $media->items(), 'meta' => ['total' => $media->total(), 'page' => $media->currentPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,svg,ico,mp4', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,video/mp4'],
            'alt' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if ($ext === 'svg') {
            $svg = (string) file_get_contents($file->getRealPath());
            if (preg_match('/<script|on\w+\s*=|javascript:|<foreignObject/i', $svg)) {
                return response()->json(['message' => 'This SVG contains scripts and cannot be uploaded.', 'errors' => ['file' => ['This SVG contains scripts and cannot be uploaded.']]], 422);
            }
        }

        $dir = now()->format('Y/m');
        $name = Str::random(24).'.'.$ext;
        $path = $file->storeAs('media/'.$dir, $name, 'public');
        [$width, $height] = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];

        $media = Media::query()->create([
            'path' => $dir.'/'.$name,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt' => $request->input('alt'),
            'uploaded_by' => $request->user()->id,
        ]);
        Audit::log('media.uploaded', "{$request->user()->name} uploaded \"{$media->original_name}\"", "{$request->user()->name} uploaded the file \"{$media->original_name}\" (".round($media->size / 1024).' KB) to the media library.', 'success', $media, ['path' => $path], null, $media->original_name);

        return response()->json(['data' => $media], 201);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        Storage::disk('public')->delete('media/'.$media->path);
        $media->delete();
        Audit::log('media.deleted', "{$request->user()->name} deleted \"{$media->original_name}\"", "{$request->user()->name} removed \"{$media->original_name}\" from the media library. Pages still pointing to it will show a missing image.", 'success', $media, [], null, $media->original_name);

        return response()->json(['ok' => true]);
    }
}
