<?php

namespace App\Http\Requests\Rental;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreRentalExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'proposed_due_at' => ['required', 'string', 'date'],
        ];
    }

    public function normalizedProposedDue(): CarbonImmutable
    {
        $timezone = config('app.timezone');

        return CarbonImmutable::parse((string) $this->validated('proposed_due_at'), $timezone)
            ->setTimezone($timezone);
    }
}
