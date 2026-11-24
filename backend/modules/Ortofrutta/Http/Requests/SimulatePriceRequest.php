<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimulatePriceRequest extends FormRequest
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
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:50'],
            'yield_pct' => ['required', 'numeric', 'gt:0', 'max:100'],
            'processing' => ['required', 'numeric', 'min:0', 'max:50'],
            'discount_pct' => ['required', 'numeric', 'min:0', 'lt:100'],
            'list_price' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'purchase_price' => 'prezzo di acquisto',
            'yield_pct' => 'resa',
            'processing' => 'costo di lavorazione',
            'discount_pct' => 'sconto',
            'list_price' => 'prezzo di listino',
        ];
    }
}
