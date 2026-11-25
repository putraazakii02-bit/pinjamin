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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RentalExtensionPaymentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_borrower_can_report_cash_with_server_owned_payment_fields(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        [, , $otherTransaction, $otherExtension] = $this->createFixture();

        $response = $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
                'payment_note' => 'Cash submitted.',
                'amount' => '0.01',
                'payment_type' => 'rental_fee',
                'extension_id' => $otherExtension->id,
                'transaction_id' => $otherTransaction->id,
                'reported_by' => $owner->id,
                'payment_status' => 'confirmed',
                'confirmed_by' => $owner->id,
                'confirmed_at' => '2035-05-10 09:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_type', 'extension_fee')
            ->assertJsonPath('data.payment_status', 'reported')
            ->assertJsonPath('data.amount', '51.00')
            ->assertJsonPath('data.reported_by', $borrower->id)
            ->assertJsonPath('data.transaction_id', $transaction->id);

        $paymentId = $response->json('data.id');
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'transaction_id' => $transaction->id,
            'extension_id' => $extension->id,
            'reported_by' => $borrower->id,
            'payment_type' => 'extension_fee',
            'payment_status' => 'reported',
            'amount' => '51.00',
            'confirmed_by' => null,
            'confirmed_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_note' => null,
            'proof_path' => null,
        ], 'mysql');

        $transaction->refresh();
        $extension->refresh();
        $this->assertSame('active', $transaction->status);
        $this->assertSame('2035-05-11 09:00:00', $transaction->due_at->toDateTimeString());
        $this->assertSame('accepted', $extension->status);
        $this->assertNull($transaction->overdue_at);
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }

    public function test_borrower_can_report_bank_transfer_and_other_manual_with_private_proof(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        foreach (['bank_transfer', 'other_manual'] as $method) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();
            $proof = UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf');

            $response = $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction, $extension),
                    ['payment_method' => $method, 'proof' => $proof],
                    ['Accept' => 'application/json']
                )
                ->assertCreated()
                ->assertJsonPath('data.payment_method', $method)
                ->assertJsonPath('data.has_proof', true);

            $payment = Payment::on('mysql')->findOrFail($response->json('data.id'));
            $this->assertNotNull($payment->proof_path);
            Storage::disk('local')->assertExists($payment->proof_path);
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_bank_transfer_and_other_manual_require_proof(): void
    {
        foreach (['bank_transfer', 'other_manual'] as $method) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();

            $this->actingAsToken($borrower)
                ->postJson($this->paymentsEndpoint($transaction, $extension), [
                    'payment_method' => $method,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('proof');
        }
    }

    public function test_only_jpg_jpeg_png_and_pdf_proofs_within_five_megabytes_are_accepted(): void
    {
        Storage::fake('local');
        $proofs = [
            UploadedFile::fake()->image('proof.jpg'),
            UploadedFile::fake()->image('proof.jpeg'),
            UploadedFile::fake()->image('proof.png'),
            UploadedFile::fake()->create('proof.pdf', 5120, 'application/pdf'),
        ];

        foreach ($proofs as $proof) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();

            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction, $extension),
                    ['payment_method' => 'bank_transfer', 'proof' => $proof],
                    ['Accept' => 'application/json']
                )
                ->assertCreated();
        }
    }

    public function test_invalid_or_oversized_proof_is_rejected(): void
    {
        foreach ([
            UploadedFile::fake()->create('proof.gif', 100, 'image/gif'),
            UploadedFile::fake()->create('proof.pdf', 5121, 'application/pdf'),
        ] as $proof) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();

            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction, $extension),
                    ['payment_method' => 'bank_transfer', 'proof' => $proof],
                    ['Accept' => 'application/json']
                )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('proof');

            $this->assertSame(
                0,
                Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
            );
        }
    }

    public function test_guest_suspended_and_deactivated_borrowers_cannot_report_payment(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $this->postJson($this->paymentsEndpoint($transaction, $extension), [
            'payment_method' => 'cash',
        ])->assertUnauthorized();

        foreach (['suspended', 'deactivated'] as $accountStatus) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();
            $this->setAccountStatus($borrower, $accountStatus);

            $this->actingAsToken($borrower)
                ->postJson($this->paymentsEndpoint($transaction, $extension), [
                    'payment_method' => 'cash',
                ])
                ->assertForbidden();
        }
    }

    public function test_only_borrower_can_report_and_admin_parties_are_rejected(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $outsider = $this->createUser();

        $this->actingAsToken($owner)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();
        $this->actingAsToken($outsider)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();

        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            ownerRole: 'admin'
        );
        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();

        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            borrowerRole: 'admin'
        );
        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_only_accepted_extensions_can_be_paid(): void
    {
        foreach (['pending', 'rejected', 'withdrawn', 'superseded'] as $status) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture(
                extensionStatus: $status
            );

            $this->actingAsToken($borrower)
                ->postJson($this->paymentsEndpoint($transaction, $extension), [
                    'payment_method' => 'cash',
                ])
                ->assertConflict();
        }
    }

    public function test_only_active_or_overdue_transactions_can_receive_extension_payment(): void
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
            [$owner, $borrower, $transaction, $extension] = $this->createFixture(
                transactionStatus: $status
            );

            $this->actingAsToken($borrower)
                ->postJson($this->paymentsEndpoint($transaction, $extension), [
                    'payment_method' => 'cash',
                ])
                ->assertConflict();
        }
    }

    public function test_overdue_extension_can_be_paid_after_previous_due_at_passes(): void
    {
        $this->freezeAt('2035-05-12 09:00:00');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            transactionStatus: 'overdue',
            dueAt: CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC'),
            proposedDueAt: CarbonImmutable::parse('2035-05-13 09:00:00', 'UTC')
        );

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertCreated();
    }

    public function test_reported_or_confirmed_payment_blocks_another_report(): void
    {
        foreach (['reported', 'confirmed'] as $status) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();
            $this->createPayment($transaction, $extension, $status);

            $this->actingAsToken($borrower)
                ->postJson($this->paymentsEndpoint($transaction, $extension), [
                    'payment_method' => 'cash',
                ])
                ->assertConflict();

            $this->assertSame(
                1,
                Payment::on('mysql')
                    ->where('transaction_id', $transaction->id)
                    ->where('extension_id', $extension->id)
                    ->count()
            );
        }
    }

    public function test_rejected_payment_history_allows_a_new_report(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $rejectedPayment = $this->createPayment($transaction, $extension, 'rejected');

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
                'amount' => '1.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '51.00');

        $this->assertSame(
            2,
            Payment::on('mysql')
                ->where('transaction_id', $transaction->id)
                ->where('extension_id', $extension->id)
                ->count()
        );
        $this->assertDatabaseHas('payments', [
            'id' => $rejectedPayment->id,
            'payment_status' => 'rejected',
        ], 'mysql');
    }

    public function test_simultaneous_reported_and_confirmed_payments_are_rejected_without_changes(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $confirmedPayment = $this->createPayment($transaction, $extension, 'confirmed');
        $reportedPayment = $this->createPayment($transaction, $extension, 'reported');
        $transactionBefore = [
            'status' => $transaction->status,
            'due_at' => $transaction->due_at->toDateTimeString(),
            'overdue_at' => null,
        ];

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertConflict();

        $confirmedPayment->refresh();
        $reportedPayment->refresh();
        $transaction->refresh();
        $this->assertSame('confirmed', $confirmedPayment->payment_status);
        $this->assertSame('reported', $reportedPayment->payment_status);
        $this->assertSame($transactionBefore['status'], $transaction->status);
        $this->assertSame($transactionBefore['due_at'], $transaction->due_at->toDateTimeString());
        $this->assertNull($transaction->overdue_at);
        $this->assertSame(
            2,
            Payment::on('mysql')
                ->where('transaction_id', $transaction->id)
                ->where('extension_id', $extension->id)
                ->count()
        );
    }

    public function test_report_does_not_change_due_status_or_extension_status(): void
    {
        $this->freezeAt('2035-05-12 09:00:00');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            transactionStatus: 'overdue',
            dueAt: CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC')
        );
        $originalDueAt = $transaction->due_at->toDateTimeString();
        $originalOverdueAt = null;

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction, $extension), [
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        $transaction->refresh();
        $extension->refresh();
        $this->assertSame('overdue', $transaction->status);
        $this->assertSame($originalDueAt, $transaction->due_at->toDateTimeString());
        $this->assertSame($originalOverdueAt, $transaction->overdue_at?->toDateTimeString());
        $this->assertSame('accepted', $extension->status);
    }

    public function test_owner_can_confirm_reported_extension_payment_and_apply_future_due_date(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            proposedDueAt: CarbonImmutable::parse('2035-05-13 09:00:00', 'UTC')
        );
        $payment = $this->createPayment($transaction, $extension, 'reported');
        $originalStartAt = $transaction->start_at->toDateTimeString();
        $originalDailyPrice = $transaction->agreed_daily_price;
        $originalTotalPrice = $transaction->total_price;

        $this->actingAsToken($owner)
            ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'confirmed')
            ->assertJsonPath('data.confirmed_by', $owner->id);

        $payment->refresh();
        $transaction->refresh();
        $extension->refresh();
        $this->assertSame('confirmed', $payment->payment_status);
        $this->assertSame($owner->id, $payment->confirmed_by);
        $this->assertSame('2035-05-10 09:00:00', $payment->confirmed_at->toDateTimeString());
        $this->assertSame('2035-05-13 09:00:00', $transaction->due_at->toDateTimeString());
        $this->assertSame('active', $transaction->status);
        $this->assertSame($originalStartAt, $transaction->start_at->toDateTimeString());
        $this->assertSame($originalDailyPrice, $transaction->agreed_daily_price);
        $this->assertSame($originalTotalPrice, $transaction->total_price);
        $this->assertSame('accepted', $extension->status);
    }

    public function test_overdue_transaction_becomes_active_when_extension_due_date_is_future(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            transactionStatus: 'overdue',
            dueAt: CarbonImmutable::parse('2035-05-09 09:00:00', 'UTC'),
            proposedDueAt: CarbonImmutable::parse('2035-05-13 09:00:00', 'UTC')
        );
        $payment = $this->createPayment($transaction, $extension, 'reported');

        $this->actingAsToken($owner)
            ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, 'confirm'))
            ->assertOk();

        $transaction->refresh();
        $this->assertSame('active', $transaction->status);
        $this->assertSame('2035-05-13 09:00:00', $transaction->due_at->toDateTimeString());
    }

    public function test_past_or_equal_extension_due_date_makes_transaction_overdue(): void
    {
        foreach ([
            CarbonImmutable::parse('2035-05-09 09:00:00', 'UTC'),
            CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC'),
        ] as $proposedDueAt) {
            $confirmedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
            $this->travelTo($confirmedAt);
            [$owner, $borrower, $transaction, $extension] = $this->createFixture(
                transactionStatus: 'overdue',
                dueAt: CarbonImmutable::parse('2035-05-08 09:00:00', 'UTC'),
                proposedDueAt: $proposedDueAt
            );
            $payment = $this->createPayment($transaction, $extension, 'reported');

            $this->actingAsToken($owner)
                ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, 'confirm'))
                ->assertOk();

            $transaction->refresh();
            $this->assertSame('overdue', $transaction->status);
            $this->assertSame($proposedDueAt->toDateTimeString(), $transaction->due_at->toDateTimeString());
            $this->assertSame($confirmedAt->toDateTimeString(), $transaction->overdue_at->toDateTimeString());
        }
    }

    public function test_existing_overdue_at_is_not_overwritten_when_confirmation_leaves_transaction_overdue(): void
    {
        $confirmedAt = CarbonImmutable::parse('2035-05-12 09:00:00', 'UTC');
        $existingOverdueAt = CarbonImmutable::parse('2035-05-11 12:00:00', 'UTC');
        $this->travelTo($confirmedAt);
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            transactionStatus: 'overdue',
            dueAt: CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC'),
            proposedDueAt: CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC'),
            overdueAt: $existingOverdueAt
        );
        $payment = $this->createPayment($transaction, $extension, 'reported');

        $this->actingAsToken($owner)
            ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, 'confirm'))
            ->assertOk();

        $transaction->refresh();
        $this->assertSame('overdue', $transaction->status);
        $this->assertSame($existingOverdueAt->toDateTimeString(), $transaction->overdue_at->toDateTimeString());
    }

    public function test_only_owner_can_confirm_or_reject_extension_payment(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $payment = $this->createPayment($transaction, $extension, 'reported');
        $outsider = $this->createUser();
        $admin = $this->createUser(role: 'admin');

        foreach ([$borrower, $outsider, $admin] as $user) {
            foreach (['confirm', 'reject'] as $action) {
                $this->actingAsToken($user)
                    ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, $action), [
                        'rejection_note' => 'Not authorized.',
                    ])
                    ->assertForbidden();
            }
        }

        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('reported', $payment->payment_status);
        $this->assertSame('active', $transaction->status);
    }

    public function test_admin_transaction_parties_cannot_confirm_or_reject_extension_payment(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            ownerRole: 'admin'
        );
        $payment = $this->createPayment($transaction, $extension, 'reported');
        foreach (['confirm', 'reject'] as $action) {
            $this->actingAsToken($owner)
                ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, $action))
                ->assertForbidden();
        }

        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            borrowerRole: 'admin'
        );
        $payment = $this->createPayment($transaction, $extension, 'reported');
        foreach (['confirm', 'reject'] as $action) {
            $this->actingAsToken($owner)
                ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, $action))
                ->assertForbidden();
        }
    }

    public function test_only_active_or_overdue_transactions_can_be_reviewed(): void
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
            [$owner, $borrower, $transaction, $extension] = $this->createFixture(
                transactionStatus: $status
            );
            $payment = $this->createPayment($transaction, $extension, 'reported');

            foreach (['confirm', 'reject'] as $action) {
                $this->actingAsToken($owner)
                    ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, $action))
                    ->assertConflict();
            }
        }
    }

    public function test_only_accepted_extension_can_be_confirmed_or_rejected(): void
    {
        foreach (['pending', 'rejected', 'withdrawn', 'superseded'] as $status) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture(
                extensionStatus: $status
            );
            $payment = $this->createPayment($transaction, $extension, 'reported');

            foreach (['confirm', 'reject'] as $action) {
                $this->actingAsToken($owner)
                    ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, $action))
                    ->assertConflict();
            }
        }
    }

    public function test_rejected_or_confirmed_payment_cannot_be_reviewed_again(): void
    {
        foreach (['rejected', 'confirmed'] as $paymentStatus) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture();
            $payment = $this->createPayment($transaction, $extension, $paymentStatus);

            foreach (['confirm', 'reject'] as $action) {
                $this->actingAsToken($owner)
                    ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, $action))
                    ->assertConflict();
            }

            $payment->refresh();
            $this->assertSame($paymentStatus, $payment->payment_status);
        }
    }

    public function test_payment_from_another_extension_or_transaction_cannot_be_reviewed(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $otherExtension = $this->createExtension($transaction);
        $paymentFromOtherExtension = $this->createPayment($transaction, $otherExtension, 'reported');
        [, , $otherTransaction, $foreignExtension] = $this->createFixture();
        $paymentFromOtherTransaction = $this->createPayment(
            $otherTransaction,
            $foreignExtension,
            'reported'
        );

        foreach ([
            [$extension, $paymentFromOtherExtension],
            [$extension, $paymentFromOtherTransaction],
        ] as [$routeExtension, $routePayment]) {
            foreach (['confirm', 'reject'] as $action) {
                $this->actingAsToken($owner)
                    ->patchJson($this->reviewEndpoint(
                        $transaction,
                        $routeExtension,
                        $routePayment,
                        $action
                    ))
                    ->assertNotFound();
            }
        }
    }

    public function test_owner_can_reject_payment_with_optional_rejection_note_and_keep_transaction_unchanged(): void
    {
        $this->freezeAt('2035-05-12 09:00:00');
        foreach ([null, 'Proof is unclear.'] as $rejectionNote) {
            [$owner, $borrower, $transaction, $extension] = $this->createFixture(
                transactionStatus: 'overdue',
                dueAt: CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC'),
                overdueAt: CarbonImmutable::parse('2035-05-11 10:00:00', 'UTC')
            );
            $payment = $this->createPayment($transaction, $extension, 'reported');
            $originalDueAt = $transaction->due_at->toDateTimeString();
            $originalOverdueAt = $transaction->overdue_at->toDateTimeString();

            $this->actingAsToken($owner)
                ->patchJson(
                    $this->reviewEndpoint($transaction, $extension, $payment, 'reject'),
                    $rejectionNote === null ? [] : ['rejection_note' => $rejectionNote]
                )
                ->assertOk()
                ->assertJsonPath('data.payment_status', 'rejected')
                ->assertJsonPath('data.rejected_by', $owner->id)
                ->assertJsonPath('data.rejection_note', $rejectionNote);

            $payment->refresh();
            $transaction->refresh();
            $extension->refresh();
            $this->assertSame('rejected', $payment->payment_status);
            $this->assertSame($owner->id, $payment->rejected_by);
            $this->assertSame('2035-05-12 09:00:00', $payment->rejected_at->toDateTimeString());
            $this->assertSame($originalDueAt, $transaction->due_at->toDateTimeString());
            $this->assertSame($originalOverdueAt, $transaction->overdue_at->toDateTimeString());
            $this->assertSame('overdue', $transaction->status);
            $this->assertSame('accepted', $extension->status);
        }
    }

    public function test_extension_payment_proof_uses_private_transaction_payment_route(): void
    {
        Storage::fake('local');
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $proof = UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf');
        $response = $this->actingAsToken($borrower)
            ->post(
                $this->paymentsEndpoint($transaction, $extension),
                ['payment_method' => 'bank_transfer', 'proof' => $proof],
                ['Accept' => 'application/json']
            )
            ->assertCreated();
        $payment = Payment::on('mysql')->findOrFail($response->json('data.id'));

        $this->actingAsToken($owner)
            ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'confirmed');
        $this->actingAsToken($owner)
            ->get($response->json('data.proof_url'))
            ->assertOk();
        $this->actingAsToken($borrower)
            ->get($response->json('data.proof_url'))
            ->assertOk();
        $this->actingAsToken($this->createUser())
            ->get($response->json('data.proof_url'))
            ->assertForbidden();
        $this->assertNotNull($payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);
    }

    public function test_legacy_payment_endpoints_cannot_confirm_or_reject_extension_fee(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture(
            transactionStatus: 'awaiting_payment_confirmation'
        );
        $payment = $this->createPayment($transaction, $extension, 'reported');

        $this->actingAsToken($owner)
            ->patchJson($this->legacyReviewEndpoint($transaction, $payment, 'confirm'))
            ->assertNotFound();
        $this->actingAsToken($owner)
            ->patchJson($this->legacyReviewEndpoint($transaction, $payment, 'reject'), [])
            ->assertNotFound();

        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('reported', $payment->payment_status);
        $this->assertSame('awaiting_payment_confirmation', $transaction->status);
    }

    public function test_transaction_update_failure_rolls_back_payment_confirmation(): void
    {
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $payment = $this->createPayment($transaction, $extension, 'reported');
        $eventName = 'eloquent.updating: '.RentalTransaction::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated transaction update failure.');
        });

        try {
            $this->actingAsToken($owner)
                ->patchJson($this->reviewEndpoint($transaction, $extension, $payment, 'confirm'))
                ->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('reported', $payment->payment_status);
        $this->assertNull($payment->confirmed_by);
        $this->assertSame('active', $transaction->status);
        $this->assertSame('2035-05-11 09:00:00', $transaction->due_at->toDateTimeString());
    }

    public function test_payment_record_failure_cleans_up_uploaded_extension_proof(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $transaction, $extension] = $this->createFixture();
        $eventName = 'eloquent.creating: '.Payment::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated Extension Payment record failure.');
        });

        try {
            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction, $extension),
                    [
                        'payment_method' => 'bank_transfer',
                        'proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
                    ],
                    ['Accept' => 'application/json']
                )
                ->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(
            0,
            Payment::on('mysql')
                ->where('transaction_id', $transaction->id)
                ->where('extension_id', $extension->id)
                ->count()
        );
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function createFixture(
        string $transactionStatus = 'active',
        string $extensionStatus = 'accepted',
        string $ownerRole = 'student',
        string $borrowerRole = 'student',
        ?CarbonImmutable $dueAt = null,
        ?CarbonImmutable $proposedDueAt = null,
        ?CarbonImmutable $overdueAt = null
    ): array {
        $owner = $this->createUser(role: $ownerRole);
        $borrower = $this->createUser(role: $borrowerRole);
        $dueAt ??= CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC');
        $proposedDueAt ??= CarbonImmutable::parse('2035-05-13 09:00:00', 'UTC');
        $startAt = $dueAt->subDay();

        $category = Category::on('mysql')->create([
            'name' => 'Extension Payment category '.Str::uuid(),
            'is_active' => true,
        ]);
        $item = Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Extension Payment item '.Str::uuid(),
            'description' => 'Item fixture for Extension Payment tests.',
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
        $transaction->status = $transactionStatus;
        $transaction->save();

        $extension = $this->createExtension(
            $transaction,
            $extensionStatus,
            $proposedDueAt
        );

        return [$owner, $borrower, $transaction, $extension];
    }

    private function createExtension(
        RentalTransaction $transaction,
        string $status = 'accepted',
        ?CarbonImmutable $proposedDueAt = null
    ): RentalExtension {
        $extension = new RentalExtension();
        $extension->setConnection('mysql');
        $extension->transaction_id = $transaction->id;
        $extension->proposer_id = $transaction->borrower_id;
        $extension->previous_extension_id = null;
        $extension->previous_due_at = CarbonImmutable::parse($transaction->due_at);
        $extension->proposed_due_at = $proposedDueAt
            ?? CarbonImmutable::parse($transaction->due_at)->addDays(2);
        $extension->proposed_fee = '51.00';
        $extension->message = null;
        $extension->status = $status;
        $extension->responded_by = $status === 'accepted'
            ? $transaction->owner_id
            : null;
        $extension->responded_at = $status === 'accepted'
            ? now()
            : null;
        $extension->save();

        return $extension;
    }

    private function createPayment(
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
        $payment->payment_method = 'cash';
        $payment->payment_type = 'extension_fee';
        $payment->amount = $extension->proposed_fee;
        $payment->proof_path = $proofPath;
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
            ? 'Previous report rejected.'
            : null;
        $payment->save();

        return $payment;
    }

    private function createUser(
        string $accountStatus = 'active',
        string $role = 'student'
    ): User {
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

    private function paymentsEndpoint(
        RentalTransaction $transaction,
        RentalExtension $extension
    ): string {
        return "/api/rental-transactions/{$transaction->id}/extensions/{$extension->id}/payments";
    }

    private function reviewEndpoint(
        RentalTransaction $transaction,
        RentalExtension $extension,
        Payment $payment,
        string $action
    ): string {
        return $this->paymentsEndpoint($transaction, $extension)
            ."/{$payment->id}/{$action}";
    }

    private function legacyReviewEndpoint(
        RentalTransaction $transaction,
        Payment $payment,
        string $action
    ): string {
        return "/api/rental-transactions/{$transaction->id}/payments/{$payment->id}/{$action}";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('extension-payment-test')->plainTextToken);
    }
}
