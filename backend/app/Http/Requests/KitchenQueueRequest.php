<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KitchenQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $tickets = $this->input('tickets');

        if (! is_array($tickets)) {
            return;
        }

        foreach ($tickets as $index => $ticket) {
            if (is_array($ticket) && array_key_exists('priority', $ticket) && $ticket['priority'] === '') {
                $tickets[$index]['priority'] = null;
            }
        }

        $this->merge(['tickets' => $tickets]);
    }

    public function rules(): array
    {
        return [
            'now' => ['required', 'numeric', 'gte:0'],
            'tickets' => ['required', 'array', 'min:1'],
            'tickets.*.id' => ['required', 'string', 'distinct', 'max:50'],
            'tickets.*.table' => ['required', 'integer'],
            'tickets.*.created' => ['required', 'numeric', 'gte:0'],
            'tickets.*.prep' => ['required', 'numeric', 'gt:0'],
            'tickets.*.priority' => ['nullable', 'string', Rule::in(array_keys(config('tablesplit.kitchen.priorities')))],
        ];
    }

    public function messages(): array
    {
        $priorities = implode(', ', array_keys(config('tablesplit.kitchen.priorities')));

        return [
            'tickets.*.id.distinct' => 'Ticket IDs must be unique.',
            'tickets.*.created.gte' => 'Ticket creation time must be zero or greater.',
            'tickets.*.prep.gt' => 'Preparation time must be a positive number.',
            'tickets.*.priority.in' => "The ticket priority must be one of: {$priorities}.",
            'now.gte' => 'The current time must be zero or greater.',
        ];
    }
}
