<?php

namespace App\Http\Requests;

use App\Enums\InteractionType;
use App\Models\Interaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Interaction::class, $this->route('contact')]);
    }

    protected function prepareForValidation(): void
    {
        // The idempotency key travels as a header (it's metadata about the
        // request, not part of the interaction's data) but is validated
        // alongside the body for a single consistent error response — see
        // DECISIONS.md #2.
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', Rule::enum(InteractionType::class)],
            // Defaults to now() if omitted; may be backdated but not more
            // than 5 minutes into the future (small clock-skew allowance).
            'timestamp' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'note' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.uuid' => 'The Idempotency-Key header must be a valid UUID.',
        ];
    }
}
