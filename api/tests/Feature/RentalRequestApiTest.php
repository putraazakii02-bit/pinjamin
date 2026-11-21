<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\RentalNegotiation;
use App\Models\RentalRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RentalRequestApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_create_a_rental_request(): void
    {
        $item = $this->createItem($this->createUser());

        $this->postJson($this->endpoint($item), $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_suspended_account_cannot_create_a_rental_request(): void
    {
        $user = $this->createUser('suspended');
        $item = $this->createItem($this->createUser());

        $this->postAs($user, $item)
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_deactivated_account_cannot_create_a_rental_request(): void
    {
        $user = $this->createUser('deactivated');
        $item = $this->createItem($this->createUser());

        $this->postAs($user, $item)
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_active_student_can_create_a_rental_request(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $this->postAs($borrower, $item)
            ->assertCreated()
            ->assertJsonPath('data.item_id', $item->id)
            ->assertJsonPath('data.borrower_id', $borrower->id)
            ->assertJsonPath('data.initial_negotiation.status', 'pending');
    }

    public function test_new_rental_request_has_pending_status(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item)->assertCreated();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $response->json('data.id'),
            'status' => 'pending',
        ], 'mysql');
    }

    public function test_first_negotiation_has_pending_status(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item)->assertCreated();

        $this->assertDatabaseHas('rental_negotiations', [
            'request_id' => $response->json('data.id'),
            'status' => 'pending',
        ], 'mysql');
    }

    public function test_borrower_id_comes_from_authenticated_user(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item)->assertCreated();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $response->json('data.id'),
            'borrower_id' => $borrower->id,
        ], 'mysql');
    }

    public function test_proposer_id_comes_from_authenticated_user(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item)->assertCreated();

        $this->assertDatabaseHas('rental_negotiations', [
            'request_id' => $response->json('data.id'),
            'proposer_id' => $borrower->id,
        ], 'mysql');
    }

    public function test_payload_borrower_id_cannot_replace_authenticated_user(): void
    {
        $borrower = $this->createUser();
        $otherUser = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item, [
            'borrower_id' => $otherUser->id,
        ])->assertCreated();

        $this->assertDatabaseHas('rental_requests', [
            'id' => $response->json('data.id'),
            'borrower_id' => $borrower->id,
        ], 'mysql');
        $this->assertDatabaseMissing('rental_requests', [
            'id' => $response->json('data.id'),
            'borrower_id' => $otherUser->id,
        ], 'mysql');
    }

    public function test_payload_proposer_id_cannot_replace_authenticated_user(): void
    {
        $borrower = $this->createUser();
        $otherUser = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item, [
            'proposer_id' => $otherUser->id,
        ])->assertCreated();

        $this->assertDatabaseHas('rental_negotiations', [
            'request_id' => $response->json('data.id'),
            'proposer_id' => $borrower->id,
        ], 'mysql');
        $this->assertDatabaseMissing('rental_negotiations', [
            'request_id' => $response->json('data.id'),
            'proposer_id' => $otherUser->id,
        ], 'mysql');
    }

    public function test_active_admin_cannot_create_a_rental_request(): void
    {
        $admin = $this->createUser('active', 'admin');
        $item = $this->createItem($this->createUser());

        $this->postAs($admin, $item)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Hanya mahasiswa yang dapat mengajukan peminjaman.'
            );
    }

    public function test_owner_cannot_request_own_item(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->postAs($owner, $item)->assertForbidden();
    }

    public function test_inactive_item_cannot_be_requested(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser(), 'inactive');

        $this->postAs($borrower, $item)->assertNotFound();
    }

    public function test_archived_item_cannot_be_requested(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser(), 'archived');

        $this->postAs($borrower, $item)->assertNotFound();
    }

    public function test_soft_deleted_item_cannot_be_requested(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $item->delete();

        $this->postAs($borrower, $item)->assertNotFound();
    }

    public function test_zero_daily_price_is_rejected(): void
    {
        $this->assertInvalidPayload(['proposed_daily_price' => '0']);
    }

    public function test_negative_daily_price_is_rejected(): void
    {
        $this->assertInvalidPayload(['proposed_daily_price' => '-1.00']);
    }

    public function test_daily_price_with_more_than_two_decimal_places_is_rejected(): void
    {
        $this->assertInvalidPayload(['proposed_daily_price' => '12.345']);
    }

    public function test_daily_price_over_decimal_capacity_is_rejected(): void
    {
        $this->assertInvalidPayload(['proposed_daily_price' => '10000000000.00']);
    }

    public function test_invalid_start_time_is_rejected(): void
    {
        $this->assertInvalidPayload(['proposed_start_at' => 'not-a-date']);
    }

    public function test_invalid_due_time_is_rejected(): void
    {
        $this->assertInvalidPayload(['proposed_due_at' => 'not-a-date']);
    }

    public function test_due_time_before_start_time_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['proposed_due_at'] = CarbonImmutable::parse(
            $payload['proposed_start_at'],
            config('app.timezone')
        )->subHour()->format('Y-m-d H:i:s');

        $this->assertInvalidPayload([], $payload);
    }

    public function test_duration_under_24_hours_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['proposed_due_at'] = CarbonImmutable::parse(
            $payload['proposed_start_at'],
            config('app.timezone')
        )->addHours(23)->addMinutes(59)->addSeconds(59)->format('Y-m-d H:i:s');

        $this->assertInvalidPayload([], $payload);
    }

    public function test_duration_of_exactly_24_hours_is_accepted(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $this->postAs($borrower, $item)->assertCreated();
    }

    public function test_duration_over_24_hours_is_accepted(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $payload = $this->validPayload();
        $payload['proposed_due_at'] = CarbonImmutable::parse(
            $payload['proposed_start_at'],
            config('app.timezone')
        )->addHours(25)->format('Y-m-d H:i:s');

        $this->postAs($borrower, $item, $payload)->assertCreated();
    }

    public function test_timezone_offsets_are_normalized_to_application_timezone(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $applicationTimezone = config('app.timezone');
        $start = CarbonImmutable::parse('2035-05-10T09:00:00+07:00')
            ->setTimezone($applicationTimezone);
        $payload = [
            'proposed_daily_price' => '25.50',
            'proposed_start_at' => '2035-05-10T09:00:00+07:00',
            'proposed_due_at' => '2035-05-11T09:00:00+07:00',
        ];

        $response = $this->postAs($borrower, $item, $payload)->assertCreated();

        $this->assertDatabaseHas('rental_negotiations', [
            'request_id' => $response->json('data.id'),
            'proposed_start_at' => $start->format('Y-m-d H:i:s'),
        ], 'mysql');
    }

    public function test_pending_request_for_same_item_and_borrower_returns_conflict(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $this->createRentalRequest($item, $borrower, 'pending');

        $this->postAs($borrower, $item)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Anda masih memiliki permintaan aktif untuk barang ini.'
            );
    }

    public function test_negotiating_request_for_same_item_and_borrower_returns_conflict(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $this->createRentalRequest($item, $borrower, 'negotiating');

        $this->postAs($borrower, $item)->assertConflict();
    }

    public function test_new_request_is_allowed_after_previous_request_was_rejected(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $this->createRentalRequest($item, $borrower, 'rejected');

        $this->postAs($borrower, $item)->assertCreated();
    }

    public function test_new_request_is_allowed_after_previous_request_was_cancelled(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $this->createRentalRequest($item, $borrower, 'cancelled');

        $this->postAs($borrower, $item)->assertCreated();
    }

    public function test_new_request_is_allowed_after_previous_request_expired(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $this->createRentalRequest($item, $borrower, 'expired');

        $this->postAs($borrower, $item)->assertCreated();
    }

    public function test_negotiation_creation_failure_rolls_back_new_request(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $eventName = 'eloquent.creating: ' . RentalNegotiation::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated negotiation creation failure.');
        });

        try {
            $this->postAs($borrower, $item)->assertInternalServerError();
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseMissing('rental_requests', [
            'item_id' => $item->id,
            'borrower_id' => $borrower->id,
        ], 'mysql');
    }

    public function test_request_and_first_negotiation_are_linked_to_correct_item_and_user(): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());

        $response = $this->postAs($borrower, $item)->assertCreated();
        $requestId = $response->json('data.id');
        $negotiationId = $response->json('data.initial_negotiation.id');

        $this->assertDatabaseHas('rental_requests', [
            'id' => $requestId,
            'item_id' => $item->id,
            'borrower_id' => $borrower->id,
        ], 'mysql');
        $this->assertDatabaseHas('rental_negotiations', [
            'id' => $negotiationId,
            'request_id' => $requestId,
            'proposer_id' => $borrower->id,
            'responded_by' => null,
            'responded_at' => null,
        ], 'mysql');
        $this->assertDatabaseMissing('rental_transactions', [
            'request_id' => $requestId,
        ], 'mysql');
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed>|null $payload
     */
    private function assertInvalidPayload(array $overrides, ?array $payload = null): void
    {
        $borrower = $this->createUser();
        $item = $this->createItem($this->createUser());
        $payload ??= array_merge($this->validPayload(), $overrides);

        $this->postAs($borrower, $item, $payload)->assertUnprocessable();
    }

    private function createUser(string $accountStatus = 'active', string $role = 'student'): User
    {
        $user = User::factory()->create();
        $user->account_status = $accountStatus;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function createItem(User $owner, string $listingStatus = 'active'): Item
    {
        $category = Category::on('mysql')->create([
            'name' => 'Rental request category ' . Str::uuid(),
            'is_active' => true,
        ]);

        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Rental request item ' . Str::uuid(),
            'description' => 'Item description for rental request testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => $listingStatus,
        ]);
    }

    private function createRentalRequest(Item $item, User $borrower, string $status): RentalRequest
    {
        $rentalRequest = new RentalRequest();
        $rentalRequest->setConnection('mysql');
        $rentalRequest->item_id = $item->id;
        $rentalRequest->borrower_id = $borrower->id;
        $rentalRequest->status = $status;
        $rentalRequest->save();

        return $rentalRequest;
    }

    private function endpoint(Item $item): string
    {
        return '/api/items/' . $item->id . '/rental-requests';
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function postAs(User $user, Item $item, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($user->createToken('rental-request-test')->plainTextToken)
            ->postJson($this->endpoint($item), array_merge($this->validPayload(), $overrides));
    }

    /**
     * @return array<string, string|null>
     */
    private function validPayload(): array
    {
        $start = CarbonImmutable::create(2035, 5, 10, 9, 0, 0, config('app.timezone'));

        return [
            'request_message' => 'Saya ingin meminjam barang ini.',
            'proposed_daily_price' => '25.50',
            'proposed_start_at' => $start->format('Y-m-d H:i:s'),
            'proposed_due_at' => $start->addHours(24)->format('Y-m-d H:i:s'),
        ];
    }
}
