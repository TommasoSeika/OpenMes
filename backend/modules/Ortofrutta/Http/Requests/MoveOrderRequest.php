<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveOrderRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'exists:orto_orders,id'],
            'line_id' => ['nullable', 'integer', 'exists:orto_lines,id'],
            'position' => ['required', 'integer', 'min:0', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'order_id' => 'ordine',
            'line_id' => 'linea',
            'position' => 'posizione',
        ];
    }
}
