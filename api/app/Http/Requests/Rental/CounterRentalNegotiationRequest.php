<?php

namespace App\Http\Requests\Rental;

use App\Support\RentalPriceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CounterRentalNegotiationRequest extends FormRequest
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
            'proposed_daily_price' => [
                'required',
                'numeric',
                'regex:/^\d+(?:\.\d{1,2})?$/',
                'gt:0',
                'decimal:0,2',
                'max:9999999999.99',
            ],
            'proposed_start_at' => ['required', 'string', 'date'],
            'proposed_due_at' => ['required', 'string', 'date'],
            'message' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('proposed_start_at')
                || $validator->errors()->has('proposed_due_at')) {
                return;
            }

            try {
                $start = $this->normalizedStart();
                $due = $this->normalizedDue();
            } catch (\InvalidArgumentException) {
                $validator->errors()->add(
                    'proposed_start_at',
                    'Waktu peminjaman harus berisi tanggal dan waktu yang valid.'
                );

                return;
            }

            $durationMicroseconds = RentalPriceCalculator::durationMicroseconds($start, $due);

            if ($durationMicroseconds <= 0) {
                $validator->errors()->add(
                    'proposed_due_at',
                    'Waktu pengembalian harus lebih besar daripada waktu mulai.'
                );

                return;
            }

            if ($durationMicroseconds < RentalPriceCalculator::MICROSECONDS_PER_DAY) {
                $validator->errors()->add(
                    'proposed_due_at',
                    'Durasi peminjaman minimal 24 jam penuh.'
                );
            }
        });
    }

    public function normalizedStart(): CarbonImmutable
    {
        $timezone = config('app.timezone');

        return CarbonImmutable::parse((string) $this->validated('proposed_start_at'), $timezone)
            ->setTimezone($timezone);
    }

    public function normalizedDue(): CarbonImmutable
    {
        $timezone = config('app.timezone');

        return CarbonImmutable::parse((string) $this->validated('proposed_due_at'), $timezone)
            ->setTimezone($timezone);
    }
}
