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

class RentalRequestCancellationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_cancel_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $this->patchJson($this->cancelEndpoint($request))->assertUnauthorized();
    }

    public function test_suspended_account_cannot_cancel_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $borrower = $this->setAccountStatus($borrower, 'suspended');

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_cancel_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $borrower = $this->setAccountStatus($borrower, 'deactivated');

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();
    }

    public function test_borrower_can_cancel_pending_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'cancelled',
        ], 'mysql');
    }

    public function test_borrower_can_cancel_negotiating_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $request->status = 'negotiating';
        $request->save();

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_admin_borrower_cannot_cancel_request_or_change_its_data(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer(
            'student',
            'admin'
        );

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'pending',
            'cancelled_at' => null,
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'pending',
            'responded_by' => null,
        ], 'mysql');
        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_request_with_admin_owner_cannot_be_cancelled_by_either_party(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer(
            'admin',
            'student'
        );

        $this->actingAsToken($owner)
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();
        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'pending',
            'cancelled_at' => null,
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'pending',
            'responded_by' => null,
        ], 'mysql');
        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_owner_cannot_cancel_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $this->actingAsToken($owner)
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();
    }

    public function test_outside_user_cannot_cancel_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $this->actingAsToken($this->createUser())
            ->patchJson($this->cancelEndpoint($request))
            ->assertForbidden();
    }

    public function test_accepted_request_cannot_be_cancelled(): void
    {
        $this->assertStatusCannotBeCancelled('accepted');
    }

    public function test_rejected_request_cannot_be_cancelled(): void
    {
        $this->assertStatusCannotBeCancelled('rejected');
    }

    public function test_cancelled_request_cannot_be_cancelled_again(): void
    {
        $this->assertStatusCannotBeCancelled('cancelled');
    }

    public function test_expired_request_cannot_be_cancelled(): void
    {
        $this->assertStatusCannotBeCancelled('expired');
    }

    public function test_cancelled_at_is_filled(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $response = $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk();

        $request->refresh();
        $this->assertNotNull($request->cancelled_at);
        $response->assertJsonPath('data.cancelled_at', $request->cancelled_at->toJSON());
    }

    public function test_pending_negotiation_is_withdrawn_by_borrower(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk();

        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'withdrawn',
            'responded_by' => $borrower->id,
        ], 'mysql');
        $negotiation->refresh();
        $this->assertNotNull($negotiation->responded_at);
    }

    public function test_cancellation_preserves_negotiation_history(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $oldNegotiationId = $negotiation->id;

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk();

        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $oldNegotiationId,
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_non_pending_negotiations_are_not_changed(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $accepted = $this->createNegotiation($request, $borrower, 'accepted');
        $rejected = $this->createNegotiation($request, $owner, 'rejected');
        $superseded = $this->createNegotiation($request, $borrower, 'superseded');
        $withdrawn = $this->createNegotiation($request, $owner, 'withdrawn');

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk();

        foreach ([$accepted, $rejected, $superseded, $withdrawn] as $negotiation) {
            $this->assertDatabaseHas('rental_negotiations', [
                'id' => $negotiation->id,
                'status' => $negotiation->status,
            ], 'mysql');
        }
    }

    public function test_all_multiple_pending_negotiations_are_withdrawn(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $secondPending = $this->createNegotiation($request, $owner, 'pending');

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk();

        foreach ([$negotiation, $secondPending] as $pending) {
            $this->assertDatabaseHas('rental_negotiations', [
                'id' => $pending->id,
                'status' => 'withdrawn',
                'responded_by' => $borrower->id,
            ], 'mysql');
            $pending->refresh();
            $this->assertNotNull($pending->responded_at);
        }
    }

    public function test_request_with_transaction_cannot_be_cancelled(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $negotiation->status = 'accepted';
        $negotiation->save();

        $transaction = $request->transaction()->make([
            'accepted_negotiation_id' => $negotiation->id,
            'item_id' => $item->id,
            'owner_id' => $owner->id,
            'borrower_id' => $borrower->id,
            'agreed_daily_price' => '25.50',
            'total_price' => '25.50',
            'start_at' => $negotiation->proposed_start_at,
            'due_at' => $negotiation->proposed_due_at,
        ]);
        $transaction->save();

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertConflict();
    }

    public function test_cancellation_creates_no_transaction(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertOk();

        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_negotiation_update_failure_rolls_back_cancellation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $eventName = 'eloquent.updating: ' . RentalNegotiation::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated negotiation update failure.');
        });

        try {
            $this->actingAsToken($borrower)
                ->patchJson($this->cancelEndpoint($request))
                ->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $request->refresh();
        $negotiation->refresh();
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->cancelled_at);
        $this->assertSame('pending', $negotiation->status);
    }

    private function assertStatusCannotBeCancelled(string $status): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createRequestWithOffer();
        $request->status = $status;
        $request->save();

        $this->actingAsToken($borrower)
            ->patchJson($this->cancelEndpoint($request))
            ->assertConflict();
    }

    private function createRequestWithOffer(
        string $ownerRole = 'student',
        string $borrowerRole = 'student'
    ): array
    {
        $owner = $this->createUser('active', $ownerRole);
        $borrower = $this->createUser('active', $borrowerRole);
        $item = $this->createItem($owner);
        $request = $this->createRequest($item, $borrower);
        $negotiation = $this->createNegotiation($request, $borrower, 'pending');

        return [$owner, $borrower, $item, $request, $negotiation];
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

    private function setAccountStatus(User $user, string $status): User
    {
        $user->account_status = $status;
        $user->save();

        return $user;
    }

    private function createItem(User $owner): Item
    {
        $category = Category::on('mysql')->create([
            'name' => 'Cancellation category ' . Str::uuid(),
            'is_active' => true,
        ]);

        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Cancellation item ' . Str::uuid(),
            'description' => 'Item description for cancellation testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => 'active',
        ]);
    }

    private function createRequest(Item $item, User $borrower): RentalRequest
    {
        $request = new RentalRequest();
        $request->setConnection('mysql');
        $request->item_id = $item->id;
        $request->borrower_id = $borrower->id;
        $request->request_message = 'Cancellation test request.';
        $request->status = 'pending';
        $request->save();

        return $request;
    }

    private function createNegotiation(
        RentalRequest $request,
        User $proposer,
        string $status
    ): RentalNegotiation {
        $start = CarbonImmutable::create(
            2035,
            5,
            10,
            9,
            0,
            0,
            config('app.timezone')
        );
        $negotiation = new RentalNegotiation();
        $negotiation->setConnection('mysql');
        $negotiation->request_id = $request->id;
        $negotiation->proposer_id = $proposer->id;
        $negotiation->proposed_daily_price = '25.50';
        $negotiation->proposed_start_at = $start;
        $negotiation->proposed_due_at = $start->addHours(24);
        $negotiation->message = null;
        $negotiation->status = $status;
        $negotiation->responded_by = null;
        $negotiation->responded_at = null;
        $negotiation->save();

        return $negotiation;
    }

    private function cancelEndpoint(RentalRequest $request): string
    {
        return "/api/rental-requests/{$request->id}/cancel";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('rental-cancellation-test')->plainTextToken);
    }
}
