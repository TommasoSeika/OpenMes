<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The search of the traceability screen. Everything is optional. */
class TraceRequest extends FormRequest
{
    use ItalianMessages {
        messages as private italian;
    }

    /** Access is decided by the route middleware (tab.access). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // cliente: from a delivered order back to the producers; lotto: from a raw lot forward to the customers.
            'vista' => ['nullable', 'string', 'in:cliente,lotto'],
            'q' => ['nullable', 'string', 'max:60'],
            'cliente' => ['nullable', 'integer', 'exists:orto_customers,id'],
            'produttore' => ['nullable', 'integer', 'exists:orto_producers,id'],
            'dal' => ['nullable', 'date_format:Y-m-d'],
            'al' => ['nullable', 'date_format:Y-m-d'],
            'ordine' => ['nullable', 'integer', 'exists:orto_orders,id'],
            'foglio' => ['nullable', 'integer', 'exists:orto_work_sheets,id'],
            'lotto' => ['nullable', 'integer', 'exists:orto_lots,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'ricerca', 'cliente' => 'cliente', 'produttore' => 'produttore',
            'dal' => 'dal giorno', 'al' => 'al giorno', 'ordine' => 'ordine', 'foglio' => 'foglio', 'lotto' => 'lotto',
        ];
    }

    public function messages(): array
    {
        return ['date_format' => 'Il campo :attribute deve essere una data.'] + $this->italian();
    }
}
