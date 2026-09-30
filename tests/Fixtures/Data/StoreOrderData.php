<?php

namespace Langsys\Laravel\Tests\Fixtures\Data;

use Spatie\LaravelData\Data;

/** A laravel-data request DTO: rules, labels and a nested object, as an application writes one. */
class StoreOrderData extends Data
{
    public function __construct(
        public string $customer_name,
        public string $coupon,
        public ?AddressData $address,
    ) {
    }

    public static function rules(): array
    {
        return [
            'customer_name' => ['required', 'max:80'],
            'coupon'        => [new UppercaseCode()],
        ];
    }

    public static function attributes(): array
    {
        return ['customer_name' => 'customer name', 'coupon' => 'coupon code'];
    }
}
