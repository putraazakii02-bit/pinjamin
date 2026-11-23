<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\StoreRentalRequestRequest;
use App\Http\Resources\RentalRequestResource;
use App\Models\Item;
use App\Models\RentalNegotiation;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RentalRequestController extends Controller
{
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()->role === 'student',
            403,
            'Hanya mahasiswa yang dapat mengakses Rental Request.'
        );

        $requests = RentalRequest::query()
            ->where('borrower_id', $request->user()->getAuthIdentifier())
            ->whereHas('item', function ($itemQuery): void {
                $itemQuery
                    ->withTrashed()
                    ->whereHas('owner', fn ($ownerQuery) => $ownerQuery->where('role', 'student'));
            })
            ->with($this->listingRelations())
            ->orderByDesc('id')
            ->get();

        return RentalRequestResource::collection($requests);
    }

    public function incomingRequests(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()->role === 'student',
            403,
            'Hanya mahasiswa yang dapat mengakses Rental Request.'
        );

        $requests = RentalRequest::query()
            ->whereHas('item', function ($itemQuery) use ($request): void {
                $itemQuery
                    ->withTrashed()
                    ->where('owner_id', $request->user()->getAuthIdentifier());
            })
            ->whereHas('borrower', fn ($borrowerQuery) => $borrowerQuery->where('role', 'student'))
            ->with($this->listingRelations())
            ->orderByDesc('id')
            ->get();

        return RentalRequestResource::collection($requests);
    }

    public function show(Request $request, RentalRequest $rentalRequest): JsonResponse
    {
        $rentalRequest->load([
            'item' => fn ($itemQuery) => $itemQuery
                ->withTrashed()
                ->with('owner'),
            'borrower',
            'negotiations' => fn ($query) => $query
                ->orderBy('created_at')
                ->orderBy('id'),
            'transaction',
        ]);

        $borrowerId = (string) $rentalRequest->borrower_id;
        $ownerId = $rentalRequest->item
            ? (string) $rentalRequest->item->owner_id
            : null;
        $userId = (string) $request->user()->getAuthIdentifier();

        abort_unless(
            $request->user()->role === 'student'
                && $rentalRequest->borrower?->role === 'student'
                && $rentalRequest->item?->owner?->role === 'student',
            403,
            'Hanya pihak Student yang dapat mengakses Rental Request ini.'
        );

        abort_unless(
            $borrowerId === $userId || $ownerId === $userId,
            403,
            'Anda bukan peminjam maupun pemilik barang dari Rental Request ini.'
        );

        return (new RentalRequestResource($rentalRequest))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * @return array<string, mixed>
     */
    private function listingRelations(): array
    {
        return [
            'item' => fn ($itemQuery) => $itemQuery
                ->withTrashed()
                ->with('owner'),
            'borrower',
            'negotiations' => fn ($query) => $query
                ->orderBy('created_at')
                ->orderBy('id'),
            'transaction',
        ];
    }

    public function store(StoreRentalRequestRequest $request, Item $item): JsonResponse
    {
        $borrower = $request->user();
        abort_unless($borrower->role === 'student', 403, 'Hanya mahasiswa yang dapat mengajukan peminjaman.');

        $rentalRequest = DB::transaction(function () use ($item, $borrower, $request): RentalRequest {
            $lockedItem = Item::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedItem->listing_status === 'active', 404);
            $owner = User::query()
                ->whereKey($lockedItem->owner_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $owner->role === 'student',
                403,
                'Barang milik Admin tidak dapat diminta untuk dipinjam.'
            );
            abort_unless(
                (string) $lockedItem->owner_id !== (string) $borrower->getAuthIdentifier(),
                403,
                'Pemilik barang tidak dapat mengajukan permintaan untuk barangnya sendiri.'
            );

            $hasActiveRequest = $lockedItem->rentalRequests()
                ->where('borrower_id', $borrower->getAuthIdentifier())
                ->whereIn('status', ['pending', 'negotiating'])
                ->exists();

            abort_unless(
                ! $hasActiveRequest,
                409,
                'Anda masih memiliki permintaan aktif untuk barang ini.'
            );

            $validated = $request->validated();
            $rentalRequest = $lockedItem->rentalRequests()->make([
                'borrower_id' => $borrower->getAuthIdentifier(),
                'request_message' => $validated['request_message'] ?? null,
            ]);
            $rentalRequest->status = 'pending';
            $rentalRequest->save();

            $firstNegotiation = $rentalRequest->negotiations()->make([
                'proposed_daily_price' => $validated['proposed_daily_price'],
                'proposed_start_at' => $request->normalizedProposedStartAt(),
                'proposed_due_at' => $request->normalizedProposedDueAt(),
                'message' => null,
            ]);
            $firstNegotiation->proposer_id = $borrower->getAuthIdentifier();
            $firstNegotiation->status = 'pending';
            $firstNegotiation->responded_by = null;
            $firstNegotiation->responded_at = null;
            $firstNegotiation->save();

            return $rentalRequest->load('negotiations');
        });

        return (new RentalRequestResource($rentalRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(\Illuminate\Http\Request $request, RentalRequest $rentalRequest): JsonResponse
    {
        $borrower = $request->user();

        $rentalRequest = DB::transaction(function () use (
            $borrower,
            $rentalRequest
        ): RentalRequest {
            $lockedRequest = RentalRequest::query()
                ->whereKey($rentalRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem = Item::withTrashed()
                ->whereKey($lockedRequest->item_id)
                ->lockForUpdate()
                ->firstOrFail();
            $partyRoles = User::query()
                ->whereKey([$lockedRequest->borrower_id, $lockedItem->owner_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('role', 'id');

            abort_unless(
                $partyRoles->get($lockedRequest->borrower_id) === 'student',
                403,
                'Hanya mahasiswa peminjam yang dapat membatalkan Rental Request.'
            );
            abort_unless(
                $partyRoles->get($lockedItem->owner_id) === 'student',
                403,
                'Pemilik barang harus ber-role mahasiswa.'
            );
            abort_unless(
                (string) $lockedRequest->borrower_id === (string) $borrower->getAuthIdentifier(),
                403,
                'Hanya peminjam pemilik Rental Request yang dapat membatalkan.'
            );

            abort_unless(
                in_array($lockedRequest->status, ['pending', 'negotiating'], true),
                409,
                'Rental Request tidak dapat dibatalkan pada status saat ini.'
            );

            abort_if(
                $lockedRequest->transaction()->lockForUpdate()->exists(),
                409,
                'Rental Request sudah memiliki Rental Transaction.'
            );

            $cancelledAt = now();
            $pendingNegotiations = RentalNegotiation::query()
                ->where('request_id', $lockedRequest->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($pendingNegotiations as $pendingNegotiation) {
                $pendingNegotiation->status = 'withdrawn';
                $pendingNegotiation->responded_by = $borrower->getAuthIdentifier();
                $pendingNegotiation->responded_at = $cancelledAt;
                $pendingNegotiation->save();
            }

            $lockedRequest->status = 'cancelled';
            $lockedRequest->cancelled_at = $cancelledAt;
            $lockedRequest->save();

            return $lockedRequest->load([
                'negotiations' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'transaction',
            ]);
        });

        return (new RentalRequestResource($rentalRequest))
            ->response()
            ->setStatusCode(200);
    }
}
