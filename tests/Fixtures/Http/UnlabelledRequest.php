<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/** Every message listable, one field with no declared label: a package's request, say, the app cannot label. */
class UnlabelledRequest extends FormRequest
{
    public function rules(): array
    {
        return ['device_name' => 'required|string'];
    }
}
