<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\StoreRentalExtensionRequest;
use App\Http\Resources\RentalExtensionResource;
use App\Models\Payment;
use App\Models\RentalExtension;
use App\Models\RentalTransaction;
use App\Models\User;
use App\Support\RentalPriceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalExtensionController extends Controller
{
    public function index(
        Request $request,
        RentalTransaction $transaction
    ): AnonymousResourceCollection {
        $userId = (string) $request->user()->getAuthIdentifier();

        abort_unless(
            (string) $transaction->owner_id === $userId
                || (string) $transaction->borrower_id === $userId,
            403,
            'Anda bukan pemilik maupun peminjam Rental Transaction ini.'
        );

        $this->ensureStudentParties($transaction, lockUsers: false);

        $extensions = RentalExtension::query()
            ->where('transaction_id', $transaction->getKey())
            ->orderBy('id')
            ->get();

        return RentalExtensionResource::collection($extensions);
    }

    public function store(
        StoreRentalExtensionRequest $request,
        RentalTransaction $transaction
    ): JsonResponse {
        $extension = DB::transaction(function () use ($request, $transaction): RentalExtension {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                (string) $lockedTransaction->borrower_id === (string) $request->user()->getAuthIdentifier(),
                403,
                'Hanya Borrower transaksi yang dapat mengajukan Extension.'
            );
            abort_unless(
                $lockedTransaction->status === 'active',
                409,
                'Rental Transaction tidak aktif.'
            );
            abort_unless(
                $lockedTransaction->due_at->gt(now()),
                409,
                'Extension hanya dapat diajukan sebelum due_at.'
            );

            $extensions = RentalExtension::query()
                ->where('transaction_id', $lockedTransaction->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payments = $this->lockExtensionPayments(
                $lockedTransaction,
                $extensions->modelKeys()
            );
            $this->ensureNoConflictingPaymentStatuses($payments);

            foreach ($extensions as $existingExtension) {
                if (! in_array($existingExtension->status, ['pending', 'accepted'], true)) {
                    continue;
                }

                $hasConfirmedPayment = $payments->contains(
                    fn (Payment $payment): bool => (string) $payment->extension_id
                            === (string) $existingExtension->getKey()
                        && $payment->payment_status === 'confirmed'
                );

                abort_if(
                    ! $hasConfirmedPayment,
                    409,
                    'Transaksi memiliki Extension yang belum dibayar atau masih menunggu respons.'
                );
            }

            $this->ensureStudentParties($lockedTransaction);

            $timezone = config('app.timezone');
            $previousDueAt = CarbonImmutable::parse($lockedTransaction->due_at, $timezone)
                ->setTimezone($timezone);
            $proposedDueAt = $request->normalizedProposedDue();
            $durationMicroseconds = RentalPriceCalculator::durationMicroseconds(
                $previousDueAt,
                $proposedDueAt
            );

            if ($durationMicroseconds <= 0) {
                throw ValidationException::withMessages([
                    'proposed_due_at' => 'Waktu pengembalian baru harus setelah due_at saat ini.',
                ]);
            }

            if ($durationMicroseconds < RentalPriceCalculator::MICROSECONDS_PER_DAY) {
                throw ValidationException::withMessages([
                    'proposed_due_at' => 'Durasi tambahan minimal 24 jam penuh.',
                ]);
            }

            $durationDays = RentalPriceCalculator::calculateDurationDays(
                $previousDueAt,
                $proposedDueAt
            );
            $dailyPriceCents = RentalPriceCalculator::decimalToCents(
                (string) $lockedTransaction->agreed_daily_price
            );
            $proposedFeeCents = $dailyPriceCents * $durationDays;

            if ($proposedFeeCents > RentalPriceCalculator::MAX_DECIMAL_CENTS) {
                throw ValidationException::withMessages([
                    'proposed_due_at' => 'Biaya Extension melebihi kapasitas decimal(12,2).',
                ]);
            }

            $extension = $lockedTransaction->extensions()->make([
                'proposer_id' => $request->user()->getAuthIdentifier(),
                'previous_extension_id' => null,
                'previous_due_at' => $previousDueAt,
                'proposed_due_at' => $proposedDueAt,
                'proposed_fee' => RentalPriceCalculator::formatCents($proposedFeeCents),
                'message' => null,
            ]);
            $extension->status = 'pending';
            $extension->responded_by = null;
            $extension->responded_at = null;
            $extension->save();

            return $extension;
        });

        return (new RentalExtensionResource($extension))
            ->additional(['message' => 'Extension submitted successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function accept(
        Request $request,
        RentalTransaction $transaction,
        RentalExtension $extension
    ): JsonResponse {
        return $this->respond($request, $transaction, $extension, 'accepted');
    }

    public function reject(
        Request $request,
        RentalTransaction $transaction,
        RentalExtension $extension
    ): JsonResponse {
        return $this->respond($request, $transaction, $extension, 'rejected');
    }

    private function respond(
        Request $request,
        RentalTransaction $transaction,
        RentalExtension $extension,
        string $responseStatus
    ): JsonResponse {
        $extension = DB::transaction(function () use (
            $request,
            $transaction,
            $extension,
            $responseStatus
        ): RentalExtension {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExtension = RentalExtension::query()
                ->whereKey($extension->getKey())
                ->where('transaction_id', $lockedTransaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $payments = $this->lockExtensionPayments(
                $lockedTransaction,
                [$lockedExtension->getKey()]
            );
            $this->ensureNoConflictingPaymentStatuses($payments);
            $this->ensureStudentParties($lockedTransaction, $lockedExtension);

            abort_unless(
                (string) $lockedTransaction->owner_id === (string) $request->user()->getAuthIdentifier(),
                403,
                'Hanya Owner transaksi yang dapat merespons Extension.'
            );
            abort_unless(
                in_array($lockedTransaction->status, ['active', 'overdue'], true),
                409,
                'Extension tidak dapat direspons pada status transaksi saat ini.'
            );
            abort_unless(
                $lockedExtension->status === 'pending',
                409,
                'Hanya Extension pending yang dapat direspons.'
            );

            $lockedExtension->status = $responseStatus;
            $lockedExtension->responded_by = $request->user()->getAuthIdentifier();
            $lockedExtension->responded_at = now();
            $lockedExtension->save();

            return $lockedExtension;
        });

        $message = $responseStatus === 'accepted'
            ? 'Extension accepted successfully.'
            : 'Extension rejected successfully.';

        return (new RentalExtensionResource($extension))
            ->additional(['message' => $message])
            ->response()
            ->setStatusCode(200);
    }

    public function withdraw(
        Request $request,
        RentalTransaction $transaction,
        RentalExtension $extension
    ): JsonResponse {
        $extension = DB::transaction(function () use (
            $request,
            $transaction,
            $extension
        ): RentalExtension {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExtension = RentalExtension::query()
                ->whereKey($extension->getKey())
                ->where('transaction_id', $lockedTransaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureStudentParties($lockedTransaction, $lockedExtension);

            abort_unless(
                (string) $lockedTransaction->borrower_id === (string) $request->user()->getAuthIdentifier(),
                403,
                'Hanya Borrower transaksi yang dapat menarik Extension.'
            );
            abort_unless(
                $lockedExtension->status === 'pending',
                409,
                'Hanya Extension pending yang dapat ditarik.'
            );

            $lockedExtension->status = 'withdrawn';
            $lockedExtension->save();

            return $lockedExtension;
        });

        return (new RentalExtensionResource($extension))
            ->additional(['message' => 'Extension withdrawn successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * @param array<int, int|string> $extensionIds
     * @return EloquentCollection<int, Payment>
     */
    private function lockExtensionPayments(
        RentalTransaction $transaction,
        array $extensionIds
    ): EloquentCollection {
        if ($extensionIds === []) {
            return new EloquentCollection();
        }

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
     * @param EloquentCollection<int, Payment> $payments
     */
    private function ensureNoConflictingPaymentStatuses(EloquentCollection $payments): void
    {
        foreach ($payments->groupBy('extension_id') as $extensionPayments) {
            $statuses = $extensionPayments->pluck('payment_status');

            abort_if(
                $statuses->contains('confirmed') && $statuses->contains('reported'),
                409,
                'Extension memiliki Payment reported dan confirmed secara bersamaan.'
            );
            abort_if(
                $extensionPayments->count() > 1,
                409,
                'Extension memiliki lebih dari satu Payment aktif.'
            );
        }
    }

    private function ensureStudentParties(
        RentalTransaction $transaction,
        ?RentalExtension $extension = null,
        bool $lockUsers = true
    ): void {
        $userIds = [
            $transaction->owner_id,
            $transaction->borrower_id,
        ];

        if ($extension !== null) {
            $userIds[] = $extension->proposer_id;
        }

        $usersQuery = User::query()
            ->whereKey(array_values(array_unique($userIds)))
            ->orderBy('id');
        if ($lockUsers) {
            $usersQuery->lockForUpdate();
        }
        $partyRoles = $usersQuery->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($transaction->owner_id) === 'student'
                && $partyRoles->get($transaction->borrower_id) === 'student',
            403,
            'Owner dan Borrower transaksi harus ber-role student.'
        );

        if ($extension !== null) {
            abort_unless(
                (string) $extension->proposer_id === (string) $transaction->borrower_id
                    && $partyRoles->get($extension->proposer_id) === 'student',
                403,
                'Proposer Extension harus merupakan Borrower ber-role student.'
            );
        }
    }
}
