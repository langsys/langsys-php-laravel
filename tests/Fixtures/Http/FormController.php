<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

class FormController
{
    public function pay(StorePaymentRequest $request)
    {
        return 'ok';
    }

    public function odd(UnlistableRequest $request)
    {
        return 'ok';
    }

    public function order(\Langsys\Laravel\Tests\Fixtures\Data\StoreOrderData $data)
    {
        return 'ok';
    }

    public function device(UnlabelledRequest $request)
    {
        return 'ok';
    }

    public function team(RouteBoundRequest $request)
    {
        return 'ok';
    }
}
