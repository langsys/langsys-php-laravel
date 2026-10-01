<?php

namespace Langsys\Laravel\Tests\Fixtures\Data;

use Langsys\Laravel\Tests\Fixtures\app\Enums\OrderStatus;
use Spatie\LaravelData\Data;

/** A laravel-data request DTO: rules, labels, a nested object and an enum laravel-data infers a rule for, as an application writes one. */
class StoreOrderData extends Data
{
    public function __construct(
        public string $customer_name,
        public string $coupon,
        public ?AddressData $address,
        public OrderStatus $status,
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
        return ['customer_name' => 'customer name', 'coupon' => 'coupon code', 'status' => 'order status'];
    }
}
