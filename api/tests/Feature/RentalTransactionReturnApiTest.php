<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Payment;
use App\Models\RentalExtension;
use App\Models\RentalNegotiation;
use App\Models\RentalRequest;
use App\Models\RentalTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalTransactionReturnApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_either_return_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->patchJson($this->borrowerEndpoint($transaction))->assertUnauthorized();
        $this->patchJson($this->ownerEndpoint($transaction))->assertUnauthorized();
    }

    public function test_suspended_and_deactivated_accounts_cannot_access_return_endpoints(): void
    {
        $borrowerReturnedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');

        foreach (['suspended', 'deactivated'] as $accountStatus) {
            [$owner, $borrower, $transaction] = $this->createTransaction(
                'return_pending_confirmation',
                null,
                null,
                'student',
                'student',
                $borrowerReturnedAt
            );
            $this->setAccountStatus($owner, $accountStatus);
            $this->setAccountStatus($borrower, $accountStatus);

            $this->actingAsToken($borrower)
                ->patchJson($this->borrowerEndpoint($transaction))
                ->assertForbidden();
            $this->actingAsToken($owner)
                ->patchJson($this->ownerEndpoint($transaction))
                ->assertForbidden();

            $transaction->refresh();
            $this->assertSame('return_pending_confirmation', $transaction->status);
            $this->assertSame(
                $borrowerReturnedAt->toDateTimeString(),
                $transaction->borrower_returned_at->toDateTimeString()
            );
            $this->assertNull($transaction->owner_return_received_at);
        }
    }

    public function test_student_borrower_can_start_return_from_active_transaction(): void
    {
        $returnedAt = $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC')
        );

        $response = $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'return_pending_confirmation');

        $transaction->refresh();
        $this->assertSame('return_pending_confirmation', $transaction->status);
        $this->assertSame(
            $returnedAt->toDateTimeString(),
            $transaction->borrower_returned_at->toDateTimeString()
        );
        $this->assertNull($transaction->overdue_at);
        $this->assertNull($transaction->owner_return_received_at);
        $this->assertSame(
            $returnedAt->toDateTimeString(),
            CarbonImmutable::parse($response->json('data.borrower_returned_at'))->toDateTimeString()
        );
        $this->assertNull($response->json('data.owner_return_received_at'));
    }

    public function test_student_borrower_can_start_return_from_overdue_transaction_without_overwriting_overdue_at(): void
    {
        $returnedAt = $this->freezeAt('2035-05-12 09:00:00');
        $overdueAt = CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'overdue',
            CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC'),
            $overdueAt
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'return_pending_confirmation');

        $transaction->refresh();
        $this->assertSame('return_pending_confirmation', $transaction->status);
        $this->assertSame(
            $returnedAt->toDateTimeString(),
            $transaction->borrower_returned_at->toDateTimeString()
        );
        $this->assertSame(
            $overdueAt->toDateTimeString(),
            $transaction->overdue_at->toDateTimeString()
        );
    }

    public function test_overdue_transaction_with_null_overdue_at_keeps_it_null_during_return(): void
    {
        $this->freezeAt('2035-05-12 09:00:00');
        $dueAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'overdue',
            $dueAt
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'return_pending_confirmation');

        $transaction->refresh();
        $this->assertNotNull($transaction->borrower_returned_at);
        $this->assertNull($transaction->overdue_at);
        $this->assertSame($dueAt->toDateTimeString(), $transaction->due_at->toDateTimeString());
        $this->assertSame('return_pending_confirmation', $transaction->status);
    }

    public function test_late_active_return_sets_missing_overdue_at_to_return_time(): void
    {
        $returnedAt = $this->freezeAt('2035-05-12 09:00:00');
        $dueAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            $dueAt
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'return_pending_confirmation');

        $transaction->refresh();
        $this->assertSame(
            $returnedAt->toDateTimeString(),
            $transaction->borrower_returned_at->toDateTimeString()
        );
        $this->assertSame(
            $returnedAt->toDateTimeString(),
            $transaction->overdue_at->toDateTimeString()
        );
        $this->assertSame($dueAt->toDateTimeString(), $transaction->due_at->toDateTimeString());
        $this->assertSame('return_pending_confirmation', $transaction->status);
    }

    public function test_late_active_return_preserves_existing_overdue_at(): void
    {
        $this->freezeAt('2035-05-12 09:00:00');
        $dueAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $overdueAt = CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            $dueAt,
            $overdueAt
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $transaction->refresh();
        $this->assertSame(
            $overdueAt->toDateTimeString(),
            $transaction->overdue_at->toDateTimeString()
        );
        $this->assertSame($dueAt->toDateTimeString(), $transaction->due_at->toDateTimeString());
        $this->assertSame('return_pending_confirmation', $transaction->status);
    }

    public function test_borrower_return_does_not_overwrite_existing_owner_return_timestamp(): void
    {
        $ownerReturnReceivedAt = CarbonImmutable::parse('2035-05-09 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            null,
            null,
            'student',
            'student',
            null,
            $ownerReturnReceivedAt
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $transaction->refresh();
        $this->assertSame(
            $ownerReturnReceivedAt->toDateTimeString(),
            $transaction->owner_return_received_at->toDateTimeString()
        );
        $this->assertNotNull($transaction->borrower_returned_at);
        $this->assertSame('return_pending_confirmation', $transaction->status);
    }

    public function test_admin_borrower_cannot_start_return_without_changing_transaction(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            null,
            null,
            'student',
            'admin'
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'active',
            'borrower_returned_at' => null,
            'owner_return_received_at' => null,
            'overdue_at' => null,
        ], 'mysql');
    }

    public function test_student_borrower_cannot_start_return_when_owner_is_admin(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            null,
            null,
            'admin',
            'student'
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'active',
            'borrower_returned_at' => null,
            'owner_return_received_at' => null,
            'overdue_at' => null,
        ], 'mysql');
    }

    public function test_non_borrower_cannot_start_return(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($this->createUser())
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'active',
            'borrower_returned_at' => null,
        ], 'mysql');
    }

    public function test_status_other_than_active_or_overdue_rejects_borrower_return(): void
    {
        foreach ([
            'awaiting_payment',
            'awaiting_payment_confirmation',
            'awaiting_handover',
            'return_pending_confirmation',
            'completed',
            'cancelled',
            'disputed',
        ] as $status) {
            [$owner, $borrower, $transaction] = $this->createTransaction($status);

            $this->actingAsToken($borrower)
                ->patchJson($this->borrowerEndpoint($transaction))
                ->assertConflict();

            $this->assertDatabaseHas('rental_transactions', [
                'id' => $transaction->id,
                'status' => $status,
                'borrower_returned_at' => null,
                'owner_return_received_at' => null,
            ], 'mysql');
        }
    }

    public function test_repeated_borrower_return_is_rejected_without_overwriting_first_timestamp(): void
    {
        $firstReturnAt = $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $this->freezeAt('2035-05-10 09:05:00');
        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();
        $this->assertSame(
            $firstReturnAt->toDateTimeString(),
            $transaction->borrower_returned_at->toDateTimeString()
        );
        $this->assertNull($transaction->owner_return_received_at);
        $this->assertSame('return_pending_confirmation', $transaction->status);
    }

    public function test_student_owner_can_confirm_return_after_borrower_starts_it(): void
    {
        $borrowerReturnedAt = $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $ownerReceivedAt = $this->freezeAt('2035-05-10 09:05:00');
        $response = $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $transaction->refresh();
        $this->assertSame(
            $borrowerReturnedAt->toDateTimeString(),
            $transaction->borrower_returned_at->toDateTimeString()
        );
        $this->assertSame(
            $ownerReceivedAt->toDateTimeString(),
            $transaction->owner_return_received_at->toDateTimeString()
        );
        $this->assertSame('completed', $transaction->status);
        $this->assertNull($transaction->completed_at);
        $this->assertSame(
            $borrowerReturnedAt->toDateTimeString(),
            CarbonImmutable::parse($response->json('data.borrower_returned_at'))->toDateTimeString()
        );
        $this->assertSame(
            $ownerReceivedAt->toDateTimeString(),
            CarbonImmutable::parse($response->json('data.owner_return_received_at'))->toDateTimeString()
        );
    }

    public function test_admin_owner_cannot_confirm_return_without_changing_transaction(): void
    {
        $borrowerReturnedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'return_pending_confirmation',
            null,
            null,
            'admin',
            'student',
            $borrowerReturnedAt
        );

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'return_pending_confirmation',
            'borrower_returned_at' => $borrowerReturnedAt->toDateTimeString(),
            'owner_return_received_at' => null,
            'completed_at' => null,
        ], 'mysql');
    }

    public function test_student_owner_cannot_confirm_return_when_borrower_is_admin(): void
    {
        $borrowerReturnedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'return_pending_confirmation',
            null,
            null,
            'student',
            'admin',
            $borrowerReturnedAt
        );

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'return_pending_confirmation',
            'borrower_returned_at' => $borrowerReturnedAt->toDateTimeString(),
            'owner_return_received_at' => null,
            'completed_at' => null,
        ], 'mysql');
    }

    public function test_borrower_and_outside_user_cannot_confirm_owner_return(): void
    {
        $borrowerReturnedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'return_pending_confirmation',
            null,
            null,
            'student',
            'student',
            $borrowerReturnedAt
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($this->createUser())
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'return_pending_confirmation',
            'borrower_returned_at' => $borrowerReturnedAt->toDateTimeString(),
            'owner_return_received_at' => null,
        ], 'mysql');
    }

    public function test_owner_cannot_confirm_return_before_borrower_starts_it(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertConflict();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'active',
            'borrower_returned_at' => null,
            'owner_return_received_at' => null,
            'completed_at' => null,
        ], 'mysql');
    }

    public function test_owner_cannot_confirm_return_twice_or_overwrite_first_timestamp(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'return_pending_confirmation',
            null,
            null,
            'student',
            'student',
            CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC')
        );
        $firstReceivedAt = $this->freezeAt('2035-05-10 09:05:00');

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk();

        $this->freezeAt('2035-05-10 09:10:00');
        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();
        $this->assertSame(
            $firstReceivedAt->toDateTimeString(),
            $transaction->owner_return_received_at->toDateTimeString()
        );
        $this->assertSame('completed', $transaction->status);
        $this->assertNull($transaction->completed_at);
    }

    public function test_owner_cannot_confirm_return_when_borrower_timestamp_is_missing(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'return_pending_confirmation'
        );

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertConflict();

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'return_pending_confirmation',
            'borrower_returned_at' => null,
            'owner_return_received_at' => null,
            'completed_at' => null,
        ], 'mysql');
    }

    public function test_borrower_return_withdraws_pending_extension_and_keeps_history(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'pending');

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $extension->refresh();
        $this->assertSame('withdrawn', $extension->status);
        $this->assertDatabaseHas('rental_extensions', [
            'id' => $extension->id,
            'transaction_id' => $transaction->id,
            'status' => 'withdrawn',
        ], 'mysql');
    }

    public function test_borrower_return_withdraws_accepted_extension_without_active_payment(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $extension->refresh();
        $this->assertSame('withdrawn', $extension->status);
        $this->assertDatabaseHas('rental_extensions', [
            'id' => $extension->id,
            'status' => 'withdrawn',
        ], 'mysql');
    }

    public function test_return_withdraws_accepted_extension_and_rejects_reported_payment_without_deleting_proof(): void
    {
        Storage::fake('local');
        $returnStartedAt = $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');
        $proofPath = 'payments/'.$transaction->id.'/extensions/'.$extension->id.'/proof.pdf';
        Storage::disk('local')->put($proofPath, 'private payment proof');
        $payment = $this->createExtensionPayment($transaction, $extension, 'reported', $proofPath);
        $reportedAt = $payment->reported_at->toDateTimeString();
        $amount = $payment->amount;
        $paymentMethod = $payment->payment_method;
        $paymentNote = $payment->payment_note;

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $extension->refresh();
        $payment->refresh();
        $this->assertSame('withdrawn', $extension->status);
        $this->assertSame('rejected', $payment->payment_status);
        $this->assertNull($payment->rejected_by);
        $this->assertSame($returnStartedAt->toDateTimeString(), $payment->rejected_at->toDateTimeString());
        $this->assertSame(
            'Payment otomatis ditolak karena Borrower memulai proses Return.',
            $payment->rejection_note
        );
        $this->assertSame($reportedAt, $payment->reported_at->toDateTimeString());
        $this->assertSame($amount, $payment->amount);
        $this->assertSame($paymentMethod, $payment->payment_method);
        $this->assertSame($paymentNote, $payment->payment_note);
        $this->assertSame($proofPath, $payment->proof_path);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => 'rejected',
            'rejected_by' => null,
            'proof_path' => $proofPath,
        ], 'mysql');
        Storage::disk('local')->assertExists($proofPath);
    }

    public function test_return_keeps_accepted_extension_and_confirmed_payment_unchanged(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');
        $payment = $this->createExtensionPayment($transaction, $extension, 'confirmed');
        $paymentBefore = $payment->refresh()->getAttributes();
        $dueAtBefore = $transaction->due_at->toDateTimeString();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $extension->refresh();
        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('accepted', $extension->status);
        $this->assertSame($paymentBefore, $payment->getAttributes());
        $this->assertSame($dueAtBefore, $transaction->due_at->toDateTimeString());
    }

    public function test_return_keeps_rejected_payment_history_when_withdrawing_accepted_extension(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');
        $payment = $this->createExtensionPayment($transaction, $extension, 'rejected');
        $paymentBefore = $payment->refresh()->getAttributes();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $extension->refresh();
        $payment->refresh();
        $this->assertSame('withdrawn', $extension->status);
        $this->assertSame($paymentBefore, $payment->getAttributes());
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => 'rejected',
        ], 'mysql');
    }

    public function test_return_preserves_rejected_withdrawn_and_superseded_extension_history(): void
    {
        foreach (['rejected', 'withdrawn', 'superseded'] as $status) {
            [$owner, $borrower, $transaction] = $this->createTransaction();
            $extension = $this->createExtension($transaction, $status);
            $extensionBefore = $extension->refresh()->getAttributes();
            $payment = $this->createExtensionPayment($transaction, $extension, 'rejected');
            $paymentBefore = $payment->refresh()->getAttributes();

            $this->actingAsToken($borrower)
                ->patchJson($this->borrowerEndpoint($transaction))
                ->assertOk();

            $extension->refresh();
            $payment->refresh();
            $this->assertSame($extensionBefore, $extension->getAttributes());
            $this->assertSame($paymentBefore, $payment->getAttributes());
        }
    }

    public function test_return_rejects_extensions_with_non_borrower_proposers_for_every_status(): void
    {
        Storage::fake('local');
        $this->freezeAt('2035-05-12 09:00:00');
        $cases = [
            ['pending', 'student'],
            ['accepted', 'admin'],
            ['rejected', 'student'],
            ['withdrawn', 'student'],
            ['superseded', 'student'],
        ];

        foreach ($cases as [$extensionStatus, $proposerRole]) {
            [$owner, $borrower, $transaction] = $this->createTransaction(
                'active',
                CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC')
            );
            $proposer = $this->createUser('active', $proposerRole);
            $extension = $this->createExtension(
                $transaction,
                $extensionStatus,
                $proposer->id
            );
            $proofPath = 'payments/'.$transaction->id.'/extensions/'.$extension->id.'/proof.pdf';
            Storage::disk('local')->put($proofPath, 'historical rejected payment proof');
            $payment = $this->createExtensionPayment(
                $transaction,
                $extension,
                'rejected',
                $proofPath
            );

            $transactionBefore = $transaction->refresh()->getAttributes();
            $extensionBefore = $extension->refresh()->getAttributes();
            $paymentBefore = $payment->refresh()->getAttributes();

            $this->actingAsToken($borrower)
                ->patchJson($this->borrowerEndpoint($transaction))
                ->assertConflict();

            $transaction->refresh();
            $extension->refresh();
            $payment->refresh();
            $this->assertSame($transactionBefore, $transaction->getAttributes());
            $this->assertSame($extensionBefore, $extension->getAttributes());
            $this->assertSame($paymentBefore, $payment->getAttributes());
            $this->assertSame(
                1,
                RentalExtension::on('mysql')
                    ->where('transaction_id', $transaction->id)
                    ->count()
            );
            $this->assertSame(
                1,
                Payment::on('mysql')
                    ->where('transaction_id', $transaction->id)
                    ->where('payment_type', 'extension_fee')
                    ->count()
            );
            Storage::disk('local')->assertExists($proofPath);
        }
    }

    public function test_invalid_proposer_on_later_extension_rejects_return_before_any_extension_or_payment_changes(): void
    {
        Storage::fake('local');
        $this->freezeAt('2035-05-12 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'active',
            CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC')
        );
        $pendingExtension = $this->createExtension($transaction, 'pending');
        $acceptedExtension = $this->createExtension($transaction, 'accepted');
        $reportedProofPath = 'payments/'.$transaction->id.'/extensions/'.$acceptedExtension->id.'/reported.pdf';
        Storage::disk('local')->put($reportedProofPath, 'reported payment proof');
        $reportedPayment = $this->createExtensionPayment(
            $transaction,
            $acceptedExtension,
            'reported',
            $reportedProofPath
        );

        $otherStudent = $this->createUser();
        $invalidExtension = $this->createExtension(
            $transaction,
            'pending',
            $otherStudent->id
        );
        $historicalProofPath = 'payments/'.$transaction->id.'/extensions/'.$invalidExtension->id.'/rejected.pdf';
        Storage::disk('local')->put($historicalProofPath, 'rejected payment proof');
        $historicalPayment = $this->createExtensionPayment(
            $transaction,
            $invalidExtension,
            'rejected',
            $historicalProofPath
        );

        $transactionBefore = $transaction->refresh()->getAttributes();
        $extensionsBefore = RentalExtension::on('mysql')
            ->where('transaction_id', $transaction->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (RentalExtension $extension): array => $extension->getAttributes())
            ->all();
        $paymentsBefore = Payment::on('mysql')
            ->where('transaction_id', $transaction->id)
            ->where('payment_type', 'extension_fee')
            ->orderBy('id')
            ->get()
            ->map(static fn (Payment $payment): array => $payment->getAttributes())
            ->all();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();
        $extensionsAfter = RentalExtension::on('mysql')
            ->where('transaction_id', $transaction->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (RentalExtension $extension): array => $extension->getAttributes())
            ->all();
        $paymentsAfter = Payment::on('mysql')
            ->where('transaction_id', $transaction->id)
            ->where('payment_type', 'extension_fee')
            ->orderBy('id')
            ->get()
            ->map(static fn (Payment $payment): array => $payment->getAttributes())
            ->all();

        $this->assertSame($transactionBefore, $transaction->getAttributes());
        $this->assertSame($extensionsBefore, $extensionsAfter);
        $this->assertSame($paymentsBefore, $paymentsAfter);
        $this->assertSame(3, count($extensionsAfter));
        $this->assertSame(2, count($paymentsAfter));
        Storage::disk('local')->assertExists($reportedProofPath);
        Storage::disk('local')->assertExists($historicalProofPath);
    }

    public function test_return_processes_multiple_extensions_and_ignores_rental_fee_payment(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $confirmedExtension = $this->createExtension($transaction, 'accepted');
        $confirmedPayment = $this->createExtensionPayment(
            $transaction,
            $confirmedExtension,
            'confirmed'
        );
        $confirmedPaymentBefore = $confirmedPayment->refresh()->getAttributes();

        $pendingExtension = $this->createExtension($transaction, 'pending');
        $unpaidExtension = $this->createExtension($transaction, 'accepted');
        $reportedExtension = $this->createExtension($transaction, 'accepted');
        $reportedPayment = $this->createExtensionPayment(
            $transaction,
            $reportedExtension,
            'reported'
        );
        $rentalFeePayment = $this->createRentalFeePayment($transaction);
        $rentalFeePaymentBefore = $rentalFeePayment->refresh()->getAttributes();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $confirmedExtension->refresh();
        $confirmedPayment->refresh();
        $pendingExtension->refresh();
        $unpaidExtension->refresh();
        $reportedExtension->refresh();
        $reportedPayment->refresh();
        $rentalFeePayment->refresh();

        $this->assertSame('accepted', $confirmedExtension->status);
        $this->assertSame($confirmedPaymentBefore, $confirmedPayment->getAttributes());
        $this->assertSame('withdrawn', $pendingExtension->status);
        $this->assertSame('withdrawn', $unpaidExtension->status);
        $this->assertSame('withdrawn', $reportedExtension->status);
        $this->assertSame('rejected', $reportedPayment->payment_status);
        $this->assertSame($rentalFeePaymentBefore, $rentalFeePayment->getAttributes());
        $this->assertSame(
            4,
            RentalExtension::on('mysql')
                ->where('transaction_id', $transaction->id)
                ->count()
        );
    }

    public function test_extension_payment_anomalies_reject_return_without_changing_any_data(): void
    {
        Storage::fake('local');
        $this->freezeAt('2035-05-12 09:00:00');
        $cases = [
            ['accepted', ['reported', 'confirmed']],
            ['accepted', ['reported', 'reported']],
            ['accepted', ['confirmed', 'confirmed']],
            ['pending', ['reported']],
            ['rejected', ['confirmed']],
            ['withdrawn', ['reported']],
            ['superseded', ['confirmed']],
        ];

        foreach ($cases as [$extensionStatus, $paymentStatuses]) {
            [$owner, $borrower, $transaction] = $this->createTransaction(
                'active',
                CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC')
            );
            $extension = $this->createExtension($transaction, $extensionStatus);
            $proofPath = 'payments/'.$transaction->id.'/extensions/'.$extension->id.'/proof.pdf';
            Storage::disk('local')->put($proofPath, 'proof that must be retained');

            $payments = [];
            foreach ($paymentStatuses as $paymentIndex => $paymentStatus) {
                $payments[] = $this->createExtensionPayment(
                    $transaction,
                    $extension,
                    $paymentStatus,
                    $paymentIndex === 0 ? $proofPath : null
                );
            }

            $transactionBefore = $transaction->refresh()->getAttributes();
            $extensionBefore = $extension->refresh()->getAttributes();
            $paymentsBefore = array_map(
                static fn (Payment $payment): array => $payment->refresh()->getAttributes(),
                $payments
            );

            $this->actingAsToken($borrower)
                ->patchJson($this->borrowerEndpoint($transaction))
                ->assertConflict();

            $transaction->refresh();
            $extension->refresh();
            $this->assertSame($transactionBefore, $transaction->getAttributes());
            $this->assertSame($extensionBefore, $extension->getAttributes());
            $this->assertSame(
                $paymentsBefore,
                array_map(
                    static fn (Payment $payment): array => $payment->refresh()->getAttributes(),
                    $payments
                )
            );
            $this->assertSame(
                1,
                RentalExtension::on('mysql')
                    ->where('transaction_id', $transaction->id)
                    ->count()
            );
            $this->assertSame(
                count($paymentStatuses),
                Payment::on('mysql')
                    ->where('transaction_id', $transaction->id)
                    ->where('payment_type', 'extension_fee')
                    ->count()
            );

            Storage::disk('local')->assertExists($proofPath);
        }
    }

    private function createTransaction(
        string $status = 'active',
        ?CarbonImmutable $dueAt = null,
        ?CarbonImmutable $overdueAt = null,
        string $ownerRole = 'student',
        string $borrowerRole = 'student',
        ?CarbonImmutable $borrowerReturnedAt = null,
        ?CarbonImmutable $ownerReturnReceivedAt = null
    ): array {
        $owner = $this->createUser('active', $ownerRole);
        $borrower = $this->createUser('active', $borrowerRole);
        $dueAt ??= CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC');
        $startAt = $dueAt->subDay();

        $category = Category::on('mysql')->create([
            'name' => 'Return category '.Str::uuid(),
            'is_active' => true,
        ]);

        $item = Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Return item '.Str::uuid(),
            'description' => 'Item fixture for return testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => 'active',
        ]);

        $rentalRequest = new RentalRequest();
        $rentalRequest->setConnection('mysql');
        $rentalRequest->item_id = $item->id;
        $rentalRequest->borrower_id = $borrower->id;
        $rentalRequest->status = 'accepted';
        $rentalRequest->save();

        $negotiation = new RentalNegotiation();
        $negotiation->setConnection('mysql');
        $negotiation->request_id = $rentalRequest->id;
        $negotiation->proposer_id = $borrower->id;
        $negotiation->proposed_daily_price = '25.50';
        $negotiation->proposed_start_at = $startAt;
        $negotiation->proposed_due_at = $dueAt;
        $negotiation->status = 'accepted';
        $negotiation->save();

        $transaction = new RentalTransaction();
        $transaction->setConnection('mysql');
        $transaction->request_id = $rentalRequest->id;
        $transaction->accepted_negotiation_id = $negotiation->id;
        $transaction->item_id = $item->id;
        $transaction->owner_id = $owner->id;
        $transaction->borrower_id = $borrower->id;
        $transaction->agreed_daily_price = '25.50';
        $transaction->total_price = '25.50';
        $transaction->start_at = $startAt;
        $transaction->due_at = $dueAt;
        $transaction->overdue_at = $overdueAt;
        $transaction->borrower_returned_at = $borrowerReturnedAt;
        $transaction->owner_return_received_at = $ownerReturnReceivedAt;
        $transaction->status = $status;
        $transaction->save();

        return [$owner, $borrower, $transaction];
    }

    private function createExtension(
        RentalTransaction $transaction,
        string $status,
        ?int $proposerId = null
    ): RentalExtension {
        $extension = new RentalExtension();
        $extension->setConnection('mysql');
        $extension->transaction_id = $transaction->id;
        $extension->proposer_id = $proposerId ?? $transaction->borrower_id;
        $extension->previous_extension_id = null;
        $extension->previous_due_at = $transaction->due_at;
        $extension->proposed_due_at = $transaction->due_at->addDay();
        $extension->proposed_fee = '25.50';
        $extension->message = null;
        $extension->status = $status;
        $extension->responded_by = in_array($status, ['accepted', 'rejected'], true)
            ? $transaction->owner_id
            : null;
        $extension->responded_at = in_array($status, ['accepted', 'rejected'], true)
            ? now()
            : null;
        $extension->save();

        return $extension;
    }

    private function createExtensionPayment(
        RentalTransaction $transaction,
        RentalExtension $extension,
        string $status,
        ?string $proofPath = null
    ): Payment {
        $payment = new Payment();
        $payment->setConnection('mysql');
        $payment->transaction_id = $transaction->id;
        $payment->extension_id = $extension->id;
        $payment->reported_by = $transaction->borrower_id;
        $payment->payment_method = 'bank_transfer';
        $payment->payment_type = 'extension_fee';
        $payment->amount = $extension->proposed_fee;
        $payment->proof_path = $proofPath;
        $payment->payment_note = 'Return integration fixture.';
        $payment->payment_status = $status;
        $payment->reported_at = now();
        $payment->confirmed_by = $status === 'confirmed'
            ? $transaction->owner_id
            : null;
        $payment->confirmed_at = $status === 'confirmed'
            ? now()
            : null;
        $payment->rejected_by = $status === 'rejected'
            ? $transaction->owner_id
            : null;
        $payment->rejected_at = $status === 'rejected'
            ? now()
            : null;
        $payment->rejection_note = $status === 'rejected'
            ? 'Historical rejection.'
            : null;
        $payment->save();

        return $payment;
    }

    private function createRentalFeePayment(RentalTransaction $transaction): Payment
    {
        $payment = new Payment();
        $payment->setConnection('mysql');
        $payment->transaction_id = $transaction->id;
        $payment->extension_id = null;
        $payment->reported_by = $transaction->borrower_id;
        $payment->payment_method = 'cash';
        $payment->payment_type = 'rental_fee';
        $payment->amount = $transaction->total_price;
        $payment->payment_status = 'reported';
        $payment->reported_at = now();
        $payment->save();

        return $payment;
    }

    private function createUser(string $accountStatus = 'active', string $role = 'student'): User
    {
        $user = User::factory()->create();
        $user->account_status = $accountStatus;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function setAccountStatus(User $user, string $status): void
    {
        $user->account_status = $status;
        $user->save();
    }

    private function freezeAt(string $time): CarbonImmutable
    {
        $date = CarbonImmutable::parse($time, 'UTC');
        $this->travelTo($date);

        return $date;
    }

    private function borrowerEndpoint(RentalTransaction $transaction): string
    {
        return "/api/rental-transactions/{$transaction->id}/return/borrower-confirm";
    }

    private function ownerEndpoint(RentalTransaction $transaction): string
    {
        return "/api/rental-transactions/{$transaction->id}/return/owner-confirm";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('rental-transaction-return-test')->plainTextToken);
    }
}
