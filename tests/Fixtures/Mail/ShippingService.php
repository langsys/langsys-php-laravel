<?php

namespace Langsys\Laravel\Tests\Fixtures\Mail;

use Illuminate\Support\Facades\Mail;

class ShippingService
{
    public function confirm(string $email): void
    {
        Mail::to($email)->send(new OrderShipped());
    }
}
