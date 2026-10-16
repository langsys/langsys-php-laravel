<?php

namespace Langsys\Laravel\Tests\Fixtures\InlineValidation;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

/** A controller validating through Laravel's `ValidatesRequests`. */
class AccountController
{
    use ValidatesRequests;

    public function update(Request $request)
    {
        $this->validate($request, ['name' => 'required']);
    }
}
