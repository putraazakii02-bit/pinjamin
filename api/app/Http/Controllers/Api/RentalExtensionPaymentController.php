<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RejectPaymentRequest;
use App\Http\Requests\Payment\ReportPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\RentalExtension;
use App\Models\RentalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RentalExtensionPaymentController extends Controller
{
    public function store(
        ReportPaymentRequest $request,
        RentalTransaction $transaction,
        RentalExtension $extension
    ): JsonResponse {
        $storedPath = null;

        try {
            $payment = DB::transaction(function () use (
                $request,
                $transaction,
                $extension,
                &$storedPath
            ): Payment {
                $lockedTransaction = RentalTransaction::query()
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedExtension = RentalExtension::query()
                    ->whereKey($extension->getKey())
                    ->where('transaction_id', $lockedTransaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $payments = $this->lockExtensionPayments($lockedTransaction, $lockedExtension);
                $this->ensureStudentParties($lockedTransaction, $lockedExtension);

                abort_unless(
                    (string) $lockedTransaction->borrower_id
                        === (string) $request->user()->getAuthIdentifier(),
                    403,
                    'Hanya Borrower transaksi yang dapat melaporkan pembayaran Extension.'
                );
                $this->ensureConsistentPayments($payments);
                abort_unless(
                    in_array($lockedTransaction->status, ['active', 'overdue'], true),
                    409,
                    'Transaksi tidak dapat menerima pembayaran Extension pada status saat ini.'
                );
                abort_unless(
                    $lockedExtension->status === 'accepted',
                    409,
                    'Hanya Extension accepted yang dapat dibayar.'
                );
                abort_if(
                    $payments->contains(
                        static fn (Payment $existingPayment): bool => in_array(
                            $existingPayment->payment_status,
                            ['reported', 'confirmed'],
                            true
                        )
                    ),
                    409,
                    'Extension sudah memiliki Payment aktif atau terkonfirmasi.'
                );

                $proof = $request->file('proof');
                if ($proof !== null) {
                    $fileExtension = strtolower($proof->getClientOriginalExtension());
                    $path = $proof->storeAs(
                        'payments/'.$lockedTransaction->getKey().'/extensions/'.$lockedExtension->getKey(),
                        Str::uuid().'.'.$fileExtension,
                        'local'
                    );

                    if (! is_string($path) || $path === '') {
                        throw new RuntimeException('Unable to store Extension payment proof.');
                    }

                    $storedPath = $path;
                }

                $reportedAt = now();
                $payment = $lockedTransaction->payments()->make([
                    'extension_id' => $lockedExtension->getKey(),
                    'reported_by' => $lockedTransaction->borrower_id,
                    'payment_method' => $request->validated('payment_method'),
                    'payment_type' => 'extension_fee',
                    'amount' => $lockedExtension->proposed_fee,
                    'proof_path' => $storedPath,
                    'payment_note' => $request->validated('payment_note'),
                ]);
                $payment->payment_status = 'reported';
                $payment->reported_at = $reportedAt;
                $payment->confirmed_by = null;
                $payment->confirmed_at = null;
                $payment->rejected_by = null;
                $payment->rejected_at = null;
                $payment->rejection_note = null;
                $payment->save();

                return $payment;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                $this->cleanupProof($storedPath, (int) $transaction->getKey());
            }

            throw $exception;
        }

        return (new PaymentResource($payment))
            ->additional(['message' => 'Extension payment reported successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function confirm(
        Request $request,
        RentalTransaction $transaction,
        RentalExtension $extension,
        Payment $payment
    ): JsonResponse {
        return $this->review(
            $request,
            $transaction,
            $extension,
            $payment,
            'confirmed',
            null
        );
    }

    public function reject(
        RejectPaymentRequest $request,
        RentalTransaction $transaction,
        RentalExtension $extension,
        Payment $payment
    ): JsonResponse {
        return $this->review(
            $request,
            $transaction,
            $extension,
            $payment,
            'rejected',
            $request->validated('rejection_note')
        );
    }

    private function review(
        Request $request,
        RentalTransaction $transaction,
        RentalExtension $extension,
        Payment $payment,
        string $decision,
        ?string $rejectionNote
    ): JsonResponse {
        $payment = DB::transaction(function () use (
            $request,
            $transaction,
            $extension,
            $payment,
            $decision,
            $rejectionNote
        ): Payment {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExtension = RentalExtension::query()
                ->whereKey($extension->getKey())
                ->where('transaction_id', $lockedTransaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $payments = $this->lockExtensionPayments($lockedTransaction, $lockedExtension);
            $this->ensureStudentParties($lockedTransaction, $lockedExtension);
            abort_unless(
                (string) $lockedTransaction->owner_id
                    === (string) $request->user()->getAuthIdentifier(),
                403,
                'Hanya Owner transaksi yang dapat merespons pembayaran Extension.'
            );
            $lockedPayment = $payments->first(
                static fn (Payment $candidate): bool => (string) $candidate->getKey()
                    === (string) $payment->getKey()
            );
            abort_unless($lockedPayment instanceof Payment, 404);
            $this->ensureConsistentPayments($payments);
            abort_unless(
                in_array($lockedTransaction->status, ['active', 'overdue'], true),
                409,
                'Transaksi tidak dapat memproses pembayaran Extension pada status saat ini.'
            );
            abort_unless(
                $lockedExtension->status === 'accepted',
                409,
                'Extension harus berstatus accepted.'
            );
            abort_unless(
                $lockedPayment->payment_status === 'reported',
                409,
                'Hanya Payment reported yang dapat dikonfirmasi atau ditolak.'
            );

            $decisionAt = now();
            if ($decision === 'confirmed') {
                $lockedPayment->payment_status = 'confirmed';
                $lockedPayment->confirmed_by = $request->user()->getAuthIdentifier();
                $lockedPayment->confirmed_at = $decisionAt;
                $lockedPayment->rejected_by = null;
                $lockedPayment->rejected_at = null;
                $lockedPayment->rejection_note = null;
                $lockedPayment->save();

                $lockedTransaction->due_at = $lockedExtension->proposed_due_at;
                if ($lockedExtension->proposed_due_at->gt($decisionAt)) {
                    $lockedTransaction->status = 'active';
                } else {
                    $lockedTransaction->status = 'overdue';
                    if ($lockedTransaction->overdue_at === null) {
                        $lockedTransaction->overdue_at = $decisionAt;
                    }
                }
                $lockedTransaction->save();
            } else {
                $lockedPayment->payment_status = 'rejected';
                $lockedPayment->rejected_by = $request->user()->getAuthIdentifier();
                $lockedPayment->rejected_at = $decisionAt;
                $lockedPayment->rejection_note = $rejectionNote;
                $lockedPayment->confirmed_by = null;
                $lockedPayment->confirmed_at = null;
                $lockedPayment->save();
            }

            return $lockedPayment;
        });

        $message = $decision === 'confirmed'
            ? 'Extension payment confirmed successfully.'
            : 'Extension payment rejected successfully.';

        return (new PaymentResource($payment))
            ->additional(['message' => $message])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * @return EloquentCollection<int, Payment>
     */
    private function lockExtensionPayments(
        RentalTransaction $transaction,
        RentalExtension $extension
    ): EloquentCollection {
        return Payment::query()
            ->where('transaction_id', $transaction->getKey())
            ->where('extension_id', $extension->getKey())
            ->where('payment_type', 'extension_fee')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param EloquentCollection<int, Payment> $payments
     */
    private function ensureConsistentPayments(EloquentCollection $payments): void
    {
        $activePayments = $payments->filter(
            static fn (Payment $payment): bool => in_array(
                $payment->payment_status,
                ['reported', 'confirmed'],
                true
            )
        );
        $hasReported = $activePayments->contains('payment_status', 'reported');
        $hasConfirmed = $activePayments->contains('payment_status', 'confirmed');

        abort_if(
            $hasReported && $hasConfirmed,
            409,
            'Extension memiliki Payment reported dan confirmed secara bersamaan.'
        );
        abort_if(
            $activePayments->count() > 1,
            409,
            'Extension memiliki lebih dari satu Payment aktif.'
        );
    }

    private function ensureStudentParties(
        RentalTransaction $transaction,
        RentalExtension $extension
    ): void {
        $partyRoles = User::query()
            ->whereKey(array_values(array_unique([
                $transaction->owner_id,
                $transaction->borrower_id,
                $extension->proposer_id,
            ])))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($transaction->owner_id) === 'student'
                && $partyRoles->get($transaction->borrower_id) === 'student'
                && (string) $extension->proposer_id === (string) $transaction->borrower_id
                && $partyRoles->get($extension->proposer_id) === 'student',
            403,
            'Owner, Borrower, dan Proposer Extension harus merupakan Student terkait.'
        );
    }

    private function cleanupProof(string $path, int $transactionId): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                $this->logCleanupFailure('Failed to clean up Extension payment proof after upload failure.', [
                    'transaction_id' => $transactionId,
                    'path' => $path,
                ]);
            }
        } catch (Throwable $cleanupException) {
            $this->logCleanupFailure('Failed to clean up Extension payment proof after upload failure.', [
                'transaction_id' => $transactionId,
                'path' => $path,
                'cleanup_exception_class' => $cleanupException::class,
            ]);
        }
    }

    /**
     * @param array<string, int|string|null> $context
     */
    private function logCleanupFailure(string $message, array $context): void
    {
        try {
            Log::error($message, $context);
        } catch (Throwable $loggingException) {
            $context['logging_exception_class'] = $loggingException::class;
            error_log($message.' '.json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}
