<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\StoreRentalRequestRequest;
use App\Http\Resources\RentalRequestResource;
use App\Models\Item;
use App\Models\RentalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RentalRequestController extends Controller
{
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
}
