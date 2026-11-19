<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'item_id',
    'borrower_id',
    'request_message',
])]
class RentalRequest extends Model
{
    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    public function negotiations(): HasMany
    {
        return $this->hasMany(RentalNegotiation::class, 'request_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(RentalTransaction::class, 'request_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'request_id');
    }
}
