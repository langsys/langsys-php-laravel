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
            'type'        => ['required', Rule::in(['personal', 'business', 'nonprofit'])],
            'vat_id'      => 'required_if:type,business,nonprofit',
            'items.*.sku' => 'required|string',
            'pin'         => ['nullable', Password::min(8)->letters()],
            'reference'   => ['nullable', new MaxWords(3)],
            'amount'      => 'nullable|numeric|gt:deposit',
            'lines.*.qty' => 'nullable|integer|min:1',
            'contact'     => 'nullable|email',
        ];
    }

    public function attributes(): array
    {
        return ['cc_number' => 'card number', 'vat_id' => 'VAT number', 'items.*.sku' => 'item code', 'reference' => 'payment reference', 'lines.*.qty' => 'line quantity'];
    }

    public function messages(): array
    {
        return [
            'holder.max'      => 'Keep the :attribute under :max letters.',
            'lines.*.qty.min' => 'Line #:position needs at least :min.',
            'contact.email'   => ':input is not a valid email address.',
        ];
    }
}
