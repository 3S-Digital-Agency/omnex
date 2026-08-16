<?php

namespace App\Http\Controllers;

use App\Http\Resources\DriveFileResource;
use App\Http\Resources\DriveFolderResource;
use App\Http\Resources\DriveVersionResource;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DriveVersion;
use App\Support\Storage\StorageProviderException;
use App\Support\Storage\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StorageController extends Controller
{
    public function __construct(private StorageService $storage) {}

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('storage.read');

        return response()->json(['data' => $this->storage->providers()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('storage.read');

        return response()->json($this->listing(null));
    }

    public function folder(Request $request, string $folder): JsonResponse
    {
        $this->authorize('storage.read');

        return response()->json($this->listing(DriveFolder::findOrFail($folder)));
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $this->authorize('storage.manage');

        $data = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $folder = $this->storage->createFolder($data['parent_id'] ?? null, $data['name']);

        return response()->json(new DriveFolderResource($folder), 201);
    }

    public function updateFolder(Request $request, string $folder): JsonResponse
    {
        $this->authorize('storage.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(new DriveFolderResource(
            $this->storage->renameFolder(DriveFolder::findOrFail($folder), $data['name'])
        ));
    }

    public function destroyFolder(Request $request, string $folder): JsonResponse
    {
        $this->authorize('storage.manage');

        $this->storage->deleteFolder(DriveFolder::findOrFail($folder));

        return response()->json(null, 204);
    }

    public function storeFile(Request $request): JsonResponse
    {
        $this->authorize('storage.manage');

        $data = $request->validate([
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'contents' => ['required', 'string'],
            'mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $file = $this->storage->upload(
            $data['folder_id'] ?? null,
            $data['name'],
            $this->decodeContents($data['contents']),
            $data['mime_type'] ?? 'application/octet-stream',
        );

        return response()->json(new DriveFileResource($file), 201);
    }

    public function showFile(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.read');

        return response()->json(new DriveFileResource(DriveFile::findOrFail($file)));
    }

    public function downloadFile(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.read');

        $file = DriveFile::findOrFail($file);

        try {
            $url = $this->storage->downloadUrl($file);
        } catch (StorageProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json([
            'url' => $url,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
        ]);
    }

    public function updateFile(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contents' => ['sometimes', 'string'],
            'mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $contents = array_key_exists('contents', $data)
            ? $this->decodeContents($data['contents'])
            : null;

        try {
            $file = $this->storage->updateFile(
                DriveFile::findOrFail($file),
                $data['name'] ?? null,
                $contents,
                $data['mime_type'] ?? null,
            );
        } catch (StorageProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new DriveFileResource($file));
    }

    public function trashFile(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.manage');

        return response()->json(new DriveFileResource(
            $this->storage->trashFile(DriveFile::findOrFail($file))
        ));
    }

    public function restoreFile(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.manage');

        return response()->json(new DriveFileResource(
            $this->storage->restoreFile(DriveFile::findOrFail($file))
        ));
    }

    public function destroyFile(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.manage');

        try {
            $this->storage->deleteFile(DriveFile::findOrFail($file));
        } catch (StorageProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function trash(Request $request): JsonResponse
    {
        $this->authorize('storage.read');

        return response()->json(['data' => DriveFileResource::collection($this->storage->trash())]);
    }

    public function versions(Request $request, string $file): JsonResponse
    {
        $this->authorize('storage.read');

        return response()->json([
            'data' => DriveVersionResource::collection($this->storage->versions(DriveFile::findOrFail($file))),
        ]);
    }

    public function restoreVersion(Request $request, string $file, string $version): JsonResponse
    {
        $this->authorize('storage.manage');

        $file = DriveFile::findOrFail($file);
        $version = DriveVersion::where('file_id', $file->id)->findOrFail($version);

        try {
            $file = $this->storage->restoreVersion($file, $version);
        } catch (StorageProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new DriveFileResource($file));
    }

    /**
     * @return array{folder: ?DriveFolderResource, folders: mixed, files: mixed, quota: array{used: int, limit: int}}
     */
    private function listing(?DriveFolder $folder): array
    {
        $listing = $this->storage->list($folder);

        return [
            'folder' => $listing['folder'] ? new DriveFolderResource($listing['folder']) : null,
            'folders' => DriveFolderResource::collection($listing['folders']),
            'files' => DriveFileResource::collection($listing['files']),
            'quota' => $listing['quota'],
        ];
    }

    private function decodeContents(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw ValidationException::withMessages(['contents' => ['The contents must be a valid base64 string.']]);
        }

        return $decoded;
    }
}
