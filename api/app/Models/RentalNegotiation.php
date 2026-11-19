<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'request_id',
    'proposer_id',
    'proposed_daily_price',
    'proposed_start_at',
    'proposed_due_at',
    'message',
])]
class RentalNegotiation extends Model
{
    protected function casts(): array
    {
        return [
            'proposed_daily_price' => 'decimal:2',
            'proposed_start_at' => 'datetime',
            'proposed_due_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function rentalRequest(): BelongsTo
    {
        return $this->belongsTo(RentalRequest::class, 'request_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposer_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(RentalTransaction::class, 'accepted_negotiation_id');
    }
}
