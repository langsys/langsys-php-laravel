<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

use Illuminate\Foundation\Http\FormRequest;

class HandleRequest extends FormRequest
{
    public function rules(): array
    {
        return ['handle' => [new SlugField()]];
    }

    public function attributes(): array
    {
        return ['handle' => 'handle'];
    }
}
