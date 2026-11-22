<?php

namespace App\Console\Commands;

use App\Models\RentalTransaction;
use Illuminate\Console\Command;

class MarkOverdueRentalTransactions extends Command
{
    protected $signature = 'rental-transactions:mark-overdue';

    protected $description = 'Mark active rental transactions overdue after their due time.';

    public function handle(): int
    {
        $checkedAt = now();

        $count = RentalTransaction::query()
            ->where('status', 'active')
            ->where('due_at', '<=', $checkedAt)
            ->update([
                'status' => 'overdue',
                'overdue_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);

        $this->info("Marked {$count} rental transaction(s) as overdue.");

        return self::SUCCESS;
    }
}
