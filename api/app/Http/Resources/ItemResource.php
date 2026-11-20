<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'description' => $this->description,
            'daily_price' => $this->daily_price,
            'item_condition' => $this->item_condition,
            'condition_notes' => $this->condition_notes,
            'listing_status' => $this->listing_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
