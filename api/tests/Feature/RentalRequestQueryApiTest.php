<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\RentalNegotiation;
use App\Models\RentalRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalRequestQueryApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_rental_request_listing_and_detail(): void
    {
        [, $borrower, $item, $request] = $this->createRequestFixture();

        $this->getJson('/api/my/rental-requests')->assertUnauthorized();
        $this->getJson('/api/incoming/rental-requests')->assertUnauthorized();
        $this->getJson('/api/rental-requests/' . $request->id)->assertUnauthorized();
    }

    public function test_suspended_account_cannot_access_rental_request_query_api(): void
    {
        [, $borrower, $item, $request] = $this->createRequestFixture();
        $borrower = $this->setAccountStatus($borrower, 'suspended');

        $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-requests')
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_access_rental_request_query_api(): void
    {
        [, $borrower, $item, $request] = $this->createRequestFixture();
        $borrower = $this->setAccountStatus($borrower, 'deactivated');

        $this->actingAsToken($borrower)
            ->getJson('/api/incoming/rental-requests')
            ->assertForbidden();
    }

    public function test_borrower_listing_only_contains_requests_of_login_user(): void
    {
        [, $borrower, $item, $request] = $this->createRequestFixture();
        [, $otherBorrower, $otherItem, $otherRequest] = $this->createRequestFixture();

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-requests')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($request->id, $ids);
        $this->assertNotContains($otherRequest->id, $ids);
    }

    public function test_owner_listing_only_contains_requests_for_owned_items(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        [, $otherBorrower, $otherItem, $otherRequest] = $this->createRequestFixture();

        $response = $this->actingAsToken($owner)
            ->getJson('/api/incoming/rental-requests')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($request->id, $ids);
        $this->assertNotContains($otherRequest->id, $ids);
    }

    public function test_owner_listing_excludes_request_for_another_owner_item(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        [$otherOwner, $otherBorrower, $otherItem, $otherRequest] = $this->createRequestFixture();

        $response = $this->actingAsToken($owner)
            ->getJson('/api/incoming/rental-requests')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($otherRequest->id, $ids);
    }

    public function test_borrower_can_view_request_detail(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.borrower_id', $borrower->id);
    }

    public function test_owner_can_view_request_detail(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $this->actingAsToken($owner)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.owner.id', $owner->id);
    }

    public function test_admin_cannot_access_request_listings_or_detail_as_a_party(): void
    {
        [, $adminBorrower, , $borrowerRequest] = $this->createRequestFixture(
            'student',
            'admin'
        );
        [$adminOwner, , , $ownerRequest] = $this->createRequestFixture(
            'admin',
            'student'
        );

        $this->actingAsToken($adminBorrower)
            ->getJson('/api/my/rental-requests')
            ->assertForbidden();
        $this->getJson('/api/rental-requests/'.$borrowerRequest->id)
            ->assertForbidden();

        $this->actingAsToken($adminOwner)
            ->getJson('/api/incoming/rental-requests')
            ->assertForbidden();
        $this->getJson('/api/rental-requests/'.$ownerRequest->id)
            ->assertForbidden();
    }

    public function test_student_listings_and_details_exclude_requests_with_admin_parties(): void
    {
        [, $studentBorrower, , $adminOwnerRequest] = $this->createRequestFixture(
            'admin',
            'student'
        );
        [$studentOwner, , , $adminBorrowerRequest] = $this->createRequestFixture(
            'student',
            'admin'
        );

        $borrowerListing = $this->actingAsToken($studentBorrower)
            ->getJson('/api/my/rental-requests')
            ->assertOk();
        $this->assertNotContains(
            $adminOwnerRequest->id,
            collect($borrowerListing->json('data'))->pluck('id')->all()
        );
        $this->getJson('/api/rental-requests/'.$adminOwnerRequest->id)
            ->assertForbidden();

        $ownerListing = $this->actingAsToken($studentOwner)
            ->getJson('/api/incoming/rental-requests')
            ->assertOk();
        $this->assertNotContains(
            $adminBorrowerRequest->id,
            collect($ownerListing->json('data'))->pluck('id')->all()
        );
        $this->getJson('/api/rental-requests/'.$adminBorrowerRequest->id)
            ->assertForbidden();
    }

    public function test_outside_user_cannot_view_request_detail(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $this->actingAsToken($this->createUser())
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertForbidden();
    }

    public function test_detail_request_not_related_to_user_is_rejected(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $outside = $this->createUser();

        $this->actingAsToken($outside)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertForbidden();
    }

    public function test_all_rental_request_statuses_are_listed(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $statuses = ['pending', 'negotiating', 'accepted', 'rejected', 'cancelled', 'expired'];

        foreach ($statuses as $index => $status) {
            if ($index === 0) {
                $request->status = $status;
                $request->save();

                continue;
            }

            $this->createRequest($item, $borrower, $status);
        }

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-requests')
            ->assertOk();

        $listedStatuses = collect($response->json('data'))->pluck('status')->all();
        foreach ($statuses as $status) {
            $this->assertContains($status, $listedStatuses);
        }
    }

    public function test_all_negotiation_statuses_are_shown_in_detail(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $statuses = ['pending', 'accepted', 'rejected', 'withdrawn', 'superseded'];

        foreach ($statuses as $index => $status) {
            $this->createNegotiation(
                $request,
                $borrower,
                $status,
                CarbonImmutable::create(2035, 5, 10, 9, $index, 0, config('app.timezone'))
            );
        }

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk();

        $listedStatuses = collect($response->json('data.negotiations'))->pluck('status')->all();
        foreach ($statuses as $status) {
            $this->assertContains($status, $listedStatuses);
        }
    }

    public function test_negotiation_history_is_sorted_by_created_at_and_id(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $first = $this->createNegotiation(
            $request,
            $borrower,
            'pending',
            CarbonImmutable::create(2035, 5, 10, 9, 0, 0, config('app.timezone'))
        );
        $second = $this->createNegotiation(
            $request,
            $owner,
            'superseded',
            CarbonImmutable::create(2035, 5, 10, 10, 0, 0, config('app.timezone'))
        );

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk();

        $ids = collect($response->json('data.negotiations'))->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_transaction_is_included_when_available(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $request->status = 'accepted';
        $request->save();
        $negotiation = $this->createNegotiation($request, $borrower, 'accepted');

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
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.transaction.id', $transaction->id)
            ->assertJsonPath('data.transaction.accepted_negotiation_id', $negotiation->id);
    }

    public function test_transaction_is_null_when_not_available(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.transaction', null);
    }

    public function test_item_information_is_included_in_detail(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.item.id', $item->id)
            ->assertJsonPath('data.item.title', $item->title)
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner.name', $owner->name);
    }

    public function test_owner_information_contains_only_public_fields(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk();

        $ownerData = $response->json('data.owner');
        $this->assertSame(['id', 'name'], array_keys($ownerData));
    }

    public function test_cancelled_request_includes_cancelled_at(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $request->status = 'cancelled';
        $request->cancelled_at = CarbonImmutable::create(
            2035,
            5,
            10,
            12,
            0,
            0,
            config('app.timezone')
        );
        $request->save();

        $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', $request->cancelled_at->toJSON());
    }

    public function test_listing_does_not_change_request_status_or_data(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();

        $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-requests')
            ->assertOk();

        $request->refresh();
        $this->assertSame('pending', $request->status);
    }

    public function test_requests_of_other_users_do_not_appear_in_borrower_listing(): void
    {
        [, $borrower, $item, $request] = $this->createRequestFixture();
        [, $otherBorrower, $otherItem, $otherRequest] = $this->createRequestFixture();

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-requests')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($request->id, $ids);
        $this->assertNotContains($otherRequest->id, $ids);
    }

    public function test_soft_deleted_item_does_not_hide_request_history(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        $item->delete();

        $this->actingAsToken($borrower)
            ->getJson('/api/rental-requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('data.item.id', $item->id)
            ->assertJsonPath('data.owner.id', $owner->id);
    }

    public function test_owner_incoming_listing_includes_request_for_soft_deleted_item(): void
    {
        [$owner, $borrower, $item, $request] = $this->createRequestFixture();
        [, $otherBorrower, $otherItem, $otherRequest] = $this->createRequestFixture();
        $item->delete();

        $response = $this->actingAsToken($owner)
            ->getJson('/api/incoming/rental-requests')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($request->id, $ids);
        $this->assertNotContains($otherRequest->id, $ids);

        $listedRequest = collect($response->json('data'))
            ->firstWhere('id', $request->id);
        $this->assertSame($item->id, $listedRequest['item']['id']);
    }

    private function createRequestFixture(
        string $ownerRole = 'student',
        string $borrowerRole = 'student'
    ): array
    {
        $owner = $this->createUser('active', $ownerRole);
        $borrower = $this->createUser('active', $borrowerRole);
        $item = $this->createItem($owner);
        $request = $this->createRequest($item, $borrower);

        return [$owner, $borrower, $item, $request];
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
            'name' => 'Rental query category ' . Str::uuid(),
            'is_active' => true,
        ]);

        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Rental query item ' . Str::uuid(),
            'description' => 'Item description for rental query testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => 'active',
        ]);
    }

    private function createRequest(
        Item $item,
        User $borrower,
        string $status = 'pending'
    ): RentalRequest {
        $request = new RentalRequest();
        $request->setConnection('mysql');
        $request->item_id = $item->id;
        $request->borrower_id = $borrower->id;
        $request->request_message = 'Rental query test request.';
        $request->status = $status;
        $request->save();

        return $request;
    }

    private function createNegotiation(
        RentalRequest $request,
        User $proposer,
        string $status,
        ?CarbonImmutable $createdAt = null
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

        if ($createdAt) {
            $negotiation->created_at = $createdAt;
            $negotiation->save();
        }

        return $negotiation;
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('rental-query-test')->plainTextToken);
    }
}
