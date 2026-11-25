<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\CounterRentalNegotiationRequest;
use App\Http\Resources\RentalRequestResource;
use App\Models\Item;
use App\Models\RentalNegotiation;
use App\Models\RentalRequest;
use App\Models\RentalTransaction;
use App\Models\User;
use App\Support\RentalPriceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalNegotiationController extends Controller
{
    public function counter(
        CounterRentalNegotiationRequest $request,
        RentalRequest $rentalRequest,
        RentalNegotiation $negotiation
    ): JsonResponse {
        $user = $request->user();

        $rentalRequest = DB::transaction(function () use (
            $request,
            $rentalRequest,
            $negotiation,
            $user
        ): RentalRequest {
            $lockedRequest = RentalRequest::query()
                ->whereKey($rentalRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNegotiationCanBeRespondedTo($lockedRequest, $negotiation, $user);

            $pendingNegotiations = RentalNegotiation::query()
                ->where('request_id', $lockedRequest->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            abort_unless($pendingNegotiations->count() === 1, 409, 'Permintaan memiliki status penawaran pending yang tidak konsisten.');
            $pending = $pendingNegotiations->first();
            abort_unless((string) $pending->id === (string) $negotiation->id, 404);

            $item = Item::query()
                ->whereKey($lockedRequest->item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePartyCanRespond($lockedRequest, $item, $user);
            $this->ensureUserIsReceivingOffer($pending, $user);

            $validated = $request->validated();
            $start = $request->normalizedStart();
            $due = $request->normalizedDue();

            $dailyCents = RentalPriceCalculator::decimalToCents(
                (string) $validated['proposed_daily_price']
            );
            $durationDays = RentalPriceCalculator::calculateDurationDays($start, $due);
            $totalCents = $dailyCents * $durationDays;

            if ($totalCents > RentalPriceCalculator::MAX_DECIMAL_CENTS) {
                throw ValidationException::withMessages([
                    'proposed_daily_price' => 'Total harga melebihi kapasitas decimal(12,2).',
                ]);
            }

            $respondedAt = now();
            $pending->status = 'superseded';
            $pending->responded_by = $user->getAuthIdentifier();
            $pending->responded_at = $respondedAt;
            $pending->save();

            $counterOffer = $lockedRequest->negotiations()->make([
                'proposer_id' => $user->getAuthIdentifier(),
                'proposed_daily_price' => $validated['proposed_daily_price'],
                'proposed_start_at' => $start,
                'proposed_due_at' => $due,
                'message' => $validated['message'] ?? null,
            ]);
            $counterOffer->status = 'pending';
            $counterOffer->responded_by = null;
            $counterOffer->responded_at = null;
            $counterOffer->save();

            $lockedRequest->status = 'negotiating';
            $lockedRequest->save();

            return $lockedRequest->load([
                'negotiations' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ]);
        });

        return (new RentalRequestResource($rentalRequest))
            ->response()
            ->setStatusCode(200);
    }

    public function accept(
        \Illuminate\Http\Request $request,
        RentalRequest $rentalRequest,
        RentalNegotiation $negotiation
    ): JsonResponse {
        $user = $request->user();

        $rentalRequest = DB::transaction(function () use (
            $rentalRequest,
            $negotiation,
            $user
        ): RentalRequest {
            $lockedRequest = RentalRequest::query()
                ->whereKey($rentalRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNegotiationCanBeRespondedTo($lockedRequest, $negotiation, $user);

            $pendingNegotiations = RentalNegotiation::query()
                ->where('request_id', $lockedRequest->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            abort_unless($pendingNegotiations->count() === 1, 409, 'Permintaan memiliki status penawaran pending yang tidak konsisten.');
            $pending = $pendingNegotiations->first();
            abort_unless((string) $pending->id === (string) $negotiation->id, 404);

            $item = Item::query()
                ->whereKey($lockedRequest->item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePartyCanRespond($lockedRequest, $item, $user);
            $this->ensureUserIsReceivingOffer($pending, $user);

            abort_if(
                $lockedRequest->transaction()->lockForUpdate()->exists(),
                409,
                'Rental Request sudah memiliki Rental Transaction.'
            );

            $acceptedAt = now();
            $pending->status = 'accepted';
            $pending->responded_by = $user->getAuthIdentifier();
            $pending->responded_at = $acceptedAt;
            $pending->save();

            $lockedRequest->status = 'accepted';
            $lockedRequest->save();

            $timezone = config('app.timezone');
            $start = CarbonImmutable::parse($pending->proposed_start_at, $timezone);
            $due = CarbonImmutable::parse($pending->proposed_due_at, $timezone);
            $durationMicroseconds = RentalPriceCalculator::durationMicroseconds($start, $due);
            abort_unless(
                $durationMicroseconds >= RentalPriceCalculator::MICROSECONDS_PER_DAY,
                422,
                'Durasi peminjaman minimal 24 jam penuh.'
            );
            $dailyCents = RentalPriceCalculator::decimalToCents(
                (string) $pending->proposed_daily_price
            );
            $durationDays = RentalPriceCalculator::calculateDurationDays($start, $due);
            $totalCents = $dailyCents * $durationDays;

            abort_unless(
                $totalCents <= RentalPriceCalculator::MAX_DECIMAL_CENTS,
                422,
                'Total harga melebihi kapasitas decimal(12,2).'
            );

            $transaction = $lockedRequest->transaction()->make([
                'accepted_negotiation_id' => $pending->id,
                'item_id' => $item->id,
                'owner_id' => $item->owner_id,
                'borrower_id' => $lockedRequest->borrower_id,
                'agreed_daily_price' => RentalPriceCalculator::formatCents($dailyCents),
                'total_price' => RentalPriceCalculator::formatCents($totalCents),
                'start_at' => $start,
                'due_at' => $due,
            ]);
            $transaction->save();

            return $lockedRequest->load([
                'negotiations' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'transaction',
            ]);
        });

        return (new RentalRequestResource($rentalRequest))
            ->response()
            ->setStatusCode(200);
    }

    public function reject(
        \Illuminate\Http\Request $request,
        RentalRequest $rentalRequest,
        RentalNegotiation $negotiation
    ): JsonResponse {
        $user = $request->user();

        $rentalRequest = DB::transaction(function () use (
            $rentalRequest,
            $negotiation,
            $user
        ): RentalRequest {
            $lockedRequest = RentalRequest::query()
                ->whereKey($rentalRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNegotiationCanBeRespondedTo($lockedRequest, $negotiation, $user);

            $pendingNegotiations = RentalNegotiation::query()
                ->where('request_id', $lockedRequest->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            abort_unless($pendingNegotiations->count() === 1, 409, 'Permintaan memiliki status penawaran pending yang tidak konsisten.');
            $pending = $pendingNegotiations->first();
            abort_unless((string) $pending->id === (string) $negotiation->id, 404);

            $item = Item::query()
                ->whereKey($lockedRequest->item_id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                (string) $item->owner_id === (string) $user->getAuthIdentifier(),
                403,
                'Hanya pemilik barang yang dapat menolak Rental Request.'
            );
            $this->ensurePartyCanRespond($lockedRequest, $item, $user);
            $this->ensureUserIsReceivingOffer($pending, $user);

            $rejectedAt = now();
            $pending->status = 'rejected';
            $pending->responded_by = $user->getAuthIdentifier();
            $pending->responded_at = $rejectedAt;
            $pending->save();

            $lockedRequest->status = 'rejected';
            $lockedRequest->save();

            return $lockedRequest->load([
                'negotiations' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ]);
        });

        return (new RentalRequestResource($rentalRequest))
            ->response()
            ->setStatusCode(200);
    }

    private function ensureNegotiationCanBeRespondedTo(
        RentalRequest $rentalRequest,
        RentalNegotiation $negotiation,
        $user
    ): void {
        abort_unless(
            (string) $negotiation->request_id === (string) $rentalRequest->id,
            404
        );
        abort_unless(
            in_array($rentalRequest->status, ['pending', 'negotiating'], true),
            409,
            'Rental Request sudah berakhir dan tidak dapat direspons.'
        );
    }

    private function ensurePartyCanRespond(
        RentalRequest $rentalRequest,
        Item $item,
        $user
    ): void {
        $partyRoles = User::query()
            ->whereKey([$item->owner_id, $rentalRequest->borrower_id])
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($item->owner_id) === 'student'
                && $partyRoles->get($rentalRequest->borrower_id) === 'student',
            403,
            'Hanya mahasiswa yang dapat bernegosiasi.'
        );

        $userId = (string) $user->getAuthIdentifier();
        $isOwner = (string) $item->owner_id === $userId;
        $isBorrower = (string) $rentalRequest->borrower_id === $userId;

        abort_unless($isOwner || $isBorrower, 403, 'Anda bukan pemilik maupun peminjam Rental Request ini.');
    }

    private function ensureUserIsReceivingOffer(
        RentalNegotiation $negotiation,
        $user
    ): void {
        abort_unless(
            (string) $negotiation->proposer_id !== (string) $user->getAuthIdentifier(),
            403,
            'Pengirim penawaran tidak dapat merespons penawarannya sendiri.'
        );
    }
}
