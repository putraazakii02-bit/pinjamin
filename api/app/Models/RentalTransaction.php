<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'request_id',
    'accepted_negotiation_id',
    'item_id',
    'owner_id',
    'borrower_id',
    'agreed_daily_price',
    'total_price',
    'start_at',
    'due_at',
    'cancellation_reason',
])]
class RentalTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'agreed_daily_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'start_at' => 'datetime',
            'due_at' => 'datetime',
            'overdue_at' => 'datetime',
            'owner_handover_confirmed_at' => 'datetime',
            'borrower_received_confirmed_at' => 'datetime',
            'borrower_returned_at' => 'datetime',
            'owner_return_received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function rentalRequest(): BelongsTo
    {
        return $this->belongsTo(RentalRequest::class, 'request_id');
    }

    public function acceptedNegotiation(): BelongsTo
    {
        return $this->belongsTo(RentalNegotiation::class, 'accepted_negotiation_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(RentalExtension::class, 'transaction_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'transaction_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'transaction_id');
    }
}
