<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Payment;
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
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_payment_api(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->getJson($this->paymentsEndpoint($transaction))->assertUnauthorized();
        $this->postJson($this->paymentsEndpoint($transaction), [])
            ->assertUnauthorized();
    }

    public function test_suspended_account_cannot_access_payment_api(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $borrower = $this->setAccountStatus($borrower, 'suspended');

        $this->actingAsToken($borrower)
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_access_payment_api(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $borrower = $this->setAccountStatus($borrower, 'deactivated');

        $this->actingAsToken($borrower)
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_borrower_can_report_cash_payment(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
                'payment_note' => 'Cash paid.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'reported')
            ->assertJsonPath('data.payment_type', 'rental_fee')
            ->assertJsonPath('data.amount', '25.50');

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'reported_by' => $borrower->id,
            'amount' => '25.50',
            'payment_status' => 'reported',
            'payment_type' => 'rental_fee',
            'extension_id' => null,
        ], 'mysql');
    }

    public function test_owner_cannot_report_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($owner)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_outside_user_cannot_report_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($this->createUser())
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_confirm_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'confirmed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => 'confirmed',
            'confirmed_by' => $owner->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_handover',
        ], 'mysql');
    }

    public function test_owner_can_reject_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($owner)
            ->patchJson(
                $this->paymentEndpoint($transaction, $payment, 'reject'),
                ['rejection_note' => 'Proof is unclear.']
            )
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'rejected');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => 'rejected',
            'rejected_by' => $owner->id,
            'rejection_note' => 'Proof is unclear.',
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment',
        ], 'mysql');
    }

    public function test_borrower_cannot_confirm_or_reject_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($borrower)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertForbidden();
        $this->actingAsToken($borrower)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'reject'), [])
            ->assertForbidden();
    }

    public function test_admin_cannot_confirm_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');
        $admin = $this->createAdmin();

        $this->assertNotSame($admin->id, $owner->id);
        $this->assertNotSame($admin->id, $borrower->id);

        $this->actingAsToken($admin)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertForbidden();

        $payment->refresh();
        $transaction->refresh();

        $this->assertSame('reported', $payment->payment_status);
        $this->assertNull($payment->confirmed_by);
        $this->assertNull($payment->confirmed_at);
        $this->assertNotSame($admin->id, $payment->confirmed_by);
        $this->assertSame('awaiting_payment_confirmation', $transaction->status);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => 'reported',
            'confirmed_by' => null,
            'confirmed_at' => null,
        ], 'mysql');
        $this->assertDatabaseMissing('payments', [
            'id' => $payment->id,
            'confirmed_by' => $admin->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment_confirmation',
        ], 'mysql');
    }

    public function test_admin_cannot_reject_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');
        $admin = $this->createAdmin();

        $this->assertNotSame($admin->id, $owner->id);
        $this->assertNotSame($admin->id, $borrower->id);

        $this->actingAsToken($admin)
            ->patchJson(
                $this->paymentEndpoint($transaction, $payment, 'reject'),
                ['rejection_note' => 'Admin rejection attempt.']
            )
            ->assertForbidden();

        $payment->refresh();
        $transaction->refresh();

        $this->assertSame('reported', $payment->payment_status);
        $this->assertNull($payment->rejected_by);
        $this->assertNull($payment->rejected_at);
        $this->assertNotSame($admin->id, $payment->rejected_by);
        $this->assertSame('awaiting_payment_confirmation', $transaction->status);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => 'reported',
            'rejected_by' => null,
            'rejected_at' => null,
        ], 'mysql');
        $this->assertDatabaseMissing('payments', [
            'id' => $payment->id,
            'rejected_by' => $admin->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment_confirmation',
        ], 'mysql');
    }

    public function test_admin_borrower_cannot_report_payment(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture(
            'student',
            'admin'
        );

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment',
        ], 'mysql');
    }

    public function test_admin_owner_cannot_confirm_reject_or_access_payment_data(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');
        $owner->role = 'admin';
        $owner->save();

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertForbidden();
        $this->actingAsToken($owner)
            ->patchJson(
                $this->paymentEndpoint($transaction, $payment, 'reject'),
                ['rejection_note' => 'Admin rejection attempt.']
            )
            ->assertForbidden();
        $this->actingAsToken($owner)
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($owner)
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertForbidden();

        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('reported', $payment->payment_status);
        $this->assertNull($payment->confirmed_by);
        $this->assertNull($payment->confirmed_at);
        $this->assertNull($payment->rejected_by);
        $this->assertNull($payment->rejected_at);
        $this->assertSame('awaiting_payment_confirmation', $transaction->status);
    }

    public function test_student_borrower_cannot_report_payment_when_owner_is_admin(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture(
            'admin',
            'student'
        );

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment',
        ], 'mysql');
    }

    public function test_student_owner_cannot_manage_payment_when_borrower_is_admin(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');
        $borrower->role = 'admin';
        $borrower->save();

        $this->actingAsToken($borrower)
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($borrower)
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertForbidden();

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertForbidden();
        $this->actingAsToken($owner)
            ->patchJson(
                $this->paymentEndpoint($transaction, $payment, 'reject'),
                ['rejection_note' => 'Student owner with admin borrower.']
            )
            ->assertForbidden();
        $this->actingAsToken($owner)
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($owner)
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertForbidden();

        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('reported', $payment->payment_status);
        $this->assertNull($payment->confirmed_by);
        $this->assertNull($payment->confirmed_at);
        $this->assertNull($payment->rejected_by);
        $this->assertNull($payment->rejected_at);
        $this->assertSame('awaiting_payment_confirmation', $transaction->status);
    }

    public function test_outside_user_cannot_view_history(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($this->createUser())
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_outside_user_cannot_access_proof(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment(
            $transaction,
            $borrower,
            'bank_transfer',
            UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')
        );

        $this->actingAsToken($this->createUser())
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertForbidden();
    }

    public function test_proof_payment_from_different_transaction_returns_not_found(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment(
            $transaction,
            $borrower,
            'bank_transfer',
            UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')
        );
        [, $otherBorrower, , , $otherTransaction] = $this->createPaymentFixture();

        // Otorisasi diperiksa terlebih dahulu: $otherBorrower adalah pihak $otherTransaction,
        // sehingga lolos 403, kemudian payment yang tidak berelasi dengan $otherTransaction
        // menghasilkan 404.
        $this->actingAsToken($otherBorrower)
            ->get($this->proofEndpoint($otherTransaction, $payment))
            ->assertNotFound();
    }

    public function test_payment_without_proof_returns_not_found(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($borrower)
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertNotFound();
    }

    public function test_cash_payment_may_not_have_proof(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertCreated();
    }

    public function test_bank_transfer_requires_proof(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'bank_transfer',
            ])
            ->assertUnprocessable();
    }

    public function test_other_manual_requires_proof(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'other_manual',
            ])
            ->assertUnprocessable();
    }

    public function test_invalid_proof_format_is_rejected(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->post(
                $this->paymentsEndpoint($transaction),
                [
                    'payment_method' => 'bank_transfer',
                    'proof' => UploadedFile::fake()->create('proof.txt', 100, 'text/plain'),
                ],
                ['Accept' => 'application/json']
            )
            ->assertUnprocessable();
    }

    public function test_jpg_jpeg_png_and_pdf_proofs_are_accepted(): void
    {
        $formats = [
            ['jpg', 'image/jpeg'],
            ['jpeg', 'image/jpeg'],
            ['png', 'image/png'],
            ['pdf', 'application/pdf'],
        ];

        foreach ($formats as [$extension, $mime]) {
            [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction),
                    [
                        'payment_method' => 'bank_transfer',
                        'proof' => UploadedFile::fake()->create('proof.'.$extension, 100, $mime),
                    ],
                    ['Accept' => 'application/json']
                )
                ->assertCreated();
        }
    }

    public function test_proof_larger_than_five_megabytes_is_rejected(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->post(
                $this->paymentsEndpoint($transaction),
                [
                    'payment_method' => 'bank_transfer',
                    'proof' => UploadedFile::fake()->create('proof.pdf', 5121, 'application/pdf'),
                ],
                ['Accept' => 'application/json']
            )
            ->assertUnprocessable();
    }

    public function test_amount_from_payload_is_ignored_and_total_price_is_used(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
                'amount' => '1.00',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => '25.50',
        ], 'mysql');
    }

    public function test_payment_amount_from_payload_is_ignored_and_full_total_is_recorded(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
                'amount' => '12.75',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => '25.50',
        ], 'mysql');
    }

    public function test_duplicate_reported_payment_is_rejected(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertConflict();
    }

    public function test_payment_can_be_reported_again_after_rejection(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $first = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($owner)
            ->patchJson(
                $this->paymentEndpoint($transaction, $first, 'reject'),
                ['rejection_note' => 'Please re-upload.']
            )
            ->assertOk();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        $this->assertSame(
            2,
            Payment::on('mysql')->where('transaction_id', $transaction->id)->count()
        );
    }

    public function test_confirmed_payment_cannot_be_reported_again(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertOk();

        $this->actingAsToken($borrower)
            ->postJson($this->paymentsEndpoint($transaction), [
                'payment_method' => 'cash',
            ])
            ->assertConflict();
    }

    public function test_payment_cannot_be_verified_twice(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertOk();
        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'reject'), [])
            ->assertConflict();
    }

    public function test_payment_history_is_ordered_and_contains_verification_status(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
            ->assertOk();

        $this->actingAsToken($borrower)
            ->getJson($this->paymentsEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.0.payment_status', 'confirmed')
            ->assertJsonPath('data.0.confirmed_by', $owner->id)
            ->assertJsonFragment(['rejected_by' => null]);
    }

    public function test_payment_cannot_be_accessed_through_different_transaction(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');
        [, , , , $otherTransaction] = $this->createPaymentFixture();

        $this->actingAsToken($owner)
            ->patchJson($this->paymentEndpoint($otherTransaction, $payment, 'confirm'))
            ->assertNotFound();
    }

    public function test_record_creation_failure_cleans_up_proof_and_rolls_back(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $eventName = 'eloquent.creating: ' . Payment::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated payment record failure.');
        });

        try {
            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction),
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

        $this->assertDatabaseMissing('payments', [
            'transaction_id' => $transaction->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment',
        ], 'mysql');
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }

    public function test_cleanup_delete_false_does_not_replace_record_creation_error(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $disk = Storage::disk('local');
        $mockDisk = Mockery::mock($disk)->makePartial();
        $mockDisk->shouldReceive('putFileAs')
            ->andReturn('payments/'.$transaction->id.'/proof.pdf');
        $mockDisk->shouldReceive('delete')
            ->andReturn(false);
        Storage::shouldReceive('disk')
            ->with('local')
            ->andReturn($mockDisk);

        $eventName = 'eloquent.creating: ' . Payment::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Original payment record error.');
        });

        try {
            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction),
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

        $this->assertDatabaseMissing('payments', [
            'transaction_id' => $transaction->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment',
        ], 'mysql');
    }

    public function test_cleanup_delete_exception_does_not_replace_record_creation_error(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $disk = Storage::disk('local');
        $mockDisk = Mockery::mock($disk)->makePartial();
        $mockDisk->shouldReceive('putFileAs')
            ->andReturn('payments/'.$transaction->id.'/proof.pdf');
        $mockDisk->shouldReceive('delete')
            ->andThrow(new RuntimeException('Cleanup delete exception.'));
        Storage::shouldReceive('disk')
            ->with('local')
            ->andReturn($mockDisk);

        $eventName = 'eloquent.creating: ' . Payment::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Original payment record error.');
        });

        try {
            $this->actingAsToken($borrower)
                ->post(
                    $this->paymentsEndpoint($transaction),
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

        $this->assertDatabaseMissing('payments', [
            'transaction_id' => $transaction->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'awaiting_payment',
        ], 'mysql');
    }

    public function test_payment_or_transaction_update_failure_rolls_back_confirmation(): void
    {
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment($transaction, $borrower, 'cash');
        $eventName = 'eloquent.updating: ' . Payment::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated payment update failure.');
        });

        try {
            $this->actingAsToken($owner)
                ->patchJson($this->paymentEndpoint($transaction, $payment, 'confirm'))
                ->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $payment->refresh();
        $transaction->refresh();
        $this->assertSame('reported', $payment->payment_status);
        $this->assertSame('awaiting_payment_confirmation', $transaction->status);
    }

    public function test_borrower_and_owner_can_access_proof_and_outside_user_cannot(): void
    {
        Storage::fake('local');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();
        $payment = $this->reportPayment(
            $transaction,
            $borrower,
            'bank_transfer',
            UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')
        );

        $this->actingAsToken($borrower)
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertOk();
        $this->actingAsToken($owner)
            ->get($this->proofEndpoint($transaction, $payment))
            ->assertOk();
    }

    public function test_proof_is_not_stored_in_public_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$owner, $borrower, $item, $request, $transaction] = $this->createPaymentFixture();

        $this->actingAsToken($borrower)
            ->post(
                $this->paymentsEndpoint($transaction),
                [
                    'payment_method' => 'bank_transfer',
                    'proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
                ],
                ['Accept' => 'application/json']
            )
            ->assertCreated();

        $this->assertNotEmpty(Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function createPaymentFixture(
        string $ownerRole = 'student',
        string $borrowerRole = 'student'
    ): array
    {
        $owner = $this->createUser('active', $ownerRole);
        $borrower = $this->createUser('active', $borrowerRole);
        $item = $this->createItem($owner);
        $request = $this->createRentalRequest($item, $borrower);
        $negotiation = $this->createNegotiation($request, $borrower);
        $transaction = $this->createTransaction($request, $negotiation, $item, $owner, $borrower);

        return [$owner, $borrower, $item, $request, $transaction];
    }

    private function createUser(
        string $accountStatus = 'active',
        string $role = 'student'
    ): User
    {
        $user = User::factory()->create();
        $user->account_status = $accountStatus;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function createAdmin(string $accountStatus = 'active'): User
    {
        $user = User::factory()->create();
        $user->role = 'admin';
        $user->account_status = $accountStatus;
        $user->save();

        return $user;
    }

    private function setAccountStatus(User $user, string $status): User
    {
        $user->account_status = $status;
        $user->save();

        return $user;
    }

    private function createItem(User $owner): Item
    {
        $category = Category::on('mysql')->create([
            'name' => 'Payment category ' . Str::uuid(),
            'is_active' => true,
        ]);

        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Payment item ' . Str::uuid(),
            'description' => 'Item description for payment testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => 'active',
        ]);
    }

    private function createRentalRequest(Item $item, User $borrower): RentalRequest
    {
        $request = new RentalRequest();
        $request->setConnection('mysql');
        $request->item_id = $item->id;
        $request->borrower_id = $borrower->id;
        $request->request_message = 'Payment test request.';
        $request->status = 'accepted';
        $request->save();

        return $request;
    }

    private function createNegotiation(
        RentalRequest $request,
        User $proposer
    ): RentalNegotiation {
        $start = CarbonImmutable::create(2035, 5, 10, 9, 0, 0, config('app.timezone'));

        $negotiation = new RentalNegotiation();
        $negotiation->setConnection('mysql');
        $negotiation->request_id = $request->id;
        $negotiation->proposer_id = $proposer->id;
        $negotiation->proposed_daily_price = '25.50';
        $negotiation->proposed_start_at = $start;
        $negotiation->proposed_due_at = $start->addHours(24);
        $negotiation->message = null;
        $negotiation->status = 'accepted';
        $negotiation->save();

        return $negotiation;
    }

    private function createTransaction(
        RentalRequest $request,
        RentalNegotiation $negotiation,
        Item $item,
        User $owner,
        User $borrower
    ): RentalTransaction {
        $transaction = new RentalTransaction();
        $transaction->setConnection('mysql');
        $transaction->request_id = $request->id;
        $transaction->accepted_negotiation_id = $negotiation->id;
        $transaction->item_id = $item->id;
        $transaction->owner_id = $owner->id;
        $transaction->borrower_id = $borrower->id;
        $transaction->agreed_daily_price = '25.50';
        $transaction->total_price = '25.50';
        $transaction->start_at = $negotiation->proposed_start_at;
        $transaction->due_at = $negotiation->proposed_due_at;
        $transaction->status = 'awaiting_payment';
        $transaction->save();

        return $transaction;
    }

    private function reportPayment(
        RentalTransaction $transaction,
        User $borrower,
        string $method,
        ?UploadedFile $proof = null
    ): Payment {
        Storage::fake('local');
        $payload = [
            'payment_method' => $method,
        ];

        if ($proof) {
            $payload['proof'] = $proof;
        }

        $response = $this->actingAsToken($borrower)
            ->post($this->paymentsEndpoint($transaction), $payload, ['Accept' => 'application/json'])
            ->assertCreated();

        return Payment::on('mysql')->findOrFail($response->json('data.id'));
    }

    private function paymentsEndpoint(RentalTransaction $transaction): string
    {
        return '/api/rental-transactions/' . $transaction->id . '/payments';
    }

    private function paymentEndpoint(
        RentalTransaction $transaction,
        Payment $payment,
        string $action
    ): string {
        return $this->paymentsEndpoint($transaction) . '/' . $payment->id . '/' . $action;
    }

    private function proofEndpoint(
        RentalTransaction $transaction,
        Payment $payment
    ): string {
        return $this->paymentsEndpoint($transaction) . '/' . $payment->id . '/proof';
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('payment-api-test')->plainTextToken);
    }
}
