<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RejectPaymentRequest;
use App\Http\Requests\Payment\ReportPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\RentalTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request, RentalTransaction $transaction): AnonymousResourceCollection
    {
        $this->ensurePartyCanView($request, $transaction);

        return PaymentResource::collection(
            $transaction->payments()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(ReportPaymentRequest $request, RentalTransaction $transaction): JsonResponse
    {
        $storedPath = null;

        try {
            $payment = DB::transaction(function () use ($request, $transaction, &$storedPath): Payment {
                $lockedTransaction = RentalTransaction::query()
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $user = $request->user();
                $this->ensureStudentParties($lockedTransaction, true);
                abort_unless(
                    (string) $lockedTransaction->borrower_id === (string) $user->getAuthIdentifier(),
                    403,
                    'Hanya peminjam terkait yang dapat melaporkan pembayaran.'
                );
                abort_unless(
                    $lockedTransaction->status === 'awaiting_payment',
                    409,
                    'Rental Transaction tidak menunggu pembayaran.'
                );

                $blockingPaymentExists = $lockedTransaction->payments()
                    ->where('payment_type', 'rental_fee')
                    ->whereIn('payment_status', ['reported', 'confirmed'])
                    ->lockForUpdate()
                    ->exists();

                abort_if(
                    $blockingPaymentExists,
                    409,
                    'Rental Transaction sudah memiliki laporan atau pembayaran rental fee yang aktif.'
                );

                $proof = $request->file('proof');
                if ($proof) {
                    $extension = strtolower($proof->getClientOriginalExtension());
                    $path = $proof->storeAs(
                        'payments/'.$lockedTransaction->getKey(),
                        Str::uuid().'.'.$extension,
                        'local'
                    );

                    if ($path === false) {
                        throw new RuntimeException('Unable to store payment proof.');
                    }

                    $storedPath = $path;
                }

                $payment = $lockedTransaction->payments()->make([
                    'reported_by' => $user->getAuthIdentifier(),
                    'payment_method' => $request->validated('payment_method'),
                    'payment_type' => 'rental_fee',
                    'amount' => $lockedTransaction->total_price,
                    'proof_path' => $storedPath,
                    'payment_note' => $request->validated('payment_note'),
                    'extension_id' => null,
                ]);
                $payment->payment_status = 'reported';
                $payment->reported_at = now();
                $payment->confirmed_by = null;
                $payment->confirmed_at = null;
                $payment->rejected_by = null;
                $payment->rejected_at = null;
                $payment->rejection_note = null;
                $payment->save();

                $lockedTransaction->status = 'awaiting_payment_confirmation';
                $lockedTransaction->save();

                return $payment;
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                $this->cleanupProof($storedPath, $transaction->getKey());
            }

            throw $exception;
        }

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment reported successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function confirm(Request $request, RentalTransaction $transaction, Payment $payment): JsonResponse
    {
        $payment = DB::transaction(function () use ($request, $transaction, $payment): Payment {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = $this->lockedPayment($lockedTransaction, $payment);
            $this->ensureOwner($request, $lockedTransaction);
            abort_unless(
                $lockedPayment->payment_status === 'reported',
                409,
                'Payment tidak dalam status reported.'
            );

            abort_unless(
                $lockedTransaction->status === 'awaiting_payment_confirmation',
                409,
                'Rental Transaction tidak menunggu konfirmasi pembayaran.'
            );

            $lockedPayment->payment_status = 'confirmed';
            $lockedPayment->confirmed_by = $request->user()->getAuthIdentifier();
            $lockedPayment->confirmed_at = now();
            $lockedPayment->rejected_by = null;
            $lockedPayment->rejected_at = null;
            $lockedPayment->rejection_note = null;
            $lockedPayment->save();

            $lockedTransaction->status = 'awaiting_handover';
            $lockedTransaction->save();

            return $lockedPayment;
        });

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment confirmed successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    public function reject(
        RejectPaymentRequest $request,
        RentalTransaction $transaction,
        Payment $payment
    ): JsonResponse {
        $payment = DB::transaction(function () use ($request, $transaction, $payment): Payment {
            $lockedTransaction = RentalTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = $this->lockedPayment($lockedTransaction, $payment);
            $this->ensureOwner($request, $lockedTransaction);
            abort_unless(
                $lockedPayment->payment_status === 'reported',
                409,
                'Payment tidak dalam status reported.'
            );

            abort_unless(
                $lockedTransaction->status === 'awaiting_payment_confirmation',
                409,
                'Rental Transaction tidak menunggu konfirmasi pembayaran.'
            );

            $lockedPayment->payment_status = 'rejected';
            $lockedPayment->rejected_by = $request->user()->getAuthIdentifier();
            $lockedPayment->rejected_at = now();
            $lockedPayment->rejection_note = $request->validated('rejection_note');
            $lockedPayment->confirmed_by = null;
            $lockedPayment->confirmed_at = null;
            $lockedPayment->save();

            $lockedTransaction->status = 'awaiting_payment';
            $lockedTransaction->save();

            return $lockedPayment;
        });

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment rejected successfully.'])
            ->response()
            ->setStatusCode(200);
    }

    public function proof(
        Request $request,
        RentalTransaction $transaction,
        Payment $payment
    ): StreamedResponse {
        $this->ensurePartyCanView($request, $transaction);

        $lockedPayment = $transaction->payments()
            ->whereKey($payment->getKey())
            ->firstOrFail();

        $proofPath = $lockedPayment->proof_path;
        abort_unless($proofPath, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($proofPath), 404);

        $mimeType = $disk->mimeType($proofPath) ?: 'application/octet-stream';

        return $disk->response($proofPath, headers: [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function lockedPayment(
        RentalTransaction $transaction,
        Payment $payment
    ): Payment {
        return $transaction->payments()
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureOwner(Request $request, RentalTransaction $transaction): void
    {
        $this->ensureStudentParties($transaction, true);

        abort_unless(
            (string) $transaction->owner_id === (string) $request->user()->getAuthIdentifier(),
            403,
            'Hanya pemilik barang yang dapat memverifikasi pembayaran.'
        );
    }

    private function ensurePartyCanView(Request $request, RentalTransaction $transaction): void
    {
        $this->ensureStudentParties($transaction);

        $userId = (string) $request->user()->getAuthIdentifier();
        $isBorrower = (string) $transaction->borrower_id === $userId;
        $isOwner = (string) $transaction->owner_id === $userId;

        abort_unless($isBorrower || $isOwner, 403, 'Anda tidak memiliki akses terhadap pembayaran ini.');
    }

    private function ensureStudentParties(
        RentalTransaction $transaction,
        bool $lock = false
    ): void {
        $query = User::query()
            ->whereKey([$transaction->owner_id, $transaction->borrower_id])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $partyRoles = $query->pluck('role', 'id');

        abort_unless(
            $partyRoles->get($transaction->owner_id) === 'student'
                && $partyRoles->get($transaction->borrower_id) === 'student',
            403,
            'Kedua pihak transaksi harus ber-role mahasiswa.'
        );
    }

    private function cleanupProof(string $path, int $transactionId): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                $this->logCleanupFailure('Failed to clean up payment proof after upload failure.', [
                    'transaction_id' => $transactionId,
                    'path' => $path,
                ]);
            }
        } catch (Throwable $cleanupException) {
            $this->logCleanupFailure('Failed to clean up payment proof after upload failure.', [
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
