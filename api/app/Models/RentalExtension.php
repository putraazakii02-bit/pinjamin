<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'transaction_id',
    'proposer_id',
    'previous_extension_id',
    'previous_due_at',
    'proposed_due_at',
    'proposed_fee',
    'message',
])]
class RentalExtension extends Model
{
    protected function casts(): array
    {
        return [
            'previous_due_at' => 'datetime',
            'proposed_due_at' => 'datetime',
            'proposed_fee' => 'decimal:2',
            'responded_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(RentalTransaction::class, 'transaction_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposer_id');
    }

    public function previousExtension(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_extension_id');
    }

    public function subsequentExtensions(): HasMany
    {
        return $this->hasMany(self::class, 'previous_extension_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'extension_id');
    }
}
