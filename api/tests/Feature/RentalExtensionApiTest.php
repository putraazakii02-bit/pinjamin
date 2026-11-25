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
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalExtensionApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_student_borrower_can_submit_extension_with_snapshot_and_server_calculated_fee(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $previousDueAt = CarbonImmutable::parse($transaction->due_at);
        $proposedDueAt = $previousDueAt->addHours(25);

        $response = $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $proposedDueAt->format('Y-m-d H:i:s'),
                'proposed_fee' => '0.01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.proposed_fee', '51.00')
            ->assertJsonPath('data.previous_extension_id', null)
            ->assertJsonPath('data.message', null);

        $extensionId = $response->json('data.id');
        $this->assertDatabaseHas('rental_extensions', [
            'id' => $extensionId,
            'transaction_id' => $transaction->id,
            'proposer_id' => $borrower->id,
            'previous_extension_id' => null,
            'previous_due_at' => $previousDueAt->toDateTimeString(),
            'proposed_due_at' => $proposedDueAt->toDateTimeString(),
            'proposed_fee' => '51.00',
            'message' => null,
            'status' => 'pending',
            'responded_by' => null,
            'responded_at' => null,
        ], 'mysql');

        $transaction->refresh();
        $this->assertSame('active', $transaction->status);
        $this->assertSame(
            '2035-05-11 09:00:00',
            $transaction->due_at->toDateTimeString()
        );
        $this->assertSame(
            0,
            Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_guest_cannot_submit_or_respond_to_extension(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);

        $this->postJson($this->extensionsEndpoint($transaction))
            ->assertUnauthorized();
        $this->patchJson($this->extensionActionEndpoint($transaction, $extension, 'accept'))
            ->assertUnauthorized();
    }

    public function test_suspended_and_deactivated_borrowers_and_owners_are_rejected(): void
    {
        foreach (['suspended', 'deactivated'] as $accountStatus) {
            [$owner, $borrower, $transaction] = $this->createTransaction();
            $this->setAccountStatus($borrower, $accountStatus);

            $this->actingAsToken($borrower)
                ->postJson($this->extensionsEndpoint($transaction), [
                    'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
                ])
                    ->assertForbidden();

            [$owner, $borrower, $transaction] = $this->createTransaction();
            $extension = $this->createExtension($transaction);
            $this->setAccountStatus($owner, $accountStatus);

            $this->actingAsToken($owner)
                ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'accept'))
                ->assertForbidden();
        }
    }

    public function test_only_borrower_can_submit_and_outside_user_cannot_submit(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $outsider = $this->createUser();
        $payload = [
            'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
        ];

        $this->actingAsToken($owner)
            ->postJson($this->extensionsEndpoint($transaction), $payload)
            ->assertForbidden();
        $this->actingAsToken($outsider)
            ->postJson($this->extensionsEndpoint($transaction), $payload)
            ->assertForbidden();
    }

    public function test_admin_cannot_be_borrower_or_owner_of_an_extension_transaction(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            ownerRole: 'student',
            borrowerRole: 'admin'
        );

        $response = $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ]);
        $response->assertForbidden();

        [$owner, $borrower, $transaction] = $this->createTransaction(
            ownerRole: 'admin',
            borrowerRole: 'student'
        );
        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ])
            ->assertForbidden();

        $extension = $this->createExtension($transaction);
        $this->actingAsToken($owner)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'accept'))
            ->assertForbidden();
    }

    public function test_non_active_transaction_cannot_receive_an_extension(): void
    {
        foreach ([
            'awaiting_payment',
            'awaiting_payment_confirmation',
            'awaiting_handover',
            'overdue',
            'return_pending_confirmation',
            'completed',
            'cancelled',
            'disputed',
        ] as $status) {
            [$owner, $borrower, $transaction] = $this->createTransaction(status: $status);

            $this->actingAsToken($borrower)
                ->postJson($this->extensionsEndpoint($transaction), [
                    'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
                ])
                ->assertConflict();
        }
    }

    public function test_extension_submission_is_rejected_at_or_after_previous_due_at(): void
    {
        foreach ([
            CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC'),
            CarbonImmutable::parse('2035-05-11 09:00:01', 'UTC'),
        ] as $now) {
            $this->travelTo($now);
            [$owner, $borrower, $transaction] = $this->createTransaction(
                dueAt: CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC')
            );

            $this->actingAsToken($borrower)
                ->postJson($this->extensionsEndpoint($transaction), [
                    'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
                ])
                ->assertConflict();
        }
    }

    public function test_extension_additional_duration_must_be_at_least_24_hours(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addHours(23)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proposed_due_at');
    }

    public function test_extension_duration_rounding_and_fee_match_negotiation_calculation(): void
    {
        $expectedFees = [
            24 => '25.50',
            25 => '51.00',
            48 => '51.00',
            50 => '76.50',
        ];

        foreach ($expectedFees as $durationHours => $expectedFee) {
            $this->freezeAt('2035-05-10 09:00:00');
            [$owner, $borrower, $transaction] = $this->createTransaction();

            $this->actingAsToken($borrower)
                ->postJson($this->extensionsEndpoint($transaction), [
                    'proposed_due_at' => $transaction->due_at
                        ->addHours($durationHours)
                        ->toDateTimeString(),
                ])
                ->assertCreated()
                ->assertJsonPath('data.proposed_fee', $expectedFee);
        }
    }

    public function test_extension_fee_over_decimal_capacity_is_rejected(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction(
            dailyPrice: '9999999999.99'
        );

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addHours(25)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proposed_due_at');

        $this->assertSame(
            0,
            RentalExtension::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_pending_or_unpaid_accepted_extension_blocks_a_second_submission(): void
    {
        foreach ([
            ['pending', null],
            ['accepted', null],
            ['accepted', 'reported'],
        ] as [$status, $paymentStatus]) {
            $this->freezeAt('2035-05-10 09:00:00');
            [$owner, $borrower, $transaction] = $this->createTransaction();
            $extension = $this->createExtension($transaction, $status);
            if ($paymentStatus !== null) {
                $this->createPayment($transaction, $extension, 'extension_fee', $paymentStatus);
            }

            $this->actingAsToken($borrower)
                ->postJson($this->extensionsEndpoint($transaction), [
                    'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
                ])
                ->assertConflict();

            $this->assertSame(
                1,
                RentalExtension::on('mysql')->where('transaction_id', $transaction->id)->count()
            );
        }
    }

    public function test_confirmed_extension_payment_does_not_block_a_subsequent_extension(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');
        $this->createPayment($transaction, $extension, 'extension_fee', 'confirmed');

        $transaction->due_at = $extension->proposed_due_at;
        $transaction->save();

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(
            2,
            RentalExtension::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_rejected_extension_does_not_block_a_new_submission(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $this->createExtension($transaction, 'rejected');

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ])
            ->assertCreated();

        $this->assertSame(
            2,
            RentalExtension::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_rental_fee_payment_does_not_count_as_extension_payment(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $this->createExtension($transaction, 'accepted');
        $this->createPayment($transaction, null, 'rental_fee', 'confirmed');

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ])
            ->assertConflict();
    }

    public function test_submission_stops_when_an_extension_has_reported_and_confirmed_payments(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');
        $this->createPayment($transaction, $extension, 'extension_fee', 'confirmed');
        $this->createPayment($transaction, $extension, 'extension_fee', 'reported');

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ])
            ->assertConflict();

        $this->assertSame(
            1,
            RentalExtension::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_submission_rejects_two_confirmed_payments_without_changing_existing_data(): void
    {
        $this->freezeAt('2035-05-10 09:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction, 'accepted');
        $payments = [
            $this->createPayment($transaction, $extension, 'extension_fee', 'confirmed'),
            $this->createPayment($transaction, $extension, 'extension_fee', 'confirmed'),
        ];
        $transactionBefore = $transaction->refresh()->getAttributes();
        $extensionBefore = $extension->refresh()->getAttributes();
        $paymentsBefore = array_map(
            static fn (Payment $payment): array => $payment->refresh()->getAttributes(),
            $payments
        );

        $this->actingAsToken($borrower)
            ->postJson($this->extensionsEndpoint($transaction), [
                'proposed_due_at' => $transaction->due_at->addDay()->toDateTimeString(),
            ])
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
            RentalExtension::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
        $this->assertSame(
            2,
            Payment::on('mysql')
                ->where('transaction_id', $transaction->id)
                ->where('payment_type', 'extension_fee')
                ->count()
        );
    }

    public function test_accept_and_reject_reject_multiple_active_payments_without_changing_data(): void
    {
        $paymentStatusPairs = [
            ['reported', 'reported'],
            ['confirmed', 'confirmed'],
            ['reported', 'confirmed'],
        ];

        foreach (['accept', 'reject'] as $action) {
            foreach ($paymentStatusPairs as $paymentStatuses) {
                $this->freezeAt('2035-05-10 09:00:00');
                [$owner, $borrower, $transaction] = $this->createTransaction();
                $extension = $this->createExtension($transaction);
                $payments = array_map(
                    fn (string $paymentStatus): Payment => $this->createPayment(
                        $transaction,
                        $extension,
                        'extension_fee',
                        $paymentStatus
                    ),
                    $paymentStatuses
                );
                $transactionBefore = $transaction->refresh()->getAttributes();
                $extensionBefore = $extension->refresh()->getAttributes();
                $paymentsBefore = array_map(
                    static fn (Payment $payment): array => $payment->refresh()->getAttributes(),
                    $payments
                );

                $this->actingAsToken($owner)
                    ->patchJson($this->extensionActionEndpoint($transaction, $extension, $action))
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
                    2,
                    Payment::on('mysql')
                        ->where('transaction_id', $transaction->id)
                        ->where('extension_id', $extension->id)
                        ->where('payment_type', 'extension_fee')
                        ->count()
                );
            }
        }
    }

    public function test_owner_can_accept_pending_extension_without_changing_transaction_or_creating_payment(): void
    {
        $respondedAt = $this->freezeAt('2035-05-10 10:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);
        $originalDueAt = $transaction->due_at->toDateTimeString();

        $this->actingAsToken($owner)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'accept'))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.responded_by', $owner->id);

        $extension->refresh();
        $transaction->refresh();
        $this->assertSame('accepted', $extension->status);
        $this->assertSame($owner->id, $extension->responded_by);
        $this->assertSame($respondedAt->toDateTimeString(), $extension->responded_at->toDateTimeString());
        $this->assertSame($originalDueAt, $transaction->due_at->toDateTimeString());
        $this->assertSame('active', $transaction->status);
        $this->assertSame(
            0,
            Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_owner_can_reject_pending_extension_without_changing_transaction_or_creating_payment(): void
    {
        $respondedAt = $this->freezeAt('2035-05-10 10:00:00');
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);
        $originalDueAt = $transaction->due_at->toDateTimeString();

        $this->actingAsToken($owner)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'reject'))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.responded_by', $owner->id);

        $extension->refresh();
        $transaction->refresh();
        $this->assertSame('rejected', $extension->status);
        $this->assertSame($owner->id, $extension->responded_by);
        $this->assertSame($respondedAt->toDateTimeString(), $extension->responded_at->toDateTimeString());
        $this->assertSame($originalDueAt, $transaction->due_at->toDateTimeString());
        $this->assertSame('active', $transaction->status);
        $this->assertSame(
            0,
            Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_owner_can_accept_or_reject_pending_extension_after_previous_due_at_passes(): void
    {
        foreach (['active', 'overdue'] as $status) {
            foreach (['accept', 'reject'] as $action) {
                $this->freezeAt('2035-05-10 09:00:00');
                [$owner, $borrower, $transaction] = $this->createTransaction();
                $extension = $this->createExtension($transaction);
                $previousDueAt = $extension->previous_due_at;

                $this->freezeAt('2035-05-12 09:00:00');
                $transaction->status = $status;
                $transaction->save();

                $this->actingAsToken($owner)
                    ->patchJson($this->extensionActionEndpoint($transaction, $extension, $action))
                    ->assertOk()
                    ->assertJsonPath(
                        'data.status',
                        $action === 'accept' ? 'accepted' : 'rejected'
                    );

                $extension->refresh();
                $transaction->refresh();
                $this->assertSame(
                    $action === 'accept' ? 'accepted' : 'rejected',
                    $extension->status
                );
                $this->assertSame('2035-05-11 09:00:00', $previousDueAt->toDateTimeString());
                $this->assertSame(
                    '2035-05-11 09:00:00',
                    $transaction->due_at->toDateTimeString()
                );
                $this->assertSame($status, $transaction->status);
            }
        }
    }

    public function test_borrower_admin_and_outside_user_cannot_respond_as_owner(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);
        $outsider = $this->createUser();
        $admin = $this->createUser(role: 'admin');

        foreach ([$borrower, $outsider, $admin] as $user) {
            $this->actingAsToken($user)
                ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'accept'))
                ->assertForbidden();
            $this->actingAsToken($user)
                ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'reject'))
                ->assertForbidden();
        }

        $extension->refresh();
        $this->assertSame('pending', $extension->status);
        $this->assertNull($extension->responded_by);
        $this->assertNull($extension->responded_at);
    }

    public function test_responses_are_rejected_after_return_starts_or_transaction_completes(): void
    {
        foreach (['return_pending_confirmation', 'completed'] as $status) {
            foreach (['accept', 'reject'] as $action) {
                [$owner, $borrower, $transaction] = $this->createTransaction(status: $status);
                $extension = $this->createExtension($transaction);

                $this->actingAsToken($owner)
                    ->patchJson($this->extensionActionEndpoint($transaction, $extension, $action))
                    ->assertConflict();

                $extension->refresh();
                $this->assertSame('pending', $extension->status);
                $this->assertNull($extension->responded_by);
                $this->assertNull($extension->responded_at);
            }
        }
    }

    public function test_non_pending_extension_cannot_be_responded_to(): void
    {
        foreach (['accepted', 'rejected', 'withdrawn', 'superseded'] as $status) {
            foreach (['accept', 'reject'] as $action) {
                [$owner, $borrower, $transaction] = $this->createTransaction();
                $extension = $this->createExtension($transaction, $status);

                $this->actingAsToken($owner)
                    ->patchJson($this->extensionActionEndpoint($transaction, $extension, $action))
                    ->assertConflict();

                $extension->refresh();
                $this->assertSame($status, $extension->status);
            }
        }
    }

    public function test_extension_from_another_transaction_cannot_be_responded_to(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        [, , $otherTransaction] = $this->createTransaction();
        $otherExtension = $this->createExtension($otherTransaction);

        $this->actingAsToken($owner)
            ->patchJson($this->extensionActionEndpoint($transaction, $otherExtension, 'accept'))
            ->assertNotFound();

        $otherExtension->refresh();
        $this->assertSame('pending', $otherExtension->status);
    }

    public function test_owner_response_stops_when_extension_has_reported_and_confirmed_payments(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);
        $this->createPayment($transaction, $extension, 'extension_fee', 'confirmed');
        $this->createPayment($transaction, $extension, 'extension_fee', 'reported');

        $this->actingAsToken($owner)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'accept'))
            ->assertConflict();

        $extension->refresh();
        $this->assertSame('pending', $extension->status);
        $this->assertNull($extension->responded_by);
        $this->assertNull($extension->responded_at);
    }

    public function test_borrower_can_withdraw_pending_extension_without_changing_transaction_or_payment(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);
        $payment = $this->createPayment($transaction, $extension, 'extension_fee', 'reported');
        $originalDueAt = $transaction->due_at->toDateTimeString();
        $paymentBefore = [
            'payment_status' => $payment->payment_status,
            'reported_at' => $payment->reported_at->toDateTimeString(),
            'updated_at' => $payment->updated_at->toDateTimeString(),
        ];

        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $extension->refresh();
        $transaction->refresh();
        $payment->refresh();
        $this->assertSame('withdrawn', $extension->status);
        $this->assertNull($extension->responded_by);
        $this->assertNull($extension->responded_at);
        $this->assertSame($originalDueAt, $transaction->due_at->toDateTimeString());
        $this->assertSame('active', $transaction->status);
        $this->assertSame($paymentBefore['payment_status'], $payment->payment_status);
        $this->assertSame($paymentBefore['reported_at'], $payment->reported_at->toDateTimeString());
        $this->assertSame($paymentBefore['updated_at'], $payment->updated_at->toDateTimeString());
        $this->assertDatabaseHas('rental_extensions', [
            'id' => $extension->id,
            'transaction_id' => $transaction->id,
            'status' => 'withdrawn',
        ], 'mysql');
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'transaction_id' => $transaction->id,
            'extension_id' => $extension->id,
            'payment_type' => 'extension_fee',
            'payment_status' => 'reported',
        ], 'mysql');
    }

    public function test_owner_admin_and_outside_user_cannot_withdraw_extension(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);
        $outsider = $this->createUser();

        foreach ([$owner, $outsider, $this->createUser(role: 'admin')] as $user) {
            $this->actingAsToken($user)
                ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
                ->assertForbidden();
        }

        [$owner, $borrower, $transaction] = $this->createTransaction(borrowerRole: 'admin');
        $extension = $this->createExtension($transaction);
        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertForbidden();

        [$owner, $borrower, $transaction] = $this->createTransaction(ownerRole: 'admin');
        $extension = $this->createExtension($transaction);
        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertForbidden();
    }

    public function test_guest_cannot_withdraw_or_view_extension_history(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);

        $this->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertUnauthorized();
        $this->getJson($this->extensionsEndpoint($transaction))
            ->assertUnauthorized();
    }

    public function test_suspended_and_deactivated_users_cannot_withdraw_or_view_history(): void
    {
        foreach (['suspended', 'deactivated'] as $accountStatus) {
            [$owner, $borrower, $transaction] = $this->createTransaction();
            $extension = $this->createExtension($transaction);
            $this->setAccountStatus($borrower, $accountStatus);

            $this->actingAsToken($borrower)
                ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
                ->assertForbidden();
            $this->actingAsToken($borrower)
                ->getJson($this->extensionsEndpoint($transaction))
                ->assertForbidden();

            $this->setAccountStatus($borrower, 'active');
            $this->setAccountStatus($owner, $accountStatus);
            $this->actingAsToken($owner)
                ->getJson($this->extensionsEndpoint($transaction))
                ->assertForbidden();
        }
    }

    public function test_only_pending_extension_can_be_withdrawn_and_history_is_preserved(): void
    {
        foreach (['accepted', 'rejected', 'withdrawn', 'superseded'] as $status) {
            [$owner, $borrower, $transaction] = $this->createTransaction();
            $extension = $this->createExtension($transaction, $status);

            $this->actingAsToken($borrower)
                ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
                ->assertConflict();

            $extension->refresh();
            $this->assertSame($status, $extension->status);
            $this->assertDatabaseHas('rental_extensions', [
                'id' => $extension->id,
                'transaction_id' => $transaction->id,
                'status' => $status,
            ], 'mysql');
        }
    }

    public function test_withdraw_from_another_transaction_is_rejected(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        [, , $otherTransaction] = $this->createTransaction();
        $otherExtension = $this->createExtension($otherTransaction);

        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $otherExtension, 'withdraw'))
            ->assertNotFound();

        $otherExtension->refresh();
        $this->assertSame('pending', $otherExtension->status);
        $this->assertSame($otherTransaction->id, $otherExtension->transaction_id);
    }

    public function test_repeated_withdraw_is_rejected_without_removing_extension_history(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $extension = $this->createExtension($transaction);

        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertOk();
        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertConflict();

        $this->assertSame(
            1,
            RentalExtension::on('mysql')
                ->where('transaction_id', $transaction->id)
                ->where('id', $extension->id)
                ->where('status', 'withdrawn')
                ->count()
        );
    }

    public function test_withdraw_does_not_add_a_transaction_status_restriction(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(status: 'completed');
        $extension = $this->createExtension($transaction);

        $this->actingAsToken($borrower)
            ->patchJson($this->extensionActionEndpoint($transaction, $extension, 'withdraw'))
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
    }

    public function test_owner_and_borrower_can_view_all_extension_statuses_in_id_order(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $statuses = ['pending', 'accepted', 'rejected', 'withdrawn', 'superseded'];
        $extensions = [];
        foreach ($statuses as $status) {
            $extensions[] = $this->createExtension($transaction, $status);
        }
        [, , $otherTransaction] = $this->createTransaction();
        $otherExtension = $this->createExtension($otherTransaction, 'pending');

        $expectedIds = array_map(
            static fn (RentalExtension $extension): int => (int) $extension->id,
            $extensions
        );
        $before = RentalExtension::on('mysql')
            ->where('transaction_id', $transaction->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (RentalExtension $extension): array => [
                'id' => (int) $extension->id,
                'status' => $extension->status,
                'created_at' => $extension->created_at->toDateTimeString(),
                'updated_at' => $extension->updated_at->toDateTimeString(),
            ])
            ->all();

        foreach ([$owner, $borrower] as $user) {
            $response = $this->actingAsToken($user)
                ->getJson($this->extensionsEndpoint($transaction))
                ->assertOk()
                ->assertJsonCount(count($statuses), 'data')
                ->assertJsonMissingPath('data.0.payments')
                ->assertJsonMissingPath('data.0.proof_path');

            foreach ($statuses as $index => $status) {
                $response->assertJsonPath("data.{$index}.id", $expectedIds[$index]);
                $response->assertJsonPath("data.{$index}.status", $status);
                $response->assertJsonPath(
                    "data.{$index}.transaction_id",
                    $transaction->id
                );
            }
        }

        $after = RentalExtension::on('mysql')
            ->where('transaction_id', $transaction->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (RentalExtension $extension): array => [
                'id' => (int) $extension->id,
                'status' => $extension->status,
                'created_at' => $extension->created_at->toDateTimeString(),
                'updated_at' => $extension->updated_at->toDateTimeString(),
            ])
            ->all();

        $this->assertSame($before, $after);
        $this->assertNotContains((int) $otherExtension->id, $expectedIds);
    }

    public function test_admin_parties_and_outside_users_cannot_view_extension_history(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $this->createExtension($transaction);
        $outsider = $this->createUser();

        $this->actingAsToken($outsider)
            ->getJson($this->extensionsEndpoint($transaction))
            ->assertForbidden();

        [$owner, $borrower, $transaction] = $this->createTransaction(ownerRole: 'admin');
        $this->createExtension($transaction);
        $this->actingAsToken($borrower)
            ->getJson($this->extensionsEndpoint($transaction))
            ->assertForbidden();

        [$owner, $borrower, $transaction] = $this->createTransaction(borrowerRole: 'admin');
        $this->createExtension($transaction);
        $this->actingAsToken($owner)
            ->getJson($this->extensionsEndpoint($transaction))
            ->assertForbidden();
    }

    private function createTransaction(
        string $status = 'active',
        ?CarbonImmutable $dueAt = null,
        string $ownerRole = 'student',
        string $borrowerRole = 'student',
        string $dailyPrice = '25.50'
    ): array {
        $owner = $this->createUser(role: $ownerRole);
        $borrower = $this->createUser(role: $borrowerRole);
        $dueAt ??= CarbonImmutable::parse('2035-05-11 09:00:00', 'UTC');
        $startAt = $dueAt->subDay();

        $category = Category::on('mysql')->create([
            'name' => 'Extension category ' . Str::uuid(),
            'is_active' => true,
        ]);

        $item = Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Extension item ' . Str::uuid(),
            'description' => 'Item fixture for rental extension testing.',
            'daily_price' => $dailyPrice,
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
        $negotiation->proposed_daily_price = $dailyPrice;
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
        $transaction->agreed_daily_price = $dailyPrice;
        $transaction->total_price = $dailyPrice;
        $transaction->start_at = $startAt;
        $transaction->due_at = $dueAt;
        $transaction->status = $status;
        $transaction->save();

        return [$owner, $borrower, $transaction];
    }

    private function createExtension(
        RentalTransaction $transaction,
        string $status = 'pending'
    ): RentalExtension {
        $extension = new RentalExtension();
        $extension->setConnection('mysql');
        $extension->transaction_id = $transaction->id;
        $extension->proposer_id = $transaction->borrower_id;
        $extension->previous_extension_id = null;
        $previousDueAt = CarbonImmutable::parse($transaction->due_at);
        $extension->previous_due_at = $previousDueAt;
        $extension->proposed_due_at = $previousDueAt->addDay();
        $extension->proposed_fee = '25.50';
        $extension->message = null;
        $extension->status = $status;
        $extension->responded_by = null;
        $extension->responded_at = null;
        $extension->save();

        return $extension;
    }

    private function createPayment(
        RentalTransaction $transaction,
        ?RentalExtension $extension,
        string $paymentType,
        string $paymentStatus
    ): Payment {
        $payment = new Payment();
        $payment->setConnection('mysql');
        $payment->transaction_id = $transaction->id;
        $payment->extension_id = $paymentType === 'extension_fee'
            ? $extension?->id
            : null;
        $payment->reported_by = $transaction->borrower_id;
        $payment->payment_method = 'cash';
        $payment->payment_type = $paymentType;
        $payment->amount = $paymentType === 'extension_fee'
            ? ($extension?->proposed_fee ?? '25.50')
            : $transaction->total_price;
        $payment->payment_status = $paymentStatus;
        $payment->reported_at = now();
        $payment->confirmed_by = $paymentStatus === 'confirmed'
            ? $transaction->owner_id
            : null;
        $payment->confirmed_at = $paymentStatus === 'confirmed'
            ? now()
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

    private function extensionsEndpoint(RentalTransaction $transaction): string
    {
        return "/api/rental-transactions/{$transaction->id}/extensions";
    }

    private function extensionActionEndpoint(
        RentalTransaction $transaction,
        RentalExtension $extension,
        string $action
    ): string {
        return $this->extensionsEndpoint($transaction) . "/{$extension->id}/{$action}";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('rental-extension-test')->plainTextToken);
    }
}
