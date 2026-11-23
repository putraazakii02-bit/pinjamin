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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RentalTransactionHandoverApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_either_handover_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->patchJson($this->ownerEndpoint($transaction))->assertUnauthorized();
        $this->patchJson($this->borrowerEndpoint($transaction))->assertUnauthorized();
    }

    public function test_suspended_accounts_cannot_access_either_handover_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $this->setAccountStatus($owner, 'suspended');
        $this->setAccountStatus($borrower, 'suspended');

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_deactivated_accounts_cannot_access_either_handover_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $this->setAccountStatus($owner, 'deactivated');
        $this->setAccountStatus($borrower, 'deactivated');

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_owner_can_confirm_handover_without_changing_transaction_status(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $confirmedAt = $this->freezeAt('2035-05-10 09:00:00');

        $response = $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_handover');

        $transaction->refresh();

        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $transaction->owner_handover_confirmed_at->toDateTimeString()
        );
        $this->assertNull($transaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $transaction->status);
        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            CarbonImmutable::parse($response->json('data.owner_handover_confirmed_at'))->toDateTimeString()
        );
    }

    public function test_owner_cannot_use_borrower_confirmation_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($owner)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_borrower_cannot_use_owner_confirmation_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($borrower)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_borrower_cannot_confirm_receipt_before_owner(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();
        $this->assertNull($transaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $transaction->status);
    }

    public function test_borrower_can_confirm_receipt_after_owner_and_resource_returns_both_timestamps(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $ownerConfirmedAt = $this->freezeAt('2035-05-10 09:00:00');

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk();

        $borrowerConfirmedAt = $this->freezeAt('2035-05-10 09:05:00');
        $response = $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $transaction->refresh();

        $this->assertSame(
            $ownerConfirmedAt->toDateTimeString(),
            $transaction->owner_handover_confirmed_at->toDateTimeString()
        );
        $this->assertSame(
            $borrowerConfirmedAt->toDateTimeString(),
            $transaction->borrower_received_confirmed_at->toDateTimeString()
        );
        $this->assertSame('active', $transaction->status);
        $this->assertSame(
            $ownerConfirmedAt->toDateTimeString(),
            CarbonImmutable::parse($response->json('data.owner_handover_confirmed_at'))->toDateTimeString()
        );
        $this->assertSame(
            $borrowerConfirmedAt->toDateTimeString(),
            CarbonImmutable::parse($response->json('data.borrower_received_confirmed_at'))->toDateTimeString()
        );
    }

    public function test_outside_user_cannot_access_either_handover_endpoint(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $outsideUser = $this->createUser();

        $this->actingAsToken($outsideUser)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($outsideUser)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_admin_who_is_not_a_transaction_party_cannot_confirm_either_side(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $admin = $this->createUser('active', 'admin');

        $this->actingAsToken($admin)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();
        $this->actingAsToken($admin)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_admin_owner_cannot_confirm_handover_without_changing_transaction(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'awaiting_handover',
            'admin'
        );

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertForbidden();

        $transaction->refresh();
        $this->assertNull($transaction->owner_handover_confirmed_at);
        $this->assertNull($transaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $transaction->status);
    }

    public function test_admin_borrower_cannot_confirm_handover_without_changing_transaction(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction(
            'awaiting_handover',
            'student',
            'admin'
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertForbidden();

        $transaction->refresh();
        $this->assertNull($transaction->owner_handover_confirmed_at);
        $this->assertNull($transaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $transaction->status);
    }

    public function test_student_cannot_confirm_handover_when_other_party_is_admin(): void
    {
        [$adminOwner, $studentBorrower, $ownerAdminTransaction] = $this->createTransaction(
            'awaiting_handover',
            'admin',
            'student'
        );
        $ownerHandoverAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $ownerAdminTransaction->owner_handover_confirmed_at = $ownerHandoverAt;
        $ownerAdminTransaction->save();

        $this->actingAsToken($studentBorrower)
            ->patchJson($this->borrowerEndpoint($ownerAdminTransaction))
            ->assertForbidden();

        $ownerAdminTransaction->refresh();
        $this->assertSame(
            $ownerHandoverAt->toDateTimeString(),
            $ownerAdminTransaction->owner_handover_confirmed_at->toDateTimeString()
        );
        $this->assertNull($ownerAdminTransaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $ownerAdminTransaction->status);

        [$studentOwner, $adminBorrower, $borrowerAdminTransaction] = $this->createTransaction(
            'awaiting_handover',
            'student',
            'admin'
        );

        $this->actingAsToken($studentOwner)
            ->patchJson($this->ownerEndpoint($borrowerAdminTransaction))
            ->assertForbidden();

        $borrowerAdminTransaction->refresh();
        $this->assertNull($borrowerAdminTransaction->owner_handover_confirmed_at);
        $this->assertNull($borrowerAdminTransaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $borrowerAdminTransaction->status);
    }

    public function test_repeated_owner_confirmation_returns_conflict_without_overwriting_timestamp(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();
        $firstConfirmationAt = $this->freezeAt('2035-05-10 09:00:00');

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk();

        $this->freezeAt('2035-05-10 09:05:00');
        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();

        $this->assertSame(
            $firstConfirmationAt->toDateTimeString(),
            $transaction->owner_handover_confirmed_at->toDateTimeString()
        );
        $this->assertSame('awaiting_handover', $transaction->status);
    }

    public function test_repeated_borrower_confirmation_returns_conflict_without_overwriting_timestamp(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->freezeAt('2035-05-10 09:00:00');
        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk();

        $firstConfirmationAt = $this->freezeAt('2035-05-10 09:05:00');
        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertOk();

        $this->freezeAt('2035-05-10 09:10:00');
        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();

        $this->assertSame(
            $firstConfirmationAt->toDateTimeString(),
            $transaction->borrower_received_confirmed_at->toDateTimeString()
        );
        $this->assertSame('active', $transaction->status);
    }

    public function test_status_other_than_awaiting_handover_rejects_both_confirmations(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction('active');
        $transaction->owner_handover_confirmed_at = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $transaction->save();

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertConflict();
        $this->actingAsToken($borrower)
            ->patchJson($this->borrowerEndpoint($transaction))
            ->assertConflict();

        $transaction->refresh();
        $this->assertSame('active', $transaction->status);
        $this->assertNull($transaction->borrower_received_confirmed_at);
    }

    public function test_failed_borrower_update_rolls_back_timestamp_and_status(): void
    {
        [$owner, $borrower, $transaction] = $this->createTransaction();

        $this->actingAsToken($owner)
            ->patchJson($this->ownerEndpoint($transaction))
            ->assertOk();

        $eventName = 'eloquent.updating: ' . RentalTransaction::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated rental transaction update failure.');
        });

        try {
            $this->actingAsToken($borrower)
                ->patchJson($this->borrowerEndpoint($transaction))
                ->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $transaction->refresh();
        $this->assertNotNull($transaction->owner_handover_confirmed_at);
        $this->assertNull($transaction->borrower_received_confirmed_at);
        $this->assertSame('awaiting_handover', $transaction->status);
    }

    private function createTransaction(
        string $status = 'awaiting_handover',
        string $ownerRole = 'student',
        string $borrowerRole = 'student'
    ): array
    {
        $owner = $this->createUser('active', $ownerRole);
        $borrower = $this->createUser('active', $borrowerRole);

        $category = Category::on('mysql')->create([
            'name' => 'Handover category ' . Str::uuid(),
            'is_active' => true,
        ]);

        $item = Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Handover item ' . Str::uuid(),
            'description' => 'Item fixture for handover testing.',
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

        $startAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $negotiation = new RentalNegotiation();
        $negotiation->setConnection('mysql');
        $negotiation->request_id = $rentalRequest->id;
        $negotiation->proposer_id = $borrower->id;
        $negotiation->proposed_daily_price = '25.50';
        $negotiation->proposed_start_at = $startAt;
        $negotiation->proposed_due_at = $startAt->addDay();
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
        $transaction->due_at = $startAt->addDay();
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

    private function ownerEndpoint(RentalTransaction $transaction): string
    {
        return "/api/rental-transactions/{$transaction->id}/handover/owner-confirm";
    }

    private function borrowerEndpoint(RentalTransaction $transaction): string
    {
        return "/api/rental-transactions/{$transaction->id}/handover/borrower-confirm";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('rental-transaction-handover-test')->plainTextToken);
    }
}
