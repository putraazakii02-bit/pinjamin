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

class MarkOverdueRentalTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_active_transaction_past_due_is_marked_overdue(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $transaction = $this->createTransaction('active', $checkedAt->subSecond());

        $this->runCommand(1);

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'overdue',
        ], 'mysql');
    }

    public function test_overdue_at_uses_the_frozen_check_time(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $transaction = $this->createTransaction('active', $checkedAt->subHour());

        $this->runCommand(1);

        $transaction->refresh();

        $this->assertSame($checkedAt->toDateTimeString(), $transaction->overdue_at->toDateTimeString());
        $this->assertSame($checkedAt->toDateTimeString(), $transaction->updated_at->toDateTimeString());
    }

    public function test_transaction_due_exactly_at_check_time_is_marked_overdue(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $transaction = $this->createTransaction('active', $checkedAt);

        $this->runCommand(1);

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'overdue',
        ], 'mysql');
    }

    public function test_active_transaction_not_yet_due_is_not_changed(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $transaction = $this->createTransaction('active', $checkedAt->addSecond());

        $this->runCommand(0);

        $this->assertDatabaseHas('rental_transactions', [
            'id' => $transaction->id,
            'status' => 'active',
            'overdue_at' => null,
        ], 'mysql');
    }

    public function test_non_active_transactions_are_not_changed_when_past_due(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $transactions = [
            $this->createTransaction('awaiting_payment', $checkedAt->subHour()),
            $this->createTransaction('awaiting_handover', $checkedAt->subHour()),
            $this->createTransaction('completed', $checkedAt->subHour()),
        ];

        $this->runCommand(0);

        foreach ($transactions as $transaction) {
            $this->assertDatabaseHas('rental_transactions', [
                'id' => $transaction->id,
                'status' => $transaction->status,
                'overdue_at' => null,
            ], 'mysql');
        }
    }

    public function test_already_overdue_transaction_keeps_its_original_overdue_at(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $originalOverdueAt = $checkedAt->subDay();
        $transaction = $this->createTransaction('overdue', $checkedAt->subHour(), $originalOverdueAt);

        $this->runCommand(0);

        $transaction->refresh();

        $this->assertSame($originalOverdueAt->toDateTimeString(), $transaction->overdue_at->toDateTimeString());
    }

    public function test_repeated_command_does_not_change_marked_transaction_again(): void
    {
        $checkedAt = CarbonImmutable::parse('2035-05-10 09:00:00', 'UTC');
        $this->travelTo($checkedAt);
        $transaction = $this->createTransaction('active', $checkedAt->subHour());

        $this->runCommand(1);
        $transaction->refresh();
        $firstOverdueAt = $transaction->overdue_at->toDateTimeString();
        $firstUpdatedAt = $transaction->updated_at->toDateTimeString();

        $this->travelTo($checkedAt->addMinute());
        $this->runCommand(0);
        $transaction->refresh();

        $this->assertSame('overdue', $transaction->status);
        $this->assertSame($firstOverdueAt, $transaction->overdue_at->toDateTimeString());
        $this->assertSame($firstUpdatedAt, $transaction->updated_at->toDateTimeString());
    }

    public function test_command_succeeds_when_no_transaction_is_eligible(): void
    {
        $this->runCommand(0);
    }

    private function runCommand(int $count): void
    {
        $this->artisan('rental-transactions:mark-overdue')
            ->expectsOutput("Marked {$count} rental transaction(s) as overdue.")
            ->assertExitCode(0);
    }

    private function createTransaction(
        string $status,
        CarbonImmutable $dueAt,
        ?CarbonImmutable $overdueAt = null
    ): RentalTransaction {
        $owner = User::factory()->create();
        $borrower = User::factory()->create();

        $category = Category::on('mysql')->create([
            'name' => 'Overdue category ' . Str::uuid(),
            'is_active' => true,
        ]);

        $item = Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Overdue item ' . Str::uuid(),
            'description' => 'Item fixture for overdue transaction testing.',
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
        $negotiation->proposed_start_at = $dueAt->subDay();
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
        $transaction->start_at = $dueAt->subDay();
        $transaction->due_at = $dueAt;
        $transaction->status = $status;
        $transaction->overdue_at = $overdueAt;
        $transaction->save();

        return $transaction;
    }
}
