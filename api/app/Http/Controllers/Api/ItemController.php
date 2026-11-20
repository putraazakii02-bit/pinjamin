<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ItemResource::collection(
            Item::query()
                ->where('listing_status', 'active')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function myItems(Request $request): AnonymousResourceCollection
    {
        return ItemResource::collection(
            Item::query()
                ->where('owner_id', $request->user()->getAuthIdentifier())
                ->orderByDesc('id')
                ->get()
        );
    }

    public function show(Request $request, Item $item): ItemResource
    {
        if ($item->listing_status !== 'active'
            && (string) $item->owner_id !== (string) $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        return new ItemResource($item);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = Item::query()->create([
            ...$request->validated(),
            'owner_id' => $request->user()->getAuthIdentifier(),
            'listing_status' => 'active',
        ]);

        return (new ItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $this->ensureOwner($request, $item);
        $item->update($request->validated());

        return new ItemResource($item);
    }

    public function destroy(Request $request, Item $item): Response
    {
        $this->ensureOwner($request, $item);
        $item->delete();

        return response()->noContent();
    }

    private function ensureOwner(Request $request, Item $item): void
    {
        abort_unless(
            (string) $item->owner_id === (string) $request->user()->getAuthIdentifier(),
            403
        );
    }
}
