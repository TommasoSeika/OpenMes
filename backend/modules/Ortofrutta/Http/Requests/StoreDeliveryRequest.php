<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryRequest extends FormRequest
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
            'producer_id' => ['required', 'integer', 'exists:orto_producers,id'],
            'variety' => ['required', 'string', 'max:60'],
            'gross_kg' => ['required', 'numeric', 'gt:0', 'max:60000'],
            'bins' => ['required', 'integer', 'min:1', 'max:400'],
            'bin_tare_kg' => ['required', 'numeric', 'min:0', 'max:80'],
            'cell_id' => ['required', 'integer', 'exists:orto_cells,id'],
            'purchase_price_kg' => ['required', 'numeric', 'min:0', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return [
            'producer_id' => 'produttore',
            'variety' => 'varietà',
            'gross_kg' => 'peso lordo',
            'bins' => 'numero di bins',
            'bin_tare_kg' => 'tara per bin',
            'cell_id' => 'cella',
            'purchase_price_kg' => 'prezzo di acquisto',
        ];
    }
}
