<?php

namespace Langsys\Laravel\Tests\Fixtures\InlineValidation;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Validation written inline, each way Laravel offers, and calls that only look like it. */
class OrderController
{
    public function store(Request $request)
    {
        $request->validate(['qty' => 'required']);
        $validator = Validator::make($request->all(), ['sku' => 'required']);
        $validator->validate();
        $request->validated();
        \Illuminate\Support\Facades\Validator::validate($request->all(), ['note' => 'string']);
        validator($request->all(), ['coupon' => 'alpha']);
        $request?->validateWithBag('order', ['address' => 'required']);
        $request->input('qty');
        Validator::extend('sku', fn () => true);
        \Illuminate\Support\Collection::make([]);
        new Validator();
        $schema = new \stdClass();
        $schema->validate(['json' => 'body']);
        request()->validate(['agree' => 'accepted']);
    }

    /** A FormRequest's own `validator()` hook, declared, not called. */
    #[Validator('an attribute named like the helper')]
    public function validator($factory)
    {
        return $factory->make([], []);
    }
}
