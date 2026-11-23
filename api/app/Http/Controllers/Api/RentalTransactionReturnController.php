<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RentalTransactionResource;
use App\Models\RentalTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalTransactionReturnController extends Controller
{
    public function confirmBorrowerReturn(
        Request $request,
        RentalTransaction $transaction
    ): JsonResponse {
        $transaction = DB::transaction(function () use ($request, $transaction): RentalTransaction {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureStudentParties($lockedTransaction);
            abort_unless(
                (string) $lockedTransaction->borrower_id === (string) $request->user()->getAuthIdentifier(),
                403,
                'Hanya peminjam terkait yang dapat memulai pengembalian.'
            );
            abort_unless(
                in_array($lockedTransaction->status, ['active', 'overdue'], true),
                409,
                'Rental Transaction tidak dapat dikembalikan pada status saat ini.'
            );
            abort_if(
                $lockedTransaction->borrower_returned_at !== null,
                409,
                'Pengembalian barang sudah dimulai.'
            );

            $returnedAt = now();
            if ($lockedTransaction->status === 'active'
                && $lockedTransaction->due_at->lte($returnedAt)
                && $lockedTransaction->overdue_at === null) {
                $lockedTransaction->overdue_at = $returnedAt;
            }

            $lockedTransaction->borrower_returned_at = $returnedAt;
            $lockedTransaction->status = 'return_pending_confirmation';
            $lockedTransaction->save();

            return $lockedTransaction;
        });

        return (new RentalTransactionResource($transaction))
            ->additional(['message' => 'Return initiated successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    public function confirmOwnerReturn(
        Request $request,
        RentalTransaction $transaction
    ): JsonResponse {
        $transaction = DB::transaction(function () use ($request, $transaction): RentalTransaction {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureStudentParties($lockedTransaction);
            abort_unless(
                (string) $lockedTransaction->owner_id === (string) $request->user()->getAuthIdentifier(),
                403,
                'Hanya pemilik barang terkait yang dapat mengonfirmasi penerimaan.'
            );
            abort_unless(
                $lockedTransaction->status === 'return_pending_confirmation',
                409,
                'Rental Transaction tidak menunggu konfirmasi penerimaan barang.'
            );
            abort_unless(
                $lockedTransaction->borrower_returned_at !== null,
                409,
                'Peminjam belum memulai pengembalian barang.'
            );
            abort_if(
                $lockedTransaction->owner_return_received_at !== null,
                409,
                'Penerimaan kembali barang sudah dikonfirmasi.'
            );

            $lockedTransaction->owner_return_received_at = now();
            if ($lockedTransaction->borrower_returned_at !== null
                && $lockedTransaction->owner_return_received_at !== null) {
                $lockedTransaction->status = 'completed';
            }
            $lockedTransaction->save();

            return $lockedTransaction;
        });

        return (new RentalTransactionResource($transaction))
            ->additional(['message' => 'Return receipt confirmed successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    private function ensureStudentParties(RentalTransaction $transaction): void
    {
        $partyRoles = User::query()
            ->whereKey([$transaction->owner_id, $transaction->borrower_id])
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($transaction->owner_id) === 'student'
                && $partyRoles->get($transaction->borrower_id) === 'student',
            403,
            'Kedua pihak transaksi harus ber-role mahasiswa.'
        );
    }
}
