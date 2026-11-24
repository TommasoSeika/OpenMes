<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockOrderRequest extends FormRequest
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
            'item_id' => ['required', 'integer', 'exists:orto_items,id'],
            'pallets' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_id' => 'articolo',
            'pallets' => 'pedane',
        ];
    }
}
