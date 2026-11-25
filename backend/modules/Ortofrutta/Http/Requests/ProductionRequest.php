<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** An action on the whole crew of one production. */
class ProductionRequest extends FormRequest
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
            'production' => ['required', 'integer', 'exists:orto_orders,id'],
        ];
    }

    public function attributes(): array
    {
        return ['production' => 'produzione'];
    }
}
