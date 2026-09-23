<?php

namespace App\Http\Requests;

use App\Models\Interaction;
use Illuminate\Foundation\Http\FormRequest;

class ListInteractionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [Interaction::class, $this->route('contact')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
