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

class RentalNegotiationResponseApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_respond_to_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertUnauthorized();
    }

    public function test_suspended_account_cannot_respond_to_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $owner = $this->setAccountStatus($owner, 'suspended');

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_respond_to_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $owner = $this->setAccountStatus($owner, 'deactivated');

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertForbidden();
    }

    public function test_outside_user_cannot_respond_to_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $outside = $this->createUser();

        $this->actingAsToken($outside)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertForbidden();
    }

    public function test_owner_can_accept_initial_offer(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.transaction.accepted_negotiation_id', $negotiation->id);

        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'accepted',
            'responded_by' => $owner->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'accepted',
        ], 'mysql');
    }

    public function test_owner_can_counter_initial_offer(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($owner)
            ->patchJson(
                $this->counterEndpoint($request, $negotiation),
                $this->counterPayload(27.75, 25)
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'negotiating');

        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'superseded',
            'responded_by' => $owner->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'request_id' => $request->id,
            'proposer_id' => $owner->id,
            'status' => 'pending',
        ], 'mysql');
        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_borrower_can_accept_owner_counter_offer(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $ownerCounter = $this->counterAs($owner, $request, $negotiation, 27.75, 25)->assertOk();

        $counterId = $ownerCounter->json('data.negotiations.1.id');

        $this->actingAsToken($borrower)
            ->patchJson($this->acceptEndpoint($request, RentalNegotiation::on('mysql')->findOrFail($counterId)))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_borrower_can_counter_owner_offer(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $ownerCounter = $this->counterAs($owner, $request, $negotiation, 27.75, 25)->assertOk();
        $counterId = $ownerCounter->json('data.negotiations.1.id');
        $ownerCounterNegotiation = RentalNegotiation::on('mysql')->findOrFail($counterId);

        $this->actingAsToken($borrower)
            ->patchJson(
                $this->counterEndpoint($request, $ownerCounterNegotiation),
                $this->counterPayload(26.50, 26)
            )
            ->assertOk();

        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $ownerCounterNegotiation->id,
            'status' => 'superseded',
            'responded_by' => $borrower->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'request_id' => $request->id,
            'proposer_id' => $borrower->id,
            'status' => 'pending',
        ], 'mysql');
    }

    public function test_negotiation_history_is_preserved_across_alternating_offers(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $ownerCounter = $this->counterAs($owner, $request, $negotiation, 27.75, 25)->assertOk();
        $ownerCounterId = $ownerCounter->json('data.negotiations.1.id');
        $ownerCounterNegotiation = RentalNegotiation::on('mysql')->findOrFail($ownerCounterId);

        $this->counterAs($borrower, $request, $ownerCounterNegotiation, 26.50, 26)->assertOk();

        $this->assertSame(
            3,
            RentalNegotiation::on('mysql')->where('request_id', $request->id)->count()
        );
    }

    public function test_owner_can_reject_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($owner)
            ->patchJson($this->rejectEndpoint($request, $negotiation))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'rejected',
            'responded_by' => $owner->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'rejected',
        ], 'mysql');
    }

    public function test_admin_owner_cannot_counter_accept_or_reject_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer('admin');
        $token = $owner->createToken('negotiation-response-test')->plainTextToken;

        $this->withToken($token)
            ->patchJson(
                $this->counterEndpoint($request, $negotiation),
                $this->counterPayload(27.75, 25)
            )
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson($this->rejectEndpoint($request, $negotiation))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'pending',
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

    public function test_admin_borrower_cannot_counter_accept_or_reject_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer(
            'student',
            'admin'
        );
        $negotiation->proposer_id = $owner->id;
        $negotiation->save();
        $token = $borrower->createToken('negotiation-response-test')->plainTextToken;

        $this->withToken($token)
            ->patchJson(
                $this->counterEndpoint($request, $negotiation),
                $this->counterPayload(27.75, 25)
            )
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson($this->rejectEndpoint($request, $negotiation))
            ->assertForbidden();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'pending',
            'proposer_id' => $owner->id,
            'responded_by' => null,
        ], 'mysql');
        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_sender_cannot_accept_initial_offer(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($borrower)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertForbidden();
    }

    public function test_sender_cannot_counter_own_pending_offer(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($borrower)
            ->patchJson(
                $this->counterEndpoint($request, $negotiation),
                $this->counterPayload(27.75, 25)
            )
            ->assertForbidden();
    }

    public function test_superseded_negotiation_cannot_be_responded_to(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $this->counterAs($owner, $request, $negotiation, 27.75, 25)->assertOk();

        $this->actingAsToken($borrower)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertNotFound();
    }

    public function test_request_rejected_status_cannot_be_responded_to(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $request->status = 'rejected';
        $request->save();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertConflict();
    }

    public function test_request_expired_status_cannot_be_responded_to(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $request->status = 'expired';
        $request->save();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertConflict();
    }

    public function test_owner_cannot_reject_as_borrower(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($borrower)
            ->patchJson($this->rejectEndpoint($request, $negotiation))
            ->assertForbidden();
    }

    public function test_only_one_pending_negotiation_is_allowed(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $extra = $this->createNegotiation($request, $owner, 'pending', 30.00, 24);

        $this->actingAsToken($borrower)
            ->patchJson($this->acceptEndpoint($request, $extra))
            ->assertConflict();
    }

    public function test_negotiation_must_belong_to_requested_rental_request(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        [, , , $otherRequest, $otherNegotiation] = $this->createPendingOffer();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $otherNegotiation))
            ->assertNotFound();
    }

    public function test_counter_offer_validates_zero_negative_and_decimal_rules(): void
    {
        foreach ([['proposed_daily_price' => '0'], ['proposed_daily_price' => '-1'], ['proposed_daily_price' => '12.345'], ['proposed_daily_price' => '10000000000.00']] as $override) {
            [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
            $payload = array_merge($this->counterPayload(25.00, 24), $override);

            $this->actingAsToken($owner)
                ->patchJson($this->counterEndpoint($request, $negotiation), $payload)
                ->assertUnprocessable();
        }
    }

    public function test_counter_offer_requires_valid_dates_and_minimum_duration(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $payload = $this->counterPayload(25.00, 23);
        $payload['proposed_due_at'] = CarbonImmutable::parse(
            $payload['proposed_start_at'],
            config('app.timezone')
        )->addHours(23)->format('Y-m-d H:i:s');

        $this->actingAsToken($owner)
            ->patchJson($this->counterEndpoint($request, $negotiation), $payload)
            ->assertUnprocessable();
    }

    public function test_duration_and_total_price_are_calculated_for_24_hours(): void
    {
        $this->assertAcceptedTotalPrice(24, '25.50', '25.50');
    }

    public function test_duration_and_total_price_are_calculated_for_25_hours(): void
    {
        $this->assertAcceptedTotalPrice(25, '25.50', '51.00');
    }

    public function test_duration_and_total_price_are_calculated_for_48_hours(): void
    {
        $this->assertAcceptedTotalPrice(48, '25.50', '51.00');
    }

    public function test_duration_and_total_price_are_calculated_for_50_hours(): void
    {
        $this->assertAcceptedTotalPrice(50, '25.50', '76.50');
    }

    public function test_transaction_contains_owner_borrower_item_and_accepted_negotiation(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertOk();

        $this->assertDatabaseHas('rental_transactions', [
            'request_id' => $request->id,
            'accepted_negotiation_id' => $negotiation->id,
            'item_id' => $item->id,
            'owner_id' => $owner->id,
            'borrower_id' => $borrower->id,
        ], 'mysql');
    }

    public function test_no_transaction_exists_before_offer_is_accepted(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_transaction_creation_failure_rolls_back_acceptance(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $eventName = 'eloquent.creating: ' . RentalTransaction::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated transaction creation failure.');
        });

        try {
            $this->actingAsToken($owner)
                ->patchJson($this->acceptEndpoint($request, $negotiation))
                ->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'pending',
        ], 'mysql');
        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
    }

    public function test_existing_transaction_prevents_second_acceptance(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();

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

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertConflict();
    }

    public function test_accept_rejects_total_price_over_decimal_capacity(): void
    {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $negotiation->proposed_daily_price = '9999999999.99';
        $negotiation->proposed_due_at = CarbonImmutable::parse(
            $negotiation->proposed_due_at,
            config('app.timezone')
        )->addHours(24);
        $negotiation->save();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertUnprocessable();

        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $request->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiation->id,
            'status' => 'pending',
        ], 'mysql');
    }

    private function assertAcceptedTotalPrice(
        int $durationHours,
        string $dailyPrice,
        string $expectedTotal
    ): void {
        [$owner, $borrower, $item, $request, $negotiation] = $this->createPendingOffer();
        $negotiation->proposed_daily_price = $dailyPrice;
        $negotiation->proposed_start_at = CarbonImmutable::create(
            2035,
            5,
            10,
            9,
            0,
            0,
            config('app.timezone')
        );
        $negotiation->proposed_due_at = CarbonImmutable::parse(
            $negotiation->proposed_start_at
        )->addHours($durationHours);
        $negotiation->save();

        $this->actingAsToken($owner)
            ->patchJson($this->acceptEndpoint($request, $negotiation))
            ->assertOk();

        $this->assertDatabaseHas('rental_transactions', [
            'request_id' => $request->id,
            'agreed_daily_price' => $dailyPrice,
            'total_price' => $expectedTotal,
        ], 'mysql');
    }

    private function createPendingOffer(
        string $ownerRole = 'student',
        string $borrowerRole = 'student'
    ): array
    {
        $owner = $this->createUser('active', $ownerRole);
        $borrower = $this->createUser('active', $borrowerRole);
        $item = $this->createItem($owner);
        $request = $this->createRentalRequest($item, $borrower);
        $negotiation = $this->createNegotiation($request, $borrower, 'pending', 25.50, 24);

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
            'name' => 'Negotiation category ' . Str::uuid(),
            'is_active' => true,
        ]);

        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Negotiation item ' . Str::uuid(),
            'description' => 'Item description for negotiation testing.',
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
        $request->request_message = 'Test rental request.';
        $request->status = 'pending';
        $request->save();

        return $request;
    }

    private function createNegotiation(
        RentalRequest $request,
        User $proposer,
        string $status,
        float $dailyPrice,
        int $durationHours
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
        $negotiation->proposed_daily_price = number_format($dailyPrice, 2, '.', '');
        $negotiation->proposed_start_at = $start;
        $negotiation->proposed_due_at = $start->addHours($durationHours);
        $negotiation->message = null;
        $negotiation->status = $status;
        $negotiation->responded_by = null;
        $negotiation->responded_at = null;
        $negotiation->save();

        return $negotiation;
    }

    private function counterPayload(float $dailyPrice, int $durationHours): array
    {
        $start = CarbonImmutable::create(
            2035,
            5,
            10,
            9,
            0,
            0,
            config('app.timezone')
        );

        return [
            'proposed_daily_price' => number_format($dailyPrice, 2, '.', ''),
            'proposed_start_at' => $start->format('Y-m-d H:i:s'),
            'proposed_due_at' => $start->addHours($durationHours)->format('Y-m-d H:i:s'),
            'message' => 'Counter offer.',
        ];
    }

    private function counterAs(
        User $user,
        RentalRequest $request,
        RentalNegotiation $negotiation,
        float $dailyPrice,
        int $durationHours
    ): \Illuminate\Testing\TestResponse {
        return $this->actingAsToken($user)
            ->patchJson(
                $this->counterEndpoint($request, $negotiation),
                $this->counterPayload($dailyPrice, $durationHours)
            );
    }

    private function acceptEndpoint(RentalRequest $request, RentalNegotiation $negotiation): string
    {
        return "/api/rental-requests/{$request->id}/negotiations/{$negotiation->id}/accept";
    }

    private function counterEndpoint(RentalRequest $request, RentalNegotiation $negotiation): string
    {
        return "/api/rental-requests/{$request->id}/negotiations/{$negotiation->id}/counter";
    }

    private function rejectEndpoint(RentalRequest $request, RentalNegotiation $negotiation): string
    {
        return "/api/rental-requests/{$request->id}/negotiations/{$negotiation->id}/reject";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('negotiation-response-test')->plainTextToken);
    }
}
