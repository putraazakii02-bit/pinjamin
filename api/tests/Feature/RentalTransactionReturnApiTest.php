<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\RentalNegotiation;
use App\Models\RentalRequest;
use App\Models\RentalTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
