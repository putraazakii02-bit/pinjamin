<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RentalTransactionQueryResource extends RentalTransactionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'overdue_at' => $this->overdue_at,
            'completed_at' => $this->completed_at,
            'item' => $this->whenLoaded(
                'item',
                fn () => $this->item ? new ItemResource($this->item) : null
            ),
            'owner' => $this->whenLoaded(
                'owner',
                fn () => $this->owner
                    ? [
                        'id' => $this->owner->id,
                        'name' => $this->owner->name,
                    ]
                    : null
            ),
            'borrower' => $this->whenLoaded(
                'borrower',
                fn () => $this->borrower
                    ? [
                        'id' => $this->borrower->id,
                        'name' => $this->borrower->name,
                    ]
                    : null
            ),
            'rental_request' => $this->whenLoaded(
                'rentalRequest',
                fn () => $this->rentalRequest
                    ? new RentalRequestResource($this->rentalRequest)
                    : null
            ),
            'accepted_negotiation' => $this->whenLoaded(
                'acceptedNegotiation',
                fn () => $this->acceptedNegotiation
                    ? new RentalNegotiationResource($this->acceptedNegotiation)
                    : null
            ),
        ]);
    }
}
