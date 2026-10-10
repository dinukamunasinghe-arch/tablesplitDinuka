<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SplitBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('code') === '') {
            $this->merge(['code' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'seats' => ['required', 'array', 'min:1'],
            'seats.*' => ['required', 'integer', 'distinct'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.category' => ['required', 'string', Rule::in(array_keys(config('tablesplit.categories')))],
            'items.*.shared_by' => ['required', 'array', 'min:1'],
            'items.*.shared_by.*' => ['required', 'integer'],
            'code' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null) {
                    return;
                }

                if (! array_key_exists($value, config('tablesplit.discounts'))) {
                    $fail('The discount code is invalid.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        $categories = implode(', ', array_keys(config('tablesplit.categories')));

        return [
            'seats.required' => 'At least one seat is required.',
            'seats.*.distinct' => 'Seat IDs must be unique.',
            'items.*.category.in' => "The item category must be one of: {$categories}.",
            'items.*.unit_price.gt' => 'The unit price must be a positive number.',
            'items.*.quantity.gt' => 'The quantity must be a positive number.',
            'items.*.shared_by.required' => 'Every item must be shared by at least one seat.',
            'items.*.shared_by.min' => 'Every item must be shared by at least one seat.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seats = $this->input('seats');
            $items = $this->input('items');

            if (! is_array($seats) || ! is_array($items)) {
                return;
            }

            $allowed = [];

            foreach ($seats as $seat) {
                if (is_int($seat) || (is_string($seat) && preg_match('/^-?\d+$/', $seat) === 1)) {
                    $allowed[(int) $seat] = true;
                }
            }

            foreach ($items as $index => $item) {
                if (! is_array($item) || ! is_array($item['shared_by'] ?? null)) {
                    continue;
                }

                $seen = [];

                foreach ($item['shared_by'] as $shareIndex => $seatId) {
                    if (! is_int($seatId) && ! (is_string($seatId) && preg_match('/^-?\d+$/', (string) $seatId) === 1)) {
                        continue;
                    }

                    $seatId = (int) $seatId;

                    if (isset($seen[$seatId])) {
                        $validator->errors()->add(
                            "items.{$index}.shared_by.{$shareIndex}",
                            'An item cannot list the same seat twice.',
                        );
                    }

                    $seen[$seatId] = true;

                    if (! isset($allowed[$seatId])) {
                        $validator->errors()->add(
                            "items.{$index}.shared_by.{$shareIndex}",
                            "Seat {$seatId} does not exist in the supplied seats.",
                        );
                    }
                }
            }
        });
    }
}
