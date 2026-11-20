<?php

namespace App\Http\Requests\Item;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where(fn (Builder $query) => $query->where('is_active', true)),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'required', 'string'],
            'daily_price' => [
                'sometimes',
                'required',
                'numeric',
                'gt:0',
                'decimal:0,2',
                'max:9999999999.99',
            ],
            'item_condition' => [
                'sometimes',
                'required',
                Rule::in(['new', 'like_new', 'good', 'fair']),
            ],
            'condition_notes' => ['sometimes', 'nullable', 'string'],
            'listing_status' => [
                'sometimes',
                'required',
                Rule::in(['active', 'inactive', 'archived']),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add(
                    'item',
                    'Minimal satu field barang harus dikirim.'
                );
            }
        });
    }
}
