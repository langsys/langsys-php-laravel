<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StorePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cc_number'   => 'required|digits_between:12,19',
            'holder'      => ['required', 'string', 'max:80'],
            'type'        => ['required', Rule::in(['personal', 'business'])],
            'vat_id'      => 'required_if:type,business',
            'items.*.sku' => 'required|string',
            'pin'         => ['nullable', Password::min(8)->letters()],
        ];
    }

    public function attributes(): array
    {
        return ['cc_number' => 'card number', 'vat_id' => 'VAT number', 'items.*.sku' => 'item code'];
    }

    public function messages(): array
    {
        return ['holder.max' => 'Keep the :attribute under :max letters.'];
    }
}
