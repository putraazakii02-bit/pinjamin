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

class RentalTransactionQueryApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_transaction_lists_or_detail(): void
    {
        [$transaction] = $this->createTransaction();

        $this->getJson('/api/my/rental-transactions')->assertUnauthorized();
        $this->getJson('/api/incoming/rental-transactions')->assertUnauthorized();
        $this->getJson($this->transactionEndpoint($transaction))->assertUnauthorized();
    }

    public function test_suspended_and_deactivated_accounts_cannot_access_transaction_queries(): void
    {
        foreach (['suspended', 'deactivated'] as $accountStatus) {
            [$transaction, $owner, $borrower] = $this->createTransaction();

            $this->setAccountStatus($borrower, $accountStatus);
            $this->actingAsToken($borrower)
                ->getJson('/api/my/rental-transactions')
                ->assertForbidden();
            $this->getJson($this->transactionEndpoint($transaction))
                ->assertForbidden();

            $this->setAccountStatus($owner, $accountStatus);
            $this->actingAsToken($owner)
                ->getJson('/api/incoming/rental-transactions')
                ->assertForbidden();
            $this->getJson($this->transactionEndpoint($transaction))
                ->assertForbidden();
        }
    }

    public function test_borrower_list_contains_all_and_only_transactions_for_the_logged_in_borrower(): void
    {
        $owner = $this->createUser();
        $borrower = $this->createUser();
        $otherBorrower = $this->createUser();
        [$first] = $this->createTransaction('active', $owner, $borrower);
        [$second] = $this->createTransaction('completed', $owner, $borrower);
        [$other] = $this->createTransaction('active', $owner, $otherBorrower);

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-transactions')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_owner_list_contains_all_and_only_transactions_for_the_logged_in_owner(): void
    {
        $owner = $this->createUser();
        $otherOwner = $this->createUser();
        $borrower = $this->createUser();
        [$first] = $this->createTransaction('awaiting_payment', $owner, $borrower);
        [$second] = $this->createTransaction('overdue', $owner, $borrower);
        [$other] = $this->createTransaction('active', $otherOwner, $borrower);

        $response = $this->actingAsToken($owner)
            ->getJson('/api/incoming/rental-transactions')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_admin_cannot_access_transaction_lists_or_detail_as_either_party(): void
    {
        $student = $this->createUser();
        $adminBorrower = $this->createUser('active', 'admin');
        $adminOwner = $this->createUser('active', 'admin');
        [$borrowedTransaction] = $this->createTransaction(
            'active',
            $student,
            $adminBorrower
        );
        [$ownedTransaction] = $this->createTransaction(
            'active',
            $adminOwner,
            $student
        );

        $this->actingAsToken($adminBorrower)
            ->getJson('/api/my/rental-transactions')
            ->assertForbidden();
        $this->getJson($this->transactionEndpoint($borrowedTransaction))
            ->assertForbidden();

        $this->actingAsToken($adminOwner)
            ->getJson('/api/incoming/rental-transactions')
            ->assertForbidden();
        $this->getJson($this->transactionEndpoint($ownedTransaction))
            ->assertForbidden();
    }

    public function test_lists_exclude_transactions_with_a_non_student_counterparty(): void
    {
        $studentOwner = $this->createUser();
        $studentBorrower = $this->createUser();
        $adminOwner = $this->createUser('active', 'admin');
        $adminBorrower = $this->createUser('active', 'admin');

        [$ownerAdminTransaction] = $this->createTransaction(
            'active',
            $adminOwner,
            $studentBorrower,
            $studentOwner,
            $studentBorrower
        );
        [$borrowerAdminTransaction] = $this->createTransaction(
            'active',
            $studentOwner,
            $adminBorrower,
            $studentOwner,
            $studentBorrower
        );
        [$validBorrowerTransaction] = $this->createTransaction(
            'active',
            $studentOwner,
            $studentBorrower
        );
        [$validOwnerTransaction] = $this->createTransaction(
            'active',
            $studentOwner,
            $studentBorrower
        );

        $borrowerIds = collect(
            $this->actingAsToken($studentBorrower)
                ->getJson('/api/my/rental-transactions')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();
        $this->assertContains($validBorrowerTransaction->id, $borrowerIds);
        $this->assertNotContains($ownerAdminTransaction->id, $borrowerIds);

        $ownerIds = collect(
            $this->actingAsToken($studentOwner)
                ->getJson('/api/incoming/rental-transactions')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();
        $this->assertContains($validOwnerTransaction->id, $ownerIds);
        $this->assertNotContains($borrowerAdminTransaction->id, $ownerIds);
    }

    public function test_owner_and_borrower_can_each_view_transaction_detail(): void
    {
        [$transaction, $owner, $borrower] = $this->createTransaction();
        $endpoint = $this->transactionEndpoint($transaction);

        $this->actingAsToken($owner)
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.owner.id', $owner->id);

        $this->actingAsToken($borrower)
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.borrower.id', $borrower->id);
    }

    public function test_user_outside_transaction_cannot_view_detail(): void
    {
        [$transaction] = $this->createTransaction();

        $this->actingAsToken($this->createUser())
            ->getJson($this->transactionEndpoint($transaction))
            ->assertForbidden();
    }

    public function test_student_party_cannot_view_transaction_with_admin_counterparty(): void
    {
        $studentOwner = $this->createUser();
        $studentBorrower = $this->createUser();
        $adminOwner = $this->createUser('active', 'admin');
        $adminBorrower = $this->createUser('active', 'admin');
        [$ownerAdminTransaction] = $this->createTransaction(
            'active',
            $adminOwner,
            $studentBorrower,
            $studentOwner,
            $studentBorrower
        );
        [$borrowerAdminTransaction] = $this->createTransaction(
            'active',
            $studentOwner,
            $adminBorrower,
            $studentOwner,
            $studentBorrower
        );

        $this->actingAsToken($studentBorrower)
            ->getJson($this->transactionEndpoint($ownerAdminTransaction))
            ->assertForbidden();
        $this->actingAsToken($studentOwner)
            ->getJson($this->transactionEndpoint($borrowerAdminTransaction))
            ->assertForbidden();
    }

    public function test_query_authorization_uses_transaction_party_columns_not_request_or_item_parties(): void
    {
        $transactionOwner = $this->createUser();
        $transactionBorrower = $this->createUser();
        $itemOwner = $this->createUser('active', 'admin');
        $requestBorrower = $this->createUser('active', 'admin');
        [$transaction] = $this->createTransaction(
            'active',
            $transactionOwner,
            $transactionBorrower,
            $itemOwner,
            $requestBorrower
        );

        $this->actingAsToken($transactionOwner)
            ->getJson('/api/incoming/rental-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $transaction->id);
        $this->actingAsToken($transactionBorrower)
            ->getJson('/api/my/rental-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $transaction->id);
        $this->actingAsToken($transactionOwner)
            ->getJson($this->transactionEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.owner.id', $transactionOwner->id)
            ->assertJsonPath('data.borrower.id', $transactionBorrower->id);
    }

    public function test_transaction_resource_includes_safe_relations_and_soft_deleted_item(): void
    {
        $createdAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $dueAt = CarbonImmutable::parse('2035-05-12 09:00:00', 'UTC');
        $overdueAt = CarbonImmutable::parse('2035-05-13 09:00:00', 'UTC');
        $completedAt = CarbonImmutable::parse('2035-05-14 09:00:00', 'UTC');
        [$transaction, $owner, $borrower, $item] = $this->createTransaction('completed');
        $transaction->start_at = $createdAt;
        $transaction->due_at = $dueAt;
        $transaction->overdue_at = $overdueAt;
        $transaction->owner_handover_confirmed_at = $createdAt;
        $transaction->borrower_received_confirmed_at = $createdAt->addMinutes(5);
        $transaction->borrower_returned_at = $dueAt->addHour();
        $transaction->owner_return_received_at = $dueAt->addHours(2);
        $transaction->completed_at = $completedAt;
        $transaction->save();
        $item->delete();
        $snapshot = $this->transactionSnapshot($transaction);

        $response = $this->actingAsToken($borrower)
            ->getJson($this->transactionEndpoint($transaction))
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.request_id', $transaction->request_id)
            ->assertJsonPath('data.accepted_negotiation_id', $transaction->accepted_negotiation_id)
            ->assertJsonPath('data.item_id', $item->id)
            ->assertJsonPath('data.owner_id', $owner->id)
            ->assertJsonPath('data.borrower_id', $borrower->id)
            ->assertJsonPath('data.agreed_daily_price', '25.50')
            ->assertJsonPath('data.total_price', '51.00')
            ->assertJsonPath('data.start_at', $transaction->start_at->toJSON())
            ->assertJsonPath('data.due_at', $transaction->due_at->toJSON())
            ->assertJsonPath('data.owner_handover_confirmed_at', $transaction->owner_handover_confirmed_at->toJSON())
            ->assertJsonPath('data.borrower_received_confirmed_at', $transaction->borrower_received_confirmed_at->toJSON())
            ->assertJsonPath('data.borrower_returned_at', $transaction->borrower_returned_at->toJSON())
            ->assertJsonPath('data.owner_return_received_at', $transaction->owner_return_received_at->toJSON())
            ->assertJsonPath('data.overdue_at', $overdueAt->toJSON())
            ->assertJsonPath('data.completed_at', $completedAt->toJSON())
            ->assertJsonPath('data.item.id', $item->id)
            ->assertJsonPath('data.item.title', $item->title)
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner.name', $owner->name)
            ->assertJsonPath('data.borrower.id', $borrower->id)
            ->assertJsonPath('data.borrower.name', $borrower->name)
            ->assertJsonPath('data.rental_request.id', $transaction->request_id)
            ->assertJsonPath('data.accepted_negotiation.id', $transaction->accepted_negotiation_id);

        $data = $response->json('data');
        $this->assertSame(['id', 'name'], array_keys($data['owner']));
        $this->assertSame(['id', 'name'], array_keys($data['borrower']));
        $this->assertArrayNotHasKey('password', $data['owner']);
        $this->assertArrayNotHasKey('token', $data['owner']);
        $this->assertArrayNotHasKey('deleted_at', $data['item']);
        $this->assertArrayNotHasKey('transaction', $data['rental_request']);
        $this->assertArrayNotHasKey('proof_path', $data);
        $this->assertSame($snapshot, $this->transactionSnapshot($transaction));

        $listedTransaction = collect(
            $this->actingAsToken($borrower)
                ->getJson('/api/my/rental-transactions')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $transaction->id);
        $this->assertNotNull($listedTransaction);
        $this->assertSame($overdueAt->toJSON(), $listedTransaction['overdue_at']);
        $this->assertSame($completedAt->toJSON(), $listedTransaction['completed_at']);
        $this->assertSame($snapshot, $this->transactionSnapshot($transaction));
    }

    public function test_overdue_and_completed_timestamps_remain_null_in_list_and_detail(): void
    {
        [$transaction, $owner, $borrower] = $this->createTransaction('active');
        $snapshot = $this->transactionSnapshot($transaction);

        $detail = $this->actingAsToken($borrower)
            ->getJson($this->transactionEndpoint($transaction))
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('overdue_at', $detail);
        $this->assertArrayHasKey('completed_at', $detail);
        $this->assertNull($detail['overdue_at']);
        $this->assertNull($detail['completed_at']);

        $listedTransaction = collect(
            $this->actingAsToken($borrower)
                ->getJson('/api/my/rental-transactions')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $transaction->id);
        $this->assertNotNull($listedTransaction);
        $this->assertArrayHasKey('overdue_at', $listedTransaction);
        $this->assertArrayHasKey('completed_at', $listedTransaction);
        $this->assertNull($listedTransaction['overdue_at']);
        $this->assertNull($listedTransaction['completed_at']);
        $this->assertSame($snapshot, $this->transactionSnapshot($transaction));
    }

    public function test_all_schema_transaction_statuses_are_visible_in_lists_and_detail_without_mutation(): void
    {
        $owner = $this->createUser();
        $borrower = $this->createUser();
        $statuses = [
            'awaiting_payment',
            'awaiting_payment_confirmation',
            'awaiting_handover',
            'active',
            'overdue',
            'return_pending_confirmation',
            'completed',
            'cancelled',
            'disputed',
        ];
        $transactions = [];

        foreach ($statuses as $status) {
            [$transaction] = $this->createTransaction($status, $owner, $borrower);
            $transactions[] = $transaction;
        }

        $snapshots = array_map(
            fn (RentalTransaction $transaction): array => $this->transactionSnapshot($transaction),
            $transactions
        );

        $response = $this->actingAsToken($borrower)
            ->getJson('/api/my/rental-transactions')
            ->assertOk();
        $listed = collect($response->json('data'));
        $this->assertSame(
            array_reverse($statuses),
            $listed->pluck('status')->all()
        );

        foreach ($transactions as $index => $transaction) {
            $this->actingAsToken($borrower)
                ->getJson($this->transactionEndpoint($transaction))
                ->assertOk()
                ->assertJsonPath('data.status', $statuses[$index]);
            $this->assertSame(
                $snapshots[$index],
                $this->transactionSnapshot($transaction)
            );
        }
    }

    private function createTransaction(
        string $status = 'active',
        ?User $owner = null,
        ?User $borrower = null,
        ?User $itemOwner = null,
        ?User $requestBorrower = null
    ): array {
        $owner ??= $this->createUser();
        $borrower ??= $this->createUser();
        $itemOwner ??= $owner;
        $requestBorrower ??= $borrower;

        $category = Category::on('mysql')->create([
            'name' => 'Transaction query category '.Str::uuid(),
            'is_active' => true,
        ]);
        $item = Item::on('mysql')->create([
            'owner_id' => $itemOwner->id,
            'category_id' => $category->id,
            'title' => 'Transaction query item '.Str::uuid(),
            'description' => 'Item fixture for transaction query testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => 'active',
        ]);

        $rentalRequest = new RentalRequest();
        $rentalRequest->setConnection('mysql');
        $rentalRequest->item_id = $item->id;
        $rentalRequest->borrower_id = $requestBorrower->id;
        $rentalRequest->request_message = 'Transaction query test request.';
        $rentalRequest->status = 'accepted';
        $rentalRequest->save();

        $startAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $dueAt = $startAt->addDays(2);
        $negotiation = new RentalNegotiation();
        $negotiation->setConnection('mysql');
        $negotiation->request_id = $rentalRequest->id;
        $negotiation->proposer_id = $requestBorrower->id;
        $negotiation->proposed_daily_price = '25.50';
        $negotiation->proposed_start_at = $startAt;
        $negotiation->proposed_due_at = $dueAt;
        $negotiation->message = 'Accepted offer.';
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
        $transaction->total_price = '51.00';
        $transaction->start_at = $startAt;
        $transaction->due_at = $dueAt;
        $transaction->status = $status;
        $transaction->save();

        return [$transaction, $owner, $borrower, $item, $rentalRequest, $negotiation];
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

    private function transactionSnapshot(RentalTransaction $transaction): array
    {
        $transaction->refresh();

        return [
            'status' => $transaction->status,
            'start_at' => $transaction->start_at?->toJSON(),
            'due_at' => $transaction->due_at?->toJSON(),
            'overdue_at' => $transaction->overdue_at?->toJSON(),
            'owner_handover_confirmed_at' => $transaction->owner_handover_confirmed_at?->toJSON(),
            'borrower_received_confirmed_at' => $transaction->borrower_received_confirmed_at?->toJSON(),
            'borrower_returned_at' => $transaction->borrower_returned_at?->toJSON(),
            'owner_return_received_at' => $transaction->owner_return_received_at?->toJSON(),
            'completed_at' => $transaction->completed_at?->toJSON(),
            'updated_at' => $transaction->updated_at?->toJSON(),
        ];
    }

    private function transactionEndpoint(RentalTransaction $transaction): string
    {
        return "/api/rental-transactions/{$transaction->id}";
    }

    private function actingAsToken(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('rental-transaction-query-test')->plainTextToken);
    }
}
