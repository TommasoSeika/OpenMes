<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** One person on, or off, one production. */
class CrewRequest extends FormRequest
{
    use ItalianMessages;

    /** Access is decided by the route middleware (tab.access). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A production is known by one of its orders.
            'production' => ['required', 'integer', 'exists:orto_orders,id'],
            'person_id' => ['required', 'integer', 'exists:orto_people,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'production' => 'produzione',
            'person_id' => 'persona',
        ];
    }
}
