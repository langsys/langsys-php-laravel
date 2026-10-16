<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Langsys\Laravel\Tests\Fixtures\app\Enums\Unmarked;

/** A request whose labels the app translates in `validation.attributes`, for the listing's language. */
class ProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email'  => 'required',
            'status' => [Rule::enum(Unmarked::class)],
            'notes'  => ['nullable', new MaxWords(3)],
        ];
    }
}
