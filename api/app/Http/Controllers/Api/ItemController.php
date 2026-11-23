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
                ->with(['images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                ->orderByDesc('id')
                ->get()
        );
    }

    public function myItems(Request $request): AnonymousResourceCollection
    {
        return ItemResource::collection(
            Item::query()
                ->where('owner_id', $request->user()->getAuthIdentifier())
                ->with(['images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
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

        $item->load(['images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);

        return new ItemResource($item);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = Item::query()->create([
            ...$request->validated(),
            'owner_id' => $request->user()->getAuthIdentifier(),
            'listing_status' => 'active',
        ]);
        $item->load('images');

        return (new ItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $this->ensureOwner($request, $item);
        $item->update($request->validated());
        $item->load(['images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);

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
            $request->user()->role === 'student'
                && (string) $item->owner_id === (string) $request->user()->getAuthIdentifier(),
            403
        );
    }
}
