<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RentalTransactionResource;
use App\Models\RentalTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalTransactionHandoverController extends Controller
{
    public function confirmOwnerHandover(
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
                'Hanya pemilik barang terkait yang dapat mengonfirmasi penyerahan.'
            );
            abort_unless(
                $lockedTransaction->status === 'awaiting_handover',
                409,
                'Rental Transaction tidak menunggu konfirmasi penyerahan.'
            );
            abort_if(
                $lockedTransaction->owner_handover_confirmed_at !== null,
                409,
                'Penyerahan barang sudah dikonfirmasi.'
            );

            $lockedTransaction->owner_handover_confirmed_at = now();
            $lockedTransaction->save();

            return $lockedTransaction;
        });

        return (new RentalTransactionResource($transaction))
            ->additional(['message' => 'Handover confirmed successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    public function confirmBorrowerReceipt(
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
                'Hanya peminjam terkait yang dapat mengonfirmasi penerimaan.'
            );
            abort_unless(
                $lockedTransaction->status === 'awaiting_handover',
                409,
                'Rental Transaction tidak menunggu konfirmasi penerimaan.'
            );
            abort_unless(
                $lockedTransaction->owner_handover_confirmed_at !== null,
                409,
                'Penyerahan barang oleh pemilik belum dikonfirmasi.'
            );
            abort_if(
                $lockedTransaction->borrower_received_confirmed_at !== null,
                409,
                'Penerimaan barang sudah dikonfirmasi.'
            );

            $lockedTransaction->borrower_received_confirmed_at = now();
            if ($lockedTransaction->owner_handover_confirmed_at !== null
                && $lockedTransaction->borrower_received_confirmed_at !== null) {
                $lockedTransaction->status = 'active';
            }
            $lockedTransaction->save();

            return $lockedTransaction;
        });

        return (new RentalTransactionResource($transaction))
            ->additional(['message' => 'Receipt confirmed successfully.'])
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
