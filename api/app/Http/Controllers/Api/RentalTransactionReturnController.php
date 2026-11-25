<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RentalTransactionResource;
use App\Models\Payment;
use App\Models\RentalExtension;
use App\Models\RentalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

            $extensions = RentalExtension::query()
                ->where('transaction_id', $lockedTransaction->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $activePayments = $this->lockActiveExtensionPayments(
                $lockedTransaction,
                $extensions->modelKeys()
            );

            $this->ensureStudentParties($lockedTransaction, $extensions);
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

            $this->ensureValidExtensionPayments($extensions, $activePayments);

            $returnStartedAt = now();
            $paymentsByExtension = $activePayments->groupBy('extension_id');
            foreach ($extensions as $extension) {
                $extensionPayments = $paymentsByExtension->get($extension->getKey(), new EloquentCollection());

                if ($extension->status === 'accepted') {
                    $reportedPayment = $extensionPayments->firstWhere('payment_status', 'reported');
                    if ($reportedPayment instanceof Payment) {
                        $reportedPayment->payment_status = 'rejected';
                        $reportedPayment->rejected_by = null;
                        $reportedPayment->rejected_at = $returnStartedAt;
                        $reportedPayment->rejection_note =
                            'Payment otomatis ditolak karena Borrower memulai proses Return.';
                        $reportedPayment->save();
                        $extensionPayments = new EloquentCollection();
                    }

                    if ($extensionPayments->isEmpty()) {
                        $extension->status = 'withdrawn';
                        $extension->save();
                    }

                    continue;
                }

                if ($extension->status === 'pending') {
                    $extension->status = 'withdrawn';
                    $extension->save();
                }
            }

            if ($lockedTransaction->status === 'active'
                && $lockedTransaction->due_at->lte($returnStartedAt)
                && $lockedTransaction->overdue_at === null) {
                $lockedTransaction->overdue_at = $returnStartedAt;
            }

            $lockedTransaction->borrower_returned_at = $returnStartedAt;
            $lockedTransaction->status = 'return_pending_confirmation';
            $lockedTransaction->save();

            return $lockedTransaction;
        });

        return (new RentalTransactionResource($transaction))
            ->additional(['message' => 'Return initiated successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * @param array<int, int|string> $extensionIds
     * @return EloquentCollection<int, Payment>
     */
    private function lockActiveExtensionPayments(
        RentalTransaction $transaction,
        array $extensionIds
    ): EloquentCollection {
        return Payment::query()
            ->where('transaction_id', $transaction->getKey())
            ->where('payment_type', 'extension_fee')
            ->whereIn('extension_id', $extensionIds)
            ->whereIn('payment_status', ['reported', 'confirmed'])
            ->orderBy('extension_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param EloquentCollection<int, RentalExtension> $extensions
     * @param EloquentCollection<int, Payment> $payments
     */
    private function ensureValidExtensionPayments(
        EloquentCollection $extensions,
        EloquentCollection $payments
    ): void {
        $extensionsById = $extensions->keyBy('id');

        foreach ($payments->groupBy('extension_id') as $extensionId => $extensionPayments) {
            $extension = $extensionsById->get($extensionId);

            abort_unless($extension instanceof RentalExtension, 409);
            abort_if(
                $extensionPayments->count() > 1,
                409,
                'Extension memiliki lebih dari satu Payment aktif.'
            );
            abort_unless(
                $extension->status === 'accepted',
                409,
                'Extension dengan Payment aktif harus berstatus accepted.'
            );
        }
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

    /**
     * @param EloquentCollection<int, RentalExtension>|null $extensions
     */
    private function ensureStudentParties(
        RentalTransaction $transaction,
        ?EloquentCollection $extensions = null
    ): void
    {
        $userIds = [
            $transaction->owner_id,
            $transaction->borrower_id,
        ];
        foreach ($extensions ?? [] as $extension) {
            $userIds[] = $extension->proposer_id;
        }

        $partyRoles = User::query()
            ->whereKey(array_values(array_unique($userIds)))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($transaction->owner_id) === 'student'
                && $partyRoles->get($transaction->borrower_id) === 'student',
            403,
            'Kedua pihak transaksi harus ber-role mahasiswa.'
        );

        foreach ($extensions ?? [] as $extension) {
            abort_unless(
                (string) $extension->proposer_id === (string) $transaction->borrower_id
                    && $partyRoles->get($extension->proposer_id) === 'student',
                409,
                'Setiap Extension harus diajukan oleh Borrower ber-role student.'
            );
        }
    }
}
