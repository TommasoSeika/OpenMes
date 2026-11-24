<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkSheetRequest extends FormRequest
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
            'started_at' => ['nullable', 'date_format:H:i'],
            'ended_at' => ['nullable', 'date_format:H:i'],
            'lots' => ['present', 'array', 'max:20'],
            'lots.*.lot_id' => ['required', 'integer', 'distinct', 'exists:orto_lots,id'],
            'lots.*.kg_in' => ['required', 'numeric', 'gt:0', 'max:200000'],
            'lots.*.kg_packed' => ['required', 'numeric', 'min:0', 'lte:lots.*.kg_in'],
            'lots.*.kg_waste_cider' => ['required', 'numeric', 'min:0', 'lte:lots.*.kg_in'],
            'lots.*.kg_waste_industry' => ['required', 'numeric', 'min:0', 'lte:lots.*.kg_in'],
        ];
    }

    public function attributes(): array
    {
        return [
            'started_at' => 'ora di inizio',
            'ended_at' => 'ora di fine',
            'lots' => 'lotti',
            'lots.*.lot_id' => 'lotto',
            'lots.*.kg_in' => 'kg entrati',
            'lots.*.kg_packed' => 'kg confezionati',
            'lots.*.kg_waste_cider' => 'scarto sidro',
            'lots.*.kg_waste_industry' => 'scarto industria',
        ];
    }
}
