<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

class RouteBoundRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|unique:teams,name,' . $this->route('team')->id];
    }
}
