<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RentalTransactionQueryResource;
use App\Models\RentalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RentalTransactionQueryController extends Controller
{
    public function myTransactions(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->getAuthIdentifier();
        $this->ensureStudentUser($userId);

        $transactions = $this->studentPartyTransactions()
            ->where('borrower_id', $userId)
            ->orderByDesc('id')
            ->get();

        return RentalTransactionQueryResource::collection($transactions);
    }

    public function incomingTransactions(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->getAuthIdentifier();
        $this->ensureStudentUser($userId);

        $transactions = $this->studentPartyTransactions()
            ->where('owner_id', $userId)
            ->orderByDesc('id')
            ->get();

        return RentalTransactionQueryResource::collection($transactions);
    }

    public function show(Request $request, RentalTransaction $transaction): JsonResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $this->ensureStudentUser($userId);

        abort_unless(
            (string) $transaction->owner_id === $userId
                || (string) $transaction->borrower_id === $userId,
            403,
            'Anda bukan pemilik maupun peminjam Rental Transaction ini.'
        );

        $this->ensureStudentParties($transaction);
        $transaction->load($this->detailRelations());

        return (new RentalTransactionQueryResource($transaction))
            ->response()
            ->setStatusCode(200);
    }

    private function studentPartyTransactions(): Builder
    {
        return RentalTransaction::query()
            ->with($this->detailRelations())
            ->whereHas('owner', function (Builder $query): void {
                $query->where('role', 'student');
            })
            ->whereHas('borrower', function (Builder $query): void {
                $query->where('role', 'student');
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'item' => fn ($query) => $query
                ->withTrashed()
                ->with([
                    'images' => fn ($imageQuery) => $imageQuery
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ]),
            'owner:id,name',
            'borrower:id,name',
            'rentalRequest',
            'acceptedNegotiation',
        ];
    }

    private function ensureStudentUser(int|string $userId): void
    {
        abort_unless(
            User::query()
                ->whereKey($userId)
                ->where('role', 'student')
                ->exists(),
            403,
            'Hanya mahasiswa yang dapat mengakses Rental Transaction.'
        );
    }

    private function ensureStudentParties(RentalTransaction $transaction): void
    {
        $partyRoles = User::query()
            ->whereKey([$transaction->owner_id, $transaction->borrower_id])
            ->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($transaction->owner_id) === 'student'
                && $partyRoles->get($transaction->borrower_id) === 'student',
            403,
            'Kedua pihak transaksi harus ber-role mahasiswa.'
        );
    }
}
