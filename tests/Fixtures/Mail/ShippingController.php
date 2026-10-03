<?php

namespace Langsys\Laravel\Tests\Fixtures\Mail;

class ShippingController
{
    public function ship(ShippingService $shipping)
    {
        $shipping->confirm('ana@example.com');

        return __('Pay now');
    }

    public function preview()
    {
        return (new OrderShipped())->render();
    }
}
