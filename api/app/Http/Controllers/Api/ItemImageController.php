<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemImagesRequest;
use App\Http\Resources\ItemImageResource;
use App\Models\Item;
use App\Models\ItemImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ItemImageController extends Controller
{
    public function store(StoreItemImagesRequest $request, Item $item): JsonResponse
    {
        $storedPaths = [];

        try {
            $images = DB::transaction(function () use ($request, $item, &$storedPaths) {
                $lockedItem = Item::query()
                    ->lockForUpdate()
                    ->findOrFail($item->getKey());
                $this->ensureOwner($request, $lockedItem);

                $existingImages = $lockedItem->images()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $newImages = $request->file('images');

                if ($existingImages->count() + count($newImages) > 5) {
                    throw ValidationException::withMessages([
                        'images' => 'A maximum of five images is allowed per item.',
                    ]);
                }

                $primaryImage = $existingImages->firstWhere('is_primary', true);
                if ($primaryImage) {
                    $lockedItem->images()
                        ->where('is_primary', true)
                        ->where('id', '!=', $primaryImage->getKey())
                        ->update(['is_primary' => false]);
                }

                $nextSortOrder = ($existingImages->max('sort_order') ?? -1) + 1;
                $createdImages = collect();

                foreach ($newImages as $offset => $file) {
                    $path = $file->storeAs(
                        'items/'.$lockedItem->getKey(),
                        Str::uuid().'.'.$file->extension(),
                        'local'
                    );

                    if ($path === false) {
                        throw new RuntimeException('Unable to store uploaded item image.');
                    }

                    $storedPaths[] = $path;
                    $createdImages->push($lockedItem->images()->create([
                        'image_path' => $path,
                        'is_primary' => ! $primaryImage && $offset === 0,
                        'sort_order' => $nextSortOrder + $offset,
                    ]));
                }

                return $createdImages;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        $this->logCleanupFailure('Failed to clean up item image after upload failure.', [
                            'item_id' => $item->getKey(),
                            'path' => $path,
                        ]);
                    }
                } catch (Throwable $cleanupException) {
                    $this->logCleanupFailure('Failed to clean up item image after upload failure.', [
                        'item_id' => $item->getKey(),
                        'path' => $path,
                        'cleanup_exception_class' => $cleanupException::class,
                    ]);
                }
            }

            throw $exception;
        }

        return ItemImageResource::collection($images)
            ->additional(['message' => 'Item images uploaded successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function file(Request $request, Item $item, ItemImage $image): StreamedResponse
    {
        if ($item->listing_status !== 'active'
            && (string) $item->owner_id !== (string) $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        $image = $item->images()
            ->whereKey($image->getKey())
            ->firstOrFail();
        $disk = Storage::disk('local');

        abort_unless($disk->exists($image->image_path), 404);

        return $disk->response($image->image_path, headers: [
            'Content-Type' => $disk->mimeType($image->image_path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function destroy(Request $request, Item $item, ItemImage $image): JsonResponse
    {
        $imagePath = DB::transaction(function () use ($request, $item, $image): string {
            $lockedItem = Item::query()
                ->lockForUpdate()
                ->findOrFail($item->getKey());
            $this->ensureOwner($request, $lockedItem);

            $imageToDelete = $lockedItem->images()
                ->whereKey($image->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $imagePath = $imageToDelete->image_path;

            if ($imageToDelete->is_primary) {
                $nextPrimary = $lockedItem->images()
                    ->where('id', '!=', $imageToDelete->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $lockedItem->images()->update(['is_primary' => false]);

                if ($nextPrimary) {
                    $nextPrimary->update(['is_primary' => true]);
                }
            }

            $imageToDelete->delete();

            return $imagePath;
        });

        $cleanupFailed = false;
        $cleanupExceptionClass = null;

        try {
            $disk = Storage::disk('local');
            $cleanupFailed = $disk->exists($imagePath) && ! $disk->delete($imagePath);
        } catch (Throwable $cleanupException) {
            $cleanupFailed = true;
            $cleanupExceptionClass = $cleanupException::class;
        }

        $response = ['message' => 'Item image record deleted successfully.'];

        if ($cleanupFailed) {
            $this->logCleanupFailure('Item image file requires cleanup after record deletion.', [
                'item_id' => $item->getKey(),
                'path' => $imagePath,
                'cleanup_exception_class' => $cleanupExceptionClass,
            ]);
            $response['warning'] = 'The physical image file still requires cleanup.';
        }

        return response()->json($response);
    }

    public function setPrimary(Request $request, Item $item, ItemImage $image): JsonResponse
    {
        $updatedImage = DB::transaction(function () use ($request, $item, $image): ItemImage {
            $lockedItem = Item::query()
                ->lockForUpdate()
                ->findOrFail($item->getKey());
            $this->ensureOwner($request, $lockedItem);

            $selectedImage = $lockedItem->images()
                ->whereKey($image->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem->images()->update(['is_primary' => false]);
            $selectedImage->update(['is_primary' => true]);

            return $selectedImage;
        });

        return response()->json([
            'message' => 'Primary item image updated successfully.',
            'data' => new ItemImageResource($updatedImage),
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $context
     */
    private function logCleanupFailure(string $message, array $context): void
    {
        try {
            Log::error($message, $context);
        } catch (Throwable $loggingException) {
            $context['logging_exception_class'] = $loggingException::class;
            error_log($message.' '.json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    private function ensureOwner(Request $request, Item $item): void
    {
        abort_unless(
            (string) $item->owner_id === (string) $request->user()->getAuthIdentifier(),
            403
        );
    }
}
