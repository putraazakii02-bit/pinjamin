<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'owner_id',
    'category_id',
    'title',
    'description',
    'daily_price',
    'item_condition',
    'condition_notes',
    'listing_status',
])]
class Item extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'daily_price' => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ItemImage::class, 'item_id');
    }

    public function rentalRequests(): HasMany
    {
        return $this->hasMany(RentalRequest::class, 'item_id');
    }

    public function rentalTransactions(): HasMany
    {
        return $this->hasMany(RentalTransaction::class, 'item_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'item_id');
    }
}
