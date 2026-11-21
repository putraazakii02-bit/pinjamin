<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'borrower_id' => $this->borrower_id,
            'request_message' => $this->request_message,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'negotiations' => $this->whenLoaded(
                'negotiations',
                fn ($negotiations) => RentalNegotiationResource::collection($negotiations)
            ),
            'initial_negotiation' => $this->whenLoaded(
                'negotiations',
                fn ($negotiations) => new RentalNegotiationResource($negotiations->first())
            ),
            'transaction' => $this->whenLoaded(
                'transaction',
                fn ($transaction) => $transaction
                    ? new RentalTransactionResource($transaction)
                    : null
            ),
        ];
    }
}
